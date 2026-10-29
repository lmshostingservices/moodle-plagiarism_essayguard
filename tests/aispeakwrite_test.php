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
 * V2.0.1: AI Advent Speaking and Writing (mod_aispeakwrite) integration.
 *
 * Skipped when mod_aispeakwrite 1.0.4 or later is not installed. Events are handed straight to
 * the observer, as in observer_test: non-internal observers are deferred until the test
 * transaction commits, so a triggered event would prove nothing.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_essayguard\observer
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\plagiarism_essayguard\observer::class)]
final class aispeakwrite_test extends \advanced_testcase {
    use \essayguard_test_helper;

    /** @var string A first draft. */
    private const DRAFT1 = 'Lieber Tim, vielen Dank für die Einladung. Ich komme gern zu deiner Party am Samstag. ' .
        'Herzlichen Glückwunsch zu deinem Geburtstag. Ich bringe einen Kuchen mit. Bis bald, Justus';

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        if (!file_exists($CFG->dirroot . '/mod/aispeakwrite/classes/event/answer_submitted.php')) {
            $this->markTestSkipped('mod_aispeakwrite 1.0.4 or later is not installed.');
        }
        $this->silence_php_deprecation_output();
        \plagiarism_essayguard\local\service\analyser::reset_caches();
    }

    protected function tearDown(): void {
        $this->restore_php_deprecation_output();
        parent::tearDown();
    }

    /**
     * An AI Advent activity with a writing task and Essay Guard on.
     *
     * @param bool $cmenabled
     * @return array
     */
    private function create_activity(bool $cmenabled = true): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('aispeakwrite', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('aispeakwrite', $module->cmid, 0, false, MUST_EXIST);
        $task = $this->getDataGenerator()->get_plugin_generator('mod_aispeakwrite')->create_task(
            ['aispeakwriteid' => $module->id]
        );
        set_config('enabled_cm_' . $cm->id, $cmenabled ? 1 : 0, 'plagiarism_essayguard');
        return ['course' => $course, 'cm' => $cm, 'task' => $task,
            'instance' => $DB->get_record('aispeakwrite', ['id' => $module->id]),
            'context' => \context_module::instance($cm->id)];
    }

    /**
     * A draft row.
     *
     * @param array $act
     * @param int $userid
     * @param int $attempt
     * @param string $content
     * @param string $status
     * @param string $kind
     * @return \stdClass
     */
    private function draft(
        array $act,
        int $userid,
        int $attempt,
        string $content,
        string $status = 'marked',
        string $kind = 'writing'
    ): \stdClass {
        global $DB;
        $now = time();
        $row = (object)['aispeakwriteid' => $act['instance']->id, 'taskid' => $act['task']->id, 'userid' => $userid,
            'attempt' => $attempt, 'kind' => $kind, 'status' => $status, 'content' => $content, 'notes' => '',
            'fbmode' => 'think', 'contenthash' => '', 'tipsversion' => '', 'timecreated' => $now - 600,
            'timemodified' => $now, 'timemarked' => $status === 'marked' ? $now : 0];
        $row->id = $DB->insert_record('aispeakwrite_sub', $row);
        return $row;
    }

    /**
     * The event AI Advent fires for a marked written draft.
     *
     * @param array $act
     * @param \stdClass $sub
     * @return \core\event\base
     */
    private function event(array $act, \stdClass $sub): \core\event\base {
        return \mod_aispeakwrite\event\answer_submitted::create([
            'objectid' => $sub->id,
            'context' => $act['context'],
            'relateduserid' => $sub->userid,
            'other' => ['taskid' => (int)$sub->taskid, 'attempt' => (int)$sub->attempt],
        ]);
    }

    /**
     * The activity type is supported and the observer is registered for the new event.
     */
    public function test_supported_and_registered(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->assertTrue(plagiarism_essayguard_supports_mod('aispeakwrite'));
        $observers = [];
        include($CFG->dirroot . '/plagiarism/essayguard/db/events.php');
        $names = array_column($observers, 'callback', 'eventname');
        $this->assertSame(
            'plagiarism_essayguard\observer::on_aispeakwrite_answer_submitted',
            $names['\mod_aispeakwrite\event\answer_submitted']
        );
        $this->assertSame('aispeakwrite', \plagiarism_essayguard\local\service\fingerprint::normalise_context_type(
            'aispeakwrite'
        ));
    }

    /**
     * asw_ keys are accepted only for the user's own written draft in this activity.
     */
    public function test_attemptkey_validation(): void {
        $this->resetAfterTest();
        $act = $this->create_activity();
        $other = $this->create_activity();
        $u1 = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $u2 = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $mine = $this->draft($act, (int)$u1->id, 1, 'x', 'draft');
        $theirs = $this->draft($act, (int)$u2->id, 1, 'x', 'draft');
        $elsewhere = $this->draft($other, (int)$u1->id, 1, 'x', 'draft');
        $speaking = $this->draft($act, (int)$u1->id, 1, '[]', 'draft', 'speaking');

        $this->assertTrue(plagiarism_essayguard_attemptkey_is_valid('asw_' . $mine->id, $act['cm'], (int)$u1->id));
        $this->assertFalse(plagiarism_essayguard_attemptkey_is_valid('asw_' . $theirs->id, $act['cm'], (int)$u1->id));
        $this->assertFalse(plagiarism_essayguard_attemptkey_is_valid('asw_' . $elsewhere->id, $act['cm'], (int)$u1->id));
        $this->assertFalse(plagiarism_essayguard_attemptkey_is_valid('asw_' . $speaking->id, $act['cm'], (int)$u1->id));
        $this->assertFalse(plagiarism_essayguard_attemptkey_is_valid('asw_x', $act['cm'], (int)$u1->id));
        $this->assertFalse(plagiarism_essayguard_attemptkey_is_valid('qa_' . $mine->id, $act['cm'], (int)$u1->id));
        // The generic hash key still works, as for any other module.
        $this->assertTrue(plagiarism_essayguard_attemptkey_is_valid(
            sha1($u1->id . ':' . $act['cm']->id),
            $act['cm'],
            (int)$u1->id
        ));
    }

    /**
     * One score per draft, under the draft's own key, with the aispeakwrite baseline and no pooled fingerprint.
     */
    public function test_one_score_per_draft(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();
        $act = $this->create_activity();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        $d1 = $this->draft($act, (int)$student->id, 1, self::DRAFT1);
        $this->add_typing_stream((int)$student->id, $act['cm']->id, $act['context']->id, 'asw_' . $d1->id, 60);
        observer::on_aispeakwrite_answer_submitted($this->event($act, $d1));

        $d2 = $this->draft($act, (int)$student->id, 2, self::DRAFT1 . ' Soll ich etwas mitbringen?');
        $this->add_typing_stream((int)$student->id, $act['cm']->id, $act['context']->id, 'asw_' . $d2->id, 30);
        observer::on_aispeakwrite_answer_submitted($this->event($act, $d2));
        // Scoring the same draft again updates its row rather than adding one.
        observer::on_aispeakwrite_answer_submitted($this->event($act, $d2));

        $rows = $DB->get_records('plagiarism_essayguard_sc', ['userid' => $student->id], 'id');
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['asw_' . $d1->id, 'asw_' . $d2->id], array_column($rows, 'attemptkey'));
        foreach ($rows as $row) {
            $this->assertSame(0, (int)$row->qslot);
            $this->assertEquals($act['cm']->id, $row->cmid);
            $metrics = json_decode($row->metricsjson, true);
            $this->assertSame('aispeakwrite', $metrics['baseline_contexttype']);
        }

        // Draft 2 is scored on the words it added, not on the prefilled draft 1.
        $m2 = json_decode($rows[array_key_last($rows)]->metricsjson, true);
        $this->assertSame(\core_text::strlen('Soll ich etwas mitbringen?'), (int)$m2['text_chars']);

        // Its own baseline partition; the pooled essay fingerprint is untouched.
        $this->assertTrue($DB->record_exists(
            'plagiarism_essayguard_fpm',
            ['userid' => $student->id, 'contexttype' => 'aispeakwrite']
        ));
        $this->assertFalse($DB->record_exists(
            'plagiarism_essayguard_fpm',
            ['userid' => $student->id, 'contexttype' => 'other']
        ));
        $this->assertFalse($DB->record_exists('plagiarism_essayguard_fp', ['userid' => $student->id]));
    }

    /**
     * Nothing is scored when the event is not for this learner's written draft, the activity is off, or there was no
     * telemetry.
     */
    public function test_observer_guards(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();
        $act = $this->create_activity();
        $off = $this->create_activity(false);
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');

        // No telemetry: no record (absence of evidence is not a LOW score).
        $quiet = $this->draft($act, (int)$student->id, 1, self::DRAFT1);
        observer::on_aispeakwrite_answer_submitted($this->event($act, $quiet));

        // Speaking answers are never scored.
        $speaking = $this->draft($act, (int)$student->id, 1, '[]', 'marked', 'speaking');
        $this->add_typing_stream((int)$student->id, $act['cm']->id, $act['context']->id, 'asw_' . $speaking->id);
        observer::on_aispeakwrite_answer_submitted($this->event($act, $speaking));

        // Activity switched off.
        $offsub = $this->draft($off, (int)$student->id, 1, self::DRAFT1);
        $this->add_typing_stream((int)$student->id, $off['cm']->id, $off['context']->id, 'asw_' . $offsub->id);
        observer::on_aispeakwrite_answer_submitted($this->event($off, $offsub));

        // An event claiming a draft of another activity.
        $foreign = $this->draft($off, (int)$student->id, 2, self::DRAFT1);
        $this->add_typing_stream((int)$student->id, $act['cm']->id, $act['context']->id, 'asw_' . $foreign->id);
        observer::on_aispeakwrite_answer_submitted($this->event($act, $foreign));

        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc'));
    }

    /**
     * The words added by a rewrite, in order; reordered or kept words are not added.
     */
    public function test_added_words(): void {
        $this->resetAfterTest();
        $this->assertSame('', observer::added_words('Ich gehe heute.', 'Ich gehe heute.'));
        $this->assertSame('morgen', observer::added_words('Ich gehe heute.', 'Ich gehe heute. morgen'));
        // Swapping two words adds one of them back.
        $this->assertContains(
            observer::added_words('Am Samstag ich komme gern.', 'Am Samstag komme ich gern.'),
            ['komme', 'ich']
        );
        $this->assertSame('Ganz neuer Text hier', observer::added_words('', 'Ganz neuer Text hier'));
        $this->assertSame('', observer::added_words('Alles weg', ''));
    }

    /**
     * Text the activity inserts itself is taken out of the length behind the keystroke ratio.
     */
    public function test_benign_insert_not_counted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();
        $act = $this->create_activity();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $sub = $this->draft($act, (int)$student->id, 1, self::DRAFT1);
        $key = 'asw_' . $sub->id;
        $this->add_typing_stream((int)$student->id, $act['cm']->id, $act['context']->id, $key, 60);
        $this->add_event(
            (int)$student->id,
            $act['cm']->id,
            $act['context']->id,
            $key,
            'benign_insert',
            1750000100000,
            ['source' => 'ui', 'insertlen' => 12]
        );
        $this->add_event(
            (int)$student->id,
            $act['cm']->id,
            $act['context']->id,
            $key,
            'input',
            1750000100001,
            ['inputType' => 'insertReplacementText', 'addedchars' => 0, 'insertlen' => 0, 'benign' => 1]
        );
        observer::on_aispeakwrite_answer_submitted($this->event($act, $sub));

        $row = $DB->get_record('plagiarism_essayguard_sc', ['attemptkey' => $key]);
        $metrics = json_decode($row->metricsjson, true);
        $this->assertSame(12, (int)$metrics['benign_chars']);
        $this->assertSame(\core_text::strlen(self::DRAFT1) - 12, (int)$metrics['text_chars']);
        $this->assertSame(0, (int)$metrics['pastecount']);
        $this->assertSame(0, (int)$metrics['large_inserts']);
    }

    /**
     * get_links() answers AI Advent's request with that draft's badge, for the right viewers only.
     */
    public function test_get_links(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();
        $act = $this->create_activity();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $peer = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($act['course'], 'editingteacher');
        $d1 = $this->draft($act, (int)$student->id, 1, self::DRAFT1);
        $d2 = $this->draft($act, (int)$student->id, 2, self::DRAFT1);
        $this->create_score(['userid' => $student->id, 'cmid' => $act['cm']->id, 'contextid' => $act['context']->id,
            'attemptkey' => 'asw_' . $d1->id, 'riskscore' => 0.9, 'risklevel' => 'high',
            'metricsjson' => json_encode(['event_count' => 50])]);

        $links = fn($sub) => plagiarism_essayguard_get_links(['cmid' => $act['cm']->id, 'userid' => $student->id,
            'component' => 'mod_aispeakwrite', 'itemid' => $sub->id, 'content' => '']);

        $this->setUser($teacher);
        $high = get_string('riskhigh', 'plagiarism_essayguard');
        $html = $links($d1);
        $this->assertStringContainsString($high, $html);
        $this->assertStringContainsString('report.php', $html);
        // Draft 2 has no score yet: the teacher gets the report link, not draft 1's badge.
        $html2 = $links($d2);
        $this->assertStringNotContainsString($high, $html2);
        $this->assertStringContainsString('report.php', $html2);
        // A draft id that is not this learner's is refused.
        $this->assertSame('', plagiarism_essayguard_get_links(['cmid' => $act['cm']->id, 'userid' => $peer->id,
            'component' => 'mod_aispeakwrite', 'itemid' => $d1->id]));

        $this->setUser($peer);
        $this->assertSame('', $links($d1));
    }

    /**
     * The tracker is injected on the writing screen for learners only, keyed on the open draft.
     */
    public function test_inject_tracker(): void {
        global $CFG, $PAGE;
        $this->resetAfterTest();
        $this->enable_essayguard();
        $CFG->enableplagiarism = 1;
        $act = $this->create_activity();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($act['course'], 'editingteacher');
        $draft = $this->draft($act, (int)$student->id, 1, '', 'draft');

        $run = function (string $page, $user) use ($act) {
            global $PAGE;
            $this->setUser($user);
            $PAGE = new \moodle_page();
            $PAGE->set_cm($act['cm']);
            $PAGE->set_url('/mod/aispeakwrite/' . $page, ['id' => $act['cm']->id, 'task' => $act['task']->id]);
            plagiarism_essayguard_inject_tracker();
            return $PAGE->requires->get_end_code();
        };

        $code = $run('write.php', $student);
        $this->assertStringContainsString('plagiarism_essayguard/tracker', $code);
        $this->assertStringContainsString('asw_' . $draft->id, $code);
        $this->assertSame('asw_' . $draft->id, get_user_preferences('essayguard_ak_' . $act['cm']->id, null, $student));

        $this->assertStringNotContainsString('plagiarism_essayguard/tracker', $run('speak.php', $student));
        $this->assertStringNotContainsString('plagiarism_essayguard/tracker', $run('view.php', $student));
        $this->assertStringNotContainsString('plagiarism_essayguard/tracker', $run('write.php', $teacher));

        // No open draft (no rewrites left): nothing to monitor.
        global $DB;
        $DB->set_field('aispeakwrite_sub', 'status', 'marked', ['id' => $draft->id]);
        $this->assertStringNotContainsString('plagiarism_essayguard/tracker', $run('write.php', $student));
    }

    /**
     * Deleting the activity and erasing its context remove the drafts' telemetry and scores.
     */
    public function test_cleanup_and_privacy(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();
        $act = $this->create_activity();
        $other = $this->create_activity();
        $student = $this->getDataGenerator()->create_and_enrol($act['course'], 'student');
        foreach ([$act, $other] as $a) {
            $sub = $this->draft($a, (int)$student->id, 1, self::DRAFT1);
            $this->add_typing_stream((int)$student->id, $a['cm']->id, $a['context']->id, 'asw_' . $sub->id);
            observer::on_aispeakwrite_answer_submitted($this->event($a, $sub));
        }
        $this->assertSame(2, $DB->count_records('plagiarism_essayguard_sc'));

        // Privacy: erase one activity's context.
        \plagiarism_essayguard\privacy\provider::delete_data_for_all_users_in_context($other['context']);
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc', ['cmid' => $other['cm']->id]));
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_ev', ['cmid' => $other['cm']->id]));

        // Course module deleted.
        $event = \core\event\course_module_deleted::create([
            'courseid' => $act['course']->id,
            'context' => $act['context'],
            'objectid' => $act['cm']->id,
            'other' => ['modulename' => 'aispeakwrite', 'instanceid' => $act['instance']->id, 'name' => 'x'],
        ]);
        observer::on_course_module_deleted($event);
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_sc'));
        $this->assertSame(0, $DB->count_records('plagiarism_essayguard_ev'));
        $this->assertFalse(get_config('plagiarism_essayguard', 'enabled_cm_' . $act['cm']->id));
    }
}
