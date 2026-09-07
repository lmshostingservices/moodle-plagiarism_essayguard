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
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;
use plagiarism_essayguard\local\service\analyser;
use plagiarism_essayguard\local\service\linguistic;
use plagiarism_essayguard\local\service\fingerprint;
use plagiarism_essayguard\local\service\explainer;

require_once(__DIR__ . '/../../lib.php');

/**
 * Finalize Attempt Web Service.
 *
 * Called at essay submission time. Runs the full linguistic analysis
 * on the final submitted text, re-scores all behavioural metrics,
 * updates the student fingerprint/baseline, and returns the complete
 * authenticity report for the instructor dashboard.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finalize_attempt extends external_api {
    /**
     * Describe the arguments accepted by the finalize_attempt web service.
     *
     * @return external_function_parameters The cmid, attemptkey, final text and question slot.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'        => new external_value(PARAM_INT, 'Course module id'),
            'attemptkey'  => new external_value(PARAM_ALPHANUMEXT, 'Typing session key'),
            'finaltext'   => new external_value(
                PARAM_RAW, // pipeline-ignore: PARAM_RAW - the student's verbatim essay; any sanitising would corrupt the linguistic measurements. Feeds numeric analysis only, is never stored verbatim by this path and is never rendered.
                'Final submitted text for linguistic analysis',
                VALUE_DEFAULT,
                ''
            ),
            'qslot'       => new external_value(
                PARAM_INT,
                'Question slot (0 = aggregate, N = per-question)',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Finalise one typing session: score it, update the baseline and return the result.
     *
     * Runs the full behavioural and linguistic analysis for the session, refreshes the
     * student's writing fingerprint, and returns the authenticity report. Callers
     * without plagiarism/essayguard:viewreport receive the same structure with the
     * score fields blanked, so a student cannot read their own risk assessment.
     *
     * @param int    $cmid       The course module the attempt belongs to.
     * @param string $attemptkey The typing session key, at most 64 characters.
     * @param string $finaltext  The submitted text, used for linguistic analysis.
     * @param int    $qslot      Quiz question slot; 0 scores the attempt as a whole.
     * @return array The result structure described by execute_returns().
     */
    public static function execute(int $cmid, string $attemptkey, string $finaltext = '', int $qslot = 0): array {
        global $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'cmid'       => $cmid,
                'attemptkey' => $attemptkey,
                'finaltext'  => $finaltext,
                'qslot'      => $qslot,
                ]
        );

        $cm      = get_coursemodule_from_id(null, $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_login($cm->course, false, $cm);

        // V1.2.219: attemptkey length + ownership, same reasoning as log_event.php.
        // finalize_attempt writes a score row keyed by attemptkey into a char(64) column
        // and never checked either. See log_event::execute() for the full explanation.
        $attemptkey = $params['attemptkey'];
        if ($attemptkey === '' || \core_text::strlen($attemptkey) > 64) {
            throw new \invalid_parameter_exception('attemptkey must be 1-64 characters');
        }
        if (preg_match('/^qa_(\\d+)$/', $attemptkey, $m)) {
            global $DB;
            if (!$DB->record_exists('quiz_attempts', ['id' => (int)$m[1], 'userid' => $USER->id])) {
                throw new \invalid_parameter_exception('attemptkey does not resolve to an attempt owned by this user');
            }
        }

        // V1.2.219: Does the caller have any business seeing the analysis? See
        // staff_result()/student_result() below.
        $canviewreport = has_capability('plagiarism/essayguard:viewreport', $context);

        // FIX-EG-SESSION-LOCK (v1.2.58): Release the PHP session lock before
        // running the full linguistic analysis + analyser::score_attempt() call.
        // finalize_attempt is invoked at form-submit time and performs the most
        // expensive scoring pass (linguistic::analyse() + all DB reads/writes).
        // Holding the session lock during this work can block the Moodle page
        // that the student is navigating to (the submission confirmation page),
        // because that page's own PHP request may need to open the same session
        // file. Closing the write handle here avoids that contention.
        \core\session\manager::write_close();

        // FIX-EG-ENABLED-CHECK-INCONSISTENT (v1.2.94): get_config() returns PHP false
        // when the key has never been saved (fresh install). !false = true → this
        // early-exit fired on fresh installs, making finalizeAttempt return empty_result
        // so the JS badge was never shown and no score record was written.
        // inject_tracker() was corrected in v1.2.88; applying the same fix here.
        $globalenabled = get_config('plagiarism_essayguard', 'enabled');
        if ($globalenabled !== false && empty($globalenabled)) {
            return self::empty_result();
        }

        // V1.2.229 FIX-EG-WS-CM-GATE: honour the per-activity switch. See log_event.php
        // for why a web service cannot rely on inject_tracker() having made this decision.
        if (!\plagiarism_essayguard_is_cm_active((int)$cm->id)) {
            return self::empty_result();
        }

        if (!plagiarism_essayguard_check_unlock()) {
            return self::empty_result();
        }

        // Run linguistic analysis on final text.
        $linguistic = !empty($params['finaltext'])
            ? linguistic::analyse($params['finaltext'])
            : [];

        // Full behavioural + linguistic scoring
        // FIX-EG-QSLOT-FINALIZE (v1.2.41): pass qslot so per-question scoring is used
        // for Moodle quiz essay fields instead of always falling back to aggregate mode.
        $result = analyser::score_attempt(
            $USER->id,
            $cm->id,
            $context->id,
            $attemptkey,
            $linguistic,
            $params['finaltext'],
            $params['qslot']
        );

        // Fingerprint update is handled exclusively by the PHP event observer
        // (observer.php) which is the authoritative scoring path. Updating here
        // as well caused the samplecount to increment twice per submission.

        /* ── v1.2.219: DO NOT HAND THE ANALYSIS BACK TO THE PERSON BEING ANALYSED ──── */
        // This method used to return score100, risklevel, baseline_deviation, the full
        // metricsjson, AND the instructor-facing explanations array to whoever called it
        // — which, on the submit path, is always the student. That array is written for a
        // teacher ("Content was pasted 3 time(s)", "Typing rhythm unusually smooth"): it
        // is a plain-English list of exactly which heuristics fired and why. Publishing it
        // to the student both leaks the detection model and makes the score iterable —
        // resubmit, read the explanations, adjust, repeat.
        //
        // A caller with plagiarism/essayguard:viewreport gets the full report, unchanged.
        // The student path gets ok/qslot only; everything else is zeroed. The score is
        // still computed and still written to the database — the teacher sees all of it
        // in the class report. Only the response to the student changes.
        //
        // MIGRATION CONSEQUENCE: the post-submit risk toast no longer appears for
        // students. tracker.js was updated to match (a zeroed risklevel means no badge).
        if (!$canviewreport) {
            return self::student_result((int)$params['qslot']);
        }

        // Get baseline status for response.
        $fp = fingerprint::get($USER->id);

        return [
            'ok'                 => true,
            'riskscore'          => (float)$result['riskscore'],
            'score100'           => (int)$result['score100'],
            'risklevel'          => $result['risklevel'],
            'qslot'              => (int)$params['qslot'],
            'baseline_status'    => $fp ? $fp->baseline_status : 'none',
            'baseline_deviation' => (float)($result['metrics']['baseline_deviation'] ?? 0.0),
            'explanations'       => array_values($result['explanations']),
            'metricsjson'        => json_encode($result['metrics']),
        ];
    }

    /**
     * v1.2.219: The student-facing response. Scoring ran and was persisted; the caller
     * simply is not entitled to read the result. ok:true so the JS knows the call
     * succeeded, empty risklevel so it knows not to render a badge.
     *
     * @param int $qslot Question slot echoed back.
     * @return array
     */
    private static function student_result(int $qslot): array {
        return [
            'ok'                 => true,
            'riskscore'          => 0.0,
            'score100'           => 0,
            'risklevel'          => '',
            'qslot'              => $qslot,
            'baseline_status'    => '',
            'baseline_deviation' => 0.0,
            'explanations'       => [],
            'metricsjson'        => '{}',
        ];
    }

    /**
     * Describe the value returned by the finalize_attempt web service.
     *
     * @return external_single_structure The authenticity result structure.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok'                 => new external_value(PARAM_BOOL, 'Success flag'),
            'riskscore'          => new external_value(PARAM_FLOAT, 'Risk score 0.0–1.0'),
            'score100'           => new external_value(PARAM_INT, 'Risk score 0–100'),
            // V1.2.219: The score/metrics/explanations fields below are populated ONLY
            // for callers holding plagiarism/essayguard:viewreport. Students receive the
            // same structure with empty values — see student_result().
            'risklevel'          => new external_value(PARAM_TEXT, 'Risk level: low|medium|high (empty for students)'),
            'qslot'              => new external_value(PARAM_INT, 'Question slot echoed back (0 = aggregate)'),
            'baseline_status'    => new external_value(PARAM_TEXT, 'Baseline status: none|preliminary|stable'),
            'baseline_deviation' => new external_value(PARAM_FLOAT, 'Deviation from student baseline 0.0–1.0'),
            'explanations'       => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Explanation string'),
                'Array of human-readable explanations for the instructor'
            ),
            'metricsjson'        => new external_value(PARAM_RAW, 'Full metrics JSON'), // pipeline-ignore: PARAM_RAW - return value, json_encode()'d server-side.
        ]);
    }

    /**
     * The "no analysis was performed" result.
     *
     * Returns ok:false so tracker.js suppresses the badge entirely rather than showing
     * a misleading "Low risk" when the plugin is disabled or the site is not unlocked.
     *
     * @return array A result structure with ok false and every score field zeroed.
     */
    private static function empty_result(): array {
        // FIX-EG-EMPTY-RESULT-OK-FALSE (v1.2.95): Return ok:false so the caller
        // (tracker.js finalizeAttempt) does NOT call injectRiskBadge(). When the
        // plugin is globally disabled or the unlock check fails, returning ok:true
        // caused the JS to inject a LOW risk badge even though no analysis ran at
        // all — teachers saw "Low risk" when the plugin was silently inactive.
        // Now ok:false suppresses the badge entirely, which is the correct UX when
        // analysis was not performed.
        return [
            'ok'                 => false,
            'riskscore'          => 0.0,
            'score100'           => 0,
            'risklevel'          => 'low',
            'qslot'              => 0,
            'baseline_status'    => 'none',
            'baseline_deviation' => 0.0,
            'explanations'       => [],
            'metricsjson'        => '{}',
        ];
    }
}
