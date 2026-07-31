<?php

/**
 * Hook callbacks for plagiarism_essayguard.
 *
 * Registers the tracker injection callback with Moodle's hook system
 * (Moodle 4.3+), replacing the legacy before_standard_html_head approach.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook'     => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => \plagiarism_essayguard\hook\before_standard_head_html_generation::class . '::callback',
        'priority' => 500,
    ],
    [
        'hook'     => \core\hook\output\before_footer_html_generation::class,
        'callback' => \plagiarism_essayguard\hook\before_footer::class . '::callback',
        'priority' => 500,
    ],
    // FIX-EG-REMOVE-LEGACY-FUNCTION (v1.2.172): Hook RESTORED.
    // The global plagiarism_essayguard_before_standard_top_of_body_html() function has
    // been removed from lib.php. Moodle's get_plugins_with_function() emits the
    // "Callback X should be migrated" DEBUG_DEVELOPER notice for ANY plugin that defines
    // the legacy function, unconditionally — registering or not registering a hook has
    // no effect on whether that notice fires. With the function gone, the notice is gone.
    // The guarded require_once(plagiarismlib.php) in lib.php ensures Path B (extends
    // plagiarism_plugin) so plagiarism_update_status() sees getDeclaringClass()=
    // 'plagiarism_plugin' and the update_status() deprecation is also suppressed.
    // This hook is re-registered here so the before_standard_top_of_body_html_generation
    // event is handled cleanly via the new hook system (no-op callback).
    [
        'hook'     => \core\hook\output\before_standard_top_of_body_html_generation::class,
        'callback' => \plagiarism_essayguard\hook\before_standard_top_of_body_html_generation::class . '::callback',
        'priority' => 500,
    ],
];
