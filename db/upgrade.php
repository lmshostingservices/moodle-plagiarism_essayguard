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
 * Database upgrade steps for Essay Guard.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plagiarism_essayguard plugin.
 *
 * @param int $oldversion The currently installed version number.
 * @return bool True on success.
 */
function xmldb_plagiarism_essayguard_upgrade($oldversion) {
    if ($oldversion < 2026072500) {
        upgrade_plugin_savepoint(true, 2026072500, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026072501) {
        // FIX-EG-AMD-ANON-DEFINE (v1.2.218): no schema change; savepoint only.
        upgrade_plugin_savepoint(true, 2026072501, 'plagiarism', 'essayguard');
    }

    return true;
}
