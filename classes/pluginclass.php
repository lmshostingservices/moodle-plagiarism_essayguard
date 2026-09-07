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
 * EssayGuard plagiarism plugin class — Path B (extends plagiarism_plugin).
 *
 * Loaded by the spl_autoload_register in lib.php on first use, by which point
 * plagiarism_plugin is always defined. update_status() is INHERITED from the
 * parent class — getDeclaringClass()->getName() === 'plagiarism_plugin' — so
 * Moodle's plagiarism_update_status() ReflectionMethod check never fires the
 * deprecation notice, regardless of debug level.
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * v1.2.219: print_disclosure() now returns a real disclosure instead of ''. Returning
 * an empty string meant students were never told, anywhere in the interface, that every
 * keystroke and paste in this activity is recorded and scored. Moodle calls this method
 * precisely so a plagiarism plugin can meet that obligation; a silent tracker on a
 * client site is a legal problem, not a UI nicety.
 */
class plagiarism_plugin_essayguard extends plagiarism_plugin {
    /**
     * Return the Essay Guard badge and report links for one submission.
     *
     * @param array $linkarray Moodle plagiarism link data: cmid, userid, content and,
     *                         for quizzes, the question attempt being reviewed.
     * @return string HTML for the badge, or the empty string when nothing is shown.
     */
    public function get_links($linkarray) {
        return plagiarism_essayguard_get_links($linkarray);
    }

    /**
     * Return the student-facing disclosure shown on the submission form.
     *
     * States that keystrokes, pauses, corrections and paste events are recorded, who
     * can see the result, and how long the telemetry is kept.
     *
     * @param int $cmid The course module the student is submitting to.
     * @return string HTML disclosure, or the empty string when Essay Guard is not active here.
     */
    public function print_disclosure($cmid) {
        return plagiarism_essayguard_print_disclosure((int)$cmid);
    }

    /**
     * Persist the per-activity "Enable Essay Guard" checkbox when an activity is saved.
     *
     * @param object $data The submitted course module form data.
     * @return void
     */
    public function save_form_elements($data) {
        if (!empty($data->coursemodule)) {
            set_config(
                'enabled_cm_' . $data->coursemodule,
                !empty($data->essayguard_enabled) ? 1 : 0,
                'plagiarism_essayguard'
            );
        }
    }

    /**
     * Legacy per-module form hook.
     *
     * Essay Guard adds its checkbox from
     * plagiarism_essayguard_coursemodule_standard_elements() in lib.php instead, so
     * nothing is added here.
     *
     * @param object $mform      The course module form.
     * @param object $context    The context the form is being built for.
     * @param string $modulename The activity type, e.g. "quiz".
     * @return bool Always false.
     */
    public function get_form_elements_module($mform, $context, $modulename = '') {
        return false;
    }
}
