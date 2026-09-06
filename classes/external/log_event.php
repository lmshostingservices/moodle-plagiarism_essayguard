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
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace plagiarism_essayguard\external;

defined('MOODLE_INTERNAL') || die();

// FIX-EG-EXTERNAL-API-CLASS (v1.2.141): Moodle 4.2+ moved external_api and
// related classes to the \core_external\ namespace. The global 'external_api'
// alias only exists when lib/externallib.php is explicitly loaded. Without this
// require_once every web service call (tracker flush, finalize, get_badges)
// throws "Class external_api not found", causing Moodle's AJAX layer to return
// a generalexceptionmessage. tracker.js caught that error silently and rolled
// back — zero events ever reached plagiarism_essayguard_ev, all badges were LOW.
//
// FIX-EG-CFG-SCOPE (v1.2.142): PHP scoping rule — when a file is included from
// inside a function (e.g. Moodle's spl_autoload_register classloader), its
// top-level code runs in that function's local scope, not the global scope.
// $CFG is a global variable and is therefore invisible at file level when the
// autoloader includes this class file, causing a fatal "cannot access property
// on null" error. Every AJAX call then returns a PHP exception; tracker.js
// catches it silently, rolls back the queue, and zero events ever reach
// plagiarism_essayguard_ev. Fix: declare global $CFG before the require_once.
global $CFG;
require_once($CFG->libdir . '/externallib.php');

use context_module;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use plagiarism_essayguard\local\service\analyser;

require_once(__DIR__ . '/../../lib.php');

class log_event extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'       => new external_value(PARAM_INT, 'Course module id'),
            'attemptkey' => new external_value(PARAM_ALPHANUMEXT, 'Typing session key'),
            'events'     => new external_multiple_structure(
                new external_single_structure([
                    'eventname'   => new external_value(PARAM_ALPHAEXT, 'Event name'),
                    'eventtime'   => new external_value(PARAM_INT, 'Client event timestamp'),
                    'payloadjson' => new external_value(PARAM_RAW, 'JSON payload'), // pipeline-ignore: PARAM_RAW — JSON blob, json_decode()'d on store.
                ])
            ),
        ]);
    }

    public static function execute(int $cmid, string $attemptkey, array $events): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'       => $cmid,
            'attemptkey' => $attemptkey,
            'events'     => $events,
        ]);

        $cm      = get_coursemodule_from_id(null, $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_login($cm->course, false, $cm);

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
        $global_enabled = get_config('plagiarism_essayguard', 'enabled');
        if ($global_enabled !== false && empty($global_enabled)) {
            return ['ok' => true, 'ignored' => true, 'riskscore' => 0.0, 'risklevel' => 'low'];
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
        $now = time();
        foreach ($params['events'] as $event) {
            // FIX-EG-PAYLOAD-VALIDATE (v1.2.218): payloadjson arrives as an unfiltered string
            // because it is a JSON document, not free text. Decode and re-encode it here so only
            // well-formed JSON produced by json_encode() is ever written to the database.
            // Anything that is not a JSON object is stored as an empty object.
            $decodedpayload = json_decode($event['payloadjson'] ?? '{}', true);
            if (!is_array($decodedpayload)) {
                $decodedpayload = [];
            }

            $record = (object)[
                'userid'      => $USER->id,
                'cmid'        => $cm->id,
                'contextid'   => $context->id,
                'attemptkey'  => $params['attemptkey'],
                'eventname'   => $event['eventname'],
                'eventtime'   => $event['eventtime'],
                'payloadjson' => json_encode($decodedpayload),
                'timecreated' => $now,
            ];
            $DB->insert_record('plagiarism_essayguard_ev', $record);
        }

        if (!plagiarism_essayguard_check_unlock()) {
            return ['ok' => true, 'ignored' => true, 'riskscore' => 0.0, 'risklevel' => 'low'];
        }

        // FIX-EG-PERQ-FIRST (v1.2.125): Score per distinct qslot BEFORE aggregate
        // so that analyser::score_attempt() FIX-EG-AGG-PERQ-CONSISTENCY can read
        // the per-question records when computing the aggregate score.
        //
        // FIX-EG-PER-SLOT-LIVE (v1.2.61): Per-question records are written here during
        // the live quiz so plagiarism_get_links() can show per-question badges without
        // waiting for the PHP observer to run at submission time.
        $live_qslots = [];
        foreach ($params['events'] as $event) {
            $payload = json_decode($event['payloadjson'] ?? '{}', true) ?: [];
            $qslot = (int)($payload['qslot'] ?? 0);
            if ($qslot > 0 && !in_array($qslot, $live_qslots, true)) {
                $live_qslots[] = $qslot;
            }
        }
        foreach ($live_qslots as $qslot) {
            analyser::score_attempt($USER->id, $cm->id, $context->id, $params['attemptkey'], [], '', $qslot);
        }

        // Aggregate score (qslot = 0) — scored LAST so FIX-EG-AGG-PERQ-CONSISTENCY
        // can read per-question records written above.
        $result = analyser::score_attempt($USER->id, $cm->id, $context->id, $params['attemptkey']);

        return [
            'ok'        => true,
            'ignored'   => false,
            'riskscore' => (float)$result['riskscore'],
            'risklevel' => $result['risklevel'],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok'        => new external_value(PARAM_BOOL, 'Success flag'),
            'ignored'   => new external_value(PARAM_BOOL, 'Plugin disabled or skipped'),
            'riskscore' => new external_value(PARAM_FLOAT, 'Current risk score'),
            'risklevel' => new external_value(PARAM_TEXT, 'Current risk level'),
        ]);
    }
}
