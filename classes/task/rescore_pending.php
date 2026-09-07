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

namespace plagiarism_essayguard\task;

use plagiarism_essayguard\local\service\analyser;

/**
 * FIX-EG-RESCORE-TASK (v1.2.96): Retroactively re-score attempts whose events
 * arrived in the DB after the PHP observer had already fired.
 *
 * PROBLEM: the observer fires synchronously during Moodle's form processing.
 * If the JS flush() AJAX call completed AFTER the form was submitted (e.g.
 * because the old 7 s race timeout fired first), the observer saw 0 events,
 * wrote riskscore=0 (LOW), and exited. Events arrived moments later via the
 * sendBeacon fallback or a deferred XHR, but nothing triggered a re-score —
 * so the LOW badge was permanent even though behavioural telemetry was present.
 *
 * FIX: this task runs every 5 minutes (randomised minute within the hour to
 * spread server load). It finds score records where:
 *   - riskscore = 0 (scored with no events), AND
 *   - at least 5 events exist for the same (userid, cmid, attemptkey), AND
 *   - the score record was written more than 3 minutes ago (avoids racing
 *     with an in-progress submission where events are still being flushed)
 *
 * For each matching record it calls analyser::score_attempt() with the stored
 * userid / cmid / attemptkey so the full event set is re-evaluated. The
 * FIX-EG-SCORE-NO-CLOBBER guard in analyser.php ensures that if a later
 * (correct) score has already been written by another path, it is preserved.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rescore_pending extends \core\task\scheduled_task {
    /**
     * Name shown for this task on the scheduled tasks admin page.
     *
     * @return string The translated task name.
     */
    public function get_name(): string {
        return get_string('rescore_task', 'plagiarism_essayguard');
    }

    /**
     * Score finished attempts whose telemetry arrived but were never scored.
     *
     * Covers sessions where the browser never reached finalize_attempt, for example
     * because the student closed the tab as the attempt was submitted.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        // Only run when the plugin is globally enabled.
        //
        // v1.2.224 FIX-EG-RESCOREPENDING-NEVERRUNS: this read `$enabled === false ||
        // empty($enabled)`, which treats a NEVER-SAVED setting as "disabled". Every other
        // enabled-check in this plugin - lib.php (x3), observer.php, before_footer.php,
        // finalize_attempt.php, refresh_licence.php, report.php, rescore.php - reads
        // `!== false && empty()`, i.e. an unset key means enabled, because get_config()
        // returns false for a key the admin has never written and that is the state of
        // every fresh install.
        //
        // So on a site where nobody had opened Essay Guard's settings page and pressed
        // Save, live scoring ran but this task returned immediately, every run, forever.
        // This task is the backstop for attempts whose browser never reached
        // finalize_attempt - the student closed the tab as it submitted - and those
        // attempts keep riskscore = 0 permanently. The one component that exists to
        // repair the gap was the one component the default configuration switched off.
        $enabled = get_config('plagiarism_essayguard', 'enabled');
        if ($enabled !== false && empty($enabled)) {
            mtrace('Essay Guard rescore_pending: plugin disabled — skipping.');
            return;
        }

        // Score records written more than 3 minutes ago with riskscore = 0,
        // where events exist for the same (userid, cmid, attemptkey).
        // We join on the aggregate slot (qslot=0) because that is always the
        // first record written and the one the badge display falls back to.
        $cutoff = time() - 180; // 3 minutes ago

        $stale = $DB->get_records_sql(
            "SELECT sc.id, sc.userid, sc.cmid, sc.contextid, sc.attemptkey
               FROM {plagiarism_essayguard_sc} sc
              WHERE sc.qslot   = 0
                AND sc.riskscore = 0
                AND sc.timemodified < :cutoff
                AND EXISTS (
                        SELECT 1
                          FROM {plagiarism_essayguard_ev} ev
                         WHERE ev.userid     = sc.userid
                           AND ev.cmid       = sc.cmid
                           AND ev.attemptkey = sc.attemptkey
                      GROUP BY ev.userid, ev.cmid, ev.attemptkey
                        HAVING COUNT(ev.id) >= 5
                    )
           -- v1.2.221: ASC, not DESC. score_attempt() writes timemodified = time() on
           -- every pass, and an attempt that legitimately scores 0 still matches the
           -- riskscore = 0 predicate above - so with DESC the 50 records just rescored
           -- sorted straight back to the FRONT and were reprocessed five minutes later,
           -- forever. Measured over 20 cron runs against 200 eligible records: the same 50
           -- were rescored 20 times each and 150 were never touched. Oldest-first gives
           -- every record a turn.
           ORDER BY sc.timemodified ASC",
            ['cutoff' => $cutoff],
            // V1.2.219: 'LIMIT 50' was written into the SQL string. LIMIT is MySQL/
            // PostgreSQL syntax; SQL Server wants TOP and Oracle wants a ROWNUM/FETCH
            // clause, so this task threw a dml_read_exception on every cron run on
            // either of those platforms — which Moodle fully supports and which large
            // institutional clients actually run. get_records_sql()'s $limitfrom and
            // $limitnum arguments let Moodle's DML layer emit the right dialect.
            0,
            50
        );

        if (empty($stale)) {
            mtrace('Essay Guard rescore_pending: no stale zero-score records found.');
            return;
        }

        mtrace('Essay Guard rescore_pending: rescoring ' . count($stale) . ' attempt(s) with events but riskscore=0.');

        $rescored = 0;
        foreach ($stale as $row) {
            try {
                // Re-score aggregate (qslot=0) without finaltext — behavioural signals
                // are sufficient to detect paste/fast-typing. The linguistic fallback
                // (sentence_variance, vocab_diversity) requires the submitted text, but
                // if paste events are present Signal 1 already pushes the score to HIGH
                // without needing text-based signals.
                $result = analyser::score_attempt(
                    (int)$row->userid,
                    (int)$row->cmid,
                    (int)$row->contextid,
                    $row->attemptkey,
                    [], // No pre-computed linguistic metrics.
                    '', // No finaltext — rely on behavioural signals only.
                    0     // Aggregate slot.
                );
                mtrace(
                    sprintf(
                        '  [%s] userid=%d cmid=%d → risklevel=%s score=%d',
                        substr($row->attemptkey, 0, 12),
                        $row->userid,
                        $row->cmid,
                        $result['risklevel'],
                        $result['score100']
                        )
                );
                $rescored++;
            } catch (\Throwable $e) {
                mtrace(
                    sprintf(
                        '  [%s] ERROR: %s',
                        substr($row->attemptkey, 0, 12),
                        $e->getMessage()
                        )
                );
            }
        }

        mtrace("Essay Guard rescore_pending: rescored {$rescored} attempt(s).");
    }
}
