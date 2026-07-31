<?php

/**
 * Post-installation hook for plagiarism_essayguard.
 *
 * Automatically enables Moodle's global plagiarism support so admins do not
 * need to manually visit Site Administration → Advanced features and tick
 * "Enable plagiarism plugins" before the plugin becomes accessible.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_plagiarism_essayguard_install() {
    // Enable the global plagiarism subsystem so the plugin is immediately
    // accessible under Site Administration → Plugins → Plagiarism prevention.
    set_config('enableplagiarism', 1);
    return true;
}
