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

/**
 * Integration tests for the Essay Guard event observer.
 *
 * Every event these tests hand to the observer is built by core's own factory, from a
 * real activity and a real attempt — never from a hand-written payload — so a test here
 * fails the moment the observer stops matching the payload Moodle really sends.
 *
 * The events are handed straight to the observer rather than triggered, because Moodle's
 * PHPUnit wraps each test in a database transaction and core defers non-internal
 * observers until that transaction commits; a triggered event would prove nothing.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_essayguard\observer
 */
final class observer_test extends \advanced_testcase {
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
     * REGRESSION (V1.2.229 FIX-EG-OBSERVER-WRONG-USER) for the assignment path.
     *
     * mod_assign lets a teacher submit on a student's behalf, and
     * assessable_submitted::create_from_submission() then puts the TEACHER in userid and
     * the STUDENT in relateduserid — it sets relateduserid only when the two differ.
     * Until 1.2.229 the observer read userid unconditionally, so the student's writing
     * telemetry was scored against the teacher: the student's badge never appeared, the
     * teacher acquired a risk score for work they did not write, and the privacy
     * provider, which keys on userid, missed the record on both export and erasure.
     *
     * @return void
     */
    public function test_assign_submitted_on_behalf_of_student_is_scored_for_the_student(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($act['course'], 'editingteacher');

        $this->setUser($student);
        $parts = $this->save_online_text($act, $student, '<p>A written answer of a plausible length.</p>');
        $this->set_attemptkey_preference($student->id, $act['cm']->id, 'akstudent');
        $this->add_typing_stream($student->id, $act['cm']->id, $act['context']->id, 'akstudent');

        // The teacher presses submit.
        $this->setUser($teacher);
        $event = \mod_assign\event\assessable_submitted::create_from_submission(
            $parts['assign'],
            $parts['submission'],
            true
        );

        // This is the payload core really sends when someone else submits.
        $this->assertEquals($teacher->id, $event->userid);
        $this->assertEquals($student->id, $event->relateduserid);

        observer::on_assessable_submitted($event);

        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_sc', ['userid' => $student->id]));
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc', ['userid' => $teacher->id]));
    }

    /**
     * The ordinary case still works: when the student submits their own work, core sets
     * no relateduserid at all and the observer must fall back to userid.
     *
     * @return void
     */
    public function test_assign_submitted_by_the_student_is_scored_for_the_student(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        $this->setUser($student);
        $parts = $this->save_online_text($act, $student, '<p>A written answer of a plausible length.</p>');
        $this->set_attemptkey_preference($student->id, $act['cm']->id, 'akself');
        $this->add_typing_stream($student->id, $act['cm']->id, $act['context']->id, 'akself');

        $event = \mod_assign\event\assessable_submitted::create_from_submission(
            $parts['assign'],
            $parts['submission'],
            true
        );
        // Core omits relateduserid entirely when the submitter is the author.
        $this->assertNull($event->relateduserid);

        observer::on_assessable_submitted($event);

        $record = $DB->get_record('plagiarism_essayguard_sc', ['userid' => $student->id]);
        $this->assertNotFalse($record, 'A student submitting their own work must be scored.');
        $this->assertSame('akself', $record->attemptkey);
        $this->assertEquals($act['context']->id, $record->contextid);
        $this->assertEquals($act['cm']->id, $record->cmid);
    }

