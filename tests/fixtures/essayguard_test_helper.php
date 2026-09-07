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
 * Shared fixtures for the Essay Guard integration tests.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/forum/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/plagiarism/essayguard/lib.php');

/**
 * Builders for real Moodle activities, real attempts and real telemetry rows.
 *
 * Everything here goes through core's own generators and entry points; nothing
 * hand-builds an event payload or a submission record, so a test written on top of
 * this trait fails when the plugin stops matching what core really sends.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait essayguard_test_helper {
    /** @var string|false The display_errors value in force before a test silenced it. */
    private $egdisplayerrors = false;

    /**
     * Stop PHP printing E_DEPRECATED text while a test runs.
     *
     * Building a course, enrolling a teacher and deleting a module all reach core code
     * that raises deprecation notices on PHP 8.4 (php-di's implicit nullable parameters,
     * backup's xml_set_object()). PHPUnit runs with beStrictAboutOutputDuringTests, so
     * that text — emitted by Moodle, not by this plugin — marks the test risky on any
     * PHP newer than the branch supports. Only the DISPLAY of notices is suppressed:
     * echo output still counts, and debugging() is collected by Moodle's own store
     * rather than printed, so nothing this plugin does can hide behind it.
     *
     * @return void
     */
    protected function silence_php_deprecation_output(): void {
        $this->egdisplayerrors = ini_set('display_errors', '0');
    }

    /**
     * Restore whatever display_errors was set to before silence_php_deprecation_output().
     *
     * @return void
     */
    protected function restore_php_deprecation_output(): void {
        if ($this->egdisplayerrors !== false) {
            ini_set('display_errors', $this->egdisplayerrors);
        }
    }

    /**
     * Switch the plugin on site-wide and make the licence check answer "unlocked"
     * without any network call, by seeding the 30-minute config cache it reads.
     *
     * @return void
     */
    protected function enable_essayguard(): void {
        set_config('enabled', 1, 'plagiarism_essayguard');
        set_config('unlock_cache_result', 1, 'plagiarism_essayguard');
        set_config('unlock_cache_time', time(), 'plagiarism_essayguard');
        set_config('plagiarism_essayguard', 1, 'plagiarism');
    }

    /**
     * Seed the platform-settings cache the site-wide override reads, so a test can
     * exercise it without reaching lms-labs.com.
     *
     * @param bool $assignments Value for the "all assignments" platform flag.
     * @param bool $quizzes     Value for the "all quizzes" platform flag.
     * @return void
     */
    protected function set_platform_settings(bool $assignments, bool $quizzes): void {
        set_config('platform_settings_data', json_encode([
            'essayguard_assignments' => $assignments,
            'essayguard_quizzes'     => $quizzes,
            'docguard_assignments'   => false,
            'docguard_quizzes'       => false,
        ]), 'plagiarism_essayguard');
        set_config('platform_settings_time', time(), 'plagiarism_essayguard');
    }

    /**
     * Create a course with one assign activity that has Essay Guard switched on.
     *
     * @param bool $cmenabled Value written to the per-activity enabled_cm_<cmid> setting.
     * @return array{course: \stdClass, assign: \stdClass, cm: \cm_info, context: \context_module}
     */
    protected function create_essayguard_assign(bool $cmenabled = true): array {
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course'                        => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 0,
        ]);
        $cm      = get_coursemodule_from_instance('assign', $assign->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        set_config('enabled_cm_' . $cm->id, $cmenabled ? 1 : 0, 'plagiarism_essayguard');

        return ['course' => $course, 'assign' => $assign, 'cm' => $cm, 'context' => $context];
    }

    /**
     * Create a course with one quiz holding a single essay question.
     *
     * @param bool $cmenabled Value written to the per-activity enabled_cm_<cmid> setting.
     * @return array{course: \stdClass, quiz: \stdClass, cm: \stdClass, context: \context_module}
     */
    protected function create_essayguard_quiz(bool $cmenabled = true): array {
        $course = $this->getDataGenerator()->create_course();
        $quiz   = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course'           => $course->id,
            'questionsperpage' => 0,
            'grade'            => 100.0,
            'sumgrades'        => 1,
        ]);

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat  = $qgen->create_question_category();
        $essay = $qgen->create_question('essay', null, ['category' => $cat->id]);
        quiz_add_quiz_question($essay->id, $quiz);

        $cm      = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        set_config('enabled_cm_' . $cm->id, $cmenabled ? 1 : 0, 'plagiarism_essayguard');

        return ['course' => $course, 'quiz' => $quiz, 'cm' => $cm, 'context' => $context];
    }

    /**
     * Start, answer and finish one quiz attempt as the given student, through the real
     * mod_quiz entry points.
     *
     * The attempt is finished while whoever the caller has set as the current user is
     * logged in, which is how the teacher-submits-for-a-student and cron-submits-an-
     * overdue-attempt cases are reproduced.
     *
     * @param \stdClass $quiz    The quiz record.
     * @param \stdClass $student The student the attempt belongs to.
     * @param string    $answer  The essay answer to submit for slot 1.
     * @param bool      $finish  Whether to finish the attempt.
     * @return \mod_quiz\quiz_attempt The attempt object.
     */
    protected function make_quiz_attempt(
        \stdClass $quiz,
        \stdClass $student,
        string $answer,
        bool $finish = true
    ): \mod_quiz\quiz_attempt {
        $quizobj = \mod_quiz\quiz_settings::create($quiz->id, $student->id);
        $quba    = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

        $timenow = time();
        $attempt = quiz_create_attempt($quizobj, 1, false, $timenow, false, $student->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow);
        quiz_attempt_save_started($quizobj, $quba, $attempt);

        $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions($timenow, false, [
            1 => ['answer' => $answer, 'answerformat' => FORMAT_HTML],
        ]);

        if ($finish) {
            $this->finish_quiz_attempt((int)$attempt->id, $timenow);
        }

        return \mod_quiz\quiz_attempt::create($attempt->id);
    }

    /**
     * Submit a quiz attempt through whichever entry point the running branch provides.
     *
     * Moodle 5.2 split quiz_attempt::process_finish() into process_submit() and
     * process_grade_submission() and deprecated the old method; 4.5 has only the old one.
     * Either way the branch's own code fires \mod_quiz\event\attempt_submitted.
     *
     * @param int $attemptid The quiz attempt to submit.
     * @param int $timenow   The time to record the submission at.
     * @return void
     */
    protected function finish_quiz_attempt(int $attemptid, int $timenow): void {
        $attemptobj = \mod_quiz\quiz_attempt::create($attemptid);
        if (method_exists($attemptobj, 'process_submit')) {
            $attemptobj->process_submit($timenow, false);
            \mod_quiz\quiz_attempt::create($attemptid)->process_grade_submission($timenow);
            return;
        }
        $attemptobj->process_finish($timenow, false);
    }

    /**
     * Delete a course module through whichever entry point the running branch provides.
     *
     * Moodle 5.2 moved this to core_courseformat\local\cmactions::delete() and deprecated
     * the global course_delete_module(); 4.5 has only the global function.
     *
     * @param int $cmid     The course module to delete.
     * @param int $courseid The course it belongs to.
     * @return void
     */
    protected function delete_course_module(int $cmid, int $courseid): void {
        if (method_exists('\core_courseformat\local\cmactions', 'delete')) {
            \core_courseformat\formatactions::cm($courseid)->delete($cmid);
            return;
        }
        course_delete_module($cmid);
    }

    /**
     * Save an online-text assignment submission for a student and return the submission.
     *
     * @param array     $act     The activity as returned by create_essayguard_assign().
     * @param \stdClass $student The submitting student.
     * @param string    $text    The online text, as the editor would store it.
     * @return array{assign: \assign, submission: \stdClass}
     */
    protected function save_online_text(array $act, \stdClass $student, string $text): array {
        global $DB;

        $assignobj  = new \assign($act['context'], $act['cm'], $act['course']);
        $submission = $assignobj->get_user_submission($student->id, true);
        $DB->insert_record('assignsubmission_onlinetext', (object)[
            'assignment'    => $act['assign']->id,
            'submission'    => $submission->id,
            'onlinetext'    => $text,
            'onlineformat'  => FORMAT_HTML,
        ]);

        return ['assign' => $assignobj, 'submission' => $submission];
    }

    /**
     * Store one telemetry event exactly as log_event would.
     *
     * @param int    $userid     The student the event belongs to.
     * @param int    $cmid       The course module.
     * @param int    $contextid  The module context.
     * @param string $attemptkey The typing session key.
     * @param string $eventname  The tracker event name.
     * @param int    $eventtime  The browser timestamp, IN MILLISECONDS.
     * @param array  $payload    The event payload.
     * @return int The id of the inserted row.
     */
    protected function add_event(
        int $userid,
        int $cmid,
        int $contextid,
        string $attemptkey,
        string $eventname,
        int $eventtime,
        array $payload = []
    ): int {
        global $DB;
        return (int)$DB->insert_record('plagiarism_essayguard_ev', (object)[
            'userid'      => $userid,
            'cmid'        => $cmid,
            'contextid'   => $contextid,
            'attemptkey'  => $attemptkey,
            'eventname'   => $eventname,
            'eventtime'   => $eventtime,
            'payloadjson' => json_encode($payload),
            'timecreated' => time(),
        ]);
    }

    /**
     * Store a short, ordinary-looking typing stream so score_attempt() has something to
     * read. The exact numbers do not matter to the tests that use this: what matters is
     * that events EXIST for the key, because that is what the observer is deciding on.
     *
     * @param int    $userid     The student the events belong to.
     * @param int    $cmid       The course module.
     * @param int    $contextid  The module context.
     * @param string $attemptkey The typing session key.
     * @param int    $count      How many keystroke events to write.
     * @param int    $qslot      Question slot to tag the events with; 0 leaves them untagged.
     * @return void
     */
    protected function add_typing_stream(
        int $userid,
        int $cmid,
        int $contextid,
        string $attemptkey,
        int $count = 40,
        int $qslot = 0
    ): void {
        $base = 1750000000000;
        for ($i = 0; $i < $count; $i++) {
            $payload = ['ikd' => 180 + ($i % 7) * 20];
            if ($qslot > 0) {
                $payload['qslot'] = $qslot;
            }
            $this->add_event(
                $userid,
                $cmid,
                $contextid,
                $attemptkey,
                'keydown',
                $base + ($i * 200),
                $payload
            );
        }
    }

    /**
     * Record the attempt key against a user the way inject_tracker() does, so the
     * observer can find it.
     *
     * @param int    $userid     The student.
     * @param int    $cmid       The course module.
     * @param string $attemptkey The typing session key.
     * @return void
     */
    protected function set_attemptkey_preference(int $userid, int $cmid, string $attemptkey): void {
        set_user_preference('essayguard_ak_' . $cmid, $attemptkey, $userid);
    }

    /**
     * Insert a score row directly, for tests about what reads score rows.
     *
     * @param array $overrides Column values to override the defaults with.
     * @return int The id of the inserted row.
     */
    protected function create_score(array $overrides = []): int {
        global $DB;

        $record = (object)array_merge([
            'userid'       => 0,
            'cmid'         => 0,
            'contextid'    => 0,
            'attemptkey'   => 'egtestkey',
            'qslot'        => 0,
            'riskscore'    => 0,
            'risklevel'    => 'low',
            'metricsjson'  => '{}',
            'timemodified' => time(),
        ], $overrides);

        return (int)$DB->insert_record('plagiarism_essayguard_sc', $record);
    }
}
