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
 * Hook callback for core\hook\output\before_standard_top_of_body_html_generation.
 *
 * Registered in db/hooks.php. Fires during $OUTPUT->header() — AFTER
 * before_standard_head_html_generation but BEFORE the page body is written.
 * Both hooks load lib.php for belt-and-suspenders coverage:
 *
 *   • before_standard_head_html_generation (priority 500) — primary load.
 *   • before_standard_top_of_body_html_generation (priority 500) — secondary
 *     fallback in case a stale hook-registry cache means the head callback was
 *     never registered with the dispatcher.
 *
 * When lib.php is loaded, plagiarism_essayguard_before_standard_top_of_body_html()
 * is defined.  plagiarism_update_status() (called later from assign/locallib.php
 * view_grading_table()) checks function_exists() and, finding TRUE, calls the
 * no-op function directly — never reaching the ReflectionMethod / update_status()
 * deprecation path.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_standard_top_of_body_html_generation {
    /**
     * Ensure lib.php is loaded so plagiarism_essayguard_before_standard_top_of_body_html()
     * is defined before plagiarism_update_status() calls function_exists().
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook The hook
     *        instance dispatched by Moodle at the top of the page body.
     * @return void
     */
    public static function callback(
        \core\hook\output\before_standard_top_of_body_html_generation $hook
    ): void {
        global $CFG;
        // FIX-EG-BODY-PRELOAD-LIB (v1.2.184): Secondary belt-and-suspenders load.
        // before_standard_head_html_generation already loads lib.php (v1.2.183).
        // This callback fires immediately after in the same $OUTPUT->header() call,
        // providing a second guarantee that lib.php and its global function are in
        // memory even when the head callback's hook registration is stale.
        require_once($CFG->libdir . '/plagiarismlib.php');
        require_once(__DIR__ . '/../../lib.php');
    }
}
