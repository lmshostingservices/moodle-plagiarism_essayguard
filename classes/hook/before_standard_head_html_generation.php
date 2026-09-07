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

namespace plagiarism_essayguard\hook;

/**
 * Hook callback for core\hook\output\before_standard_head_html_generation.
 *
 * Replaces the legacy plagiarism_essayguard_before_standard_html_head() callback
 * on Moodle 4.3 and above where the hook system is available.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_standard_head_html_generation {
    /**
     * Inject the Essay Guard tracker AMD module into the page head.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook The hook instance
     *        dispatched by Moodle while it builds the page head.
     * @return void
     */
    public static function callback(
        \core\hook\output\before_standard_head_html_generation $hook
    ): void {
        global $CFG;
        // FIX-EG-PRELOAD-PLAGIARISMLIB (v1.2.167): Pre-load plagiarismlib.php so that
        // plagiarism_plugin IS in memory before process_legacy_callbacks() calls
        // get_plugins_with_function() and include_once(lib.php).
        //
        // Hook callbacks (registered in db/hooks.php) are dispatched BEFORE
        // process_legacy_callbacks() is called. So by loading plagiarismlib.php here,
        // we ensure class_exists('plagiarism_plugin', false) = TRUE when lib.php is
        // subsequently included → Path B (extends plagiarism_plugin) is selected →
        // update_status() is inherited → getDeclaringClass() = 'plagiarism_plugin' →
        // plagiarism_update_status() else-branch sees no deprecation.
        //
        // Combined with removing the global plagiarism_essayguard_before_standard_top_of_body_html()
        // function (which caused the "Callback should be migrated" DEBUG_DEVELOPER notice),
        // this eliminates ALL plagiarism-related HTML warnings for both the student quiz
        // attempt page and the teacher grading/submissions pages.
        require_once($CFG->libdir . '/plagiarismlib.php');
        // FIX-EG-PRELOAD-LIB (v1.2.183): Also preload lib.php immediately after
        // plagiarismlib.php so that plagiarism_essayguard_before_standard_top_of_body_html()
        // is defined before plagiarism_update_status() calls function_exists().
        //
        // On the quiz Results / Overview report page, report_base.php calls
        // plagiarism_update_status() from print_header_and_tabs() immediately after
        // $OUTPUT->header() returns. This hook fires inside $OUTPUT->header(), so by
        // the time plagiarism_update_status() runs, both plagiarismlib.php (Path B
        // class selector) and lib.php (global function definition) are already loaded.
        //
        // plagiarism_update_status() then checks function_exists() → TRUE → calls the
        // no-op function directly → never reaches the ReflectionMethod / update_status()
        // branch → the "plagiarism_plugin::update_status() is deprecated" notice is gone.
        //
        // PHP's require_once prevents double-loading; this is safe even when lib.php
        // is also loaded later by process_legacy_callbacks() / get_plugins_with_function().
        require_once(__DIR__ . '/../../lib.php');
        plagiarism_essayguard_inject_tracker();
    }
}
