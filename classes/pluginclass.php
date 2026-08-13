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

defined('MOODLE_INTERNAL') || die();

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
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class plagiarism_plugin_essayguard extends plagiarism_plugin {
    public function get_links($linkarray) {
        return plagiarism_essayguard_get_links($linkarray);
    }

    public function print_disclosure($cmid) {
        return '';
    }

    public function save_form_elements($data) {
        if (!empty($data->coursemodule)) {
            set_config(
                'enabled_cm_' . $data->coursemodule,
                !empty($data->essayguard_enabled) ? 1 : 0,
                'plagiarism_essayguard'
            );
        }
    }

    public function get_form_elements_module($mform, $context, $modulename = '') {
        return false;
    }

}
