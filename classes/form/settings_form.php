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

namespace plagiarism_essayguard\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Essay Guard site settings form.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_form extends \moodleform {
    /**
     * Define the form fields.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $c = 'plagiarism_essayguard';

        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', $c), get_string('enabled_desc', $c));
        $mform->setDefault('enabled', 0);

        $mform->addElement('text', 'siteid', get_string('siteid', $c), ['size' => 40]);
        $mform->setType('siteid', PARAM_ALPHANUMEXT);
        $mform->addElement('static', 'siteid_desc', '', get_string('siteid_desc', $c));

        $mform->addElement('passwordunmask', 'apikey', get_string('apikey', $c), ['size' => 40]);
        $mform->setType('apikey', PARAM_TEXT);
        $mform->addElement('static', 'apikey_desc', '', get_string('apikey_desc', $c));

        $mform->addElement('text', 'captureinterval', get_string('captureinterval', $c));
        $mform->setType('captureinterval', PARAM_INT);
        $mform->setDefault('captureinterval', 5000);
        $mform->addElement('static', 'captureinterval_desc', '', get_string('captureinterval_desc', $c));

        $mform->addElement('text', 'minchars', get_string('minchars', $c));
        $mform->setType('minchars', PARAM_INT);
        $mform->setDefault('minchars', 120);
        $mform->addElement('static', 'minchars_desc', '', get_string('minchars_desc', $c));

        $mform->addElement('text', 'maxburstchars', get_string('maxburstchars', $c));
        $mform->setType('maxburstchars', PARAM_INT);
        $mform->setDefault('maxburstchars', 150);
        $mform->addElement('static', 'maxburstchars_desc', '', get_string('maxburstchars_desc', $c));

        $mform->addElement('advcheckbox', 'allowpaste', get_string('allowpaste', $c), get_string('allowpaste_desc', $c));

        $mform->addElement('text', 'paste_weight', get_string('paste_weight', $c));
        $mform->setType('paste_weight', PARAM_INT);
        $mform->setDefault('paste_weight', 100);
        $mform->addElement('static', 'paste_weight_desc', '', get_string('paste_weight_desc', $c));

        $mform->addElement('text', 'retentiondays', get_string('retentiondays', $c));
        $mform->setType('retentiondays', PARAM_INT);
        $mform->setDefault('retentiondays', 90);
        $mform->addElement('static', 'retentiondays_desc', '', get_string('retentiondays_desc', $c));

        $this->add_action_buttons(false);
    }

    /**
     * Range checks.
     *
     * @param array $data Submitted data.
     * @param array $files Uploaded files.
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $ranges = [
            'captureinterval' => [1000, 60000],
            'minchars' => [0, 100000],
            'maxburstchars' => [1, 100000],
            'paste_weight' => [0, 100],
            'retentiondays' => [0, 3650],
        ];
        foreach ($ranges as $field => [$min, $max]) {
            $value = (int)($data[$field] ?? 0);
            if ($value < $min || $value > $max) {
                $range = (object)['min' => $min, 'max' => $max];
                $errors[$field] = get_string('settings_outofrange', 'plagiarism_essayguard', $range);
            }
        }
        return $errors;
    }
}