    /**
     * REGRESSION (V1.2.229 FIX-EG-OBSERVER-WRONG-USER) for the quiz path, which is where
     * it matters most.
     *
     * \mod_quiz\event\attempt_submitted REQUIRES relateduserid — its validate_data()
     * throws a coding_exception without it — precisely because the attempt owner is not
     * always the submitter. quiz_attempt::fire_state_transition_event() sets
     * relateduserid to the ATTEMPT owner and leaves userid as whoever is logged in, and
     * an overdue attempt is auto-submitted by the quiz cron task, where that is the cron
     * user. Every such attempt used to be scored against the wrong user id, and the
     * student's own record never appeared at all.
     *
     * The event here is captured from a real process_finish() run, so it is exactly the
     * object core dispatches.
     *
     * @return void
     */
    public function test_quiz_attempt_finished_by_someone_else_is_scored_for_the_student(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_quiz();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($act['course'], 'editingteacher');

        // The teacher (or, in production, cron) is the logged-in user at submit time.
        $this->setUser($teacher);
        $sink       = $this->redirectEvents();
        $attemptobj = $this->make_quiz_attempt($act['quiz'], $student, 'A written answer of a plausible length.');
        $events     = $sink->get_events();
        $sink->close();

        $event = null;
        foreach ($events as $candidate) {
            if ($candidate instanceof \mod_quiz\event\attempt_submitted) {
                $event = $candidate;
            }
        }
        $this->assertNotNull($event, 'process_finish() must fire attempt_submitted.');
        $this->assertEquals($teacher->id, $event->userid);
        $this->assertEquals($student->id, $event->relateduserid);

        $this->add_typing_stream(
            $student->id,
            $act['cm']->id,
            $act['context']->id,
            'qa_' . $attemptobj->get_attemptid()
        );

        observer::on_quiz_attempt_submitted($event);

        $this->assertGreaterThan(0, $DB->count_records('plagiarism_essayguard_sc', ['userid' => $student->id]));
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc', ['userid' => $teacher->id]));
    }

    /**
     * The quiz path derives its attempt key from the attempt id rather than from a user
     * preference, so a submission made by someone else still finds the right telemetry.
     *
     * @return void
     */
    public function test_quiz_attempt_key_is_derived_from_the_attempt_id(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_quiz();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        $this->setUser($student);
        $sink       = $this->redirectEvents();
        $attemptobj = $this->make_quiz_attempt($act['quiz'], $student, 'A written answer of a plausible length.');
        $events     = $sink->get_events();
        $sink->close();

        $event = null;
        foreach ($events as $candidate) {
            if ($candidate instanceof \mod_quiz\event\attempt_submitted) {
                $event = $candidate;
            }
        }

        $key = 'qa_' . $attemptobj->get_attemptid();
        $this->add_typing_stream($student->id, $act['cm']->id, $act['context']->id, $key, 40, 1);

        observer::on_quiz_attempt_submitted($event);
        // The per-question pass logs its untagged-paste search at developer level; that
        // is analyser's diagnostic, not something this test is asserting about.
        $this->resetDebugging();

        $records = $DB->get_records('plagiarism_essayguard_sc', ['userid' => $student->id], 'qslot ASC');
        $this->assertNotEmpty($records);
        foreach ($records as $record) {
            $this->assertSame($key, $record->attemptkey);
        }
        // Per-question and aggregate rows are both written, per-question first.
        $this->assertSame([0, 1], array_values(array_unique(array_map(
            static fn($r) => (int)$r->qslot,
            $records
        ))));
    }

    /**
     * A submission with no attempt key at all — the tracker never ran, so no telemetry
     * exists — must leave no record. An integrity product that reports "no evidence
     * collected" as a green LOW badge is worse than one that reports nothing.
     *
     * @return void
     */
    public function test_submission_with_no_telemetry_writes_no_record(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        $this->setUser($student);
        $parts = $this->save_online_text($act, $student, '<p>An answer typed on a device with no JavaScript.</p>');

        observer::on_assessable_submitted(
            \mod_assign\event\assessable_submitted::create_from_submission(
                $parts['assign'],
                $parts['submission'],
                true
                )
        );
        $this->assertDebuggingCalled();

        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc'));
    }

