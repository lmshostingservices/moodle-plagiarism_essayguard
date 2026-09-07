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
 * plagiarism_essayguard file.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_essayguard\external;

defined('MOODLE_INTERNAL') || die();

// V1.2.226 FIX-EG-EXTERNAL-API-NAMESPACE: was `require_once($CFG->libdir/externallib.php)`
// plus the global `external_api` aliases. That file's own header says it "will be
// deprecated from Moodle 4.6" (= 5.0) and survives only as a back-compatibility shim;
// when core removes it, every web service call here becomes "Class external_api not
// found" - the exact failure FIX-EG-EXTERNAL-API-CLASS (v1.2.141) was written to stop,
// arriving from the other direction. That fix was right for its time: the global aliases
// really do need externallib.php loaded.
//
// The permanent answer is the \core_external\ namespace, which has existed since Moodle
// 4.2 and needs no require_once at all - the classes autoload. Since v1.0.85/v1.2.225
// this plugin requires Moodle 4.5, so the namespaced classes are always present and the
// shim is not needed on any supported branch.

use context_module;
use core_text;
use invalid_parameter_exception;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use plagiarism_essayguard\local\service\analyser;

require_once(__DIR__ . '/../../lib.php');

/**
 * External function that receives batches of typing telemetry from the browser.
 *
 * The plagiarism_essayguard/tracker AMD module records keystroke, paste, focus and
 * pause events while a student writes an essay answer, and flushes them here every
 * few seconds. Each accepted event is stored in plagiarism_essayguard_ev keyed by
 * attempt key, and the analyser later turns those rows into a risk score.
 *
 * Because the caller is the student's own browser, everything arriving here is
 * untrusted: the constants below cap how many events, how large a payload and how
 * long an attempt key one call may carry.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class log_event extends external_api {
    /**
     * v1.2.219: Hard limits on what one call may carry.
     *
     * MAX_EVENTS: the events array used to be an unbounded external_multiple_structure.
     * A student could POST 100,000 events in one call and the server would loop
     * insert_record() over every one of them. 500 is ~40x the largest legitimate 5-second
     * flush; anything above it is not a browser, it is someone with curl.
     *
     * @var int Maximum number of telemetry events accepted in a single call.
     */
    const MAX_EVENTS = 500;

    /**
     * MAX_PAYLOAD_BYTES: payloadjson is an unfiltered JSON string (see the parameter
     * declaration below) and goes straight into a TEXT column.
     * A multi-megabyte payload per event, times 500 events, is a trivial way to fill a
     * client's database. Real payloads are a few hundred bytes.
     *
     * @var int Maximum size in bytes of one event's payloadjson.
     */
    const MAX_PAYLOAD_BYTES = 2048;

    /**
     * MAX_ATTEMPTKEY_LEN: the column is char(64). PARAM_ALPHANUMEXT imposes no length
     * limit, so a longer key overflowed the column and produced an uncaught
     * dml_write_exception (a 500 to the student, and on MySQL in non-strict mode a
     * SILENTLY TRUNCATED key, which detaches the telemetry from the attempt).
     *
     * @var int Maximum accepted length of an attempt key, matching the char(64) column.
     */
    const MAX_ATTEMPTKEY_LEN = 64;

    /**
     * V1.2.229 FIX-EG-LOGEVENT-EVENTNAME-LEN: the same hole v1.2.219 closed for
     * attemptkey, still open one field along. eventname is PARAM_ALPHAEXT, which bounds
     * the character set but not the length, and it lands in a char(32) column. A client
     * posting a 200-character event name got an uncaught dml_write_exception — a 500 back
     * to the student's browser mid-attempt, with the whole batch lost — or, on a
     * non-strict MySQL, a row silently truncated to 32 characters. tracker.js only ever
     * sends names of at most 13 characters, so nothing legitimate is refused.
     *
     * @var int Maximum accepted length of an event name, matching the char(32) column.
     */
    const MAX_EVENTNAME_LEN = 32;

    /** v1.2.219: Minimum seconds between full re-scoring passes for one attempt. */
    const SCORE_THROTTLE_SECONDS = 60;

    /**
     * Describe the arguments accepted by the log_event web service.
     *
     * @return external_function_parameters The cmid, attemptkey and telemetry event list.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'       => new external_value(PARAM_INT, 'Course module id'),
            'attemptkey' => new external_value(PARAM_ALPHANUMEXT, 'Typing session key'),
            'events'     => new external_multiple_structure(
                new external_single_structure([
                    'eventname'   => new external_value(PARAM_ALPHAEXT, 'Event name'),
                    'eventtime'   => new external_value(PARAM_INT, 'Client event timestamp'),
                    'payloadjson' => new external_value(PARAM_RAW, 'JSON payload'), // pipeline-ignore: PARAM_RAW - JSON blob, size-capped at MAX_PAYLOAD_BYTES and json_decode()'d before use; never rendered.
                ]),
                'Telemetry events; at most ' . self::MAX_EVENTS . ' per call',
                VALUE_REQUIRED,
                [],
                NULL_NOT_ALLOWED
            ),
        ]);
    }

    /**
     * Store a flushed batch of typing telemetry and re-score the session if due.
     *
     * Called by tracker.js at the configured flush interval. Scoring is throttled to
     * once every SCORE_THROTTLE_SECONDS so a fast flush interval cannot turn every
     * keystroke batch into a full analysis pass.
     *
     * @param int    $cmid       The course module the events belong to.
     * @param string $attemptkey The typing session key, at most 64 characters.
     * @param array  $events     Telemetry events, each with eventname, eventtime and
     *                           payloadjson; at most MAX_EVENTS per call.
     * @return array ok, ignored, and the current score and level for staff callers.
     */
    public static function execute(int $cmid, string $attemptkey, array $events): array {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'cmid'       => $cmid,
                'attemptkey' => $attemptkey,
                'events'     => $events,
                ]
        );

        $cm      = get_coursemodule_from_id(null, $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_login($cm->course, false, $cm);

        /* ── v1.2.219: TELEMETRY WAS ENTIRELY CLIENT-ASSERTED ──────────────────────── */
        // Everything below this comment used to be missing. The server accepted whatever
        // attemptkey the client named, in whatever quantity, and wrote it under $USER->id
        // without ever asking whether that key belonged to that user or to a real attempt.
        // Three separate holes, closed here.

        /* (1) Length. attemptkey lands in a char(64) column. PARAM_ALPHANUMEXT does not
         * bound length, so an over-long key was either a hard dml_write_exception or,
         * on a non-strict MySQL, a silent truncation that orphaned the telemetry.
         */
        $attemptkey = $params['attemptkey'];
        if ($attemptkey === '' || core_text::strlen($attemptkey) > self::MAX_ATTEMPTKEY_LEN) {
            throw new invalid_parameter_exception(
                'attemptkey must be 1-' . self::MAX_ATTEMPTKEY_LEN . ' characters'
            );
        }

        /* (2) Ownership. For quizzes the tracker names the key 'qa_<quiz_attempts.id>'.
         * Nothing verified that the row exists or that it belongs to the caller, so a
         * student could POST a fabricated clean typing stream under ANOTHER student's
         * attempt key and overwrite their risk evidence — or manufacture a spotless
         * stream under a key of their own choosing and have it scored as genuine.
         * Now a qa_* key must resolve to a quiz_attempts row owned by $USER.
         */
        if (preg_match('/^qa_(\d+)$/', $attemptkey, $m)) {
            $ownsattempt = $DB->record_exists(
                'quiz_attempts',
                [
                    'id'     => (int)$m[1],
                    'userid' => $USER->id,
                    ]
            );
            if (!$ownsattempt) {
                throw new invalid_parameter_exception('attemptkey does not resolve to an attempt owned by this user');
            }
        }

        /* (3) Volume. Cap the batch and each payload. Without these a single POST could
         * insert 100,000 rows or several MB per event into the client's database.
         */
        $events = $params['events'];
        if (count($events) > self::MAX_EVENTS) {
            throw new invalid_parameter_exception(
                'too many events in one call (max ' . self::MAX_EVENTS . ')'
            );
        }
        foreach ($events as $event) {
            if (strlen((string)$event['payloadjson']) > self::MAX_PAYLOAD_BYTES) {
                throw new invalid_parameter_exception(
                    'payloadjson exceeds ' . self::MAX_PAYLOAD_BYTES . ' bytes'
                );
            }
            // V1.2.229 FIX-EG-LOGEVENT-EVENTNAME-LEN — see the constant.
            $name = (string)$event['eventname'];
            if ($name === '' || core_text::strlen($name) > self::MAX_EVENTNAME_LEN) {
                throw new invalid_parameter_exception(
                    'eventname must be 1-' . self::MAX_EVENTNAME_LEN . ' characters'
                );
            }
        }
        /* ──────────────────────────────────────────────────────────────────────────── */

        // V1.2.219: A student must not be handed their own integrity score. See the
        // return block at the end of this method for the full reasoning.
        $canviewscore = has_capability('plagiarism/essayguard:viewreport', $context);

        // FIX-EG-SESSION-LOCK (v1.2.58): Release the PHP session lock before
        // performing any DB writes or running analyser::score_attempt(). Without
        // this, Moodle's session handler holds an exclusive file lock for the
        // entire duration of the request. Because tracker.js flushes every 5 s,
        // a slow scoring pass (reading all events + scoring + writing the score
        // record) could block a second flush that arrived before the first one
        // completed, causing the student's browser to queue AJAX calls and
        // potentially lose events. Closing the session write handle here frees
        // the lock so concurrent requests from the same student session proceed
        // in parallel without waiting.
        \core\session\manager::write_close();

        // FIX-EG-ENABLED-CHECK-INCONSISTENT (v1.2.94): get_config() returns PHP false
        // when the key has never been saved (fresh install). !false = true → this
        // early-exit fired on fresh installs, causing log_event to return 'ignored: true'
        // without storing any events in plagiarism_essayguard_ev. The JS queue then
        // cleared those events thinking they were safely persisted — events were silently
        // lost, and no score record could ever be produced.
        // inject_tracker() was corrected in v1.2.88; applying the same fix here.
        $globalenabled = get_config('plagiarism_essayguard', 'enabled');
        if ($globalenabled !== false && empty($globalenabled)) {
            return self::result($canviewscore, true, 0.0, 'low');
        }

        /*
         * V1.2.229 FIX-EG-WS-CM-GATE: the per-activity gate was missing here and in
         * finalize_attempt. Both web services checked the SITE-wide switch and the licence
         * and then wrote telemetry and score rows for any course module the caller named.
         *
         * A teacher who unticks "Enable Essay Guard" on their assignment, or an admin who
         * turns it off for one quiz, has said the plugin must not watch that activity. But
         * inject_tracker() only decides whether to inject at PAGE LOAD: every student who
         * already had the page open keeps a live tracker, keeps flushing every five
         * seconds, and every one of those flushes was accepted. The result was a badge in
         * the class report for an activity the teacher had switched off - and, since
         * log_event also scores, a full risk assessment of a student on an activity nobody
         * consented to have assessed.
         *
         * Gated in the same place as the site-wide switch and BEFORE the events are
         * stored, because this is the same kind of decision: an explicit instruction from
         * a person with the authority to give it. (The unlock check below is deliberately
         * still after storage - that one is about vendor credentials, not intent.)
         */
        if (!\plagiarism_essayguard_is_cm_active((int)$cm->id)) {
            return self::result($canviewscore, true, 0.0, 'low');
        }

        // FIX-EG-EVENTS-BEFORE-UNLOCK (v1.2.95): Store events BEFORE checking unlock
        // status. Previously if check_unlock() returned false (wrong credentials, credits
        // exhausted), events were discarded with 'ignored:true'. The JS queue then spliced
        // out those events assuming they were safely stored — all behavioral telemetry was
        // permanently lost. Even after fixing credentials, there was no data to re-score.
        //
        // Fix: always write events to plagiarism_essayguard_ev regardless of unlock result
        // (events are audit data; the enabled check above already gates the admin's intent).
        // Scoring is still gated behind check_unlock() below — only the storage is moved.
        // The PHP observer (observer.php) also runs after submit and will score correctly
        // once the unlock issue is resolved and the 30-min cache is refreshed.
        //
        // v1.2.219: one insert_records() batch instead of a loop of insert_record().
        // The loop was one round-trip per event; a 500-event flush from 200 students
        // sitting an exam is 100,000 individual INSERT statements. insert_records()
        // batches them into a single multi-row insert per chunk.
        $now = time();
        $batch = [];
        foreach ($events as $event) {
            $batch[] = (object)[
                'userid'      => $USER->id,
                'cmid'        => $cm->id,
                'contextid'   => $context->id,
                'attemptkey'  => $attemptkey,
                'eventname'   => $event['eventname'],
                'eventtime'   => $event['eventtime'],
                'payloadjson' => $event['payloadjson'],
                'timecreated' => $now,
            ];
        }
        if (!empty($batch)) {
            $DB->insert_records('plagiarism_essayguard_ev', $batch);
        }

        if (!plagiarism_essayguard_check_unlock()) {
            return self::result($canviewscore, true, 0.0, 'low');
        }

        /* ── v1.2.219: SCORING THROTTLE ────────────────────────────────────────────── */
        // This method used to run a FULL re-score on every single 5-second flush: once
        // per live question slot, plus an aggregate pass. Each score_attempt() re-reads
        // EVERY event ever recorded for the attempt and recomputes ~13 signals over them.
        // On a 5-question quiz that is 6 full re-scores every 5 seconds per student, and
        // the cost grows as the event table grows during the exam — so it is worst
        // exactly when the room is fullest. With 200 students that is ~240 full re-scores
        // per second against a table that is simultaneously being written to.
        //
        // Nothing needed it: the authoritative scoring passes are finalize_attempt() and
        // observer::on_quiz_attempt_submitted(), both at submission. The live score only
        // ever fed the badge the student saw — which they should not be seeing anyway
        // (see the return block below).
        //
        // Live scoring is kept, because get_links() can show a mid-attempt badge to a
        // teacher watching a live exam, but throttled to at most once per 60 s per
        // attempt. The marker is a user preference rather than a DB row so the throttle
        // costs nothing.
        //
        // MIGRATION CONSEQUENCE: a teacher refreshing the grading page mid-attempt may
        // see a badge up to 60 seconds out of date. Final scores are unaffected.
        $throttlekey = 'essayguard_lastscore_' . $cm->id;
        $lastscored  = (int)get_user_preferences($throttlekey, 0, $USER->id);
        if (($now - $lastscored) < self::SCORE_THROTTLE_SECONDS) {
            // Empty risklevel, not 'low' — this call did not score, so it has no answer.
            // Returning 'low' here would let a teacher's live view render a false LOW.
            return self::result($canviewscore, false, 0.0, '');
        }
        set_user_preference($throttlekey, $now, $USER->id);

        // FIX-EG-PERQ-FIRST (v1.2.125): Score per distinct qslot BEFORE aggregate
        // so that analyser::score_attempt() FIX-EG-AGG-PERQ-CONSISTENCY can read
        // the per-question records when computing the aggregate score.
        //
        // FIX-EG-PER-SLOT-LIVE (v1.2.61): Per-question records are written here during
        // the live quiz so plagiarism_get_links() can show per-question badges without
        // waiting for the PHP observer to run at submission time.
        $liveqslots = [];
        foreach ($events as $event) {
            $payload = json_decode($event['payloadjson'] ?? '{}', true) ?: [];
            $qslot = (int)($payload['qslot'] ?? 0);
            if ($qslot > 0 && !in_array($qslot, $liveqslots, true)) {
                $liveqslots[] = $qslot;
            }
        }
        foreach ($liveqslots as $qslot) {
            analyser::score_attempt($USER->id, $cm->id, $context->id, $attemptkey, [], '', $qslot);
        }

        // Aggregate score (qslot = 0) — scored LAST so FIX-EG-AGG-PERQ-CONSISTENCY
        // can read per-question records written above.
        $result = analyser::score_attempt($USER->id, $cm->id, $context->id, $attemptkey);

        return self::result($canviewscore, false, (float)$result['riskscore'], $result['risklevel']);
    }

    /**
     * v1.2.219: THE PLUGIN WAS HANDING THE STUDENT A LIVE INTEGRITY ORACLE.
     *
     * This method returned riskscore and risklevel to the browser on EVERY 5-second
     * flush. A student could type a sentence, watch the number move, undo, retype it
     * differently, and iterate until the badge read LOW — turning a detector into a
     * tuning instrument. It also told the people being detected which of their
     * behaviours the detector reacts to, which is the one thing the heuristics depend on
     * not being known.
     *
     * The score is now returned only to a viewer holding
     * plagiarism/essayguard:viewreport in this context — a teacher watching a live exam.
     * The student path gets 'ok' and 'ignored' and nothing else. The response STRUCTURE
     * is unchanged (Moodle's external API validates returns against a fixed structure),
     * so the fields are present but zeroed on the student path; tracker.js was updated to
     * treat a zeroed/absent score as "no badge" rather than as a genuine LOW.
     *
     * MIGRATION CONSEQUENCE: students no longer see a live risk badge while typing, and
     * no longer see one after submitting. This is the intended behaviour of an integrity
     * tool and matches what teachers assume is happening.
     *
     * @param bool   $canviewscore Whether the caller holds the viewreport capability.
     * @param bool   $ignored      Whether the event batch was stored but not scored.
     * @param float  $riskscore    Computed risk score.
     * @param string $risklevel    Computed risk level.
     * @return array
     */
    private static function result(bool $canviewscore, bool $ignored, float $riskscore, string $risklevel): array {
        return [
            'ok'        => true,
            'ignored'   => $ignored,
            'riskscore' => $canviewscore ? $riskscore : 0.0,
            'risklevel' => $canviewscore ? $risklevel : '',
        ];
    }

    /**
     * Describe the value returned by the log_event web service.
     *
     * @return external_single_structure The acknowledgement structure.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok'        => new external_value(PARAM_BOOL, 'Success flag'),
            'ignored'   => new external_value(PARAM_BOOL, 'Plugin disabled or skipped'),
            // V1.2.219: Populated only for callers with plagiarism/essayguard:viewreport.
            // Students receive 0.0 / '' — see result() for why.
            'riskscore' => new external_value(PARAM_FLOAT, 'Current risk score (staff only; 0 for students)'),
            'risklevel' => new external_value(PARAM_TEXT, 'Current risk level (staff only; empty for students)'),
        ]);
    }
}
