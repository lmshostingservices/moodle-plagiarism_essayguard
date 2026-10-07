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
 * Post-installation hook for plagiarism_essayguard.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Store explicit defaults after install.
 *
 * Essay Guard is installed switched off. Nothing is captured until an administrator
 * enables the plugin and a teacher enables it on an activity. The core
 * "Enable plagiarism plugins" setting is never changed by this plugin.
 *
 * @return bool Always true.
 */
function xmldb_plagiarism_essayguard_install() {
    global $CFG;

    set_config('enabled', 0, 'plagiarism_essayguard');
    set_config('retentiondays', 90, 'plagiarism_essayguard');

    if (empty($CFG->enableplagiarism)) {
        try {
            \core\notification::add(
                get_string('notice_enableplagiarism', 'plagiarism_essayguard'),
                \core\output\notification::NOTIFY_WARNING
            );
        } catch (\Throwable $e) {
            // The notification stack is not always available (CLI install); the settings page repeats the notice.
            mtrace(get_string('notice_enableplagiarism', 'plagiarism_essayguard'));
        }
    }
    return true;
}
