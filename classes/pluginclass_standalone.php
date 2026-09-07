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

// The sibling file classes/pluginclass.php declares this same class name, but the two
// can never both be loaded in one request: the spl_autoload_register() in lib.php picks exactly one of
// them, on the single condition class_exists('plagiarism_plugin', false), and requires
// only that file. Verified on Moodle 4.5.13: abstract class plagiarism_plugin is declared
// only in plagiarism/lib.php, which no core file loads and which is not autoloadable, so
// the condition is false and this standalone file is the one that gets loaded. The
// duplicate is therefore a compile-time appearance only, never a runtime collision, and
// the sniff is silenced for the whole file (a phpcs:ignore on the class line would break
// docblock/class adjacency and trip moodle.Commenting.MissingDocblock.Class).
// phpcs:disable Generic.Classes.DuplicateClassName.Found

/**
 * EssayGuard plagiarism plugin class — Path A standalone fallback.
 *
 * Loaded by the spl_autoload_register in lib.php only when plagiarism_plugin
 * base class is unavailable (edge case: very early CLI scripts or unit tests
 * that instantiate the class before Moodle's plagiarism stack is bootstrapped).
 *
 * update_status() stub prevents ReflectionException crash on Moodle versions
 * that call new ReflectionMethod() without a try/catch guard. The stub will
 * cause getDeclaringClass() = 'plagiarism_plugin_essayguard', but this path
 * should never be reached on a normal web request.
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
class plagiarism_plugin_essayguard {
    /**
     * Legacy plagiarism API hook, retained so Moodle has something to call.
     *
     * Essay Guard scores sessions from the web services and cron, so there is no
     * per-view status work to do here.
     *
     * @param object $course The course record.
     * @param object $cm     The course module record.
     * @return bool Always true.
     */
    public function update_status($course, $cm) {
        return true;
    }

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
