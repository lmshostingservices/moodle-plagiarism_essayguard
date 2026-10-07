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
 * Restore of the per-activity Essay Guard setting.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_plagiarism_essayguard_plugin extends restore_plagiarism_plugin {
    /**
     * Paths handled when restoring an activity.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return [
            new restore_path_element('essayguard_config', $this->get_pathfor('/essayguard_config')),
        ];
    }

    /**
     * Apply the backed-up setting to the newly created course module.
     *
     * A backup without this element (made before 1.4.1, or with Essay Guard off) leaves
     * the new activity unmonitored, because a missing setting means "off".
     *
     * @param array|stdClass $data The essayguard_config element.
     * @return void
     */
    public function process_essayguard_config($data) {
        $data = (object)$data;
        $newcmid = (int)$this->task->get_moduleid();
        if ($newcmid > 0) {
            set_config('enabled_cm_' . $newcmid, empty($data->enabled) ? 0 : 1, 'plagiarism_essayguard');
        }
    }
}
