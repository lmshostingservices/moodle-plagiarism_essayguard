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
 * Upgrade steps for plagiarism_essayguard.
 *
 * Exactly one block per version, each ending in a single savepoint. Versions that
 * changed no data or schema have no block: core records the new version from
 * version.php once this function returns. Release notes live in CHANGELOG.md.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Apply the database and configuration upgrade steps for plagiarism_essayguard.
 *
 * @param int $oldversion The version currently installed.
 * @return bool Always true; a failed step throws instead.
 */
function xmldb_plagiarism_essayguard_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026030600) {
        // Default retention period for raw telemetry.
        if (!get_config('plagiarism_essayguard', 'retentiondays')) {
            set_config('retentiondays', 90, 'plagiarism_essayguard');
        }
        upgrade_plugin_savepoint(true, 2026030600, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026030700) {
        // Behavioural metric columns on the score table.
        $table = new xmldb_table('plagiarism_essayguard_sc');
        $newfields = [
            new xmldb_field('typing_time', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('idle_time', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('total_keystrokes', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('paste_events', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('backspace_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('delete_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('cursor_moves', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('average_wpm', XMLDB_TYPE_NUMBER, '8, 2', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('wpm_std_dev', XMLDB_TYPE_NUMBER, '8, 2', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('interkey_mean', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('interkey_std_dev', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('pause_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('pause_mean', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('pause_std_dev', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('burst_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('burst_mean', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('burst_std_dev', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('sentence_variance', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('vocab_diversity', XMLDB_TYPE_NUMBER, '8, 4', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('rare_word_ratio', XMLDB_TYPE_NUMBER, '8, 4', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('thinking_pause_score', XMLDB_TYPE_NUMBER, '8, 4', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('entropy_score', XMLDB_TYPE_NUMBER, '8, 4', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('explanationsjson', XMLDB_TYPE_TEXT, null, null, null, null, null),
            new xmldb_field('baseline_deviation', XMLDB_TYPE_NUMBER, '8, 4', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('baseline_status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'none'),
        ];
        foreach ($newfields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Student writing baseline table.
        $fptable = new xmldb_table('plagiarism_essayguard_fp');
        $fptable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $fptable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $fptable->add_field('samplecount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $fptable->add_field('baseline_wpm', XMLDB_TYPE_NUMBER, '8, 2', null, XMLDB_NOTNULL, null, '0');
        $fptable->add_field('baseline_pause_mean', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
        $fptable->add_field('baseline_backspace_ratio', XMLDB_TYPE_NUMBER, '8, 4', null, XMLDB_NOTNULL, null, '0');
        $fptable->add_field('baseline_burst_mean', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
        $fptable->add_field('baseline_sentence_variance', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '0');
        $fptable->add_field('baseline_vocab_diversity', XMLDB_TYPE_NUMBER, '8, 4', null, XMLDB_NOTNULL, null, '0');
        $fptable->add_field('baseline_entropy', XMLDB_TYPE_NUMBER, '8, 4', null, XMLDB_NOTNULL, null, '0');
        $fptable->add_field('baseline_interkey_mean', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
        $fptable->add_field('baseline_status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'none');
        $fptable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $fptable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $fptable->add_index('userid_ix', XMLDB_INDEX_UNIQUE, ['userid']);
        if (!$dbman->table_exists($fptable)) {
            $dbman->create_table($fptable);
        }

        upgrade_plugin_savepoint(true, 2026030700, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026031900) {
        // The 'mild' risk band was retired; reclassify stored rows by their score.
        $DB->execute(
            "UPDATE {plagiarism_essayguard_sc}
                SET risklevel = CASE WHEN riskscore < 0.35 THEN 'low' ELSE 'medium' END
              WHERE risklevel = 'mild'"
        );
        upgrade_plugin_savepoint(true, 2026031900, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026032001) {
        // Per-question scoring: qslot column, and the unique index widened to include it.
        $table = new xmldb_table('plagiarism_essayguard_sc');

        $field = new xmldb_field('qslot', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'attemptkey');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $oldindex = new xmldb_index('attemptuniq_ix', XMLDB_INDEX_UNIQUE, ['userid', 'cmid', 'attemptkey']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }

        $newindex = new xmldb_index('attemptslot_uix', XMLDB_INDEX_UNIQUE, ['userid', 'cmid', 'attemptkey', 'qslot']);
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        upgrade_plugin_savepoint(true, 2026032001, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026032601) {
        // Sessions that captured telemetry before the qslot column existed were never
        // scored. Queue them for the ad-hoc scoring task rather than scoring inline: the
        // scorer depends on schema added by later steps of this upgrade.
        $rs = $DB->get_recordset_sql(
            "SELECT DISTINCT ev.userid, ev.cmid, ev.contextid, ev.attemptkey
               FROM {plagiarism_essayguard_ev} ev
              WHERE NOT EXISTS (
                        SELECT 1
                          FROM {plagiarism_essayguard_sc} sc
                         WHERE sc.userid = ev.userid
                           AND sc.cmid = ev.cmid
                           AND sc.attemptkey = ev.attemptkey
                           AND sc.qslot = 0
                    )"
        );
        foreach ($rs as $orphan) {
            \plagiarism_essayguard\task\score_attempt::queue(
                (int)$orphan->userid,
                (int)$orphan->cmid,
                (string)$orphan->attemptkey,
                0
            );
        }
        $rs->close();
        upgrade_plugin_savepoint(true, 2026032601, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026041600) {
        // Index for report queries filtered by activity and question slot.
        $table = new xmldb_table('plagiarism_essayguard_sc');
        $index = new xmldb_index('cmid_qslot_ix', XMLDB_INDEX_NOTUNIQUE, ['cmid', 'qslot']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026041600, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026100600) {
        // Telemetry table indexes for cleanup, scoring lookups and privacy erasure.
        $table = new xmldb_table('plagiarism_essayguard_ev');

        $index = new xmldb_index('timecreated_ix', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('scoringlookup_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'cmid', 'attemptkey', 'id']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('context_ix', XMLDB_INDEX_NOTUNIQUE, ['contextid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026100600, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026100700) {
        // Per-metric running statistics (Welford mean and M2) for the writing baseline.
        $table = new xmldb_table('plagiarism_essayguard_fpm');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('contexttype', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'other');
        $table->add_field('metricname', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('samplen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('runmean', XMLDB_TYPE_NUMBER, '20, 6', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('runm2', XMLDB_TYPE_NUMBER, '20, 6', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('usermetric_uix', XMLDB_INDEX_UNIQUE, ['userid', 'contexttype', 'metricname']);
        $table->add_index('user_ix', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026100700, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026100800) {
        // 1.4.1 — two jobs.
        //
        // First: repair schema left incomplete by the duplicate-savepoint defect in
        // 1.4.0 and earlier: a site that hit that defect recorded a version as done
        // while later steps for the same version never ran. Every structure the plugin
        // needs is re-checked against db/install.xml and created when missing.
        plagiarism_essayguard_upgrade_repair_schema($dbman);

        // Second: monitoring is now opt-in: a missing site switch or a missing per-activity
        // setting means "off". Make that explicit for existing sites without changing
        // what they currently monitor: if the site switch was never saved but Essay
        // Guard has been collecting data, record it as on; and record every activity
        // that already holds Essay Guard data as on. Everything else stays off until a
        // teacher turns it on.
        $hasdata = $DB->record_exists('plagiarism_essayguard_ev', [])
            || $DB->record_exists('plagiarism_essayguard_sc', []);
        if (get_config('plagiarism_essayguard', 'enabled') === false) {
            set_config('enabled', $hasdata ? 1 : 0, 'plagiarism_essayguard');
        }
        $cmids = $DB->get_fieldset_sql(
            "SELECT DISTINCT cmid FROM {plagiarism_essayguard_ev}
              UNION
             SELECT DISTINCT cmid FROM {plagiarism_essayguard_sc}"
        );
        foreach ($cmids as $cmid) {
            if (get_config('plagiarism_essayguard', 'enabled_cm_' . $cmid) === false) {
                set_config('enabled_cm_' . $cmid, 1, 'plagiarism_essayguard');
            }
        }

        upgrade_plugin_savepoint(true, 2026100800, 'plagiarism', 'essayguard');
    }

    return true;
}

/**
 * Create any table, field or index declared in db/install.xml that is missing.
 *
 * Never drops anything. All install.xml fields are nullable or have a default, so
 * adding them to populated tables is safe; the only alteration is restoring the
 * declared precision of decimal columns.
 *
 * @param database_manager $dbman
 * @return void
 */
function plagiarism_essayguard_upgrade_repair_schema(database_manager $dbman): void {
    global $CFG, $DB;

    $xmldbfile = new xmldb_file($CFG->dirroot . '/plagiarism/essayguard/db/install.xml');
    if (!$xmldbfile->fileExists() || !$xmldbfile->loadXMLStructure()) {
        throw new moodle_exception('cannotloadxmlfile', 'error');
    }
    $structure = $xmldbfile->getStructure();

    foreach ($structure->getTables() as $table) {
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
            continue;
        }
        $columns = $DB->get_columns($table->getName(), false);
        foreach ($table->getFields() as $field) {
            if (!$dbman->field_exists($table, $field)) {
                // Clear the "previous" hint: the column it names may itself be missing.
                $field->setPrevious(null);
                $dbman->add_field($table, $field);
                continue;
            }
            // Releases up to 1.4.0 created decimal columns with no scale (an extra
            // constructor argument was silently ignored), so e.g. average_wpm was stored
            // as a whole number. Restore the declared precision.
            if ($field->getType() == XMLDB_TYPE_NUMBER && isset($columns[$field->getName()])) {
                $column = $columns[$field->getName()];
                if (
                    (int)$column->scale !== (int)$field->getDecimals()
                        || (int)$column->max_length !== (int)$field->getLength()
                ) {
                    // The DDL generators ignore a scale change away from 0 unless the
                    // length changes too, so widen first and then set the declared size.
                    $wider = clone($field);
                    $wider->setLength((int)$field->getLength() + 2);
                    $dbman->change_field_precision($table, $wider);
                    $dbman->change_field_precision($table, $field);
                }
            }
        }
        foreach ($table->getIndexes() as $index) {
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
    }
}
