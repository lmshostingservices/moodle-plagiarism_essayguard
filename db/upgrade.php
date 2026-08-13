<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_plagiarism_essayguard_upgrade($oldversion) {
    if ($oldversion < 2026072500) {
        upgrade_plugin_savepoint(true, 2026072500, 'plagiarism', 'essayguard');
    }
    return true;
}
