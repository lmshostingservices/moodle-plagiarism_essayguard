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
require_once(__DIR__ . '/fixtures/essayguard_test_helper.php');

use core_external\external_api;
use plagiarism_essayguard\external\finalize_attempt;
use plagiarism_essayguard\external\get_badges;
use plagiarism_essayguard\external\log_event;

/**
 * Integration tests for the three Essay Guard web services.
 *
 * Every call goes through validate_parameters() on the way in and
 * external_api::clean_returnvalue() on the way out, so a response that has drifted from
 * the declared execute_returns() structure fails here rather than in a browser.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_essayguard\external\log_event
 * @covers     \plagiarism_essayguard\external\finalize_attempt
 * @covers     \plagiarism_essayguard\external\get_badges
 */
final class external_test extends \advanced_testcase {
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
     * Put display_errors back the way it was.
     *
     * @return void
     */
    protected function tearDown(): void {
        $this->restore_php_deprecation_output();
        parent::tearDown();
    }

    /**
     * One telemetry event in the shape tracker.js sends.
     *
     * @param string $eventname The tracker event name.
     * @param array  $payload   The event payload.
     * @return array
     */
    private function wire_event(string $eventname = 'keydown', array $payload = ['ikd' => 200]): array {
        return [
            'eventname'   => $eventname,
            'eventtime'   => 1767322845000,
            'payloadjson' => json_encode($payload),
        ];
    }

    /**
     * Call log_event and validate the response against its own declared structure.
     *
     * @param int    $cmid       The course module.
     * @param string $attemptkey The typing session key.
     * @param array  $events     The wire events.
     * @return array The cleaned return value.
     */
    private function call_log_event(int $cmid, string $attemptkey, array $events): array {
        $result = log_event::execute($cmid, $attemptkey, $events);
        // The scoring pass logs its own diagnostics at developer level; those belong to
        // analyser's tests, not to the web-service contract these tests are about.
        $this->resetDebugging();
        return (array)external_api::clean_returnvalue(log_event::execute_returns(), $result);
    }

