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

/**
 * plagiarism_essayguard file.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_essayguard\task;

/**
 * Scheduled task that prunes raw typing telemetry past the data-retention window.
 *
 * plagiarism_essayguard_ev holds keystroke-level events and is by far the largest
 * table the plugin writes; once an attempt has been scored those rows are redundant.
 * This task deletes events older than the "retentiondays" admin setting (0 disables
 * pruning entirely). Score records in plagiarism_essayguard_sc are deliberately never
 * pruned here, so historical risk assessments stay viewable in the reports.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends \core\task\scheduled_task {
    /** @var int Rows deleted per statement. */
    const DELETE_BATCH_SIZE = 10000;

    /** @var int Seconds this task will spend deleting before deferring the rest. */
    const MAX_RUNTIME_SECONDS = 120;

    /**
     * Name shown for this task on the scheduled tasks admin page.
     *
     * @return string The translated task name.
     */
    public function get_name(): string {
        return get_string('cleanup_task', 'plagiarism_essayguard');
    }

    /**
     * Delete raw typing telemetry past the configured retention window.
     *
     * Session scores and metrics are kept so historical reports stay viewable.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $cfg = (array) get_config('plagiarism_essayguard');
        $retentiondays = (int) ($cfg['retentiondays'] ?? 90);

        if ($retentiondays <= 0) {
            mtrace('Essay Guard cleanup: retention set to 0 — pruning disabled.');
            return;
        }

        $cutoff = time() - ($retentiondays * DAYSECS);

        // Only prune raw telemetry events — these grow large and contain redundant
        // keystroke-level data once scoring is complete.
        // Score records (plagiarism_essayguard_sc) are kept permanently so
        // teachers can review historical risk assessments and compare across
        // submissions. The score table is small (one row per attempt).
        // PERF-EG-CHUNKED-CLEANUP (v1.3.0): delete in batches, with a time budget.
        //
        // This was a count_records_select() followed immediately by an unbounded
        // delete_records_select() on the same predicate: two full scans of the largest
        // table in the plugin, and then one DELETE covering everything. At roughly two
        // rows per keystroke, a 500-student three-essay sitting is of the order of
        // seven million rows, so the first run against a term of accumulated data is a
        // single multi-million-row DELETE — a long lock on InnoDB, heavy bloat on
        // PostgreSQL, and a real chance of hitting a statement timeout and failing
        // every night thereafter while the table only grows.
        //
        // Batching means a slow night prunes less rather than failing, and the table
        // never gets to the point where it cannot be pruned at all.
        $evdeleted = 0;
        $started   = time();
        while (true) {
            $ids = $DB->get_fieldset_sql(
                "SELECT id
                   FROM {plagiarism_essayguard_ev}
                  WHERE timecreated < :cutoff
               ORDER BY id ASC",
                ['cutoff' => $cutoff],
                0,
                self::DELETE_BATCH_SIZE
            );
            if (empty($ids)) {
                break;
            }
            $DB->delete_records_list('plagiarism_essayguard_ev', 'id', $ids);
            $evdeleted += count($ids);

            if ((time() - $started) >= self::MAX_RUNTIME_SECONDS) {
                mtrace(
                    'Essay Guard cleanup: time budget reached after '
                        . $evdeleted . ' event(s); the remainder will be pruned on the next run.'
                );
                break;
            }
        }

        // PRIVACY-EG-FINGERPRINT-RETENTION (v1.3.0): the behavioural profile was kept
        // forever and by anything.
        //
        // plagiarism_essayguard_fp holds a per-student writing profile — typical speed,
        // pause pattern, correction rate, rhythm — accumulated across submissions and
        // used to decide whether a later submission looks like the same person. Raw
        // events were pruned; this was not, not even when the course module was
        // deleted. The student disclosure did not mention it existed. That is storage
        // limitation (GDPR Art. 5(1)(e)) and APP 11.2 unaddressed, on the most sensitive
        // thing the plugin holds.
        //
        // A profile outlives the scores it was built from by the same retention period,
        // and no longer survives having nothing left to describe.
        $fporphans = $DB->get_fieldset_sql(
            "SELECT fp.id
               FROM {plagiarism_essayguard_fp} fp
              WHERE fp.timemodified < :cutoff
                AND NOT EXISTS (SELECT 1
                                  FROM {plagiarism_essayguard_sc} sc
                                 WHERE sc.userid = fp.userid)",
            ['cutoff' => $cutoff]
        );
        $fpdeleted = 0;
        if (!empty($fporphans)) {
            $DB->delete_records_list('plagiarism_essayguard_fp', 'id', $fporphans);
            $fpdeleted = count($fporphans);
        }

        mtrace(
            "Essay Guard cleanup: deleted {$evdeleted} telemetry event(s) older than "
                . "{$retentiondays} days, and {$fpdeleted} orphaned writing profile(s). Score records are retained."
        );
    }
}
