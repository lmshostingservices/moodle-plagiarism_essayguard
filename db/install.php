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
