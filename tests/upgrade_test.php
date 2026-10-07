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

namespace plagiarism_essayguard;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->dirroot . '/plagiarism/essayguard/db/upgrade.php');

/**
 * Upgrade script structure and the 1.4.1 schema repair.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_plagiarism_essayguard_upgrade
 * @covers     ::plagiarism_essayguard_upgrade_repair_schema
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * Each version has exactly one upgrade block and exactly one savepoint, in
     * ascending order, the last being the version in version.php.
     *
     * @return void
     */
    public function test_one_block_and_one_savepoint_per_version(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/plagiarism/essayguard/db/upgrade.php');
        preg_match_all('/if \(\$oldversion < (\d{10})\)/', $source, $blocks);
        preg_match_all("/upgrade_plugin_savepoint\(true, (\d{10}), 'plagiarism', 'essayguard'\)/", $source, $savepoints);

        $this->assertSame(array_unique($blocks[1]), $blocks[1], 'A version has more than one upgrade block.');
        $this->assertSame($blocks[1], $savepoints[1], 'Each block must end in exactly one savepoint for its own version.');
        $sorted = $blocks[1];
        sort($sorted);
        $this->assertSame($sorted, $blocks[1]);

        $plugin = new \stdClass();
        require($CFG->dirroot . '/plagiarism/essayguard/version.php');
        $this->assertSame((string)$plugin->version, end($blocks[1]));
    }

    /**
     * The repair step puts back fields, indexes and decimal scale lost by a failed
     * 1.4.0 upgrade, and leaves the schema identical to install.xml.
     *
     * @return void
     */
    public function test_repair_restores_missing_structure(): void {
        global $DB;
        $this->resetAfterTest();
        $dbman = $DB->get_manager();

        $sc = new \xmldb_table('plagiarism_essayguard_sc');
        $dbman->drop_index($sc, new \xmldb_index('attemptslot_uix', XMLDB_INDEX_UNIQUE, ['userid', 'cmid', 'attemptkey', 'qslot']));
        $dbman->drop_index($sc, new \xmldb_index('cmid_qslot_ix', XMLDB_INDEX_NOTUNIQUE, ['cmid', 'qslot']));
        $dbman->drop_field($sc, new \xmldb_field('qslot'));
        $dbman->drop_table(new \xmldb_table('plagiarism_essayguard_fpm'));
        $wpm = new \xmldb_field('average_wpm', XMLDB_TYPE_NUMBER, '8', null, XMLDB_NOTNULL, null, '0');
        $wider = new \xmldb_field('average_wpm', XMLDB_TYPE_NUMBER, '10', null, XMLDB_NOTNULL, null, '0');
        $dbman->change_field_precision($sc, $wider);
        $dbman->change_field_precision($sc, $wpm);
        $this->assertSame(0, (int)$DB->get_columns('plagiarism_essayguard_sc', false)['average_wpm']->scale);

        plagiarism_essayguard_upgrade_repair_schema($dbman);

        $this->assertTrue($dbman->field_exists($sc, 'qslot'));
        $this->assertTrue($dbman->index_exists(
            $sc,
            new \xmldb_index('attemptslot_uix', XMLDB_INDEX_UNIQUE, ['userid', 'cmid', 'attemptkey', 'qslot'])
        ));
        $this->assertTrue($dbman->table_exists('plagiarism_essayguard_fpm'));
        $this->assertSame(2, (int)$DB->get_columns('plagiarism_essayguard_sc', false)['average_wpm']->scale);

        $errors = $dbman->check_database_schema(
            (function () {
                global $CFG;
                $file = new \xmldb_file($CFG->dirroot . '/plagiarism/essayguard/db/install.xml');
                $file->loadXMLStructure();
                return $file->getStructure();
            })()
        );
        $pluginerrors = array_filter($errors, fn($table) => strpos($table, 'plagiarism_essayguard_') === 0, ARRAY_FILTER_USE_KEY);
        $this->assertSame([], $pluginerrors);
    }

    /**
     * Upgrading a site that already used Essay Guard keeps monitoring what it was
     * monitoring; activities with no data stay off.
     *
     * @return void
     */
    public function test_upgrade_preserves_existing_monitoring(): void {
        global $DB;
        $this->resetAfterTest();
        unset_config('enabled', 'plagiarism_essayguard');
        set_config('version', 2026100700, 'plagiarism_essayguard');
        $DB->insert_record('plagiarism_essayguard_ev', (object)[
            'userid' => 2, 'cmid' => 501, 'contextid' => 1, 'attemptkey' => 'k',
            'eventname' => 'keydown', 'eventtime' => 1, 'timecreated' => 1,
        ]);

        xmldb_plagiarism_essayguard_upgrade(2026100700);

        $this->assertSame('1', get_config('plagiarism_essayguard', 'enabled'));
        $this->assertSame('1', get_config('plagiarism_essayguard', 'enabled_cm_501'));
        $this->assertFalse(get_config('plagiarism_essayguard', 'enabled_cm_502'));
    }
}
