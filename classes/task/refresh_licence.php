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

/**
 * Refresh the cached lms-labs.com licence status and platform settings.
 *
 * Request paths only read these cached answers; this task (every 15 minutes) and an
 * explicit administrator action on the settings page are the only live checks. The
 * task only checks the licence status. It never purchases an unlock.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_licence extends \core\task\scheduled_task {
    /**
     * Name shown for this task on the scheduled tasks admin page.
     *
     * @return string The translated task name.
     */
    public function get_name(): string {
        return get_string('refresh_licence_task', 'plagiarism_essayguard');
    }

    /**
     * Refresh the cached licence verdict and site-wide platform settings.
     *
     * Moves the lms-labs.com calls off the page request path so no student or teacher
     * page load waits on an outbound HTTPS request. Only the site ID and API key are sent.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG;
        require_once($CFG->dirroot . '/plagiarism/essayguard/lib.php');

        // Site switch: off until an administrator enables Essay Guard.
        if (!\plagiarism_essayguard_is_enabled()) {
            mtrace('Essay Guard refresh_licence: plugin disabled — skipping.');
            return;
        }

        try {
            $unlocked = plagiarism_essayguard_check_unlock(true);
            mtrace(
                'Essay Guard refresh_licence: unlock status refreshed — '
                    . ($unlocked ? 'unlocked' : 'NOT unlocked') . '.'
            );
        } catch (\Throwable $e) {
            // Never let a vendor outage fail the cron run; the request path falls back
            // to the previous cached answer, which is fail-open.
            mtrace('Essay Guard refresh_licence: unlock refresh failed — ' . $e->getMessage());
        }

        try {
            $settings = plagiarism_essayguard_get_platform_settings(true);
            mtrace(
                'Essay Guard refresh_licence: platform settings refreshed — '
                    . 'assignments=' . (!empty($settings['essayguard_assignments']) ? 'on' : 'off')
                    . ', quizzes=' . (!empty($settings['essayguard_quizzes']) ? 'on' : 'off') . '.'
            );
        } catch (\Throwable $e) {
            mtrace('Essay Guard refresh_licence: platform settings refresh failed — ' . $e->getMessage());
        }
    }
}
