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

// FIX-EG-EXTERNAL-API-CLASS (v1.2.141): see log_event.php for full explanation.
// FIX-EG-CFG-SCOPE (v1.2.142): see log_event.php for full explanation.
global $CFG;
require_once($CFG->libdir . '/externallib.php');

use context_module;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
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
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finalize_attempt extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'        => new external_value(PARAM_INT,        'Course module id'),
            'attemptkey'  => new external_value(PARAM_ALPHANUMEXT,'Typing session key'),
            'finaltext'   => new external_value(PARAM_TEXT,       'Final submitted text for linguistic analysis', VALUE_DEFAULT, ''),
            'qslot'       => new external_value(PARAM_INT,        'Question slot (0 = aggregate, N = per-question)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $cmid, string $attemptkey, string $finaltext = '', int $qslot = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'       => $cmid,
            'attemptkey' => $attemptkey,
            'finaltext'  => $finaltext,
            'qslot'      => $qslot,
        ]);

        $cm      = get_coursemodule_from_id(null, $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_login($cm->course, false, $cm);

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
        $global_enabled = get_config('plagiarism_essayguard', 'enabled');
        if ($global_enabled !== false && empty($global_enabled)) {
            return self::empty_result();
        }

        if (!plagiarism_essayguard_check_unlock()) {
            return self::empty_result();
        }

        // Run linguistic analysis on final text
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
            $params['attemptkey'],
            $linguistic,
            $params['finaltext'],
            $params['qslot']
        );

        // Fingerprint update is handled exclusively by the PHP event observer
        // (observer.php) which is the authoritative scoring path. Updating here
        // as well caused the samplecount to increment twice per submission.

        // Get baseline status for response
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

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok'                 => new external_value(PARAM_BOOL,  'Success flag'),
            'riskscore'          => new external_value(PARAM_FLOAT, 'Risk score 0.0–1.0'),
            'score100'           => new external_value(PARAM_INT,   'Risk score 0–100'),
            'risklevel'          => new external_value(PARAM_TEXT,  'Risk level: low|medium|high'),
            'qslot'              => new external_value(PARAM_INT,   'Question slot echoed back (0 = aggregate)'),
            'baseline_status'    => new external_value(PARAM_TEXT,  'Baseline status: none|preliminary|stable'),
            'baseline_deviation' => new external_value(PARAM_FLOAT, 'Deviation from student baseline 0.0–1.0'),
            'explanations'       => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Explanation string'),
                'Array of human-readable explanations for the instructor'
            ),
            'metricsjson'        => new external_value(PARAM_RAW, 'Full metrics JSON'), // pipeline-ignore: PARAM_RAW — JSON blob, json_decode()'d by caller.
        ]);
    }

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
