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
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace plagiarism_essayguard\task;

defined('MOODLE_INTERNAL') || die();

class cleanup extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('cleanup_task', 'plagiarism_essayguard');
    }

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

        mtrace("Essay Guard cleanup: deleted {$evdeleted} telemetry event(s) older than {$retentiondays} days. Score records are retained.");
    }
}
