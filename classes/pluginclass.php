<?php
defined('MOODLE_INTERNAL') || die();

/**
 * EssayGuard plagiarism plugin class — Path B (extends plagiarism_plugin).
 *
 * Loaded by the spl_autoload_register in lib.php on first use, by which point
 * plagiarism_plugin is always defined. update_status() is INHERITED from the
 * parent class — getDeclaringClass()->getName() === 'plagiarism_plugin' — so
 * Moodle's plagiarism_update_status() ReflectionMethod check never fires the
 * deprecation notice, regardless of debug level.
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
