<?php
defined('MOODLE_INTERNAL') || die();

/**
 * EssayGuard plagiarism plugin class — Path A standalone fallback.
 *
 * Loaded by the spl_autoload_register in lib.php only when plagiarism_plugin
 * base class is unavailable (edge case: very early CLI scripts or unit tests
 * that instantiate the class before Moodle's plagiarism stack is bootstrapped).
 *
 * update_status() stub prevents ReflectionException crash on Moodle versions
 * that call new ReflectionMethod() without a try/catch guard. The stub will
 * cause getDeclaringClass() = 'plagiarism_plugin_essayguard', but this path
 * should never be reached on a normal web request.
 */
class plagiarism_plugin_essayguard {

    public function update_status($course, $cm) {
        return true;
    }

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
