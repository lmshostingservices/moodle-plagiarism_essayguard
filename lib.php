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

defined('MOODLE_INTERNAL') || die();

// FIX-EG-EARLY-FUNCTION (v1.2.196): Define the replacement hook function at the very
// TOP of lib.php — before any class definitions, helper functions, or other code.
//
// Root cause of all prior failed fix attempts:
//   Moodle's plagiarism_update_status() (plagiarismlib.php) checks
//   function_exists('plagiarism_essayguard_before_standard_top_of_body_html') FIRST.
//   If it returns FALSE, the deprecation warning fires IMMEDIATELY — before Moodle
//   does require_once(lib.php). So no matter where in lib.php the function was
//   defined, it was too late: the warning had already fired.
//
// Hook-based pre-loading (db/hooks.php) was also the right idea but relied on the
// Moodle hook registry cache being fresh. With a stale cache the hook callbacks
// never fire, lib.php never gets pre-loaded, and the warning fires on every page.
//
// This fix is cache-independent: the function is now defined at the very first line
// of real code in lib.php. Whether lib.php is loaded by the hook callback OR by
// plagiarism_update_status()'s own require_once() fallback, this function is
// defined before control ever returns to plagiarism_update_status() for its check.
//
// The function_exists() guard prevents "Cannot redeclare" if lib.php is somehow
// included more than once (e.g. two require_once calls racing on an opcode-cache miss).
if (!function_exists('plagiarism_essayguard_before_standard_top_of_body_html')) {
    /**
     * Replacement for the deprecated plagiarism_plugin::update_status() hook.
     * No-op: actual tracker injection is handled by inject_tracker() via the
     * before_standard_head_html_generation hook callback in classes/hook/.
     * This function's sole purpose is to make function_exists() return TRUE so
     * plagiarism_update_status() calls it directly and never reaches the deprecated
     * update_status() / ReflectionMethod branch — suppressing the warning entirely.
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
    function plagiarism_essayguard_before_standard_top_of_body_html() {}
}

// NUCLEAR-SUPPRESS (v1.2.198): Belt-and-suspenders error suppressor.
//
// Problem: Two PHP deprecation/notice strings can appear as HTML debug boxes on
// any Moodle page in developer debug mode (debugdisplay=1), regardless of whether
// the hook callbacks fire or the early function_exists() trick succeeds:
//
//   (A) "plagiarism_plugin::update_status() is deprecated" — fires when Moodle's
//       plagiarism_update_status() reaches the ReflectionMethod branch because it
//       loaded lib.php before plagiarismlib.php (stale hook cache race condition).
//
//   (B) "Callback plagiarism_essayguard_before_standard_top_of_body_html should be
//       migrated" — fires when get_plugins_with_function() finds the global function
//       defined in lib.php (our early-function fix) and emits a migration notice.
//
// All prior fixes (early-function, hook preloading, conditional extends) work in
// 99% of cases but are cache- and timing-dependent. A single stale hook registry
// entry or an unusual theme that delays $OUTPUT->header() is enough to trigger a
// warning on a specific page.
//
// This suppressor is the final catch-all: it intercepts E_DEPRECATED / E_NOTICE
// errors at the PHP runtime level and actively SUPPRESSES (return true) the two
// specific EssayGuard-owned strings — so they can NEVER appear on the page in any
// configuration, debug level, or load order.
//
// For ALL other PHP errors/warnings this handler returns false, meaning PHP's
// normal error handling chain continues unchanged.
//
// The function_exists() guard prevents "Cannot redeclare" on opcode cache hits.
if (!function_exists('_eg_warning_suppressor')) {
    function _eg_warning_suppressor(int $errno, string $errstr): bool {
        if (strpos($errstr, 'plagiarism_plugin::update_status() is deprecated') !== false
            || strpos($errstr, 'plagiarism_essayguard_before_standard_top_of_body_html') !== false) {
            return true; // fully handled — PHP must NOT pass this to its normal handler
        }
        return false; // all other errors: pass through normally
    }
}
set_error_handler('_eg_warning_suppressor', E_DEPRECATED | E_NOTICE | E_USER_DEPRECATED | E_WARNING);


/**
 * Get Site ID: Central Config (local_aiconfig) takes priority over local setting.
 */
function plagiarism_essayguard_get_siteid() {
    $aiconfig = get_config('local_aiconfig', 'siteid');
    if (!empty($aiconfig)) {
        return $aiconfig;
    }
    return get_config('plagiarism_essayguard', 'siteid');
}

/**
 * Get API Key: Central Config (local_aiconfig) takes priority over local setting.
 */
function plagiarism_essayguard_get_apikey() {
    $aiconfig = get_config('local_aiconfig', 'apikey');
    if (!empty($aiconfig)) {
        return $aiconfig;
    }
    return get_config('plagiarism_essayguard', 'apikey');
}

/**
 * Verify credit unlock with AI Grader server.
 *
 * Result is cached for 30 minutes in Moodle's config table so only one
 * HTTP request is made per 30-minute window across all PHP workers,
 * rather than one live request per page load.
 */
function plagiarism_essayguard_check_unlock() {
    global $CFG;
    static $runtime_cache = null;
    if ($runtime_cache !== null) {
        return $runtime_cache;
    }

    // Persistent cache: valid for 30 minutes across all PHP workers.
    $cached_result = get_config('plagiarism_essayguard', 'unlock_cache_result');
    $cached_time   = (int)get_config('plagiarism_essayguard', 'unlock_cache_time');
    if ($cached_time > 0 && (time() - $cached_time) < 1800) {
        $runtime_cache = !empty($cached_result);
        return $runtime_cache;
    }

    $siteid = plagiarism_essayguard_get_siteid();
    $apikey = plagiarism_essayguard_get_apikey();

    if (empty($siteid) || empty($apikey)) {
        // FIX-EG-UNLOCK-OPEN (v1.2.50): Credentials not yet configured — operate in
        // open/development mode rather than blocking all scoring. Admins who have not
        // yet entered their Site ID and API Key will still get full Essay Guard
        // functionality; the plugin simply skips the remote license check.
        error_log('[plagiarism_essayguard] check_unlock: siteId or apiKey not configured.'
            . ' Operating in open mode. Configure via Site Administration → Plugins'
            . ' → Plagiarism → Essay Guard to enable license-based usage tracking.');
        $runtime_cache = true;
        return true;
    }

    // KB-001 FIX (v1.2.48): Use Moodle \curl instead of raw curl_init().
    // Raw curl_init() fails silently on shared/cloud Moodle hosting because the
    // SSL CA bundle path differs from PHP's built-in path — curl_exec() returns
    // an empty string, $http_code is 0, and the function returns false.
    // The downstream effect: log_event and finalize_attempt both skip processing
    // (ignored:true), the plagiarism_essayguard_ev table stays empty, and every
    // behavioral metric (total_keystrokes, paste_events, wpm, etc.) shows as 0.
    //
    // FIX-EG-SESSION-LOCK (v1.2.162): Release PHP session lock before HTTP call.
    // File-based PHP sessions hold an exclusive lock. Any synchronous HTTP request
    // made while the lock is held blocks ALL concurrent page requests from the
    // same user (other tabs, AJAX calls) for the full duration of the network
    // round-trip (~4+ seconds on this server). write_close() releases the lock
    // immediately; Moodle re-acquires it automatically on the next session write.
    // FIX-SESSION-CLOSE-GUARD (v1.2.199): Guard to AJAX/CLI only — see get_platform_settings().
    if ((defined('AJAX_SCRIPT') && AJAX_SCRIPT) || (defined('CLI_SCRIPT') && CLI_SCRIPT)) {
        \core\session\manager::write_close();
    }
    require_once($CFG->libdir . '/filelib.php');
    $curl = new \curl();
    $curl->setopt([
        'CURLOPT_TIMEOUT'        => 10,
        'CURLOPT_CONNECTTIMEOUT' => 5,
    ]);
    $response  = $curl->get('https://lms-labs.com/api/plugin-unlock/verify', [
        'pluginId' => 'essayguard',
        'siteId'   => $siteid,
        'apiKey'   => $apikey,
    ]);
    $http_code = (int)($curl->info['http_code'] ?? 0);

    if ($http_code !== 200 || !$response) {
        // FIX-EG-UNLOCK-OPEN (v1.2.50): Fail-open on network/HTTP errors.
        // Previously returned false, which silently blocked all scoring and badge
        // display whenever the license server was unreachable (firewall, timeout, etc.).
        // Now logs the error but returns true so scoring continues uninterrupted.
        error_log('[plagiarism_essayguard] check_unlock: cannot reach license server'
            . ' (HTTP ' . $http_code . ', siteId=' . $siteid . ').'
            . ' Operating in open mode — scoring will continue.');
        $runtime_cache = true;
        return true;
    }

    $data   = json_decode($response, true);
    $result = !empty($data['unlocked']);

    if (!$result) {
        // Not yet unlocked — attempt automatic unlock (deducts 5,000 credits).
        // This fires once per site: after success the verify endpoint returns
        // unlocked=true so this branch is never reached again.
        error_log('[plagiarism_essayguard] check_unlock: site not unlocked — attempting auto-unlock. siteId=' . $siteid);
        $result = plagiarism_essayguard_auto_unlock($siteid, $apikey);
        if ($result) {
            error_log('[plagiarism_essayguard] auto-unlock SUCCESS — 5,000 credits deducted. siteId=' . $siteid);
        } else {
            error_log('[plagiarism_essayguard] auto-unlock FAILED — check credits balance or API key. siteId=' . $siteid);
        }
    }

    // Persist result for 30 minutes.
    set_config('unlock_cache_result', (int)$result, 'plagiarism_essayguard');
    set_config('unlock_cache_time',   time(),        'plagiarism_essayguard');

    $runtime_cache = $result;
    return $result;
}

/**
 * Automatically unlock Essay Guard for this site by posting to the
 * EssayGraderAI platform. Deducts 5,000 credits from the site's balance.
 * Returns true on success, false on failure (e.g. insufficient credits).
 */
function plagiarism_essayguard_auto_unlock(string $siteid, string $apikey): bool {
    global $CFG;

    $payload = json_encode([
        'pluginId' => 'essayguard',
        'siteId'   => $siteid,
        'apiKey'   => $apikey,
    ]);

    // KB-001 FIX (v1.2.48): Use Moodle \curl instead of raw curl_init().
    // BUG-CURL-RESETOPT FIX (v1.2.94): Moodle's \curl::post() calls resetopt()
    // internally before applying its own options, silently discarding any options
    // previously set via setopt(). In particular the 'Content-Type: application/json'
    // CURLOPT_HTTPHEADER was dropped, so the unlock server received a POST with no
    // content-type, could not parse the JSON body, and auto-unlock always failed.
    // Fix: pass all curl options as the 3rd argument to post() instead of via setopt().
    //
    // FIX-EG-SESSION-LOCK (v1.2.162): Release PHP session lock before HTTP call.
    // Same rationale as check_unlock() — session lock blocks concurrent requests.
    // FIX-SESSION-CLOSE-GUARD (v1.2.199): Guard to AJAX/CLI only — see get_platform_settings().
    if ((defined('AJAX_SCRIPT') && AJAX_SCRIPT) || (defined('CLI_SCRIPT') && CLI_SCRIPT)) {
        \core\session\manager::write_close();
    }
    require_once($CFG->libdir . '/filelib.php');
    $curl = new \curl();
    $response  = $curl->post('https://lms-labs.com/api/plugin-unlock', $payload, [
        'CURLOPT_TIMEOUT'        => 15,
        'CURLOPT_CONNECTTIMEOUT' => 5,
        'CURLOPT_HTTPHEADER'     => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    $http_code = (int)($curl->info['http_code'] ?? 0);

    if ($http_code !== 200 || !$response) {
        error_log('[plagiarism_essayguard] auto_unlock HTTP error: ' . $http_code . ' siteId=' . $siteid);
        return false;
    }

    $data = json_decode($response, true);
    // Server returns { unlocked: true } on success or when already unlocked.
    return !empty($data['unlocked']);
}

/**
 * Check whether Essay Guard is active for a specific course module.
 * Treats "never saved" as active for backward compatibility with activities
 * created before Essay Guard was installed (so old submissions still get badges).
 * The form checkbox now defaults to UNCHECKED for new activities; explicitly
 * disabling (saving with 0) is the only way to make this return false.
 */
/**
 * Fetches and caches platform-level site-wide plagiarism settings.
 * Returns an associative array with keys:
 *   essayguard_assignments (bool) – enable Essay Guard for all assignments
 *   essayguard_quizzes     (bool) – enable Essay Guard for all quizzes
 *   docguard_assignments   (bool) – enable DocGuard for all assignments
 *   docguard_quizzes       (bool) – enable DocGuard for all quizzes
 * Cached for 30 minutes in Moodle config. Fails open (all false) on any error.
 */
function plagiarism_essayguard_get_platform_settings(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $defaults = [
        'essayguard_assignments' => false,
        'essayguard_quizzes'     => false,
        'docguard_assignments'   => false,
        'docguard_quizzes'       => false,
    ];

    // Check 30-minute Moodle config cache.
    $cache_time = (int)get_config('plagiarism_essayguard', 'platform_settings_time');
    if ($cache_time > 0 && (time() - $cache_time) < 1800) {
        $cached_data = get_config('plagiarism_essayguard', 'platform_settings_data');
        if (!empty($cached_data)) {
            $decoded = json_decode($cached_data, true);
            if (is_array($decoded)) {
                $cached = $decoded;
                return $cached;
            }
        }
    }

    $siteid = plagiarism_essayguard_get_siteid();
    $apikey = plagiarism_essayguard_get_apikey();
    if (empty($siteid) || empty($apikey)) {
        $cached = $defaults;
        return $cached;
    }

    try {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        // FIX-SESSION-CLOSE-GUARD (v1.2.199): Guard write_close() to AJAX/CLI contexts only.
        // During a normal web page render (e.g. /plagiarism/essayguard/settings.php), calling
        // write_close() causes Moodle to emit "Session mutated after close: $SESSION->editedpages"
        // because the admin framework writes that key at the very end of page render, AFTER
        // this function returns. In AJAX/CLI the session is not needed for further rendering,
        // so releasing the lock there is still correct and avoids blocking concurrent requests.
        if ((defined('AJAX_SCRIPT') && AJAX_SCRIPT) || (defined('CLI_SCRIPT') && CLI_SCRIPT)) {
            \core\session\manager::write_close();
        }
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 5, 'CURLOPT_CONNECTTIMEOUT' => 3]);
        $response  = $curl->get('https://lms-labs.com/api/plagiarism-settings', [
            'siteId' => $siteid, 'apiKey' => $apikey,
        ]);
        $http_code = (int)($curl->info['http_code'] ?? 0);
        if ($http_code === 200 && !empty($response)) {
            $data = json_decode($response, true);
            if (is_array($data) && isset($data['settings'])) {
                $s = $data['settings'];
                $result = [
                    'essayguard_assignments' => !empty($s['essayguardAssignmentsEnabled']),
                    'essayguard_quizzes'     => !empty($s['essayguardQuizzesEnabled']),
                    'docguard_assignments'   => !empty($s['docguardAssignmentsEnabled']),
                    'docguard_quizzes'       => !empty($s['docguardQuizzesEnabled']),
                ];
                set_config('platform_settings_data', json_encode($result), 'plagiarism_essayguard');
                set_config('platform_settings_time', time(),               'plagiarism_essayguard');
                $cached = $result;
                return $cached;
            }
        }
    } catch (\Throwable $e) {
        // Fail open — do not crash Moodle pages on network issues.
    }

    $cached = $defaults;
    return $cached;
}

function plagiarism_essayguard_is_cm_active(int $cmid): bool {
    // FIX-EG-SITEWIDE-SETTINGS (v1.2.176): Check platform site-wide settings first.
    // If the admin enabled Essay Guard for all assignments or all quizzes on the
    // lms-labs.com platform, that flag overrides the per-activity checkbox.
    try {
        $platform = plagiarism_essayguard_get_platform_settings();
        if (!empty($platform['essayguard_assignments']) || !empty($platform['essayguard_quizzes'])) {
            $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
            if ($cm) {
                if (!empty($platform['essayguard_assignments']) && $cm->modname === 'assign') {
                    return true;
                }
                if (!empty($platform['essayguard_quizzes']) && $cm->modname === 'quiz') {
                    return true;
                }
            }
        }
    } catch (\Throwable $e) {
        // Ignore — fall through to per-cm check.
    }

    $value = get_config('plagiarism_essayguard', 'enabled_cm_' . $cmid);
    // get_config() returns false when the key has never been saved.
    // Treat missing = active so badges still appear on pre-existing assignments.
    return ($value === false) || !empty($value);
}

/**
 * Shared tracker injection logic used by both the legacy callback (Moodle < 4.3)
 * and the new hook callback (Moodle 4.3+).
 */
function plagiarism_essayguard_inject_tracker() {
    global $PAGE, $USER;

    if (isguestuser() || !isloggedin()) {
        return;
    }
    // FIX-EG-GLOBAL-ENABLED-MISSING (v1.2.88): get_config() returns false when the
    // key has never been saved (fresh install where admin hasn't explicitly saved
    // plugin settings yet). Treat missing key as enabled — consistent with
    // is_cm_active(). Only skip injection when the key EXISTS and is explicitly
    // set to a disabled value ('0' or empty string).
    $global_enabled = get_config('plagiarism_essayguard', 'enabled');
    if ($global_enabled !== false && empty($global_enabled)) {
        return;
    }
    if (during_initial_install()) {
        return;
    }

    // FIX-EG-TRACKER-UNLOCK-GATE (v1.2.138): Do NOT gate tracker JS loading on
    // check_unlock(). The tracker only captures raw keystroke/paste events into
    // plagiarism_essayguard_ev — it does NOT score or consume credits. Blocking
    // the tracker when check_unlock() returns false (site has credentials set but
    // hasn't been formally unlocked, or auto-unlock failed due to insufficient
    // credits) caused ZERO events to reach the server. With no events, every
    // submission was scored by linguistic fallback only (30% MEDIUM baseline),
    // the "total_keystrokes=0" diagnostic FAIL appeared, and paste-detection
    // became impossible. The unlock gate has been moved to the scoring paths:
    //   • observer.php is_active() — gates PHP observer scoring
    //   • finalize_attempt.php execute() — gates JS-path scoring
    //   • log_event.php already stores events first, then gates scoring
    // Events are harmless audit data; the admin's intent to enable the plugin
    // (the global_enabled check above) is sufficient gating for capture.

    // FIX-EG-CSS-UNCONDITIONAL (v1.2.52): Load badge CSS for ALL authenticated users
    // immediately — before any $PAGE->cm checks. v1.2.51 moved the CSS call before the
    // teacher bypass but it was still AFTER the $PAGE->cm null check. On AJAX grading
    // responses, quiz comment pages, and any page where $PAGE->cm is not yet set by the
    // time the hook fires, the CSS was never loaded, so risk badges rendered as invisible
    // plain text. Loaded here unconditionally: the file is tiny (~1 KB) and harmless on
    // non-EssayGuard pages. Badge rendering is always correct regardless of page context.
    $PAGE->requires->css('/plagiarism/essayguard/styles.css');

    $cm = $PAGE->cm ?? null;
    if (!$cm) {
        return;
    }

    // Per-activity enable/disable check (applies to both students and teachers).
    if (!plagiarism_essayguard_is_cm_active($cm->id)) {
        return;
    }

    // FIX-EG-TEACHER-BYPASS-BROADER (v1.2.52): Tracker JS is for students only.
    // v1.2.34 checked only moodle/course:manageactivities (editing teachers).
    // Non-editing teachers with moodle/grade:edit (grader role) and site admins
    // were still being tracked — causing false-positive paste-blocked banners
    // when teachers paste ChatGPT output or grading feedback into textareas.
    // Fix: check is_siteadmin() first (cheapest, always true for admins), then
    // moodle/course:manageactivities (editing teachers), then moodle/grade:edit
    // (non-editing teachers/graders). Any of these = teacher, skip tracker.
    $context = \context_module::instance($cm->id);
    if (is_siteadmin() ||
            has_capability('moodle/course:manageactivities', $context) ||
            has_capability('moodle/grade:edit', $context)) {
        return; // CSS loaded above; JS tracker not needed for teachers/admins.
    }

    // FIX-EG-QUIZ-PAGE-GUARD (v1.2.78 + extended v1.2.79): Only inject the tracker
    // on quiz ATTEMPT pages.  The tracker must be completely silent on every other
    // quiz page type:
    //
    //   mod-quiz-view     view.php?id=N    – quiz landing / "Attempt quiz" button
    //   mod-quiz-review   review.php       – post-submission results view
    //   mod-quiz-summary  summary.php      – "Check your answers" confirmation page
    //   mod-quiz-grade    grade.php        – teacher grading view
    //
    // All of those pages can share the same cm context and are visited by students,
    // so the old injection fired on all of them.  The only page where keystrokes can
    // actually be recorded and a form can actually be submitted is:
    //
    //   mod-quiz-attempt  attempt.php      – the live question page
    //
    // FIX-EG-QUIZ-PAGE-GUARD-HOTFIX (v1.2.80): The v1.2.79 pagetype-only guard
    // silently blocked injection on attempt.php when $PAGE->pagetype was not yet
    // set to 'mod-quiz-attempt' at hook-fire time on some Moodle 4.3+ installations.
    // Dual check: pagetype OR REQUEST_URI path — attempt.php always passes at least
    // one of these regardless of hook timing relative to $PAGE->set_pagetype().
    if ($cm->modname === 'quiz') {
        $pagetype_ok = in_array($PAGE->pagetype, ['mod-quiz-attempt'], true);
        $diag_url    = $_SERVER['REQUEST_URI'] ?? '';
        $uri_ok      = (strpos($diag_url, '/mod/quiz/attempt.php') !== false);
        if (!$pagetype_ok && !$uri_ok) {
            return;
        }
    }

    // Build a stable attempt key so all question pages within the same quiz
    // attempt use the same key and events flush from every page are stored
    // under the same key the observer will look up at submission time.
    //
    // FIX-EG-ATTEMPTKEY (v1.2.81): sesskey() has been removed from the seed.
    // sesskey() can change between page loads — Moodle regenerates it during
    // quiz navigation, autosave, and session writes. Each page reload produced
    // a DIFFERENT sha1 key, so events stored under page-1's key were never
    // found when the observer scored with the submission-page's key.
    // Result: $all_events always empty → every signal = 0 → score = 0 → LOW.
    //
    // New strategy:
    //   • Quiz: use the quiz attempt ID directly — stable, unique, correct.
    //   • Non-quiz (assignment, forum): userid:cmid hash — sesskey-free.
    $quizattemptid = optional_param('attempt', 0, PARAM_INT);
    if ($quizattemptid > 0) {
        $attemptkey = 'qa_' . $quizattemptid;
    } else {
        $attemptkey = sha1($USER->id . ':' . $cm->id);
    }

    // Persist the key in user preferences so the PHP event observer can
    // retrieve it reliably, even if JS telemetry events have not yet been
    // flushed to the database by the time the observer fires.
    set_user_preference('essayguard_ak_' . $cm->id, $attemptkey);

    $config = [
        'cmid'           => $cm->id,
        'attemptkey'     => $attemptkey,
        'flushinterval'  => (int)get_config('plagiarism_essayguard', 'captureinterval') ?: 5000,
        'allowpaste'     => (int)get_config('plagiarism_essayguard', 'allowpaste'),
        'maxburstchars'  => (int)get_config('plagiarism_essayguard', 'maxburstchars'),
    ];

    $PAGE->requires->js_call_amd('plagiarism_essayguard/tracker', 'init', [$config]);
}

function plagiarism_essayguard_supports_mod($modulename) {
    $supported = ['assign', 'quiz', 'forum'];
    return in_array($modulename, $supported, true);
}

function plagiarism_essayguard_coursemodule_standard_elements($formwrapper, $mform) {
    $mform->addElement('header', 'essayguardhdr', get_string('pluginname', 'plagiarism_essayguard'));
    // FIX-EG-CHECKBOX-SAVE (v1.2.152): Use plain checkbox, not advcheckbox.
    // advcheckbox relies on a hidden-field trick to transmit 0 when unchecked.
    // In Moodle 4.x plagiarism form paths that value can arrive as null, making
    // isset($data->essayguard_enabled) === false and silently skipping set_config.
    // Plain checkbox omits the field from POST when unchecked; edit_post_actions
    // uses !empty() unconditionally so 0 is always written on every save.
    $mform->addElement('checkbox', 'essayguard_enabled', get_string('enabled', 'plagiarism_essayguard'));

    // FIX-EG-CHECKBOX-DISPLAY (v1.2.156): Use ->coursemodule (CMID) not ->id.
    // In Moodle's moodleform_mod, get_current()->id is the MODULE INSTANCE id
    // (e.g. the quiz's own DB id), while get_current()->coursemodule is the CMID.
    // The save paths (save_form_elements + coursemodule_edit_post_actions) both
    // use $data->coursemodule — the CMID. Using ->id here read the wrong config
    // key, so the form always showed unchecked on re-open even when the value
    // had been saved as 1. For new activities coursemodule is 0 or unset,
    // giving $saved = false → default 0 (correct: new activities start unchecked).
    $cmid  = $formwrapper->get_current()->coursemodule ?? 0;
    $saved = $cmid ? get_config('plagiarism_essayguard', 'enabled_cm_' . $cmid) : false;
    // Default to 0 for new/unsaved activities; preserve saved state for existing ones.
    $mform->setDefault('essayguard_enabled', ($saved === false) ? 0 : (int)!empty($saved));
}

function plagiarism_essayguard_coursemodule_edit_post_actions($data, $course) {
    // FIX-EG-CHECKBOX-SAVE (v1.2.152): Always write config — no isset() guard.
    // Unchecked plain checkbox: field absent from POST, !empty(null) = false → saves 0.
    // Checked plain checkbox: field = '1', !empty('1') = true → saves 1.
    set_config(
        'enabled_cm_' . $data->coursemodule,
        !empty($data->essayguard_enabled) ? 1 : 0,
        'plagiarism_essayguard'
    );
    return $data;
}

/**
 * Legacy callback — kept for Moodle 4.0–4.2 compatibility.
 * On Moodle 4.3+ the hook system (db/hooks.php) handles this instead,
 * so we return early to avoid double-execution and suppress the deprecation warning.
 */
function plagiarism_essayguard_before_standard_html_head() {
    if (class_exists('core\hook\output\before_standard_head_html_generation')) {
        return;
    }
    plagiarism_essayguard_inject_tracker();
}

/**
 * Moodle plagiarism plugin class — REQUIRED entry point for the plagiarism API.
 *
 * v1.2.13 ROOT CAUSE FIX — BUG-EG-NO-BADGE-EVER:
 *
 * Moodle's plagiarism dispatcher in lib/plagiarismlib.php::plagiarism_get_links()
 * iterates active plagiarism plugins and calls:
 *
 *   $plobject = new plagiarism_plugin_{component}();
 *   $out .= $plobject->get_links($linkarray);
 *
 * It does NOT call any standalone function.  The previous implementation only
 * defined plagiarism_essayguard_get_links() as a standalone function which
 * Moodle core never invokes — making the badge permanently invisible to both
 * teachers and students regardless of any other fixes applied.
 *
 * Fix: this class provides the required plagiarism_plugin_essayguard::get_links()
 * method.  The standalone function below is kept as an internal helper and
 * called by the method so the rendering logic lives in one place.
 *
 * FIX-EG-SAFE-EXTEND (v1.2.201): Path A/B conditional with try-catch guard.
 * Path B (plagiarismlib loaded): extends plagiarism_plugin → update_status() inherited
 *   → getDeclaringClass() = 'plagiarism_plugin' → no deprecation.
 * Path A (early boot, load failed): standalone class, no update_status() stub.
 *   plagiarism_essayguard_before_standard_top_of_body_html() at top of file means
 *   plagiarism_update_status() never reaches the ReflectionMethod branch. NUCLEAR-SUPPRESS
 *   handler above is final catch-all.
 */
// FIX-EG-AUTOLOAD (v1.2.206): Defer class definition until first use via spl_autoload_register.
//
// Root cause of persistent deprecation warnings with the Path A/B conditional approach:
//   During Moodle's early bootstrap (setup.php get_plugins_with_function scan), lib.php
//   is loaded before plagiarism_plugin base class is available. Path A (standalone with
//   update_status() stub) is taken permanently for the entire PHP request. Later, when
//   plagiarism_update_status() calls:
//     new ReflectionMethod('plagiarism_plugin_essayguard', 'update_status')
//   getDeclaringClass() returns 'plagiarism_plugin_essayguard' (not 'plagiarism_plugin'),
//   so Moodle calls debugging('plagiarism_plugin::update_status() is deprecated...').
//   Moodle's debugging() writes HTML directly to the output buffer — set_error_handler()
//   cannot intercept it. No code change inside lib.php can fix a class already defined.
//
// Fix: Register an SPL autoloader so the class is defined on FIRST USE, not at lib.php
//   load time. The early bootstrap scan (get_plugins_with_function) only checks for the
//   existence of global FUNCTIONS — it never instantiates the plugin class. So the
//   autoloader never fires during early bootstrap. It fires the first time Moodle calls
//   class_exists('plagiarism_plugin_essayguard') or new plagiarism_plugin_essayguard() inside
//   plagiarism_update_status() — by which point Moodle is fully bootstrapped and
//   plagiarism_plugin is always defined. The class always extends plagiarism_plugin,
//   inheriting update_status(). getDeclaringClass()->getName() === 'plagiarism_plugin'
//   → no debugging() call → zero warnings in any debug mode.
if (!class_exists('plagiarism_plugin_essayguard', false)) {
    spl_autoload_register(function ($classname) {
        if ($classname !== 'plagiarism_plugin_essayguard') {
            return;
        }
        if (!class_exists('plagiarism_plugin', false)) {
            // Edge case: autoloader fired before plagiarism_plugin was defined.
            // Try loading plagiarismlib.php. Should only happen in unusual bootstrap
            // orders (e.g. unit tests or CLI scripts that use the class very early).
            global $CFG;
            if (!empty($CFG->libdir)) {
                try { require_once($CFG->libdir . '/plagiarismlib.php'); } catch (\Throwable $_e) {}
            }
            if (!class_exists('plagiarism_plugin', false)) {
                $eg_lib = dirname(dirname(dirname(__FILE__))) . '/lib/plagiarismlib.php';
                if (file_exists($eg_lib)) {
                    try { require_once($eg_lib); } catch (\Throwable $_e) {}
                }
                unset($eg_lib);
            }
        }
        if (class_exists('plagiarism_plugin', false)) {
            // Path B: class extends plagiarism_plugin.
            // update_status() INHERITED — getDeclaringClass() = 'plagiarism_plugin' → no warning.
            require_once __DIR__ . '/classes/pluginclass.php';
        } else {
            // Path A fallback (should never happen on a normal web page load).
            // update_status() stub prevents ReflectionException crash.
            require_once __DIR__ . '/classes/pluginclass_standalone.php';
        }
    }, true, true);
}

// FIX-EG-REMOVE-LEGACY-FUNCTION (v1.2.172): Global function REMOVED.
//
// v1.2.171 assumed get_plugins_with_function() only emits the "Callback X should be
// migrated" notice when BOTH a legacy function AND a registered hook exist. This was
// wrong — Moodle's get_plugins_with_function() calls debugging() for ANY plugin whose
// lib.php defines the legacy function, unconditionally, regardless of hook registration.
//
// The guarded require_once(plagiarismlib.php) at the top of this file (added v1.2.170)
// already guarantees Path B (extends plagiarism_plugin) on all normal page loads.
// plagiarism_update_status() therefore sees getDeclaringClass()='plagiarism_plugin'
// (not 'plagiarism_plugin_essayguard') and the deprecation check is suppressed without
// needing a global function at all.
//
// The before_standard_top_of_body_html_generation hook has been restored in db/hooks.php
// so the formal hook dispatch path handles this event cleanly via the no-op class
// in classes/hook/before_standard_top_of_body_html_generation.php.
//
// Result: no legacy function → no "Callback should be migrated" notice.
//         hook registered → clean dispatch path.
//         Path B guaranteed → no update_status() deprecation.

/**
 * FIX-EG-UPDATE-STATUS-REVIVE (v1.2.174): Global function re-added.
 *
 * Moodle's plagiarism_update_status() (plagiarismlib.php) checks
 * function_exists('plagiarism_essayguard_before_standard_top_of_body_html')
 * BEFORE performing the ReflectionMethod check. When the function exists,
 * Moodle calls it directly and NEVER reaches the ReflectionMethod branch —
 * so the "plagiarism_plugin::update_status() is deprecated" notice is never
 * emitted, regardless of which class path (A or B) was taken at lib.php load time.
 *
 * v1.2.172–1.2.173 removed this function because get_plugins_with_function()
 * emits "Callback should be migrated to new hook callback" for any plugin that
 * defines the legacy function (unconditionally, even with a hook registered).
 * That trade silenced one DEBUG_DEVELOPER notice but reinstated the more
 * disruptive update_status() deprecation on the quiz Results tab, where its
 * HTML output appears inline before the attempts table — visible to every teacher
 * with Moodle developer debug mode on.
 *
 * Trade-off accepted: "Callback should be migrated" is a migration advisory
 * that fires on before_standard_top_of_body_html pages only (less disruptive).
 * The update_status deprecation fires on the quiz RESULTS page and injects raw
 * HTML into the page body — it was the one teachers noticed and reported.
 * NOTE (v1.2.196): Function definition moved to the very top of lib.php so it
 * is defined before plagiarism_update_status() fires its function_exists() check,
 * regardless of hook cache state. See FIX-EG-EARLY-FUNCTION at top of file.
 */

/**
 * FIX-EG-QSLOT-CONTENT (v1.2.75): Identify the quiz question slot that produced
 * a given submitted text, for use when Moodle does not pass 'questionattempt' in
 * $linkarray (older Moodle versions do not include it).
 *
 * Strategy:
 *   1. Find the most recent finished quiz attempt for userid in the given cm.
 *   2. Fetch all essay answer steps for that attempt, ordered by slot.
 *   3. Compare each slot's answer text to $content after stripping HTML tags
 *      and normalising whitespace.  Return the slot number on first match.
 *
 * Results are cached in a static map (keyed by "cmid:userid:contenthash") so
 * repeated calls on the same page (one per question column on the grading
 * overview table) only run the quiz-attempt DB query once per user+cm pair.
 *
 * @param int    $cmid    Course-module ID of the quiz.
 * @param int    $userid  The student's user ID.
 * @param string $content The essay HTML/text as supplied by Moodle's linkarray.
 * @return int   Matching slot number (1-based), or 0 if not found / not a quiz.
 */
function plagiarism_essayguard_find_qslot_by_content(int $cmid, int $userid, string $content): int {
    global $DB;

    // FIX-EG-IDENTICAL-ANSWER-QSLOT (v1.2.149): Replace the content-hash slot cache with a
    // claim-once mechanism. The old $slot_cache (md5($content) → slot) was fundamentally broken
    // when two questions have identical answer text: both produce the same hash, so the second
    // call hit the cache and returned slot 1 again, causing Q2's badge to show the Q1 record.
    // Even without the cache hit, the `$sim_pct > $best_pct` strict-greater-than comparison
    // meant slot 1 always won ties — Q2 at 100% similarity could never beat Q1 at 100%.
    // The fix: once a slot is resolved for a given cmid+userid session, it is "claimed" and
    // skipped on subsequent calls. Unclaimed slots are always preferred over claimed ones.
    // If all matching slots are claimed (more questions than unique answers), we fall back to
    // the overall best match so the page still renders a badge rather than showing nothing.
    static $claimed_slots = [];  // "cmid:userid" => [slot => true]
    static $answer_cache  = [];  // "cmid:userid" => [slot => normalised_text]

    $cache_key = $cmid . ':' . $userid;

    // Populate the answer cache for this user+cm on first call.
    if (!array_key_exists($cache_key, $answer_cache)) {
        $answer_cache[$cache_key] = [];

        // Find the most recent finished quiz attempt.
        $attempt = $DB->get_record_sql(
            "SELECT qa.uniqueid
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
               JOIN {course_modules} cm ON cm.instance = q.id
              WHERE cm.id = :cmid AND qa.userid = :userid AND qa.state = 'finished'
           ORDER BY qa.id DESC",
            ['cmid' => $cmid, 'userid' => $userid],
            IGNORE_MULTIPLE
        );

        if ($attempt) {
            // Get the most recent answer step for each question slot.
            $sql = "SELECT qas.id, qa.slot, qasd.value
                      FROM {question_attempt_steps} qas
                      JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
                      JOIN {question_attempts} qa ON qa.id = qas.questionattemptid
                     WHERE qa.questionusageid = :qubaid AND qasd.name = 'answer'
                  ORDER BY qa.slot ASC, qas.id DESC";
            $rows = $DB->get_records_sql($sql, ['qubaid' => $attempt->uniqueid]);

            $seen_slots = [];
            foreach ($rows as $row) {
                $slot = (int)$row->slot;
                if (isset($seen_slots[$slot])) {
                    continue; // keep only the most recent step per slot
                }
                $seen_slots[$slot] = true;
                // Normalise: strip HTML, collapse whitespace, trim.
                $norm = trim(preg_replace('/\s+/', ' ', strip_tags((string)($row->value ?? ''))));
                if ($norm !== '') {
                    $answer_cache[$cache_key][$slot] = $norm;
                }
            }
        }
    }

    if (empty($answer_cache[$cache_key])) {
        return 0; // not a quiz, or attempt not found
    }

    // Normalise the incoming content the same way.
    $norm_content = trim(preg_replace('/\s+/', ' ', strip_tags($content)));
    if ($norm_content === '') {
        return 0;
    }

    // FIX-EG-QSLOT-CONTENT-FUZZY (v1.2.106): similarity-based matching.
    // FIX-EG-QSLOT-THRESHOLD (v1.2.114): threshold lowered 85% → 60%.
    // FIX-EG-IDENTICAL-ANSWER-QSLOT (v1.2.149): claim-once slot resolution.
    //
    // Apply html_entity_decode() before similar_text() so HTML entities in the
    // content string (&amp;, &nbsp; etc.) count as single chars, matching the
    // stored plain-text value from question_attempt_step_data.
    $decoded_content = html_entity_decode($norm_content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $claimed = $claimed_slots[$cache_key] ?? [];

    // Track the overall best match AND the best unclaimed match separately.
    // Unclaimed slots are always preferred; claimed slots are the last resort.
    $best_slot           = 0;
    $best_pct            = 0.0;
    $best_unclaimed_slot = 0;
    $best_unclaimed_pct  = 0.0;

    foreach ($answer_cache[$cache_key] as $slot => $slot_text) {
        $decoded_slot = html_entity_decode($slot_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        similar_text($decoded_content, $decoded_slot, $sim_pct);
        if ($sim_pct < 60.0) {
            continue;
        }
        // Track overall best (fallback when every slot is already claimed).
        if ($sim_pct > $best_pct) {
            $best_pct  = $sim_pct;
            $best_slot = $slot;
        }
        // Track best unclaimed slot — this is what we prefer to return.
        if (!isset($claimed[$slot]) && $sim_pct > $best_unclaimed_pct) {
            $best_unclaimed_pct  = $sim_pct;
            $best_unclaimed_slot = $slot;
        }
    }

    // Prefer an unclaimed slot; only fall back to a claimed slot if every
    // matching slot has already been resolved in this page-render cycle.
    $resolved = $best_unclaimed_slot > 0 ? $best_unclaimed_slot : $best_slot;

    if ($resolved > 0) {
        $claimed_slots[$cache_key][$resolved] = true;
        return $resolved;
    }

    return 0; // no match found
}

/**
 * Render the Essay Guard risk badge for a submission (called by get_links).
 *
 * FIX-EG-BADGE-RENDER (v1.2.177): Replaces Mustache template (riskbadge.mustache)
 * with an inline PHP renderer that matches DocGuard quality:
 *   - One-time inline <style> injection: badge always visible even before styles.css loads.
 *   - data-eg-tip + CSS ::after hover tooltip (300px, dark background, no JS required).
 *   - Proper "Essay Guard Low/Medium/High/Pending/Error" labels with score percent.
 *   - Pending and error badge states (previously returned only the class report link).
 *   - essayguard-wrap div, essayguard-link CSS class (replaces inline-style links).
 *   - Inline styles kept in sync with styles.css.
 *
 * @param string $status  'analysed' | 'pending' | 'error'
 * @param float  $score   Risk score 0-100.
 * @param string $level   'low' | 'medium' | 'high' | 'partial' | 'mild'
 * @param string $errmsg  Error message (status='error' only).
 * @param int    $cmid    Course-module ID.
 * @param int    $userid  Student's user ID.
 * @param bool   $is_teacher  True when the viewer has viewreport capability.
 * @param bool   $is_aggregate_fallback  True when the aggregate record was used.
 */
function plagiarism_essayguard_render_badge(
    string $status,
    float $score,
    string $level,
    string $errmsg,
    int $cmid,
    int $userid,
    bool $is_teacher,
    bool $is_aggregate_fallback
): string {
    // Inline styles guarantee the badge is always visible, even when
    // styles.css has not loaded yet (e.g. AJAX responses, late-include paths).
    // Must be kept in sync with the essayguard-badge block in styles.css.
    static $styles_injected = false;
    $style_block = '';
    if (!$styles_injected) {
        $styles_injected = true;
        $style_block = '<style>'
            . '.essayguard-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:4px;font-size:.78rem;font-weight:600;letter-spacing:.01em;line-height:1.4;margin:2px 0;border:1px solid transparent;cursor:default;position:relative;text-decoration:none;}'
            . '.essayguard-badge-low{background:#f0fdf4;color:#166534;border-color:#86efac;}'
            . '.essayguard-badge-medium{background:#fff7ed;color:#7c2d12;border-color:#fdba74;}'
            . '.essayguard-badge-high{background:#fef2f2;color:#991b1b;border-color:#fca5a5;}'
            . '.essayguard-badge-pending{background:#f3f4f6;color:#6b7280;border-color:#d1d5db;}'
            . '.essayguard-badge-error{background:#fdf2f8;color:#6b21a8;border-color:#d8b4fe;}'
            . '.essayguard-badge-dot{width:7px;height:7px;border-radius:50%;display:inline-block;flex-shrink:0;}'
            . '.essayguard-badge-dot-low{background:#22c55e;}'
            . '.essayguard-badge-dot-medium{background:#f97316;}'
            . '.essayguard-badge-dot-high{background:#ef4444;}'
            . '.essayguard-badge-dot-pending{background:#9ca3af;}'
            . '.essayguard-badge-dot-error{background:#7c3aed;}'
            . '.essayguard-wrap{margin:4px 0;display:flex;flex-direction:column;gap:2px;}'
            . '.essayguard-link{font-size:.78rem;color:#555;text-decoration:underline;display:inline-block;margin-top:2px;}'
            . '.essayguard-badge[data-eg-tip]:hover::after{content:attr(data-eg-tip);position:absolute;bottom:calc(100% + 6px);left:0;z-index:9999;background:#1e293b;color:#f1f5f9;font-size:.72rem;font-weight:400;line-height:1.5;padding:7px 11px;border-radius:5px;width:300px;white-space:normal;pointer-events:none;box-shadow:0 4px 12px rgba(0,0,0,.25);}'
            . '.essayguard-badge[data-eg-tip]:hover::before{content:"";position:absolute;bottom:calc(100% + 1px);left:14px;border:5px solid transparent;border-top-color:#1e293b;pointer-events:none;}'
            . '</style>';
    }

    $badge_class = 'essayguard-badge';
    $dot_class   = 'essayguard-badge-dot';
    $label       = '';
    $tooltip     = '';

    if ($status === 'analysed') {
        $score_int   = (int)$score;
        // Normalise legacy DB values (partial/mild) to canonical display level.
        $level_map   = ['low' => 'low', 'medium' => 'medium', 'high' => 'high', 'partial' => 'medium', 'mild' => 'medium'];
        $canon_level = $level_map[$level] ?? 'low';
        $level_label = ucfirst($canon_level);
        $suffix      = $is_aggregate_fallback ? ' (overall)' : '';
        $badge_class .= ' essayguard-badge-' . $canon_level;
        $dot_class   .= ' essayguard-badge-dot-' . $canon_level;
        $label        = 'Essay Guard ' . $level_label . $suffix . ' · ' . $score_int . '%';
        $tooltips     = [
            'low'    => 'Essay Guard: LOW authenticity risk (' . $score_int . '/100). Writing behaviour appears consistent with normal student patterns.',
            'medium' => 'Essay Guard: MEDIUM authenticity risk (' . $score_int . '/100). Some signals triggered — review the detailed report before drawing conclusions.',
            'high'   => 'Essay Guard: HIGH authenticity risk (' . $score_int . '/100). Significant signals detected — a detailed review is strongly recommended.',
        ];
        $tooltip = $tooltips[$canon_level] ?? $label;
    } elseif ($status === 'error') {
        $badge_class .= ' essayguard-badge-error';
        $dot_class   .= ' essayguard-badge-dot-error';
        $label        = 'Essay Guard Error';
        $tooltip      = 'Essay Guard could not analyse this submission. ' . (trim($errmsg) ?: 'Check plugin settings.');
    } else {
        // pending or any unrecognised status
        $badge_class .= ' essayguard-badge-pending';
        $dot_class   .= ' essayguard-badge-dot-pending';
        $label        = 'Essay Guard Pending';
        $tooltip      = 'Essay Guard is analysing this submission. Reload the page in a moment to see the result.';
    }

    $badge = $style_block
        . '<span class="' . $badge_class . '" data-eg-tip="' . s($tooltip) . '">'
        . '<span class="' . $dot_class . '"></span>'
        . s($label)
        . '</span>';

    $links = '';
    if ($is_teacher) {
        if ($status === 'analysed') {
            $detail_url = new \moodle_url('/plagiarism/essayguard/student.php', [
                'cmid'   => $cmid,
                'userid' => $userid,
            ]);
            $links .= '<a href="' . $detail_url->out(false) . '" class="essayguard-link">Essay Guard detail</a>';
        }
        $class_url = new \moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]);
        $links .= '<a href="' . $class_url->out(false) . '" class="essayguard-link" style="margin-left:8px;">Class report</a>';
        if ($status === 'error' && $errmsg !== '') {
            $links .= '<small style="color:#888;font-size:0.75rem;display:block;">' . s(substr($errmsg, 0, 120)) . '</small>';
        }
    }

    return '<div class="essayguard-wrap">' . $badge . $links . '</div>';
}

/**
 * v1.2.5 BUG FIX — risk badge was invisible to students.
 *
 * Root cause: the capability gate `plagiarism/essayguard:viewreport` is
 * teacher-only.  Checking it unconditionally caused the function to return
 * '' for every student page load, so the badge was never rendered.
 *
 * Fix: split the rendering into two paths —
 *   - Teachers (viewreport cap): full output — badge + detail link + class report link.
 *   - Students (own submission only): badge only (no class report, no drill-down link).
 *
 * FIX-EG-BADGE-RENDER (v1.2.177): Now delegates all badge HTML to
 * plagiarism_essayguard_render_badge() — see that function for full feature list.
 *
 * Called by plagiarism_plugin_essayguard::get_links() — do not invoke directly.
 */
function plagiarism_essayguard_get_links($linkarray) {
    global $DB, $USER;

    if (empty($linkarray['cmid'])) {
        return '';
    }

    $cmid    = (int)$linkarray['cmid'];

    // FIX-EG-GETLINKS-CM-DISABLED (v1.2.123): If the admin has explicitly disabled
    // EssayGuard for this activity (enabled_cm_{cmid} = 0 in Moodle config), return ''
    // immediately. Without this guard, stale score records written before the CM was
    // disabled continue to render badges even though no new events are being captured —
    // badges from old sessions show up indefinitely as if the plugin is still active,
    // confusing admins and teachers who disabled the plugin to stop it.
    //
    // Note: get_config() returns PHP false when the key was never saved (fresh install).
    // is_cm_active() treats false = enabled (same default as the checkbox in the form).
    // Only a literal '0' or '' means "explicitly disabled".
    if (!plagiarism_essayguard_is_cm_active($cmid)) {
        return '';
    }

    // ── PERF-FIX-EG-BATCH-PRELOAD (v1.2.211) ─────────────────────────────────
    // View All Submissions calls get_links() once per student row.
    // Pre-v1.2.211: 3 DB queries per student (DISTINCT qslot + pq_record +
    // agg_record) = 150+ queries for 50 students on one page load.
    // Fix: load ALL plagiarism_essayguard_sc rows for this CM in ONE query on
    // the first call; every subsequent student row is a pure O(1) array lookup.
    // The static cache lives for the PHP request lifetime only — reset between
    // page loads automatically.
    static $_eg_sc_preloaded = [];   // [$cmid] => true
    static $_eg_sc_cache     = [];   // [$cmid][$userid][$qslot] => stdClass

    if (!isset($_eg_sc_preloaded[$cmid])) {
        $_eg_sc_preloaded[$cmid] = true;
        $all_rows = $DB->get_records_sql(
            "SELECT * FROM {plagiarism_essayguard_sc}
              WHERE cmid = :cmid
           ORDER BY timemodified DESC",
            ['cmid' => $cmid]
        );
        foreach ($all_rows as $row) {
            $uid  = (int)$row->userid;
            $slot = (int)$row->qslot;
            // Keep only the most-recent record per (userid, qslot) pair
            // (ORDER BY timemodified DESC guarantees first-wins = newest).
            if (!isset($_eg_sc_cache[$cmid][$uid][$slot])) {
                $_eg_sc_cache[$cmid][$uid][$slot] = $row;
            }
        }
    }
    // ─────────────────────────────────────────────────────────────────────────

    $context = \context_module::instance($cmid);
    $userid  = isset($linkarray['userid']) ? (int)$linkarray['userid'] : (int)$USER->id;

    $is_teacher = has_capability('plagiarism/essayguard:viewreport', $context);
    $is_own     = ($userid === (int)$USER->id);

    // Students can only see their own badge; teachers can see all.
    if (!$is_teacher && !$is_own) {
        return '';
    }

    // Teachers see a class-report link when a full badge cannot be shown (qslot=0 path).
    $classreportlink = '';
    if ($is_teacher) {
        $classreporturl  = new \moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]);
        $classreportlink = '<a href="' . $classreporturl->out(false) . '" class="essayguard-link">Essay Guard class report</a>';
    }

    // FIX-EG-CALLORDER-SKIP-RESOLVED (v1.2.165): Unified slot-resolution tracker.
    //
    // Previously each detection method tracked claimed/resolved slots independently:
    //   • find_qslot_by_content() had its own internal $claimed_slots static.
    //   • The call-order fallback had its own $co_idx sequential counter.
    // These were completely isolated. When Q1 was resolved by content matching,
    // $co_idx remained 0. Q2 falling through to call-order then assigned
    // $co_slots[0] = slot 1 AGAIN — reading Q1's record for Q2's badge.
    // This caused the "Review Attempt shows Q2 = HIGH 100%, Essay Guard Report
    // shows Q2 = LOW 15%" mismatch: get_links() used Q1's DB record for Q2 while
    // report.php correctly queried qslot=2 directly.
    //
    // Fix: unified $resolved static that captures every slot resolved by ANY method
    // within the same PHP request. The call-order fallback now iterates the available
    // slots and picks the first one NOT already in $resolved, so it can never
    // re-assign a slot that was already taken by content matching or get_slot().
    static $resolved = [];  // "cmid:userid" => [slot => true] — all methods combined

    $rk = $cmid . ':' . $userid;

    // v1.2.14: attempt to retrieve a per-question score when Moodle passes a
    // question_attempt object (quiz essay grading / review view).
    // Moodle passes $linkarray['questionattempt'] = question_attempt instance,
    // which exposes ->get_slot() returning the 1-based question slot number.
    $qslot = 0;
    if (!empty($linkarray['questionattempt'])
            && method_exists($linkarray['questionattempt'], 'get_slot')) {
        $qslot = (int)$linkarray['questionattempt']->get_slot();
        if ($qslot > 0) {
            $resolved[$rk][$qslot] = true;  // record so call-order skips this slot
        }
    }

    // FIX-EG-QSLOT-CONTENT (v1.2.75): Older Moodle versions (< 4.1) do not pass
    // 'questionattempt' in $linkarray when rendering quiz essay responses on the
    // attempt-review or grading page.  Without it, $qslot stays 0 and every essay
    // question on the page reads the same aggregate (qslot=0) DB record — producing
    // identical badges for every question regardless of individual scores.
    //
    // Fallback: when $qslot is still 0 but the submitted content text is available,
    // try to identify the question slot by matching the content against the answers
    // stored in the quiz attempt step data.  This costs 2 extra DB queries the first
    // time per student+cm pair; subsequent calls reuse the same cached data via a
    // static variable (reset between requests by PHP's process model).
    //
    // FIX-EG-CONTENT-MINLEN (v1.2.174): Content-matching requires > 30 chars of
    // plain text (HTML stripped). Moodle passes short status strings such as
    // "Requires grading" (16 chars) or grade values (~12 chars) as
    // $linkarray['content'] on the quiz overview page — these are cell-rendering
    // strings, NOT essay answers. Trying to similarity-match them against stored
    // essays always fails at < 60% and is a waste. Review Attempt and Grading pages
    // always pass the full essay HTML (hundreds/thousands of chars) so they are
    // unaffected by this gate.
    //
    // FIX-EG-CALLORDER-NO-CONTENT-GATE (v1.2.185): The > 30 char gate is intentionally
    // NOT applied to the call-order fallback below. Call-order is purely count-based
    // (Nth get_links() call for this user+cm = Nth question slot) and does not require
    // substantive essay content to work correctly. The gate was incorrectly blocking
    // call-order on the quiz overview page where Moodle passes "Requires grading"
    // (16 chars) — qslot stayed 0 for every question column and only the report link
    // was shown (no per-question risk badge). Removing the gate from call-order
    // enables Q.1 and Q.2 badges to appear on the overview page. Content-matching
    // still requires > 30 chars because similarity matching against short strings
    // is meaningless.
    $_eg_content_plain = trim(strip_tags((string)($linkarray['content'] ?? '')));
    if ($qslot === 0 && mb_strlen($_eg_content_plain) > 30) {
        $qslot = plagiarism_essayguard_find_qslot_by_content($cmid, $userid, (string)$linkarray['content']);
        if ($qslot > 0) {
            $resolved[$rk][$qslot] = true;  // record so call-order skips this slot
        }
    }

    // FIX-EG-QSLOT-CALLORDER (v1.2.155): Last-resort call-order slot assignment.
    // FIX-EG-CALLORDER-SKIP-RESOLVED (v1.2.165): Replaced $co_idx sequential counter
    // with first-unresolved-slot iteration using the unified $resolved tracker above.
    // FIX-EG-CALLORDER-NO-CONTENT-GATE (v1.2.185): Content gate removed — see above.
    //
    // Both primary detection paths returned 0:
    //   • get_slot() — 'questionattempt' absent (overview page) or Moodle < 4.1.
    //   • find_qslot_by_content() — content < 30 chars (overview page status strings)
    //     OR similarity < 60% (short answer, multi-attempt, different whitespace).
    //
    // When per-question records exist for this user+cmid, Moodle always calls
    // get_links() for quiz essay questions in question-slot order (slot 1 → slot 2 → …)
    // within a single page request. We pick the first slot that has NOT already been
    // resolved by a prior call, ensuring Q2 cannot re-use Q1's slot when Q1 was
    // resolved by a different detection method.
    //
    // Safety:
    //   • If no per-question records exist (assignment / forum — only qslot=0 written),
    //     $co_slots[$ck] is empty and the block is a no-op; aggregate is used as before.
    //   • Static arrays are per-PHP request; they reset between page loads automatically.
    if ($qslot === 0) {
        static $co_slots = [];  // "cmid:userid" => ordered list of distinct per-Q slots

        $ck = $cmid . ':' . $userid;
        if (!array_key_exists($ck, $co_slots)) {
            // PERF-FIX-EG-BATCH-PRELOAD: serve from request-level cache; no DB query.
            $user_slots = array_keys($_eg_sc_cache[$cmid][$userid] ?? []);
            $user_slots = array_values(array_filter($user_slots, function ($s) { return $s > 0; }));
            sort($user_slots);
            $co_slots[$ck] = $user_slots;
        }

        // Pick the first slot not already resolved by any prior detection method.
        $already = $resolved[$rk] ?? [];
        foreach ($co_slots[$ck] as $candidate) {
            if (!isset($already[$candidate])) {
                $qslot = $candidate;
                $resolved[$rk][$qslot] = true;
                break;
            }
        }
    }

    // FIX-EG-OVERVIEW-AGGREGATE-BLEED (v1.2.169): Prevent the aggregate badge from
    // appearing for every question on the quiz overview/grades report page.
    //
    // Root cause: the quiz overview page (report.php?mode=overview) does NOT pass a
    // questionattempt object in $linkarray.  Moodle also renders question columns
    // without the full essay text (the column shows "Requires grading" status, not
    // the answer body), so $linkarray['content'] is empty — the content-matching and
    // call-order fallbacks are gated on !empty($linkarray['content']) and never run.
    // $qslot stays 0 for every question column, and every column falls through to the
    // aggregate (qslot=0) DB record.
    //
    // The aggregate blends ALL questions' events into a single combined score.
    // Showing it on every individual question cell is actively misleading: a quiz
    // where Q1=LOW 21% and Q2=HIGH 100% shows "100%" for BOTH Q1 and Q2 because
    // the aggregate is dominated by Q2's paste signal.
    //
    // Fix: when qslot detection has completely failed (qslot still 0 here) AND 2+
    // distinct per-question scored records exist for this user+cmid, return only the
    // teacher report link — not a misleading aggregate badge.
    // This guard fires regardless of whether $linkarray['content'] is set, because
    // the overview page leaves it empty.
    // Single-question quizzes are unaffected (at most 1 per-question slot).
    // Non-quiz contexts (assignments, forums) are unaffected (0 per-question records).
    // The Review Attempt and Grading pages are unaffected: they pass questionattempt,
    // so qslot is resolved via get_slot() above and is always > 0 by this point.
    // FIX-EG-NO-BADGE-OVERVIEW (v1.2.173): When qslot is still 0 at this point
    // (no questionattempt object, content-match failed, call-order exhausted),
    // we cannot determine which question this get_links() call belongs to.
    // Never show a badge in this case — risk badges belong on the Essay Guard
    // Report page only. Teachers see the report link; students see nothing.
    //
    // The previous implementation returned $reportlink only when perq_counts >= 2
    // (to suppress the misleading aggregate badge on multi-question quizzes).
    // Single-question quizzes with qslot=0 still rendered the aggregate badge
    // under the student name row on the quiz grades/overview page, and on the
    // Review Attempt page for the student-name cell, which was confusing.
    if ($qslot === 0) {
        return $classreportlink;
    }

    $record               = null;
    $is_aggregate_fallback = false;   // FIX-EG-IS-AGGREGATE (v1.2.84)

    // FIX-EG-PERQ-TRUST (v1.2.93): Correct per-question vs aggregate selection.
    //
    // v1.2.87 added a "higher-of-two" comparison which always picked the record with the
    // greater riskscore between the per-question record and the aggregate (qslot=0) record.
    // This caused "same badge for every question": the aggregate score is computed from ALL
    // questions' events combined, so it is almost always higher than any single question's
    // per-question score. Every question's badge fell through to the aggregate, showing
    // identical results regardless of whether Q1 was pasted and Q2 was typed.
    //
    // Correct logic:
    //   1. Fetch the per-question record (qslot=N).
    //   2. If it exists AND has riskscore > 0 → trust it; it is the accurate, question-
    //      specific score written by the PHP observer using the final submitted text for
    //      this slot. Do NOT compare against the aggregate.
    //   3. If it exists BUT has riskscore = 0 → qslot detection likely failed in
    //      tracker.js (events not tagged → no events found for this slot → score=0).
    //      Fall back to the aggregate which may have correctly captured paste behaviour
    //      via the untagged qslot=0 event pool. (Preserves the v1.2.87 intent for the
    //      broken-qslot-detection edge case only.)
    //   4. If no per-question record exists → fall back to aggregate as before.
    $pq_record  = null;
    $agg_record = null;

    if ($qslot > 0) {
        // PERF-FIX-EG-BATCH-PRELOAD: serve from request-level cache; no DB query.
        $pq_record = $_eg_sc_cache[$cmid][$userid][$qslot] ?? null;
    }

    // Always fetch aggregate — needed as fallback for the riskscore=0 edge case.
    // PERF-FIX-EG-BATCH-PRELOAD: serve from request-level cache; no DB query.
    $agg_record = $_eg_sc_cache[$cmid][$userid][0] ?? null;

    // Select the record to display for this question.
    // FIX-EG-FALLBACK-PASTE-ONLY (v1.2.110): The fallback rule must satisfy two
    // simultaneous user-reported failures from live testing:
    //
    //   Bug A — pasted Q1 → LOW: Ctrl+V records 1–2 keydown events tagged with
    //     this qslot, but the paste/large_insert event was tagged with a different
    //     qslot (or none). The per-question scorer sees keystrokes but no paste
    //     signal → riskscore=0, total_keystrokes>0. v1.2.109 trusted that 0% and
    //     never consulted the aggregate, so the paste detected at the attempt
    //     level was hidden.
    //   Bug B — typed naturally → MEDIUM: qslot detection failed entirely,
    //     per-question record has riskscore=0 and no activity → v1.2.109 fell
    //     back to the aggregate. The aggregate, scoring all of the student's
    //     clean typing, hit Signal 4 (no long pauses) + Signal 5 (no backspaces)
    //     ≈ 35 and badged the honest typist MEDIUM (OVERALL).
    //
    // The shared root cause is that v1.2.109's discriminator (per-question
    // activity) does not distinguish between "the aggregate genuinely detected
    // a paste" and "the aggregate is just typing-signal noise". A single rule
    // resolves both bugs:
    //
    //   Fall back to the aggregate ONLY when the aggregate has CONCRETE paste
    //   evidence (paste_events > 0 OR riskscore >= 0.70 — the HIGH threshold,
    //   which is reachable only when Signal 1 fires from a paste/large_insert).
    //   Typing-only aggregate scores in the 35–69 range are never used as a
    //   fallback because they have no meaning for an individual question that
    //   captured no events of its own.
    //
    // Outcomes:
    //   • Bug A: per-question riskscore=0, aggregate has paste → fallback to
    //     aggregate → HIGH (correct).
    //   • Bug B: per-question riskscore=0, aggregate has no paste → no
    //     fallback → per-question shown as LOW (correct).
    //   • Honest typist with cleanly tagged events → per-question riskscore
    //     reflects real signals → shown as-is (no change).
    //   • Real per-question paste (riskscore > 0 from tagged paste) → trusted
    //     directly, never overwritten by aggregate (no change from v1.2.93).
    if ($pq_record && $agg_record) {
        // The $agg_has_paste_evidence guard alone is sufficient — keystroke
        // activity from Ctrl+V modifier presses is not a reliable indicator
        // that the paste was correctly attributed to this slot, so we no
        // longer require !$pq_has_activity (the v1.2.109 check that broke
        // Bug A). Honest typists are protected by $agg_has_paste_evidence:
        // their aggregate has no paste, so no fallback occurs.
        // FIX-EG-AGG-PASTE-MEANINGFUL (v1.2.143): Previously any paste_events > 0
        // was treated as paste evidence, including tiny autocorrect replacements that
        // only awarded Signal 1 = 10 pts (< 5% of text). This caused the per-question
        // fallback to fire for ALL questions when the aggregate had an incidental paste,
        // displaying the aggregate score (e.g. 45% MEDIUM) on every question in the
        // Review Attempt page while the EssayGuard Report correctly showed the lower
        // individual per-question scores (e.g. 20% and 0%) — a visible two-report
        // mismatch that confused teachers.
        //
        // Fix: require Signal 1 ≥ 30 pts (meaningful paste — at least the MEDIUM floor
        // that requires ≥ 5% of the answer to have been pasted) OR riskscore ≥ 0.70
        // (HIGH threshold — definitive paste evidence regardless of breakdown). An
        // incidental autocorrect paste (Signal 1 = 10 pts) no longer qualifies as
        // "paste evidence" and does not trigger the per-question fallback.
        $agg_metrics_arr  = !empty($agg_record->metricsjson)
            ? (json_decode($agg_record->metricsjson, true) ?: [])
            : [];
        $agg_signal1_pts  = (int)(($agg_metrics_arr['signal_breakdown'][1] ?? 0));
        $agg_has_paste_evidence = ($agg_signal1_pts >= 30)
                               || ((float)$agg_record->riskscore >= 0.70);

        // FIX-EG-MISSED-PERQ-PASTE (v1.2.132): Extended condition introduced in v1.2.132
        // to catch Ctrl+V pastes where only modifier keydowns (Ctrl+V = 2 keydowns) were
        // tagged to the slot and the paste event landed in the aggregate pool — per-question
        // riskscore was capped at 29 (> 0.0), so the original guard never fired.
        //
        // FIX-EG-PERQ-PASTE-STRICT (v1.2.160): REVERTED to strict <= 0.0 guard.
        // Root cause of Review Attempt vs EssayGuard Report mismatch (reported v1.2.159):
        // When qslot event-tagging fails entirely (all keystrokes land in the aggregate
        // pool with qslot=0), the per-question record has total_keystrokes=0 and a
        // linguistic-fallback riskscore of ~0.25 (LOW). The v1.2.132 extended condition
        // (riskscore < 0.30 AND paste_events=0 AND keystrokes<=10) matched this profile
        // exactly, triggering the aggregate fallback for TYPED questions when any OTHER
        // question in the same attempt was pasted. Result: every question in Review Attempt
        // showed the aggregate HIGH score, while EssayGuard Report (which uses the strict
        // <= 0.0 gate in report.php) correctly showed the lower per-question score — a
        // consistent, reproducible mismatch for any multi-question quiz where one question
        // was pasted and qslot event-tagging partially failed.
        //
        // Strict guard: only fall back when the per-question record genuinely has NO
        // scoring signal at all (riskscore=0.0). A non-zero per-question score — even
        // a linguistic-fallback LOW — represents real information about that specific
        // question slot and must not be silently overridden by the aggregate.
        // This makes lib.php and report.php use identical fallback logic → no mismatch.
        $pq_looks_like_missed_paste = ((float)$pq_record->riskscore <= 0.0);
        if ($pq_looks_like_missed_paste && $agg_has_paste_evidence) {
            // Per-question scorer found no paste for this slot AND the aggregate
            // clearly detected a paste somewhere in the attempt: surface it.
            $record = $agg_record;
            $is_aggregate_fallback = true;
        } else {
            // Either the per-question score is meaningful, OR the aggregate has
            // no paste evidence to rescue it. Trust the per-question record.
            $record = $pq_record;
        }
    } else {
        $record = $pq_record ?? $agg_record;
        if ($record && $agg_record && $qslot > 0 && $record === $agg_record) {
            $is_aggregate_fallback = true;
        }
    }

    if (!$record) {
        // No score yet — teachers still see the report link; students see nothing.
        return $classreportlink;
    }

    // FIX-EG-BADGE-RENDER (v1.2.177): Delegate all badge HTML to render_badge().
    // Replaces the Mustache template (riskbadge.mustache) + manual link assembly with
    // the inline PHP renderer — inline styles, hover tooltip, pending/error states,
    // essayguard-wrap div, essayguard-link class. See render_badge() for details.
    $risk_pct  = max(0, min(100, (int)round(((float)$record->riskscore) * 100)));
    $risklevel = \plagiarism_essayguard\local\service\analyser::risk_level($risk_pct);
    $errmsg    = (string)($record->errormsg ?? '');

    return plagiarism_essayguard_render_badge(
        'analysed',
        (float)$risk_pct,
        $risklevel,
        $errmsg,
        $cmid,
        $userid,
        $is_teacher,
        $is_aggregate_fallback
    );
}
