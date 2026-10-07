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

namespace plagiarism_essayguard\task;

use plagiarism_essayguard\local\service\analyser;
use plagiarism_essayguard\local\service\fingerprint;
use plagiarism_essayguard\observer;

/**
 * Ad-hoc task that scores one submitted attempt outside the submission request.
 *
 * Queued by the quiz attempt_submitted observer, so a student's "Submit all and
 * finish" is not held up by per-question linguistic analysis. Until the task has run,
 * badges for the attempt render as "pending".
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class score_attempt extends \core\task\adhoc_task {
    /**
     * Queue scoring for one attempt. A duplicate of an already-queued task is ignored.
     *
     * @param int $userid The student who owns the attempt.
     * @param int $cmid The course module.
     * @param string $attemptkey The typing session key.
     * @param int $quizattemptid The quiz_attempts id, or 0 for a non-quiz session.
     * @return void
     */
    public static function queue(int $userid, int $cmid, string $attemptkey, int $quizattemptid): void {
        $task = new self();
        $task->set_component('plagiarism_essayguard');
        // Key order matters: is_pending() matches on the leading "cmid","userid" pair.
        $task->set_custom_data([
            'cmid' => $cmid,
            'userid' => $userid,
            'attemptkey' => $attemptkey,
            'quizattemptid' => $quizattemptid,
        ]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Whether scoring is queued but not yet done for a student in an activity.
     *
     * @param int $cmid The course module.
     * @param int $userid The student.
     * @return bool
     */
    public static function is_pending(int $cmid, int $userid): bool {
        global $DB;
        $pattern = '{"cmid":' . $cmid . ',"userid":' . $userid . ',%';
        return $DB->record_exists_select(
            'task_adhoc',
            'classname = :classname AND ' . $DB->sql_like('customdata', ':pattern'),
            ['classname' => '\\' . self::class, 'pattern' => $pattern]
        );
    }

    /**
     * Score the attempt: each quiz essay slot first, then the aggregate.
     *
     * @return void
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/plagiarism/essayguard/lib.php');

        // Cron runs many tasks in one process; never score from another task's snapshot.
        analyser::reset_caches();

        $data = $this->get_custom_data();
        $cmid = (int)$data->cmid;
        $userid = (int)$data->userid;
        $attemptkey = (string)$data->attemptkey;
        $quizattemptid = (int)$data->quizattemptid;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            mtrace('Essay Guard: course module or user no longer exists; nothing to score.');
            return;
        }
        $context = \context_module::instance($cmid);

        if ($quizattemptid > 0) {
            observer::score_quiz_attempt($quizattemptid, $userid, $cmid, $context->id, $attemptkey);
            return;
        }

        // Telemetry-only session (no submitted text available).
        $result = analyser::score_attempt($userid, $cmid, $context->id, $attemptkey, [], '', 0);
        fingerprint::update($userid, $result['metrics']);
        $contexttype = (string)($result['metrics']['baseline_contexttype'] ?? 'other');
        fingerprint::update_statistics($userid, $contexttype, $result['metrics']);
    }
}
