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

// V1.2.226 FIX-EG-EXTERNAL-API-NAMESPACE: was `require_once($CFG->libdir/externallib.php)`
// plus the global `external_api` aliases. That file's own header says it "will be
// deprecated from Moodle 4.6" (= 5.0) and survives only as a back-compatibility shim;
// when core removes it, every web service call here becomes "Class external_api not
// found" - the exact failure FIX-EG-EXTERNAL-API-CLASS (v1.2.141) was written to stop,
// arriving from the other direction. That fix was right for its time: the global aliases
// really do need externallib.php loaded.
//
// The permanent answer is the \core_external\ namespace, which has existed since Moodle
// 4.2 and needs no require_once at all - the classes autoload. Since v1.0.85/v1.2.225
// this plugin requires Moodle 4.5, so the namespaced classes are always present and the
// shim is not needed on any supported branch.

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

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
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_badges extends external_api {
    /**
     * Describe the arguments accepted by the get_badges web service.
     *
     * @return external_function_parameters The cmid and the list of user ids.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'    => new external_value(PARAM_INT, 'Course module ID'),
            'userids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'User ID')
            ),
        ]);
    }

    /**
     * Return risk badge data for several students in one activity, in one round trip.
     *
     * Used by reporter.js to add badges to the quiz grading overview table, which
     * Moodle never routes through the plagiarism get_links() API. Requires
     * plagiarism/essayguard:viewreport in the activity context.
     *
     * @param int   $cmid    The course module to read scores for.
     * @param int[] $userids The students to return badges for.
     * @return array One entry per requested student: userid, risklevel, score100, hasbadge.
     */
    public static function execute(int $cmid, array $userids): array {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'cmid'    => $cmid,
                'userids' => $userids,
                ]
        );

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

        // V1.2.224 FIX-EG-BADGES-GROUPLEAK: the capability check above is the only gate,
        // and the user ids come straight from the caller. report.php and student.php were
        // hardened for SEPARATEGROUPS in v1.2.219/v1.2.221 but this endpoint was not, so a
        // tutor restricted to one group could post any user ids for the cm and read back
        // each one's risk level and score - the same data the report withholds, through a
        // door the report's fix never covered. Filter the requested ids down to users the
        // viewer is actually entitled to see, rather than trusting the request.
        $userids = array_values(array_unique(array_map('intval', $params['userids'])));

        $cm = get_coursemodule_from_id(null, $params['cmid'], 0, false, MUST_EXIST);
        $groupmode = groups_get_activity_groupmode($cm);

        if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
            $allowedgroupids = array_keys(groups_get_activity_allowed_groups($cm));

            if (empty($allowedgroupids)) {
                // Viewer is in no group in a separate-groups activity: nothing is visible.
                return $results;
            }

            [$ginsql, $gparams] = $DB->get_in_or_equal($allowedgroupids, SQL_PARAMS_NAMED, 'grp');
            [$uinsql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'flt');
            $visible = $DB->get_fieldset_sql(
                "SELECT DISTINCT gm.userid
                   FROM {groups_members} gm
                  WHERE gm.groupid {$ginsql} AND gm.userid {$uinsql}",
                array_merge($gparams, $uparams)
            );
            $userids = array_values(array_intersect($userids, array_map('intval', $visible)));

            if (empty($userids)) {
                return $results;
            }
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $sqlparams = array_merge(['cmid' => $params['cmid']], $inparams);

        // FIX-EG-BADGE-WORST-Q (v1.2.72): The previous implementation used the
        // qslot=0 aggregate record (all keyboard events for the entire attempt
        // blended into one score). Because the aggregate averages behaviour across
        // all questions, a single high-risk question is diluted — teachers saw
        // "LOW" next to the student name even when an individual question was
        // "MEDIUM" or "HIGH". The student-level badge must reflect the worst
        // (highest-risk) question so the overview table is a reliable alert.
        //
        // New strategy:
        // 1. Fetch all per-question records (qslot > 0) ordered by riskscore DESC.
        // The first row seen per user in PHP is always the worst question.
        // 2. Fall back to the aggregate (qslot=0) record only for users who have
        // no per-question data yet (e.g. assign/forum or tracker not active).

        // Step 1 — per-question records, worst first.
        $pqrows = $DB->get_records_sql(
            "SELECT *
               FROM {plagiarism_essayguard_sc}
              WHERE cmid = :cmid AND qslot > 0 AND userid $insql
           ORDER BY userid ASC, riskscore DESC, timemodified DESC",
            $sqlparams
        );

        $byuserpq = [];
        foreach ($pqrows as $row) {
            // First seen per user = highest riskscore (ORDER BY riskscore DESC).
            if (!isset($byuserpq[$row->userid])) {
                $byuserpq[$row->userid] = $row;
            }
        }

        // Step 2 — aggregate fallback (qslot=0), most-recent first.
        $aggrows = $DB->get_records_sql(
            "SELECT *
               FROM {plagiarism_essayguard_sc}
              WHERE cmid = :cmid AND qslot = 0 AND userid $insql
           ORDER BY userid ASC, timemodified DESC",
            $sqlparams
        );

        $byuseragg = [];
        foreach ($aggrows as $row) {
            if (!isset($byuseragg[$row->userid])) {
                $byuseragg[$row->userid] = $row;
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
            $pqrecord  = $byuserpq[$uid] ?? null;
            $aggrecord = $byuseragg[$uid] ?? null;
            if ($pqrecord && $aggrecord) {
                $record = ((float)$aggrecord->riskscore > (float)$pqrecord->riskscore)
                    ? $aggrecord
                    : $pqrecord;
            } else {
                $record = $pqrecord ?? $aggrecord;
            }

            if ($record) {
                $riskpct   = max(0, min(100, (int)round((float)($record->riskscore ?? 0) * 100)));
                // Re-derive level from score so stale DB values written under old
                // thresholds are always corrected at display time (v1.2.71).
                $risklevel = \plagiarism_essayguard\local\service\analyser::risk_level($riskpct);
                $results[] = [
                    'userid'    => (int)$uid,
                    'risklevel' => $risklevel,
                    'score100'  => $riskpct,
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

    /**
     * Describe the value returned by the get_badges web service.
     *
     * @return external_multiple_structure One badge structure per student.
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'userid'    => new external_value(PARAM_INT, 'User ID'),
                'risklevel' => new external_value(PARAM_TEXT, 'Risk level: low | medium | high'),
                'score100'  => new external_value(PARAM_INT, 'Risk score 0-100'),
                'hasbadge'  => new external_value(PARAM_BOOL, 'Whether a badge record exists for this user'),
            ])
        );
    }
}
