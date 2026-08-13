<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace plagiarism_essayguard\external;

defined('MOODLE_INTERNAL') || die();

// FIX-EG-EXTERNAL-API-CLASS (v1.2.141): see log_event.php for full explanation.
// FIX-EG-CFG-SCOPE (v1.2.142): see log_event.php for full explanation.
global $CFG;
require_once($CFG->libdir . '/externallib.php');

use context_module;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;

require_once(__DIR__ . '/../../lib.php');

/**
 * get_badges Web Service.
 *
 * BUG-EG-NO-BADGE-OVERVIEW (v1.2.54):
 *
 * Moodle's plagiarism API (plagiarism_get_links) is only invoked when rendering
 * individual student submission content — e.g. on the attempt review or inline
 * grading pages. The quiz grading OVERVIEW table (/mod/quiz/report.php?mode=grading)
 * renders a summary row per student but never calls plagiarism_get_links(), so
 * Essay Guard risk badges were permanently invisible on that page.
 *
 * This external function supplies badge data in bulk so reporter.js can inject
 * badges directly into the grading overview table on the client side.
 *
 * Called by plagiarism_essayguard/reporter AMD module (teacher-only, quiz grading pages).
 * Requires plagiarism/essayguard:viewreport capability.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_badges extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'    => new external_value(PARAM_INT, 'Course module ID'),
            'userids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'User ID')
            ),
        ]);
    }

    public static function execute(int $cmid, array $userids): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'    => $cmid,
            'userids' => $userids,
        ]);

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('plagiarism/essayguard:viewreport', $context);

        // SESSION LOCK FIX: Release session lock before DB queries so concurrent
        // AJAX requests (autosave, tracker flush) are not blocked by this call.
        \core\session\manager::write_close();

        $results = [];

        if (empty($params['userids'])) {
            return $results;
        }

        list($in_sql, $in_params) = $DB->get_in_or_equal($params['userids'], SQL_PARAMS_NAMED, 'uid');
        $sql_params = array_merge(['cmid' => $params['cmid']], $in_params);

        // FIX-EG-BADGE-WORST-Q (v1.2.72): The previous implementation used the
        // qslot=0 aggregate record (all keyboard events for the entire attempt
        // blended into one score). Because the aggregate averages behaviour across
        // all questions, a single high-risk question is diluted — teachers saw
        // "LOW" next to the student name even when an individual question was
        // "MEDIUM" or "HIGH". The student-level badge must reflect the worst
        // (highest-risk) question so the overview table is a reliable alert.
        //
        // New strategy:
        //   1. Fetch all per-question records (qslot > 0) ordered by riskscore DESC.
        //      The first row seen per user in PHP is always the worst question.
        //   2. Fall back to the aggregate (qslot=0) record only for users who have
        //      no per-question data yet (e.g. assign/forum or tracker not active).

        // Step 1 — per-question records, worst first.
        $pq_rows = $DB->get_records_sql(
            "SELECT *
               FROM {plagiarism_essayguard_sc}
              WHERE cmid = :cmid AND qslot > 0 AND userid $in_sql
           ORDER BY userid ASC, riskscore DESC, timemodified DESC",
            $sql_params
        );

        $by_user_pq = [];
        foreach ($pq_rows as $row) {
            // First seen per user = highest riskscore (ORDER BY riskscore DESC).
            if (!isset($by_user_pq[$row->userid])) {
                $by_user_pq[$row->userid] = $row;
            }
        }

        // Step 2 — aggregate fallback (qslot=0), most-recent first.
        $agg_rows = $DB->get_records_sql(
            "SELECT *
               FROM {plagiarism_essayguard_sc}
              WHERE cmid = :cmid AND qslot = 0 AND userid $in_sql
           ORDER BY userid ASC, timemodified DESC",
            $sql_params
        );

        $by_user_agg = [];
        foreach ($agg_rows as $row) {
            if (!isset($by_user_agg[$row->userid])) {
                $by_user_agg[$row->userid] = $row;
            }
        }

        foreach ($params['userids'] as $uid) {
            // FIX-EG-BADGE-AGGREGATE-RESCUE (v1.2.86): always prefer the HIGHER-risk
            // record between the worst per-question result and the aggregate (qslot=0).
            //
            // Previous behaviour: per-question records were always preferred over the
            // aggregate. This caused "always Low" when qslot detection failed in
            // tracker.js (TinyMCE 6 / non-standard Moodle themes): per-question scoring
            // found no qslot-tagged events → score=0 → Low, while the aggregate correctly
            // detected the paste and scored High. Teachers saw Low because the Low
            // per-question record shadowed the correct aggregate record.
            //
            // New behaviour: compare per-question worst and aggregate; expose whichever
            // has the higher riskscore. If only one exists, use that one (no change for
            // assignments/forums that never produce per-question records).
            $pq_record  = $by_user_pq[$uid]  ?? null;
            $agg_record = $by_user_agg[$uid] ?? null;
            if ($pq_record && $agg_record) {
                $record = ((float)$agg_record->riskscore > (float)$pq_record->riskscore)
                    ? $agg_record
                    : $pq_record;
            } else {
                $record = $pq_record ?? $agg_record;
            }

            if ($record) {
                $risk_pct   = max(0, min(100, (int)round((float)($record->riskscore ?? 0) * 100)));
                // Re-derive level from score so stale DB values written under old
                // thresholds are always corrected at display time (v1.2.71).
                $risk_level = \plagiarism_essayguard\local\service\analyser::risk_level($risk_pct);
                $results[] = [
                    'userid'    => (int)$uid,
                    'risklevel' => $risk_level,
                    'score100'  => $risk_pct,
                    'hasbadge'  => true,
                ];
            } else {
                $results[] = [
                    'userid'    => (int)$uid,
                    'risklevel' => '',
                    'score100'  => 0,
                    'hasbadge'  => false,
                ];
            }
        }

        return $results;
    }

    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'userid'    => new external_value(PARAM_INT,  'User ID'),
                'risklevel' => new external_value(PARAM_TEXT, 'Risk level: low | medium | high'),
                'score100'  => new external_value(PARAM_INT,  'Risk score 0-100'),
                'hasbadge'  => new external_value(PARAM_BOOL, 'Whether a badge record exists for this user'),
            ])
        );
    }
}