    /**
     * A student's flush is stored and acknowledged, and the response matches the
     * structure the plugin publishes for it.
     *
     * @return void
     */
    public function test_log_event_stores_the_batch(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->setUser($student);

        $result = $this->call_log_event(
            (int)$act['cm']->id,
            'akwire',
            [
                $this->wire_event(),
                $this->wire_event('paste', ['insertlen' => 400]),
                ]
        );

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['ignored']);
        $this->assertSame(
            2,
            $DB->count_records(
                'plagiarism_essayguard_ev',
                [
                    'userid'     => $student->id,
                    'cmid'       => $act['cm']->id,
                    'attemptkey' => 'akwire',
                    ]
            )
        );
    }

    /**
     * A student must not be handed their own integrity score: with a live number in the
     * response, a student can type, watch it move, undo and retype until the badge reads
     * LOW, which turns a detector into a tuning instrument.
     *
     * @return void
     */
    public function test_log_event_hides_the_score_from_the_student(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->setUser($student);

        $result = $this->call_log_event((int)$act['cm']->id, 'akhide', [$this->wire_event()]);

        $this->assertSame(0.0, $result['riskscore']);
        $this->assertSame('', $result['risklevel']);
    }

    /**
     * A teacher watching a live exam does get the number, because they hold
     * plagiarism/essayguard:viewreport in the activity.
     *
     * @return void
     */
    public function test_log_event_gives_the_score_to_a_teacher(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $teacher = $this->getDataGenerator()->create_and_enrol($act['course'], 'editingteacher');
        $this->setUser($teacher);
        $this->assertTrue(has_capability('plagiarism/essayguard:viewreport', $act['context']));

        // A stream with a big paste in it, so the score is not zero by coincidence.
        $result = $this->call_log_event(
            (int)$act['cm']->id,
            'akteacher',
            [
                $this->wire_event('paste', ['insertlen' => 900]),
                $this->wire_event(),
                ]
        );

        $this->assertFalse($result['ignored']);
        $this->assertGreaterThan(0.0, $result['riskscore']);
        $this->assertContains($result['risklevel'], ['low', 'medium', 'high']);
    }

    /**
     * The scoring throttle: a second flush inside 60 seconds stores its events but does
     * not re-score, and says so by returning an EMPTY risklevel rather than 'low'.
     * Returning 'low' there would let a teacher's live view render a false all-clear for
     * a call that never scored anything.
     *
     * @return void
     */
    public function test_log_event_throttles_scoring_without_claiming_a_low_risk(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $teacher = $this->getDataGenerator()->create_and_enrol($act['course'], 'editingteacher');
        $this->setUser($teacher);

        $first = $this->call_log_event((int)$act['cm']->id, 'akthrottle', [$this->wire_event()]);
        $this->assertFalse($first['ignored']);

        $second = $this->call_log_event((int)$act['cm']->id, 'akthrottle', [$this->wire_event()]);
        $this->assertFalse($second['ignored']);
        $this->assertSame('', $second['risklevel']);

        // Both batches were stored even though only the first was scored.
        $this->assertSame(2, $DB->count_records('plagiarism_essayguard_ev', ['attemptkey' => 'akthrottle']));
    }

    /**
     * A quiz attempt key must resolve to an attempt owned by the caller. Without this a
     * student could POST a fabricated clean typing stream under another student's attempt
     * key and overwrite their risk evidence.
     *
     * @return void
     */
    public function test_log_event_rejects_another_students_attempt_key(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_quiz();
        $victim  = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $cheat   = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        $this->setUser($victim);
        $attempt = $this->make_quiz_attempt($act['quiz'], $victim, 'The victim wrote this answer themselves.');

        $this->setUser($cheat);
        $this->expectException(\invalid_parameter_exception::class);
        log_event::execute((int)$act['cm']->id, 'qa_' . $attempt->get_attemptid(), [$this->wire_event()]);
    }

    /**
     * An attempt key longer than the char(64) column is refused rather than allowed to
     * become a dml_write_exception or, on a non-strict MySQL, a silently truncated key
     * that detaches the telemetry from the attempt.
     *
     * @return void
     */
    public function test_log_event_rejects_an_oversized_attempt_key(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->setUser($student);

        $this->expectException(\invalid_parameter_exception::class);
        log_event::execute((int)$act['cm']->id, str_repeat('a', 65), [$this->wire_event()]);
    }

    /**
     * REGRESSION (V1.2.229 FIX-EG-LOGEVENT-EVENTNAME-LEN): eventname is PARAM_ALPHAEXT,
     * which bounds the character set but not the length, and it lands in a char(32)
     * column. The same hole v1.2.219 closed for attemptkey, one field along: an
     * over-long name was an uncaught dml_write_exception — a 500 to the student's
     * browser mid-attempt with the whole batch lost — or a silent MySQL truncation.
     *
     * @return void
     */
    public function test_log_event_rejects_an_oversized_event_name(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->setUser($student);

        // The column really is 32 characters, which is what the guard is sized against.
        $columns = $DB->get_columns('plagiarism_essayguard_ev');
        $this->assertSame(32, (int)$columns['eventname']->max_length);

        try {
            log_event::execute((int)$act['cm']->id, 'akname', [$this->wire_event(str_repeat('k', 33))]);
            $this->fail('An event name longer than the column must be refused.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('eventname', $e->getMessage());
        }

        // Nothing from the rejected batch was stored.
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_ev'));

        // A name that fits is accepted.
        $this->call_log_event((int)$act['cm']->id, 'akname', [$this->wire_event(str_repeat('k', 32))]);
        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_ev'));
    }

    /**
     * The batch size cap: without it a single POST could insert 100,000 rows into a
     * client's database.
     *
     * @return void
     */
    public function test_log_event_rejects_an_oversized_batch(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->setUser($student);

        $events = array_fill(0, log_event::MAX_EVENTS + 1, $this->wire_event());
        $this->expectException(\invalid_parameter_exception::class);
        log_event::execute((int)$act['cm']->id, 'akbatch', $events);
    }

    /**
     * The per-event payload cap, for the same reason.
     *
     * @return void
     */
    public function test_log_event_rejects_an_oversized_payload(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->setUser($student);

        $event = $this->wire_event();
        $event['payloadjson'] = str_repeat('x', log_event::MAX_PAYLOAD_BYTES + 1);

        $this->expectException(\invalid_parameter_exception::class);
        log_event::execute((int)$act['cm']->id, 'akpayload', [$event]);
    }

    /**
     * REGRESSION (V1.2.229 FIX-EG-WS-CM-GATE): a teacher who unticks "Enable Essay Guard"
     * on their activity has said the plugin must not watch it. inject_tracker() only
     * decides at PAGE LOAD, so every student who already had the page open kept a live
     * tracker flushing every five seconds — and every flush used to be accepted and
     * scored. The class report then showed a risk badge for an activity the teacher had
     * switched off.
     *
     * @return void
     */
    public function test_log_event_honours_the_per_activity_switch(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();
        $this->set_platform_settings(false, false);

        $act     = $this->create_essayguard_assign(false);
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->setUser($student);

        $result = $this->call_log_event((int)$act['cm']->id, 'akoffcm', [$this->wire_event()]);

        $this->assertTrue($result['ignored']);
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_ev'));
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc'));
    }

    /**
     * The site-wide switch, explicitly off, is honoured the same way.
     *
     * @return void
     */
    public function test_log_event_honours_the_site_wide_switch(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();
        set_config('enabled', 0, 'plagiarism_essayguard');

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->setUser($student);

        $result = $this->call_log_event((int)$act['cm']->id, 'akoffsite', [$this->wire_event()]);

        $this->assertTrue($result['ignored']);
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_ev'));
    }

    /**
     * finalize_attempt scores and persists the attempt but returns nothing readable to
     * the student. The explanations array is written for a teacher — it names which
     * heuristics fired and why — so publishing it to the student both leaks the detection
     * model and makes the score iterable.
     *
     * @return void
     */
    public function test_finalize_attempt_blanks_the_report_for_the_student(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->setUser($student);
        $this->add_typing_stream($student->id, (int)$act['cm']->id, (int)$act['context']->id, 'akfinal');

        $raw    = finalize_attempt::execute((int)$act['cm']->id, 'akfinal', 'The submitted answer text.');
        $result = (array)external_api::clean_returnvalue(finalize_attempt::execute_returns(), $raw);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['risklevel']);
        $this->assertSame(0.0, $result['riskscore']);
        $this->assertSame(0, $result['score100']);
        $this->assertSame([], $result['explanations']);
        $this->assertSame('{}', $result['metricsjson']);

        // The score really was computed and stored; only the response is withheld.
        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_sc', ['userid' => $student->id]));
    }

    /**
     * A teacher gets the full report, in the shape execute_returns() declares.
     *
     * @return void
     */
    public function test_finalize_attempt_returns_the_full_report_to_a_teacher(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $teacher = $this->getDataGenerator()->create_and_enrol($act['course'], 'editingteacher');
        $this->setUser($teacher);
        $this->add_typing_stream($teacher->id, (int)$act['cm']->id, (int)$act['context']->id, 'akstaff');

        $raw    = finalize_attempt::execute((int)$act['cm']->id, 'akstaff', 'The submitted answer text.');
        $result = (array)external_api::clean_returnvalue(finalize_attempt::execute_returns(), $raw);

        $this->assertTrue($result['ok']);
        $this->assertContains($result['risklevel'], ['low', 'medium', 'high']);
        $this->assertIsArray($result['explanations']);
        $this->assertNotSame('{}', $result['metricsjson']);
        $this->assertIsArray(json_decode($result['metricsjson'], true));
    }

    /**
     * REGRESSION (V1.2.229 FIX-EG-WS-CM-GATE) for the finalize path: an activity with
     * Essay Guard switched off must produce no score record, and ok:false so the browser
     * does not render a badge for an analysis that never ran.
     *
     * @return void
     */
    public function test_finalize_attempt_honours_the_per_activity_switch(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();
        $this->set_platform_settings(false, false);

        $act     = $this->create_essayguard_assign(false);
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->setUser($student);
        $this->add_typing_stream($student->id, (int)$act['cm']->id, (int)$act['context']->id, 'akofffin');

        $raw    = finalize_attempt::execute((int)$act['cm']->id, 'akofffin', 'The submitted answer text.');
        $result = (array)external_api::clean_returnvalue(finalize_attempt::execute_returns(), $raw);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc'));
    }

    /**
     * finalize_attempt applies the same attempt-key ownership rule as log_event.
     *
     * @return void
     */
    public function test_finalize_attempt_rejects_another_students_attempt_key(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act    = $this->create_essayguard_quiz();
        $victim = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $cheat  = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        $this->setUser($victim);
        $attempt = $this->make_quiz_attempt($act['quiz'], $victim, 'The victim wrote this answer themselves.');

        $this->setUser($cheat);
        $this->expectException(\invalid_parameter_exception::class);
        finalize_attempt::execute((int)$act['cm']->id, 'qa_' . $attempt->get_attemptid(), 'text');
    }

    /**
     * get_badges is teacher-only: a student asking for the badge table is refused.
     *
     * @return void
     */
    public function test_get_badges_requires_the_report_capability(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        get_badges::execute((int)$act['cm']->id, [$student->id]);
    }

    /**
     * A student with a score gets a badge; a student with none is reported as having no
     * badge rather than as a green LOW.
     *
     * @return void
     */
    public function test_get_badges_reports_scored_and_unscored_students(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $scored  = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $unscored = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($act['course'], 'editingteacher');

        $this->create_score([
            'userid'    => $scored->id,
            'cmid'      => $act['cm']->id,
            'contextid' => $act['context']->id,
            'riskscore' => 0.75,
            'risklevel' => 'high',
        ]);

        $this->setUser($teacher);
        $raw     = get_badges::execute((int)$act['cm']->id, [$scored->id, $unscored->id]);
        $results = external_api::clean_returnvalue(get_badges::execute_returns(), $raw);

        $byuser = [];
        foreach ($results as $row) {
            $byuser[$row['userid']] = $row;
        }
        $this->assertTrue($byuser[$scored->id]['hasbadge']);
        $this->assertSame(75, $byuser[$scored->id]['score100']);
        $this->assertSame('high', $byuser[$scored->id]['risklevel']);
        $this->assertFalse($byuser[$unscored->id]['hasbadge']);
        $this->assertSame('', $byuser[$unscored->id]['risklevel']);
    }

    /**
     * The student-level badge must expose the worst evidence, not an average of it: an
     * aggregate that blends every question together dilutes one high-risk answer into a
     * reassuring LOW next to the student's name.
     *
     * @return void
     */
    public function test_get_badges_exposes_the_worst_of_the_per_question_and_aggregate_records(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($act['course'], 'editingteacher');

        // Aggregate looks calm; question 2 does not.
        $this->create_score(['userid' => $student->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id, 'attemptkey' => 'akworst', 'qslot' => 0, 'riskscore' => 0.10]);
        $this->create_score(['userid' => $student->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id, 'attemptkey' => 'akworst', 'qslot' => 1, 'riskscore' => 0.20]);
        $this->create_score(['userid' => $student->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id, 'attemptkey' => 'akworst', 'qslot' => 2, 'riskscore' => 0.90]);

        $this->setUser($teacher);
        $results = external_api::clean_returnvalue(
            get_badges::execute_returns(),
            get_badges::execute((int)$act['cm']->id, [$student->id])
        );

        $this->assertSame(90, $results[0]['score100']);
        $this->assertSame('high', $results[0]['risklevel']);
    }

    /**
     * The reverse rescue: when per-question scoring found nothing because the tracker
     * could not tag events with a question slot, the aggregate must still be able to win.
     *
     * @return void
     */
    public function test_get_badges_lets_a_high_aggregate_beat_a_quiet_per_question_record(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($act['course'], 'editingteacher');

        $this->create_score(['userid' => $student->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id, 'attemptkey' => 'akresc', 'qslot' => 0, 'riskscore' => 0.85]);
        $this->create_score(['userid' => $student->id, 'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id, 'attemptkey' => 'akresc', 'qslot' => 1, 'riskscore' => 0.00]);

        $this->setUser($teacher);
        $results = external_api::clean_returnvalue(
            get_badges::execute_returns(),
            get_badges::execute((int)$act['cm']->id, [$student->id])
        );

        $this->assertSame(85, $results[0]['score100']);
    }

    /**
     * A tutor confined to one group by SEPARATEGROUPS must not be able to read the risk
     * level of a student in another group simply by naming their user id in the request.
     *
     * @return void
     */
    public function test_get_badges_does_not_leak_across_separate_groups(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator(
            )->create_module('assign',
            [
                'course'    => $course->id,
                'groupmode' => SEPARATEGROUPS,
                ]
        );
        $cm      = get_coursemodule_from_instance('assign', $assign->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        set_config('enabled_cm_' . $cm->id, 1, 'plagiarism_essayguard');

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        // A non-editing teacher the site has granted the report capability to — the exact
        // role that is confined to its own groups and must stay confined.
        $tutorroleid = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability(
            'plagiarism/essayguard:viewreport',
            CAP_ALLOW,
            $tutorroleid,
            \context_course::instance($course->id)->id,
            true
        );
        $tutor   = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $mine    = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $notmine = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $tutor->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $mine->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $notmine->id]);

        foreach ([$mine, $notmine] as $user) {
            $this->create_score([
                'userid'     => $user->id,
                'cmid'       => $cm->id,
                'contextid'  => $context->id,
                'attemptkey' => 'ak' . $user->id,
                'riskscore'  => 0.90,
                'risklevel'  => 'high',
            ]);
        }

        $this->setUser($tutor);
        $this->assertFalse(has_capability('moodle/site:accessallgroups', $context));

        $results = external_api::clean_returnvalue(
            get_badges::execute_returns(),
            get_badges::execute((int)$cm->id, [$mine->id, $notmine->id])
        );
        $byuser = [];
        foreach ($results as $row) {
            $byuser[$row['userid']] = $row;
        }

        $this->assertTrue($byuser[$mine->id]['hasbadge']);
        $this->assertFalse($byuser[$notmine->id]['hasbadge'], 'A student in another group must not be readable.');
        $this->assertSame('', $byuser[$notmine->id]['risklevel']);
        $this->assertSame(0, $byuser[$notmine->id]['score100']);
    }

    /**
     * The badge level is re-derived from the stored score at display time, so a row
     * written under old thresholds cannot show a level that contradicts its own number.
     *
     * @return void
     */
    public function test_get_badges_rederives_the_level_from_the_score(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($act['course'], 'editingteacher');

        // A stale row claiming 'low' next to a score of 0.9.
        $this->create_score([
            'userid'    => $student->id,
            'cmid'      => $act['cm']->id,
            'contextid' => $act['context']->id,
            'riskscore' => 0.90,
            'risklevel' => 'low',
        ]);

        $this->setUser($teacher);
        $results = external_api::clean_returnvalue(
            get_badges::execute_returns(),
            get_badges::execute((int)$act['cm']->id, [$student->id])
        );

        $this->assertSame('high', $results[0]['risklevel']);
    }
}
