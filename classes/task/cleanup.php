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
        $evdeleted = $DB->count_records_select(
            'plagiarism_essayguard_ev',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );
        $DB->delete_records_select(
            'plagiarism_essayguard_ev',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );

        mtrace(
            "Essay Guard cleanup: deleted {$evdeleted} telemetry event(s) older than "
                . "{$retentiondays} days. Score records are retained."
        );
    }
}
