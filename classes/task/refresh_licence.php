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
 * v1.2.219: Refresh the cached lms-labs.com licence and platform-settings answers.
 *
 * WHY THIS TASK EXISTS: check_unlock() (10 s timeout, chaining into auto_unlock()'s
 * further 15 s) and get_platform_settings() (5 s) used to be performed inline by
 * whichever page request happened to find the 30-minute cache expired. The callers
 * are is_cm_active() — every grading-page render — and observer::is_active(), which
 * runs inside quiz submission. That put up to 25 s of third-party network latency in
 * the middle of a student pressing "Submit all and finish", and because every worker's
 * cache expires at the same moment, it stampeded across a whole cohort at once.
 *
 * Those two functions are now cache-only on the request path. This task is the only
 * caller that passes $allowfetch = true (besides the admin settings page, where the
 * admin explicitly asked for a live check), and it runs every 15 minutes so the
 * 30-minute cache never goes stale under normal operation.
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

        // Only run when the plugin is globally enabled. get_config() returns false for a
        // never-saved key, which this plugin treats as enabled (see is_cm_active()).
        $enabled = get_config('plagiarism_essayguard', 'enabled');
        if ($enabled !== false && empty($enabled)) {
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