    /**
     * REGRESSION (V1.2.229 FIX-EG-OBSERVER-CM-GATE-DIVERGES): the observer used to carry
     * its own private copy of the per-activity gate, testing only the enabled_cm_<cmid>
     * checkbox. plagiarism_essayguard_is_cm_active() — which every other caller uses —
     * checks the lms-labs.com platform "all quizzes" / "all assignments" flags FIRST and
     * lets them override an unticked checkbox.
     *
     * So on a site driving Essay Guard from the platform switch, the tracker was
     * injected and the keystrokes were recorded, and then the one component that turns
     * telemetry into a score returned early — forever.
     *
     * @return void
     */
    public function test_platform_wide_switch_overrides_an_unticked_activity_checkbox(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();
        $this->set_platform_settings(true, false);

        // Checkbox explicitly OFF; the platform "all assignments" flag is ON.
        $act     = $this->create_essayguard_assign(false);
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        $this->setUser($student);
        $parts = $this->save_online_text($act, $student, '<p>A written answer of a plausible length.</p>');
        $this->set_attemptkey_preference($student->id, $act['cm']->id, 'akplatform');
        $this->add_typing_stream($student->id, $act['cm']->id, $act['context']->id, 'akplatform');

        // The gate the rest of the plugin uses says yes.
        $this->assertTrue(plagiarism_essayguard_is_cm_active((int)$act['cm']->id));

        observer::on_assessable_submitted(
            \mod_assign\event\assessable_submitted::create_from_submission(
                $parts['assign'],
                $parts['submission'],
                true
                )
        );

        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_sc', ['userid' => $student->id]));
    }

    /**
     * With the platform flags off, an unticked activity checkbox really does stop
     * scoring — the delegation above must not turn the gate into a no-op.
     *
     * @return void
     */
    public function test_unticked_activity_checkbox_blocks_scoring(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();
        $this->set_platform_settings(false, false);

        $act     = $this->create_essayguard_assign(false);
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        $this->setUser($student);
        $parts = $this->save_online_text($act, $student, '<p>A written answer of a plausible length.</p>');
        $this->set_attemptkey_preference($student->id, $act['cm']->id, 'akoff');
        $this->add_typing_stream($student->id, $act['cm']->id, $act['context']->id, 'akoff');

        observer::on_assessable_submitted(
            \mod_assign\event\assessable_submitted::create_from_submission(
                $parts['assign'],
                $parts['submission'],
                true
                )
        );

        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc'));
    }

    /**
     * The site-wide switch, when the admin has explicitly turned it off, stops the
     * observer before it reads anything.
     *
     * @return void
     */
    public function test_site_wide_disable_blocks_scoring(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();
        set_config('enabled', 0, 'plagiarism_essayguard');

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        $this->setUser($student);
        $parts = $this->save_online_text($act, $student, '<p>A written answer of a plausible length.</p>');
        $this->set_attemptkey_preference($student->id, $act['cm']->id, 'akdisabled');
        $this->add_typing_stream($student->id, $act['cm']->id, $act['context']->id, 'akdisabled');

        observer::on_assessable_submitted(
            \mod_assign\event\assessable_submitted::create_from_submission(
                $parts['assign'],
                $parts['submission'],
                true
                )
        );

        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc'));
    }

    /**
     * A never-saved "enabled" setting reads as bool false and must mean ENABLED, which
     * is the state of every fresh install. Pinning it here because `!$enabled` on a
     * never-saved key has produced this defect in five separate files in this plugin.
     *
     * @return void
     */
    public function test_never_saved_enabled_setting_still_scores(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('unlock_cache_result', 1, 'plagiarism_essayguard');
        set_config('unlock_cache_time', time(), 'plagiarism_essayguard');
        $this->assertFalse(get_config('plagiarism_essayguard', 'enabled'));

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        $this->setUser($student);
        $parts = $this->save_online_text($act, $student, '<p>A written answer of a plausible length.</p>');
        $this->set_attemptkey_preference($student->id, $act['cm']->id, 'akfresh');
        $this->add_typing_stream($student->id, $act['cm']->id, $act['context']->id, 'akfresh');

        observer::on_assessable_submitted(
            \mod_assign\event\assessable_submitted::create_from_submission(
                $parts['assign'],
                $parts['submission'],
                true
                )
        );

        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_sc', ['userid' => $student->id]));
    }

