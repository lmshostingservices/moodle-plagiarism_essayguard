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
 * Backup of the per-activity Essay Guard setting.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_plagiarism_essayguard_plugin extends backup_plagiarism_plugin {
    /**
     * Store whether Essay Guard is enabled on the activity being backed up.
     *
     * Only the teacher's setting is backed up. Telemetry and scores are personal data
     * tied to the original attempts and are never included.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $config = new backup_nested_element('essayguard_config', null, ['enabled']);
        $wrapper->add_child($config);

        $cmid = (int)$this->task->get_moduleid();
        $enabled = !empty(get_config('plagiarism_essayguard', 'enabled_cm_' . $cmid)) ? 1 : 0;
        $config->set_source_array([['enabled' => $enabled]]);

        return $plugin;
    }
}
