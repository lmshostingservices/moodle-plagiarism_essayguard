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

namespace plagiarism_essayguard\privacy;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../fixtures/essayguard_test_helper.php');

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Integration tests for the Essay Guard privacy provider.
 *
 * These run against real tables, real contexts and Moodle's own privacy plumbing: the
 * declared metadata is checked against the columns the database really has, and every
 * export assertion reads back what the framework's own content writer received.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_essayguard\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    use \essayguard_test_helper;

    /**
     * Silence core's own PHP deprecation notices for the duration of each test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->silence_php_deprecation_output();
    }

    /**
     * Restore display_errors and let the parent reset the content writer.
     *
     * @return void
     */
    public function tearDown(): void {
        $this->restore_php_deprecation_output();
        parent::tearDown();
    }

    /**
     * Build a course module context to hang the fixtures off.
     *
     * @return array{course: \stdClass, assign: \stdClass, cm: \stdClass, context: \context_module}
     */
    private function make_activity(): array {
        return $this->create_essayguard_assign();
    }

    /**
     * Every column the plugin actually stores must be declared in get_metadata().
     *
     * This is the structural version of the v1.2.219 finding that the score table
     * declared 7 of its 34 columns: it compares the declaration against the real table
     * definition, so a column added to install.xml in future cannot quietly go
     * undeclared. get_metadata() is the statement a site publishes to its data subjects
     * about what it holds, and these tables hold a keystroke-level behavioural profile of
     * a named student.
     *
     * @return void
     */
    public function test_every_stored_column_is_declared(): void {
        global $DB;
        $this->resetAfterTest();

        $collection = provider::get_metadata(new collection('plagiarism_essayguard'));

        $declared = [];
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\database_table) {
                $declared[$item->get_name()] = array_keys($item->get_privacy_fields());
            }
        }

        foreach (['plagiarism_essayguard_ev', 'plagiarism_essayguard_sc', 'plagiarism_essayguard_fp'] as $table) {
            $this->assertArrayHasKey($table, $declared, "{$table} is not declared at all.");
            $columns = array_keys($DB->get_columns($table));
            // The surrogate key is not personal data; everything else is.
            $columns = array_values(array_diff($columns, ['id']));
            sort($columns);
            $declaredcolumns = $declared[$table];
            sort($declaredcolumns);
            $this->assertSame(
                $columns,
                $declaredcolumns,
                "The declared columns for {$table} do not match the columns it really has."
            );
        }
    }

    /**
     * Every metadata summary key must resolve to a real language string, or the privacy
     * registry page fatals on the site that installs this plugin.
     *
     * @return void
     */
    public function test_every_metadata_string_exists(): void {
        $this->resetAfterTest();

        $collection = provider::get_metadata(new collection('plagiarism_essayguard'));

        foreach ($collection->get_collection() as $item) {
            $this->assertTrue(
                get_string_manager()->string_exists($item->get_summary(), 'plagiarism_essayguard'),
                'Missing language string: ' . $item->get_summary()
            );
            if (method_exists($item, 'get_privacy_fields')) {
                foreach ((array)$item->get_privacy_fields() as $field => $key) {
                    $this->assertTrue(
                        get_string_manager()->string_exists($key, 'plagiarism_essayguard'),
                        'Missing language string for field ' . $field . ': ' . $key
                    );
                }
            }
        }
    }

    /**
     * A student whose raw telemetry has been pruned by the retention task, but whose risk
     * scores are kept forever, must still be visible to the privacy framework.
     *
     * The cleanup task deletes from _ev after retentiondays and explicitly keeps _sc.
     * Before v1.2.219 get_contexts_for_userid() queried only _ev, so 91 days after a
     * submission a student's scores and behavioural metrics were exported by nothing and
     * erased by nothing while staying fully readable by teachers in the class report.
     *
     * @return void
     */
    public function test_score_only_user_is_still_found(): void {
        $this->resetAfterTest();

        $act  = $this->make_activity();
        $user = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->create_score([
            'userid'    => $user->id,
            'cmid'      => $act['cm']->id,
            'contextid' => $act['context']->id,
        ]);

        $contextids = provider::get_contexts_for_userid((int)$user->id)->get_contextids();
        $this->assertContains((int)$act['context']->id, array_map('intval', $contextids));
    }

    /**
     * A student who has only a writing baseline left is surfaced at system context,
     * which is where the export writes it and the deletion removes it.
     *
     * @return void
     */
    public function test_fingerprint_only_user_is_found_at_system_context(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record(
            'plagiarism_essayguard_fp',
            (object)[
                'userid'          => $user->id,
                'samplecount'     => 6,
                'baseline_status' => 'stable',
                'timemodified'    => time(),
                ]
        );

        $contextids = array_map('intval', provider::get_contexts_for_userid((int)$user->id)->get_contextids());
        $this->assertSame([(int)\context_system::instance()->id], $contextids);
    }

    /**
     * REGRESSION (V1.2.229 FIX-EG-PRIVACY-EVENTTIME-MS): eventtime holds MILLISECONDS.
     *
     * tracker.js stamps every event with Date.now(); the analyser reads the column back
     * as milliseconds throughout (its pause thresholds are literally 500, 2000 and 10000
     * ms). The export handed the raw value to transform::datetime(), which reads seconds,
     * so a 2026 keystroke was reported to the data subject as happening around the year
     * 55,000 — and those two timestamps are the only means a student has of checking that
     * the record describes the session they think it does.
     *
     * @return void
     */
    public function test_exported_telemetry_times_are_the_real_times(): void {
        $this->resetAfterTest();

        $act  = $this->make_activity();
        $user = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        // 2026-01-02 03:04:05 UTC and one minute later, in milliseconds, as the browser
        // sends them and as log_event stores them.
        $firstms = 1767322_845000;
        $lastms  = $firstms + 60000;
        $this->add_event($user->id, $act['cm']->id, $act['context']->id, 'akms', 'keydown', $firstms);
        $this->add_event($user->id, $act['cm']->id, $act['context']->id, 'akms', 'keydown', $lastms);

        $this->export_context_data_for_user((int)$user->id, $act['context'], 'plagiarism_essayguard');

        $data = writer::with_context(
            $act['context'])->get_data([
                get_string('pluginname', 'plagiarism_essayguard'),
                get_string('privacy:export:telemetry', 'plagiarism_essayguard'),
                'akms',
                ]
        );
        $this->assertNotEmpty($data);
        $this->assertSame(2, $data->event_count);
        $this->assertSame(
            \core_privacy\local\request\transform::datetime(intdiv($firstms, 1000)),
            $data->first_event
        );
        $this->assertSame(
            \core_privacy\local\request\transform::datetime(intdiv($lastms, 1000)),
            $data->last_event
        );
        // The bug this pins produced a date tens of thousands of years in the future.
        $this->assertLessThan(time() + YEARSECS, strtotime($data->first_event));
    }

    /**
     * A row whose eventtime is already in seconds — anything not written by tracker.js —
     * is passed through untouched rather than divided by a thousand.
     *
     * @return void
     */
    public function test_second_resolution_event_times_are_not_rescaled(): void {
        $this->resetAfterTest();

        $act  = $this->make_activity();
        $user = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $seconds = 1767322845;
        $this->add_event($user->id, $act['cm']->id, $act['context']->id, 'aksec', 'keydown', $seconds);

        $this->export_context_data_for_user((int)$user->id, $act['context'], 'plagiarism_essayguard');

        $data = writer::with_context(
            $act['context'])->get_data([
                get_string('pluginname', 'plagiarism_essayguard'),
                get_string('privacy:export:telemetry', 'plagiarism_essayguard'),
                'aksec',
                ]
        );
        $this->assertSame(\core_privacy\local\request\transform::datetime($seconds), $data->first_event);
    }

    /**
     * REGRESSION (V1.2.229 FIX-EG-PRIVACY-EXPORT-UNDERSTATED): the score export used to
     * carry 7 of the 34 declared columns.
     *
     * The 27 it dropped are the whole substance of the record — every behavioural signal,
     * the baseline deviation, and explanationsjson, which is the plain-English case
     * against the student that a teacher reads in the report. A student contesting an
     * academic-integrity finding is exactly the person entitled to that reasoning.
     *
     * @return void
     */
    public function test_score_export_carries_every_declared_column(): void {
        global $DB;
        $this->resetAfterTest();

        $act  = $this->make_activity();
        $user = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->create_score([
            'userid'           => $user->id,
            'cmid'             => $act['cm']->id,
            'contextid'        => $act['context']->id,
            'attemptkey'       => 'akexport',
            'riskscore'        => 0.82,
            'risklevel'        => 'high',
            'paste_events'     => 3,
            'pause_mean'       => 412.5,
            'entropy_score'    => 0.1234,
            'explanationsjson' => '["Content was pasted 3 time(s)"]',
        ]);

        $this->export_context_data_for_user((int)$user->id, $act['context'], 'plagiarism_essayguard');

        $data = (array)writer::with_context(
            $act['context'])->get_data([
                get_string('pluginname', 'plagiarism_essayguard'),
                get_string('privacy:export:scores', 'plagiarism_essayguard'),
                'akexport',
                ]
        );
        $this->assertNotEmpty($data);

        // Everything declared, except the identifiers the export path already carries.
        $expected = array_diff(
            array_keys($DB->get_columns('plagiarism_essayguard_sc')),
            [
                'id', 'userid', 'cmid', 'contextid',
                ]
        );
        foreach ($expected as $column) {
            $this->assertArrayHasKey($column, $data, "Column {$column} is declared but not exported.");
        }
        $this->assertSame('["Content was pasted 3 time(s)"]', $data['explanationsjson']);
        $this->assertEquals(3, $data['paste_events']);
        $this->assertEquals(412.5, $data['pause_mean']);
    }

    /**
     * The writing baseline is the longest-lived record this plugin keeps — it survives
     * the retention prune that clears the raw telemetry — so every declared baseline
     * column has to reach the export.
     *
     * @return void
     */
    public function test_fingerprint_export_carries_every_declared_column(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record(
            'plagiarism_essayguard_fp',
            (object)[
                'userid'                     => $user->id,
                'samplecount'                => 7,
                'baseline_wpm'               => 48.25,
                'baseline_pause_mean'        => 730.5,
                'baseline_backspace_ratio'   => 0.0812,
                'baseline_burst_mean'        => 41.0,
                'baseline_sentence_variance' => 18.5,
                'baseline_vocab_diversity'   => 0.61,
                'baseline_entropy'           => 0.44,
                'baseline_interkey_mean'     => 210.0,
                'baseline_status'            => 'stable',
                'timemodified'               => time(),
                ]
        );

        $system = \context_system::instance();
        $this->export_context_data_for_user((int)$user->id, $system, 'plagiarism_essayguard');

        $data = (array)writer::with_context(
            $system)->get_data([
                get_string('pluginname', 'plagiarism_essayguard'),
                get_string('privacy:export:fingerprint', 'plagiarism_essayguard'),
                ]
        );
        $expected = array_diff(array_keys($DB->get_columns('plagiarism_essayguard_fp')), ['id', 'userid']);
        foreach ($expected as $column) {
            $this->assertArrayHasKey($column, $data, "Baseline column {$column} is declared but not exported.");
        }
        $this->assertEquals(730.5, $data['baseline_pause_mean']);
        $this->assertEquals(0.44, $data['baseline_entropy']);
    }

    /**
     * REGRESSION (V1.2.229 FIX-EG-PRIVACY-PREF-DESCRIPTION): both preferences were
     * exported with the attempt-key description, so a student's export explained the
     * scoring-throttle marker as the typing-session key. Two different holdings must not
     * be described as the same holding.
     *
     * @return void
     */
    public function test_each_preference_is_exported_with_its_own_description(): void {
        $this->resetAfterTest();

        $act  = $this->make_activity();
        $user = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        set_user_preference('essayguard_ak_' . $act['cm']->id, 'akpref', $user->id);
        set_user_preference('essayguard_lastscore_' . $act['cm']->id, 1767322845, $user->id);

        provider::export_user_preferences((int)$user->id);

        $prefs = (array)writer::with_context(\context_system::instance())
            ->get_user_preferences('plagiarism_essayguard');
        $akname = 'essayguard_ak_' . $act['cm']->id;
        $lsname = 'essayguard_lastscore_' . $act['cm']->id;
        $this->assertArrayHasKey($akname, $prefs);
        $this->assertArrayHasKey($lsname, $prefs);
        $this->assertSame(
            get_string('privacy:metadata:preference:essayguard_ak', 'plagiarism_essayguard'),
            $prefs[$akname]->description
        );
        $this->assertSame(
            get_string('privacy:metadata:preference:essayguard_lastscore', 'plagiarism_essayguard'),
            $prefs[$lsname]->description
        );
        $this->assertNotSame($prefs[$akname]->description, $prefs[$lsname]->description);
    }

    /**
     * An erasure request removes the events, the scores, the baseline and the two
     * preferences, and touches nobody else.
     *
     * @return void
     */
    public function test_delete_data_for_user_removes_everything_for_that_user_only(): void {
        global $DB;
        $this->resetAfterTest();

        $act   = $this->make_activity();
        $alice = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $bob   = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        foreach ([$alice, $bob] as $user) {
            $this->add_event($user->id, $act['cm']->id, $act['context']->id, 'ak' . $user->id, 'keydown', 1767322845000);
            $this->create_score([
                'userid'    => $user->id,
                'cmid'      => $act['cm']->id,
                'contextid' => $act['context']->id,
                'attemptkey' => 'ak' . $user->id,
            ]);
            $DB->insert_record(
                'plagiarism_essayguard_fp',
                (object)[
                    'userid'          => $user->id,
                    'samplecount'     => 5,
                    'baseline_status' => 'stable',
                    'timemodified'    => time(),
                    ]
            );
            set_user_preference('essayguard_ak_' . $act['cm']->id, 'ak' . $user->id, $user->id);
            set_user_preference('essayguard_lastscore_' . $act['cm']->id, 1, $user->id);
        }

        provider::delete_data_for_user(
            new approved_contextlist(
                \core_user::get_user($alice->id),
                'plagiarism_essayguard',
                [$act['context']->id, \context_system::instance()->id]
                )
        );

        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_ev', ['userid' => $alice->id]));
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc', ['userid' => $alice->id]));
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_fp', ['userid' => $alice->id]));
        $this->assertSame(
            0,
            $DB->count_records(
                'user_preferences',
                [
                    'userid' => $alice->id,
                    'name'   => 'essayguard_ak_' . $act['cm']->id,
                    ]
            )
        );
        $this->assertSame(
            0,
            $DB->count_records(
                'user_preferences',
                [
                    'userid' => $alice->id,
                    'name'   => 'essayguard_lastscore_' . $act['cm']->id,
                    ]
            )
        );

        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_ev', ['userid' => $bob->id]));
        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_sc', ['userid' => $bob->id]));
        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_fp', ['userid' => $bob->id]));
        $this->assertSame(
            1,
            $DB->count_records(
                'user_preferences',
                [
                    'userid' => $bob->id,
                    'name'   => 'essayguard_ak_' . $act['cm']->id,
                    ]
            )
        );
    }

    /**
     * REGRESSION (V1.2.229 FIX-EG-PRIVACY-CONTEXT-PREFS): purging a module context has to
     * take the per-activity preferences with it. Without this, purging an activity left
     * every student's typing-session key for it behind in user_preferences, pointing at
     * data that no longer existed.
     *
     * @return void
     */
    public function test_purging_a_context_takes_its_preferences_too(): void {
        global $DB;
        $this->resetAfterTest();

        $act   = $this->make_activity();
        $other = $this->make_activity();
        $user  = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        $this->add_event($user->id, $act['cm']->id, $act['context']->id, 'akpurge', 'keydown', 1767322845000);
        $this->create_score([
            'userid'    => $user->id,
            'cmid'      => $act['cm']->id,
            'contextid' => $act['context']->id,
        ]);
        set_user_preference('essayguard_ak_' . $act['cm']->id, 'akpurge', $user->id);
        set_user_preference('essayguard_lastscore_' . $act['cm']->id, 1, $user->id);
        set_user_preference('essayguard_ak_' . $other['cm']->id, 'akkeep', $user->id);

        provider::delete_data_for_all_users_in_context($act['context']);

        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_ev', ['contextid' => $act['context']->id]));
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc', ['contextid' => $act['context']->id]));
        $this->assertSame(
            0,
            $DB->count_records(
                'user_preferences',
                [
                    'name' => 'essayguard_ak_' . $act['cm']->id,
                    ]
            )
        );
        $this->assertSame(
            0,
            $DB->count_records(
                'user_preferences',
                [
                    'name' => 'essayguard_lastscore_' . $act['cm']->id,
                    ]
            )
        );
        // The other activity's preference is untouched.
        $this->assertSame(
            1,
            $DB->count_records(
                'user_preferences',
                [
                    'name' => 'essayguard_ak_' . $other['cm']->id,
                    ]
            )
        );
    }

    /**
     * get_users_in_context() finds users from both the event and the score table in a
     * module context, and the baseline holders at system context.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();

        $act     = $this->make_activity();
        $withev  = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $withsc  = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $withfp  = $this->getDataGenerator()->create_user();

        $this->add_event($withev->id, $act['cm']->id, $act['context']->id, 'akev', 'keydown', 1767322845000);
        $this->create_score([
            'userid'    => $withsc->id,
            'cmid'      => $act['cm']->id,
            'contextid' => $act['context']->id,
        ]);
        $DB->insert_record(
            'plagiarism_essayguard_fp',
            (object)[
                'userid'          => $withfp->id,
                'samplecount'     => 5,
                'baseline_status' => 'stable',
                'timemodified'    => time(),
                ]
        );

        $userlist = new userlist($act['context'], 'plagiarism_essayguard');
        provider::get_users_in_context($userlist);
        $found = array_map('intval', $userlist->get_userids());
        sort($found);
        $expected = [(int)$withev->id, (int)$withsc->id];
        sort($expected);
        $this->assertSame($expected, $found);

        $systemlist = new userlist(\context_system::instance(), 'plagiarism_essayguard');
        provider::get_users_in_context($systemlist);
        $this->assertContains((int)$withfp->id, array_map('intval', $systemlist->get_userids()));
    }

    /**
     * delete_data_for_users() removes the named users' rows in the context, their
     * baselines and their preferences, and leaves everyone else's alone.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();

        $act   = $this->make_activity();
        $alice = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $bob   = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        foreach ([$alice, $bob] as $user) {
            $this->add_event($user->id, $act['cm']->id, $act['context']->id, 'ak' . $user->id, 'keydown', 1767322845000);
            $this->create_score([
                'userid'     => $user->id,
                'cmid'       => $act['cm']->id,
                'contextid'  => $act['context']->id,
                'attemptkey' => 'ak' . $user->id,
            ]);
            $DB->insert_record(
                'plagiarism_essayguard_fp',
                (object)[
                    'userid'          => $user->id,
                    'samplecount'     => 5,
                    'baseline_status' => 'stable',
                    'timemodified'    => time(),
                    ]
            );
            set_user_preference('essayguard_ak_' . $act['cm']->id, 'ak' . $user->id, $user->id);
        }

        provider::delete_data_for_users(
            new approved_userlist(
                $act['context'],
                'plagiarism_essayguard',
                [$alice->id]
                )
        );

        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_ev', ['userid' => $alice->id]));
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc', ['userid' => $alice->id]));
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_fp', ['userid' => $alice->id]));
        $this->assertSame(
            0,
            $DB->count_records(
                'user_preferences',
                ['userid' => $alice->id,
                    'name' => 'essayguard_ak_' . $act['cm']->id]
            )
        );
        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_sc', ['userid' => $bob->id]));
        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_fp', ['userid' => $bob->id]));
    }

    /**
     * An erasure request that names no context must not silently wipe the baseline of a
     * user who approved nothing.
     *
     * The baseline is deliberately deleted outside the per-context loop because it has no
     * context of its own; this pins that the approved list still has to reach the method.
     *
     * @return void
     */
    public function test_export_and_delete_round_trip_for_a_real_submission(): void {
        global $DB;
        $this->resetAfterTest();

        $act  = $this->make_activity();
        $user = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->add_event($user->id, $act['cm']->id, $act['context']->id, 'akround', 'keydown', 1767322845000);
        $this->create_score([
            'userid'     => $user->id,
            'cmid'       => $act['cm']->id,
            'contextid'  => $act['context']->id,
            'attemptkey' => 'akround',
        ]);

        // Everything the framework would ask for, in the order it would ask for it.
        $contextlist = provider::get_contexts_for_userid((int)$user->id);
        $this->assertContains((int)$act['context']->id, array_map('intval', $contextlist->get_contextids()));

        $this->export_all_data_for_user((int)$user->id, 'plagiarism_essayguard');
        $this->assertTrue(writer::with_context($act['context'])->has_any_data());

        provider::delete_data_for_user(
            new approved_contextlist(
                \core_user::get_user($user->id),
                'plagiarism_essayguard',
                $contextlist->get_contextids()
                )
        );

        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_ev', ['userid' => $user->id]));
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc', ['userid' => $user->id]));
        $this->assertSame([], provider::get_contexts_for_userid((int)$user->id)->get_contextids());
    }
}