    /**
     * A real mod_forum assessable_uploaded event, built by core's own
     * forum_trigger_content_uploaded_event(), reaches the same handler and is scored
     * against the poster. Core sets no relateduserid for forum posts, so this also
     * covers the `?:` fallback on an event type that never carries one.
     *
     * @return void
     */
    public function test_forum_post_event_is_scored(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();

        $course  = $this->getDataGenerator()->create_course();
        $forum   = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $cm      = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        set_config('enabled_cm_' . $cm->id, 1, 'plagiarism_essayguard');

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $discussion = $this->getDataGenerator(
            )->get_plugin_generator('mod_forum')->create_discussion([
                'course'  => $course->id,
                'forum'   => $forum->id,
                'userid'  => $student->id,
                'message' => '<p>A forum contribution of a plausible length.</p>',
                ]
        );
        $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);

        $this->set_attemptkey_preference($student->id, $cm->id, 'akforum');
        $this->add_typing_stream($student->id, $cm->id, $context->id, 'akforum');

        $sink = $this->redirectEvents();
        forum_trigger_content_uploaded_event($post, $cm, 'essayguard_test');
        $events = $sink->get_events();
        $sink->close();

        $event = reset($events);
        $this->assertInstanceOf(\mod_forum\event\assessable_uploaded::class, $event);
        $this->assertNull($event->relateduserid);
        $this->assertEquals($student->id, $event->userid);

        observer::on_assessable_submitted($event);

        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_sc', ['userid' => $student->id]));
    }

    /**
     * REGRESSION (V1.2.229 FIX-EG-ORPHAN-ON-CM-DELETE): deleting the activity must take
     * Essay Guard's rows with it.
     *
     * The rows key on contextid, and core deletes the module context when the module
     * goes. contextlist_base::get_contexts() silently DROPS a context id that no longer
     * resolves, so from that moment the student's risk scores, their keystroke-level
     * behavioural profile and the explanations written about them were invisible to both
     * the export and the erasure paths while still sitting in the database, with no
     * remaining route that could ever delete them.
     *
     * @return void
     */
    public function test_deleting_the_activity_purges_its_essayguard_data(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();

        $act     = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $cmid    = (int)$act['cm']->id;

        $this->set_attemptkey_preference($student->id, $cmid, 'akdelete');
        $this->add_typing_stream($student->id, $cmid, $act['context']->id, 'akdelete', 5);
        $this->create_score([
            'userid'    => $student->id,
            'cmid'      => $cmid,
            'contextid' => $act['context']->id,
            'riskscore' => 0.8,
            'risklevel' => 'high',
        ]);

        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_sc', ['cmid' => $cmid]));

        $sink = $this->redirectEvents();
        $this->delete_course_module($cmid, (int)$act['course']->id);
        $events = $sink->get_events();
        $sink->close();

        $event = null;
        foreach ($events as $candidate) {
            if ($candidate instanceof \core\event\course_module_deleted) {
                $event = $candidate;
            }
        }
        $this->assertNotNull($event, 'Deleting a module must fire course_module_deleted.');
        $this->assertEquals($cmid, $event->objectid);

        observer::on_course_module_deleted($event);

        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc', ['cmid' => $cmid]));
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_ev', ['cmid' => $cmid]));
        $this->assertFalse(get_config('plagiarism_essayguard', 'enabled_cm_' . $cmid));
        $this->assertSame(0, $DB->count_records('user_preferences', ['name' => 'essayguard_ak_' . $cmid]));
    }

    /**
     * The purge is confined to the deleted activity: a second activity's data survives.
     *
     * @return void
     */
    public function test_deleting_one_activity_leaves_another_alone(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();

        $first  = $this->create_essayguard_assign();
        $second = $this->create_essayguard_assign();
        $student = $this->getDataGenerator()->create_and_enrol($first['course'], 'student');

        foreach ([$first, $second] as $act) {
            $this->create_score([
                'userid'    => $student->id,
                'cmid'      => $act['cm']->id,
                'contextid' => $act['context']->id,
            ]);
        }

        $event = \core\event\course_module_deleted::create([
            'context'  => $first['context'],
            'objectid' => $first['cm']->id,
            'courseid' => $first['course']->id,
            'other'    => [
                'modulename'   => 'assign',
                'instanceid'   => $first['assign']->id,
                'name'         => 'x',
            ],
        ]);
        observer::on_course_module_deleted($event);

        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc', ['cmid' => $first['cm']->id]));
        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_sc', ['cmid' => $second['cm']->id]));
    }
}
