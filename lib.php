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
 * Essay Guard plagiarism plugin library: the callbacks Moodle core looks for by name.
 *
 * Moodle loads this file on essentially every page through the plagiarism dispatcher, so
 * everything here is kept cheap and side-effect free. It provides the plugin-enablement
 * checks, the per-activity settings form elements, the student disclosure, the tracker
 * injection, the answer-to-question-slot matcher and the risk badge renderer, plus an
 * spl_autoload_register() that defers defining plagiarism_plugin_essayguard until first use.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// V1.2.219: GLOBAL ERROR HANDLER REMOVED, LEGACY CALLBACK FUNCTION REMOVED.
//
// What was here (v1.2.196–v1.2.198) and why it had to go:
//
// 1. A global no-op function plagiarism_essayguard_before_standard_top_of_body_html(),
// defined purely so Moodle's function_exists() check would short-circuit before it
// reached the deprecated update_status() branch. Moodle's get_plugins_with_function()
// then emitted "Callback ... should be migrated" for it, so this fix traded one
// developer notice for another.
//
// 2. set_error_handler('_eg_warning_suppressor', ...) at FILE SCOPE. lib.php is loaded
// on essentially every page of the site, so this replaced Moodle's own error handler
// for E_WARNING / E_NOTICE / E_DEPRECATED / E_USER_DEPRECATED site-wide, for every
// plugin and every core subsystem, for the whole request. Any other component's
// warning was routed through EssayGuard's two-string strcmp and then handed to PHP's
// default handler instead of Moodle's — losing Moodle's backtrace, its debugdisplay
// handling and its error reporting integration. A plagiarism plugin has no business
// owning the site's error handler, and it was also a lie: the notice it claimed to
// suppress is written by Moodle's debugging(), which echoes HTML directly and never
// passes through set_error_handler() at all.
//
// The cause is already fixed properly, further down this file: the spl_autoload_register
// block (FIX-EG-AUTOLOAD, v1.2.206) defers the plagiarism_plugin_essayguard class
// definition until first use, by which point plagiarism_plugin always exists, so the
// class genuinely extends it and INHERITS update_status(). Moodle's ReflectionMethod
// check then sees getDeclaringClass() === 'plagiarism_plugin' and never calls debugging().
// With the real cause fixed, both the stub function and the suppressor are dead weight.
//
// The before_standard_top_of_body_html_generation hook in db/hooks.php remains registered
// and handles that event through the supported hook API.


/**
 * Get Site ID: Central Config (local_aiconfig) takes priority over local setting.
 *
 * @return string|false The site ID to authenticate with lms-labs.com, or false when
 *                      neither local_aiconfig nor this plugin has one saved.
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
 *
 * @return string|false The API key to authenticate with lms-labs.com, or false when
 *                      neither local_aiconfig nor this plugin has one saved.
 */
function plagiarism_essayguard_get_apikey() {
    $aiconfig = get_config('local_aiconfig', 'apikey');
    if (!empty($aiconfig)) {
        return $aiconfig;
    }
    return get_config('plagiarism_essayguard', 'apikey');
}

/**
 * Verify credit unlock with the LMS Labs licence server.
 *
 * Result is cached for 30 minutes in Moodle's config table.
 *
 * v1.2.219: SYNCHRONOUS VENDOR CALL REMOVED FROM THE REQUEST PATH.
 *
 * Previously any caller with a stale cache performed the fetch itself: a 10 s
 * verify call which, if the site was not yet unlocked, chained into auto_unlock()
 * for a further 15 s. The callers are is_cm_active() — which runs on every grading
 * page render — and observer::is_active(), which runs inside quiz submission. So
 * one unlucky student pressing "Submit all and finish" at the moment the cache
 * expired paid up to 25 s of vendor network latency inside their submit request,
 * and every other student who hit the same expiry window queued behind it. Worse,
 * the whole cohort's caches expire together, so this stampedes.
 *
 * Now the request path is cache-only: it never opens a socket. The refresh is done
 * by \plagiarism_essayguard\task\refresh_licence (see db/tasks.php), which runs
 * from cron every 15 minutes and is the only caller that passes $allowfetch = true,
 * apart from the admin settings page where the admin explicitly asked for a live
 * check. A stale cache is served as-is rather than triggering a fetch, and a site
 * with no cache at all fails open (returns true) exactly as before.
 *
 * MIGRATION CONSEQUENCE: cron must be running for licence state to refresh. On a
 * site with broken cron the last cached result is served indefinitely; because the
 * behaviour is fail-open, that means scoring keeps working rather than stopping.
 *
 * @param bool $allowfetch True only from cron/the admin settings page.
 * @return bool
 */
function plagiarism_essayguard_check_unlock(bool $allowfetch = false) {
    global $CFG;
    static $runtimecache = null;
    // V1.2.229 FIX-EG-STATIC-CACHE-UNTESTABLE: a function-level static survives
    // phpunit_util::reset_all_data(), so the first value computed in a PHPUnit process
    // was returned to every later test in that process no matter what the test had
    // configured. That made the unlock gate - the gate that decides whether ANY scoring
    // happens - impossible to cover, which is why it had no test. In production each
    // request is a fresh process and the static is exactly the per-request memoisation
    // it was meant to be, so this changes nothing a site ever sees.
    if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
        $runtimecache = null;
    }
    if ($runtimecache !== null && !$allowfetch) {
        return $runtimecache;
    }

    // Persistent cache: valid for 30 minutes across all PHP workers.
    $cachedresult = get_config('plagiarism_essayguard', 'unlock_cache_result');
    $cachedtime   = (int)get_config('plagiarism_essayguard', 'unlock_cache_time');
    if (!$allowfetch && $cachedtime > 0 && (time() - $cachedtime) < 1800) {
        $runtimecache = !empty($cachedresult);
        return $runtimecache;
    }

    if (!$allowfetch) {
        // V1.2.219: Cache is stale or empty and we are on a request path. Do NOT fetch.
        // Serve the last known answer; with no cached answer at all, fail open so a
        // brand-new site is not blocked waiting for the first cron run.
        $runtimecache = ($cachedtime > 0) ? !empty($cachedresult) : true;
        return $runtimecache;
    }

    $siteid = plagiarism_essayguard_get_siteid();
    $apikey = plagiarism_essayguard_get_apikey();

    if (empty($siteid) || empty($apikey)) {
        // FIX-EG-UNLOCK-OPEN (v1.2.50): Credentials not yet configured — operate in
        // open/development mode rather than blocking all scoring. Admins who have not
        // yet entered their Site ID and API Key will still get full Essay Guard
        // functionality; the plugin simply skips the remote license check.
        debugging(
            '[plagiarism_essayguard] check_unlock: siteId or apiKey not configured.'
                . ' Operating in open mode. Configure via Site Administration → Plugins'
                . ' → Plagiarism → Essay Guard to enable license-based usage tracking.',
            DEBUG_DEVELOPER
        );
        $runtimecache = true;
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
    $response  = $curl->get(
        'https://lms-labs.com/api/plugin-unlock/verify',
        [
            'pluginId' => 'essayguard',
            'siteId'   => $siteid,
            'apiKey'   => $apikey,
            ]
    );
    $httpcode = (int)($curl->info['http_code'] ?? 0);

    if ($httpcode !== 200 || !$response) {
        // FIX-EG-UNLOCK-OPEN (v1.2.50): Fail-open on network/HTTP errors.
        // Previously returned false, which silently blocked all scoring and badge
        // display whenever the license server was unreachable (firewall, timeout, etc.).
        // Now logs the error but returns true so scoring continues uninterrupted.
        debugging(
            '[plagiarism_essayguard] check_unlock: cannot reach license server'
                . ' (HTTP ' . $httpcode . ', siteId=' . $siteid . ').'
                . ' Operating in open mode — scoring will continue.',
            DEBUG_DEVELOPER
        );
        $runtimecache = true;
        return true;
    }

    $data   = json_decode($response, true);
    $result = !empty($data['unlocked']);

    if (!$result) {
        // Not yet unlocked — attempt automatic unlock (deducts 5,000 credits).
        // This fires once per site: after success the verify endpoint returns
        // unlocked=true so this branch is never reached again.
        debugging(
            '[plagiarism_essayguard] check_unlock: site not unlocked — attempting auto-unlock. siteId='
                . $siteid,
            DEBUG_DEVELOPER
        );
        $result = plagiarism_essayguard_auto_unlock($siteid, $apikey);
        if ($result) {
            debugging('[plagiarism_essayguard] auto-unlock SUCCESS — 5,000 credits deducted. siteId=' . $siteid, DEBUG_DEVELOPER);
        } else {
            debugging(
                '[plagiarism_essayguard] auto-unlock FAILED — check credits balance or API key. siteId='
                    . $siteid,
                DEBUG_DEVELOPER
            );
        }
    }

    // Persist result for 30 minutes.
    set_config('unlock_cache_result', (int)$result, 'plagiarism_essayguard');
    set_config('unlock_cache_time', time(), 'plagiarism_essayguard');

    $runtimecache = $result;
    return $result;
}

/**
 * Automatically unlock Essay Guard for this site by posting to the
 * EssayGraderAI platform. Deducts 5,000 credits from the site's balance.
 * Returns true on success, false on failure (e.g. insufficient credits).
 *
 * @param string $siteid The site licence identifier.
 * @param string $apikey The site API key.
 * @return bool True when the site is now unlocked.
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
    $response  = $curl->post(
        'https://lms-labs.com/api/plugin-unlock',
        $payload,
        [
            'CURLOPT_TIMEOUT'        => 15,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_HTTPHEADER'     => ['Content-Type: application/json', 'Accept: application/json'],
            ]
    );
    $httpcode = (int)($curl->info['http_code'] ?? 0);

    if ($httpcode !== 200 || !$response) {
        debugging('[plagiarism_essayguard] auto_unlock HTTP error: ' . $httpcode . ' siteId=' . $siteid, DEBUG_DEVELOPER);
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
 *
 * v1.2.219: Same treatment as check_unlock() — the request path is cache-only.
 * is_cm_active() calls this on every grading-page render, so a 5 s vendor GET on
 * cache expiry landed directly in page render time. Only the refresh_licence
 * scheduled task and the admin settings page pass $allowfetch = true. A stale
 * cache is served as-is; with no cache at all the safe defaults (all false) apply,
 * which is exactly what the old code returned on a network failure anyway.
 *
 * @param bool $allowfetch True only from cron/the admin settings page.
 * @return array
 */
function plagiarism_essayguard_get_platform_settings(bool $allowfetch = false): array {
    static $cached = null;
    // V1.2.229 FIX-EG-STATIC-CACHE-UNTESTABLE: see plagiarism_essayguard_check_unlock().
    if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
        $cached = null;
    }
    if ($cached !== null && !$allowfetch) {
        return $cached;
    }

    $defaults = [
        'essayguard_assignments' => false,
        'essayguard_quizzes'     => false,
        'docguard_assignments'   => false,
        'docguard_quizzes'       => false,
    ];

    // Check 30-minute Moodle config cache.
    $cacheddata = get_config('plagiarism_essayguard', 'platform_settings_data');
    $cachetime  = (int)get_config('plagiarism_essayguard', 'platform_settings_time');
    if (!$allowfetch && $cachetime > 0 && !empty($cacheddata)) {
        // V1.2.219: Note the missing freshness test is deliberate on this path — a stale
        // cached answer is still better than a synchronous HTTPS call during page render.
        $decoded = json_decode($cacheddata, true);
        if (is_array($decoded)) {
            $cached = $decoded;
            return $cached;
        }
    }

    if (!$allowfetch) {
        // No usable cache and we are on a request path: return defaults, never fetch.
        $cached = $defaults;
        return $cached;
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
        $response  = $curl->get(
            'https://lms-labs.com/api/plagiarism-settings',
            [
                'siteId' => $siteid, 'apiKey' => $apikey,
                ]
        );
        $httpcode = (int)($curl->info['http_code'] ?? 0);
        if ($httpcode === 200 && !empty($response)) {
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
                set_config('platform_settings_time', time(), 'plagiarism_essayguard');
                $cached = $result;
                return $cached;
            }
        }
    } catch (\Throwable $e) {
        // Fail open — do not crash Moodle pages on network issues. The defaults below are
        // returned instead, but log the cause at developer level so an administrator asking
        // "why is the site-wide platform flag not applying?" has something to find.
        debugging(
            'Essay Guard: could not fetch platform settings from lms-labs.com — '
                . $e->getMessage(),
            DEBUG_DEVELOPER
        );
    }

    $cached = $defaults;
    return $cached;
}

/**
 * Whether Essay Guard should track and score submissions to one activity.
 *
 * A site-wide "all assignments" or "all quizzes" flag set on the lms-labs.com
 * platform overrides the per-activity checkbox; otherwise the checkbox decides.
 *
 * @param int $cmid The course module to test.
 * @return bool True when Essay Guard is active for that activity.
 */
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
        // Ignore — fall through to the per-cm checkbox check below. A failed platform
        // lookup must never make an activity page fatal, but record why at developer
        // level: silently skipping the site-wide override is otherwise undiagnosable.
        debugging(
            'Essay Guard: platform settings lookup failed for cmid ' . $cmid . ' — '
                . $e->getMessage(),
            DEBUG_DEVELOPER
        );
    }

    $value = get_config('plagiarism_essayguard', 'enabled_cm_' . $cmid);
    /* The get_config() call returns false when the key has never been saved.
     * Treat missing = active so badges still appear on pre-existing assignments.
     */
    return ($value === false) || !empty($value);
}

/**
 * v1.2.219: Declare the user preferences this plugin writes.
 *
 * inject_tracker() calls set_user_preference('essayguard_ak_<cmid>', ...) to remember
 * which typing-session key belongs to which activity, so observer.php can find the
 * events at submission time. That preference was never declared to core, never exported
 * on a data-subject-access request, and never deleted on an erasure request. Undeclared
 * preferences also trip Moodle's own privacy self-test.
 *
 * The name is dynamic (one per course module), so it is declared as a regexp preference.
 * permissioncallback is set to disallow: nothing should be able to write this over AJAX,
 * only our own server-side code writes it.
 *
 * @return array
 */
function plagiarism_essayguard_user_preferences(): array {
    return [
        'essayguard_ak_.*' => [
            'isregexp'           => true,
            'null'               => NULL_NOT_ALLOWED,
            'default'            => '',
            'type'               => PARAM_ALPHANUMEXT,
            'permissioncallback' => 'plagiarism_essayguard_pref_never_writable',
        ],
        // V1.2.219: written by log_event.php as the per-attempt scoring throttle marker.
        'essayguard_lastscore_.*' => [
            'isregexp'           => true,
            'null'               => NULL_NOT_ALLOWED,
            'default'            => '0',
            'type'               => PARAM_INT,
            'permissioncallback' => 'plagiarism_essayguard_pref_never_writable',
        ],
    ];
}

/**
 * v1.2.219: Permission callback for the essayguard_ak_* preferences.
 * Always false: these are written by inject_tracker() server-side only. Nothing should
 * be able to set or clear its own attempt key over the user-preference AJAX endpoint —
 * a student who could rewrite it would detach their telemetry from their submission.
 *
 * @param \stdClass $user The user whose preference is being written.
 * @param string $preferencename The name of the essayguard_ak_* preference being written.
 * @return bool Always false — the preference is never writable over the AJAX endpoint.
 */
function plagiarism_essayguard_pref_never_writable($user, $preferencename): bool {
    return false;
}

/**
 * v1.2.219: Real student-facing disclosure. print_disclosure() previously returned ''.
 *
 * Moodle renders this above the submission form. Returning an empty string meant a
 * student never learned, anywhere in the interface, that this activity records every
 * keystroke and paste they make and scores it for academic-integrity risk. That is the
 * one thing a behavioural-telemetry plugin is obliged to say out loud, and on a paying
 * client's site its absence is a compliance failure, not a cosmetic one.
 *
 * The retention period is read from config so the notice cannot drift from what the
 * cleanup task actually does.
 *
 * @param int $cmid Course module id.
 * @return string HTML, or '' when Essay Guard is not active for this activity.
 */
function plagiarism_essayguard_print_disclosure(int $cmid): string {
    // Say nothing when nothing is being captured.
    $globalenabled = get_config('plagiarism_essayguard', 'enabled');
    if ($globalenabled !== false && empty($globalenabled)) {
        return '';
    }
    if ($cmid > 0 && !plagiarism_essayguard_is_cm_active($cmid)) {
        return '';
    }

    $retentiondays = (int)get_config('plagiarism_essayguard', 'retentiondays');
    if ($retentiondays <= 0) {
        /* The get_config() call returns false for a never-saved key; the cleanup task's own
         * default is 90 days, so mirror it rather than claiming "kept forever".
         */
        $retentiondays = ((string)get_config('plagiarism_essayguard', 'retentiondays') === '0') ? 0 : 90;
    }
    $retentiontext = $retentiondays > 0
        ? get_string('disclosure_retention', 'plagiarism_essayguard', $retentiondays)
        : get_string('disclosure_retention_indefinite', 'plagiarism_essayguard');

    $out  = \html_writer::start_div('essayguard-disclosure', ['role' => 'note']);
    $out .= \html_writer::tag('strong', get_string('disclosure_heading', 'plagiarism_essayguard'));
    $out .= \html_writer::tag('p', get_string('disclosure_what', 'plagiarism_essayguard'));
    $out .= \html_writer::tag('p', get_string('disclosure_why', 'plagiarism_essayguard'));
    $out .= \html_writer::tag('p', get_string('disclosure_who', 'plagiarism_essayguard'));
    $out .= \html_writer::tag('p', $retentiontext);
    $out .= \html_writer::end_div();

    return $out;
}

/**
 * Shared tracker injection logic used by both the legacy callback (Moodle < 4.3)
 * and the new hook callback (Moodle 4.3+).
 *
 * @return void
 */
function plagiarism_essayguard_inject_tracker() {
    /* $DB is needed by the v1.2.224 attempt-ownership check below. */
    global $PAGE, $USER, $DB;

    if (isguestuser() || !isloggedin()) {
        return;
    }
    // FIX-EG-GLOBAL-ENABLED-MISSING (v1.2.88): get_config() returns false when the
    // key has never been saved (fresh install where admin hasn't explicitly saved
    // plugin settings yet). Treat missing key as enabled — consistent with
    // is_cm_active(). Only skip injection when the key EXISTS and is explicitly
    // set to a disabled value ('0' or empty string).
    $globalenabled = get_config('plagiarism_essayguard', 'enabled');
    if ($globalenabled !== false && empty($globalenabled)) {
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
    // • observer.php is_active() — gates PHP observer scoring
    // • finalize_attempt.php execute() — gates JS-path scoring
    // • log_event.php already stores events first, then gates scoring
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
    if (
        is_siteadmin() ||
            has_capability('moodle/course:manageactivities', $context) ||
            has_capability('moodle/grade:edit', $context)
    ) {
        return; // CSS loaded above; JS tracker not needed for teachers/admins.
    }

    // FIX-EG-QUIZ-PAGE-GUARD (v1.2.78 + extended v1.2.79): Only inject the tracker
    // on quiz ATTEMPT pages.  The tracker must be completely silent on every other
    // quiz page type:
    //
    // mod-quiz-view     view.php?id=N    – quiz landing / "Attempt quiz" button
    // mod-quiz-review   review.php       – post-submission results view
    // mod-quiz-summary  summary.php      – "Check your answers" confirmation page
    // mod-quiz-grade    grade.php        – teacher grading view
    //
    // All of those pages can share the same cm context and are visited by students,
    // so the old injection fired on all of them.  The only page where keystrokes can
    // actually be recorded and a form can actually be submitted is:
    //
    // mod-quiz-attempt  attempt.php      – the live question page
    //
    // FIX-EG-QUIZ-PAGE-GUARD-HOTFIX (v1.2.80): The v1.2.79 pagetype-only guard
    // silently blocked injection on attempt.php when $PAGE->pagetype was not yet
    // set to 'mod-quiz-attempt' at hook-fire time on some Moodle 4.3+ installations.
    // Dual check: pagetype OR REQUEST_URI path — attempt.php always passes at least
    // one of these regardless of hook timing relative to $PAGE->set_pagetype().
    if ($cm->modname === 'quiz') {
        $pagetypeok = in_array($PAGE->pagetype, ['mod-quiz-attempt'], true);
        $diagurl    = $_SERVER['REQUEST_URI'] ?? '';
        $uriok      = (strpos($diagurl, '/mod/quiz/attempt.php') !== false);
        if (!$pagetypeok && !$uriok) {
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
    // • Quiz: use the quiz attempt ID directly — stable, unique, correct.
    // • Non-quiz (assignment, forum): userid:cmid hash — sesskey-free.
    // v1.2.224 FIX-EG-ATTEMPTKEY-UNVALIDATED: the attempt id came straight off the URL
    // and was turned into a key that is then written to this user's preferences, with
    // nothing checking that the attempt belongs to them or even to this activity.
    // log_event.php and finalize_attempt.php both validate qa_* ownership before writing
    // score data, so this was not exploitable end to end - but an unvalidated request
    // parameter that reaches a database write on a plain page render is exactly what a
    // reviewer looks for, and the validation belongs at the point the value is accepted.
    //
    // An attempt that is not this user's own, or not in this activity, falls back to the
    // hash key rather than being trusted.
    $quizattemptid = optional_param('attempt', 0, PARAM_INT);
    $attemptkey    = sha1($USER->id . ':' . $cm->id);

    if ($quizattemptid > 0 && $cm->modname === 'quiz') {
        $ownattempt = $DB->record_exists(
            'quiz_attempts',
            [
                'id'     => $quizattemptid,
                'userid' => $USER->id,
                'quiz'   => $cm->instance,
                ]
        );
        if ($ownattempt) {
            $attemptkey = 'qa_' . $quizattemptid;
        } else {
            debugging(
                '[plagiarism_essayguard] attempt=' . $quizattemptid
                . ' is not this user\'s attempt in cm ' . $cm->id . ' - falling back to the hash key.',
                DEBUG_DEVELOPER
            );
        }
    }

    // Persist the key in user preferences so the PHP event observer can
    // retrieve it reliably, even if JS telemetry events have not yet been
    // flushed to the database by the time the observer fires.
    set_user_preference('essayguard_ak_' . $cm->id, $attemptkey);

    $config = [
        'cmid'           => $cm->id,
        'attemptkey'     => $attemptkey,
        // V1.2.224 FIX-EG-CONFIG-ZERO: `?:` treats a deliberate 0 as absent and silently
        // substitutes the default - the same trap already documented at analyser.php's
        // paste_weight. Only an UNSET key (get_config returns false) should take the
        // default; an admin who typed 0 meant 0.
        'flushinterval'  => plagiarism_essayguard_config_int('captureinterval', 5000),
        // Allowpaste's intended default IS 0 (paste not allowed), so the raw cast is
        // correct here: (int)false === 0 gives the right answer for the unset case.
        'allowpaste'     => (int)get_config('plagiarism_essayguard', 'allowpaste'),
        // V1.2.228 FIX-EG-TRACKER-MAXBURST-ZERO: this used the raw accessor while the line
        // above it went through config_int(), and the comment two lines up explains
        // precisely why that is wrong - get_config() returns false for a key nobody has
        // written and (int)false is 0. Neither db/install.php nor db/upgrade.php ever
        // writes a maxburstchars default, so on EVERY fresh install the browser was told
        // the burst threshold was 0 rather than 150.
        //
        // The consequence is on the client: tracker.js flags a burst with
        // `delta >= state.maxburstchars`, so a threshold of 0 marks EVERY input event as a
        // suspicious burst. That is the identical defect v1.2.224 fixed on the server side
        // in analyser.php - "`>= $maxburstchars` alone made every input event suspicious
        // when an administrator set maxburstchars to 0". The server was hardened and the
        // configuration feed to the client was not, so the two halves of the same plugin
        // disagreed about what a burst is on every site that had not saved its settings.
        'maxburstchars'  => plagiarism_essayguard_config_int('maxburstchars', 150),
    ];

    $PAGE->requires->js_call_amd('plagiarism_essayguard/tracker', 'init', [$config]);
}

/**
 * Read an integer setting, distinguishing "never set" from a deliberate zero.
 *
 * get_config() returns false - not null - for a key an administrator has never written,
 * so `?? $default` never fires and `?: $default` fires for a legitimate 0. Both traps
 * have produced defects in this plugin before. This is the one place that gets it right:
 * false means take the default, anything else means the admin chose it.
 *
 * @param string $name The setting name within the plagiarism_essayguard component.
 * @param int $default The value to use when the setting has never been saved.
 * @return int The configured value, or $default when the key is unset.
 */
function plagiarism_essayguard_config_int(string $name, int $default): int {
    $raw = get_config('plagiarism_essayguard', $name);
    return ($raw === false || $raw === null || $raw === '') ? $default : (int)$raw;
}

/**
 * Trim an ordered attempt list to the part a resumed rescore batch has not seen.
 *
 * v1.2.225 FIX-EG-RESCORE-UNSTABLE-CURSOR: rescore.php used to resume from a numeric
 * OFFSET into a list ordered `timefinish DESC, id DESC`. That list is not stable between
 * batches: a teacher rescores a quiz precisely when students are still submitting to it,
 * and every attempt finished mid-run is inserted at the FRONT of the ordering, shifting
 * every index. Measured on a 12-attempt quiz with two submissions between each batch of
 * four, the offset walked 112,111,110,109,110,109,108,107,108,107,106,105 - it processed
 * four attempts twice and never touched 104, 103, 102 or 101 at all, then reported the
 * run complete. The cursor walks each attempt exactly once.
 *
 * Lives here rather than inline in rescore.php so it can be tested. The page had no
 * testable seam at all, which is why an off-by-a-shifting-amount bug survived two
 * releases of work on that same loop.
 *
 * @param array $attempts Attempt records in display order, each with an `id` property.
 * @param int $afterid Resume after this attempt id; 0 to start from the beginning.
 * @return array The attempts following the cursor, preserving order and keys. Empty when
 *               the cursor names an attempt that is no longer in the list - a deleted
 *               attempt or a reset quiz must not silently restart the whole run.
 */
function plagiarism_essayguard_attempts_after(array $attempts, int $afterid): array {
    if ($afterid <= 0) {
        return $attempts;
    }

    $seen = false;
    $resumed = [];
    foreach ($attempts as $key => $qa) {
        if ($seen) {
            $resumed[$key] = $qa;
        } else if ((int)$qa->id === $afterid) {
            $seen = true;
        }
    }

    return $seen ? $resumed : [];
}

/**
 * Whether Essay Guard can act on this activity type.
 *
 * @param string $modulename The activity type name, e.g. "quiz".
 * @return bool True for assign, quiz and forum; false for every other type.
 */
function plagiarism_essayguard_supports_mod($modulename) {
    $supported = ['assign', 'quiz', 'forum'];
    return in_array($modulename, $supported, true);
}

/**
 * Add the "Enable Essay Guard" checkbox to the activity settings form.
 *
 * Adds nothing for activity types Essay Guard cannot act on, or when the plugin is
 * switched off site-wide, so no teacher is shown a control that cannot take effect.
 *
 * @param object $formwrapper The moodleform_mod instance being built.
 * @param object $mform       The underlying QuickForm to add elements to.
 * @return void
 */
function plagiarism_essayguard_coursemodule_standard_elements($formwrapper, $mform) {
    global $CFG;
    // V1.2.218: only offer this section on activity types the plugin can actually act on.
    //
    // Moodle calls coursemodule_standard_elements() for EVERY activity type, so the
    // "Essay Guard" section was being added to Page, URL, Label, Folder, Book, Choice - every
    // form in the site - offering a setting that does nothing there. plagiarism_essayguard_supports_mod()
    // already existed for precisely this check and was never called from anywhere.
    //
    // It is also hidden when the plugin is switched off site-wide, or when the site is not
    // unlocked: a teacher should not be shown a control that cannot take effect.
    $modulename = '';
    if ($formwrapper && method_exists($formwrapper, 'get_current')) {
        $current = $formwrapper->get_current();
        if (!empty($current->modulename)) {
            $modulename = $current->modulename;
        }
    }
    if ($modulename !== '' && !plagiarism_essayguard_supports_mod($modulename)) {
        return;
    }
    // V1.2.223: use the plugin's OWN "enabled" convention, not a bare truthiness test.
    // This is the same get_config()-returns-false trap that had silently disabled paste
    // detection in the analyser; I reintroduced it here while adding the guard.
    //
    // There is no plagiarism_essayguard_is_enabled() - the global switch is read inline
    // everywhere else in this file (see inject_tracker), using the convention that an
    // UNSET key means enabled. get_config() returns false for a key that was never saved,
    // so a bare `!get_config(...)` reads "administrator has never opened the settings
    // page" as "plugin is off" and strips the section from the very activity types it
    // belongs on. Verified live: the section was missing from a quiz, a supported type.
    $egglobalenabled = get_config('plagiarism_essayguard', 'enabled');
    if (
        empty($CFG->enableplagiarism)
            || ($egglobalenabled !== false && empty($egglobalenabled))
    ) {
        return;
    }

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

/**
 * Save the "Enable Essay Guard" checkbox when an activity is saved.
 *
 * Writes the value unconditionally so that clearing the checkbox stores 0 rather
 * than leaving the previous value in place.
 *
 * @param object $data   The submitted course module form data.
 * @param object $course The course the activity belongs to.
 * @return object The unmodified $data, as the hook contract requires.
 */
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
 *
 * @return void
 */
function plagiarism_essayguard_before_standard_html_head() {
    if (class_exists('core\hook\output\before_standard_head_html_generation')) {
        return;
    }
    plagiarism_essayguard_inject_tracker();
}

/*
 * NOTE: this is a plain block comment, not a docblock. It documents the
 * plagiarism_plugin_essayguard class, but the class is not declared here — it is
 * required in on demand by the spl_autoload_register() below — so a doc-block opener here would
 * be an orphaned docblock (moodle.Commenting.InlineComment.DocBlock).
 *
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
// During Moodle's early bootstrap (setup.php get_plugins_with_function scan), lib.php
// is loaded before plagiarism_plugin base class is available. Path A (standalone with
// update_status() stub) is taken permanently for the entire PHP request. Later, when
// plagiarism_update_status() calls:
// new ReflectionMethod('plagiarism_plugin_essayguard', 'update_status')
// getDeclaringClass() returns 'plagiarism_plugin_essayguard' (not 'plagiarism_plugin'),
// so Moodle calls debugging('plagiarism_plugin::update_status() is deprecated...').
// Moodle's debugging() writes HTML directly to the output buffer — set_error_handler()
// cannot intercept it. No code change inside lib.php can fix a class already defined.
//
// Fix: Register an SPL autoloader so the class is defined on FIRST USE, not at lib.php
// load time. The early bootstrap scan (get_plugins_with_function) only checks for the
// existence of global FUNCTIONS — it never instantiates the plugin class. So the
// autoloader never fires during early bootstrap. It fires the first time Moodle calls
// class_exists('plagiarism_plugin_essayguard') or new plagiarism_plugin_essayguard() inside
// plagiarism_update_status() — by which point Moodle is fully bootstrapped and
// plagiarism_plugin is always defined. The class always extends plagiarism_plugin,
// inheriting update_status(). getDeclaringClass()->getName() === 'plagiarism_plugin'
// → no debugging() call → zero warnings in any debug mode.
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
                try {
                    require_once($CFG->libdir . '/plagiarismlib.php');
                } catch (\Throwable $e) {
                    debugging(
                        'Essay Guard: could not load ' . $CFG->libdir . '/plagiarismlib.php — '
                            . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }
            if (!class_exists('plagiarism_plugin', false)) {
                $eglib = dirname(dirname(dirname(__FILE__))) . '/lib/plagiarismlib.php';
                if (file_exists($eglib)) {
                    try {
                        require_once($eglib);
                    } catch (\Throwable $e) {
                        debugging(
                            'Essay Guard: could not load ' . $eglib . ' — '
                                . $e->getMessage(),
                            DEBUG_DEVELOPER
                        );
                    }
                }
                unset($eglib);
            }
        }
        if (class_exists('plagiarism_plugin', false)) {
            // Path B: class extends plagiarism_plugin.
            // update_status() INHERITED — getDeclaringClass() = 'plagiarism_plugin' → no warning.
            require_once(__DIR__ . '/classes/pluginclass.php');
        } else {
            // Path A fallback (should never happen on a normal web page load).
            // update_status() stub prevents ReflectionException crash.
            require_once(__DIR__ . '/classes/pluginclass_standalone.php');
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
// hook registered → clean dispatch path.
// Path B guaranteed → no update_status() deprecation.

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
    // The trailing comments below describe the SHAPE of each cache, not PHP code;
    // Squiz.PHP.CommentedOutCode misreads the "=>" arrows as an array literal.
    // phpcs:disable Squiz.PHP.CommentedOutCode.Found
    static $claimedslots = [];  /* "cmid:userid" => [slot => true] */
    static $answercache  = [];  /* "cmid:userid" => [slot => normalised_text] */
    // phpcs:enable Squiz.PHP.CommentedOutCode.Found

    $cachekey = $cmid . ':' . $userid;

    // Populate the answer cache for this user+cm on first call.
    if (!array_key_exists($cachekey, $answercache)) {
        $answercache[$cachekey] = [];

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

            $seenslots = [];
            foreach ($rows as $row) {
                $slot = (int)$row->slot;
                if (isset($seenslots[$slot])) {
                    continue; // Keep only the most recent step per slot.
                }
                $seenslots[$slot] = true;
                // Normalise: strip HTML, collapse whitespace, trim.
                $norm = trim(preg_replace('/\s+/', ' ', strip_tags((string)($row->value ?? ''))));
                if ($norm !== '') {
                    $answercache[$cachekey][$slot] = $norm;
                }
            }
        }
    }

    if (empty($answercache[$cachekey])) {
        return 0; // Not a quiz, or attempt not found.
    }

    // Normalise the incoming content the same way.
    $normcontent = trim(preg_replace('/\s+/', ' ', strip_tags($content)));
    if ($normcontent === '') {
        return 0;
    }

    // FIX-EG-QSLOT-CONTENT-FUZZY (v1.2.106): similarity-based matching.
    // FIX-EG-QSLOT-THRESHOLD (v1.2.114): threshold lowered 85% → 60%.
    // FIX-EG-IDENTICAL-ANSWER-QSLOT (v1.2.149): claim-once slot resolution.
    //
    // Apply html_entity_decode() before similar_text() so HTML entities in the
    // content string (&amp;, &nbsp; etc.) count as single chars, matching the
    // stored plain-text value from question_attempt_step_data.
    $decodedcontent = html_entity_decode($normcontent, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $claimed = $claimedslots[$cachekey] ?? [];

    // Track the overall best match AND the best unclaimed match separately.
    // Unclaimed slots are always preferred; claimed slots are the last resort.
    $bestslot           = 0;
    $bestpct            = 0.0;
    $bestunclaimedslot = 0;
    $bestunclaimedpct  = 0.0;

    foreach ($answercache[$cachekey] as $slot => $slottext) {
        $decodedslot = html_entity_decode($slottext, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        similar_text($decodedcontent, $decodedslot, $simpct);
        if ($simpct < 60.0) {
            continue;
        }
        // Track overall best (fallback when every slot is already claimed).
        if ($simpct > $bestpct) {
            $bestpct  = $simpct;
            $bestslot = $slot;
        }
        // Track best unclaimed slot — this is what we prefer to return.
        if (!isset($claimed[$slot]) && $simpct > $bestunclaimedpct) {
            $bestunclaimedpct  = $simpct;
            $bestunclaimedslot = $slot;
        }
    }

    // Prefer an unclaimed slot; only fall back to a claimed slot if every
    // matching slot has already been resolved in this page-render cycle.
    $resolved = $bestunclaimedslot > 0 ? $bestunclaimedslot : $bestslot;

    if ($resolved > 0) {
        $claimedslots[$cachekey][$resolved] = true;
        return $resolved;
    }

    return 0; // No match found.
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
 * @param string $status  Analysis state of the record: 'analysed', 'pending' or 'error'.
 * @param float  $score   Risk score 0-100.
 * @param string $level   Risk band: 'low', 'medium', 'high', 'partial' or 'mild'.
 * @param string $errmsg  Error message (status='error' only).
 * @param int    $cmid    Course-module ID.
 * @param int    $userid  Student's user ID.
 * @param bool   $isteacher  True when the viewer has viewreport capability.
 * @param bool   $isaggregatefallback  True when the aggregate record was used.
 * @return string The badge HTML, including the one-time inline style block on first call.
 */
function plagiarism_essayguard_render_badge(
    string $status,
    float $score,
    string $level,
    string $errmsg,
    int $cmid,
    int $userid,
    bool $isteacher,
    bool $isaggregatefallback
): string {
    // Inline styles guarantee the badge is always visible, even when
    // styles.css has not loaded yet (e.g. AJAX responses, late-include paths).
    // Must be kept in sync with the essayguard-badge block in styles.css.
    static $stylesinjected = false;
    $styleblock = '';
    if (!$stylesinjected) {
        $stylesinjected = true;
        $styleblock = '<style>'
            . '.essayguard-badge{display:inline-flex;align-items:center;gap:5px;padding:3px '
                . '10px;border-radius:4px;font-size:.78rem;font-weight:600;letter-spacing:.01em;line-height:1.4;margin:2px '
                . '0;border:1px solid transparent;cursor:default;position:relative;text-decoration:none;}'
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
            . '.essayguard-badge[data-eg-tip]:hover::after{content:attr(data-eg-tip);position:absolute;bottom:calc(100% '
                . '+ '
                . '6px);left:0;z-index:9999;background:#1e293b;color:#f1f5f9;font-size:.72rem;'
                . 'font-weight:400;line-height:1.5;padding:7px '
                . '11px;border-radius:5px;width:300px;white-space:normal;pointer-events:none;box-shadow:0 4px 12px '
                . 'rgba(0,0,0,.25);}'
            . '.essayguard-badge[data-eg-tip]:hover::before{content:"";position:absolute;bottom:calc(100% + '
                . '1px);left:14px;border:5px solid transparent;border-top-color:#1e293b;pointer-events:none;}'
            . '</style>';
    }

    $badgeclass = 'essayguard-badge';
    $dotclass   = 'essayguard-badge-dot';
    $label       = '';
    $tooltip     = '';

    if ($status === 'analysed') {
        $scoreint   = (int)$score;
        // Normalise legacy DB values (partial/mild) to canonical display level.
        // v1.2.227: 'unmeasured' is not a risk band - it is the absence of one. It must
        // never be folded into 'low'. See FIX-EG-NOTHING-MEASURED-READS-LOW in analyser.php.
        if (($level ?? '') === 'unmeasured') {
            $badgeclass .= ' essayguard-badge-unmeasured';
            $dotclass   .= ' essayguard-badge-dot-unmeasured';
            $label        = get_string('badgeunmeasured', 'plagiarism_essayguard');
            $tooltip      = get_string('tooltipunmeasured', 'plagiarism_essayguard');
            $canonlevel  = 'unmeasured';
        } else {
            $levelmap   = ['low' => 'low', 'medium' => 'medium', 'high' => 'high', 'partial' => 'medium', 'mild' => 'medium'];
            // V1.2.224 FIX-EG-BADGE-FAILS-GREEN: this fell back to 'low', so a risklevel the
            // map does not recognise - a value written by a future version, a truncated
            // column, a partially-migrated row - rendered the reassuring green LOW badge with
            // the record's real percentage beside it. A display fallback for an unknown value
            // must not be the reassuring one: it tells the teacher there is nothing to look at
            // in exactly the case where nobody knows whether there is. Fall back to 'medium',
            // which prompts a human to open the record and decide.
            $canonlevel = $levelmap[$level] ?? 'medium';
            $levellabels = [
            'low'    => get_string('risklow', 'plagiarism_essayguard'),
            'medium' => get_string('riskmedium', 'plagiarism_essayguard'),
            'high'   => get_string('riskhigh', 'plagiarism_essayguard'),
            ];
            $levellabel = $levellabels[$canonlevel] ?? ucfirst($canonlevel);
            $suffix      = $isaggregatefallback
            ? get_string('badgesuffixoverall', 'plagiarism_essayguard')
            : '';
            $badgeclass .= ' essayguard-badge-' . $canonlevel;
            $dotclass   .= ' essayguard-badge-dot-' . $canonlevel;
            $label        = get_string(
                'badgelabel',
                'plagiarism_essayguard',
                (object) [
                    'level'  => $levellabel,
                    'suffix' => $suffix,
                    'score'  => $scoreint,
                    ]
            );
            $tooltips     = [
            'low'    => get_string('tooltiplow', 'plagiarism_essayguard', $scoreint),
            'medium' => get_string('tooltipmedium', 'plagiarism_essayguard', $scoreint),
            'high'   => get_string('tooltiphigh', 'plagiarism_essayguard', $scoreint),
            ];
            $tooltip = $tooltips[$canonlevel] ?? $label;
        } // v1.2.227: closes the else that guards the normal banding path.
    } else if ($status === 'error') {
        $badgeclass .= ' essayguard-badge-error';
        $dotclass   .= ' essayguard-badge-dot-error';
        $label        = get_string('badgeerror', 'plagiarism_essayguard');
        $tooltip      = get_string(
            'tooltiperror',
            'plagiarism_essayguard',
            trim($errmsg) ?: get_string('tooltiperrordefault', 'plagiarism_essayguard')
        );
    } else {
        // Pending or any unrecognised status.
        $badgeclass .= ' essayguard-badge-pending';
        $dotclass   .= ' essayguard-badge-dot-pending';
        $label        = get_string('badgepending', 'plagiarism_essayguard');
        $tooltip      = get_string('tooltippending', 'plagiarism_essayguard');
    }

    $badge = $styleblock
        . '<span class="' . $badgeclass . '" data-eg-tip="' . s($tooltip) . '">'
        . '<span class="' . $dotclass . '"></span>'
        . s($label)
        . '</span>';

    $links = '';
    if ($isteacher) {
        if ($status === 'analysed') {
            $detailurl = new \moodle_url(
                '/plagiarism/essayguard/student.php',
                [
                    'cmid'   => $cmid,
                    'userid' => $userid,
                    ]
            );
            $links .= '<a href="' . $detailurl->out(false) . '" class="essayguard-link">'
                . get_string('detaillink', 'plagiarism_essayguard') . '</a>';
        }
        $classurl = new \moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]);
        $links .= '<a href="' . $classurl->out(false) . '" class="essayguard-link" style="margin-left:8px;">'
            . get_string('classreportlink', 'plagiarism_essayguard') . '</a>';
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
 *
 * @param array $linkarray Moodle plagiarism link data. Keys used: cmid, userid,
 *                         content, and the quiz question attempt when reviewing one.
 * @return string HTML for the badge, or the empty string when nothing is shown.
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

    $context = \context_module::instance($cmid);
    $userid  = isset($linkarray['userid']) ? (int)$linkarray['userid'] : (int)$USER->id;

    $isteacher = has_capability('plagiarism/essayguard:viewreport', $context);
    $isown     = ($userid === (int)$USER->id);

    // Students can only see their own badge; teachers can see all.
    if (!$isteacher && !$isown) {
        return '';
    }

    /* ── PERF-FIX-EG-BATCH-PRELOAD (v1.2.211) ───────────────────────────────── */
    // View All Submissions calls get_links() once per student row.
    // Pre-v1.2.211: 3 DB queries per student (DISTINCT qslot + pq_record +
    // agg_record) = 150+ queries for 50 students on one page load.
    // Fix: load the plagiarism_essayguard_sc rows for this CM in ONE query on
    // the first call; every subsequent student row is a pure O(1) array lookup.
    // The static cache lives for the PHP request lifetime only — reset between
    // page loads automatically.
    //
    // v1.2.219: TWO FIXES TO THAT PRELOAD.
    //
    // (a) SELECT * pulled metricsjson and explanationsjson — two TEXT blobs, several
    // KB each — for every user and every question slot in the activity. On a
    // 200-student, 5-question quiz that is 1,200 rows of blob hydrated into PHP
    // memory to render one badge. Only riskscore, qslot, userid and (for the
    // aggregate-fallback rule below) metricsjson are ever read; explanationsjson
    // is never touched here. The column list is now explicit.
    //
    // (b) The preload ran BEFORE the capability check and was never scoped by user, so
    // a STUDENT viewing their own submission loaded every classmate's score rows
    // into their own request. The capability check is now hoisted above the query
    // and a viewer without 'viewreport' gets a userid-scoped query. The static
    // cache is keyed by cmid AND scope so a teacher's full preload is never served
    // to a student in the same request, or vice versa.
    // Shape of the two statics: $_eg_sc_preloaded is keyed by cache key and holds true once
    // that key's rows have been loaded. $_eg_sc_cache is keyed by cache key, then by user id,
    // then by question slot, and holds one score record for each.
    static $egscpreloaded = [];
    static $egsccache     = [];

    $scopeuid = $isteacher ? 0 : (int)$USER->id;
    $cachekey = $cmid . ':' . $scopeuid;

    if (!isset($egscpreloaded[$cachekey])) {
        $egscpreloaded[$cachekey] = true;
        $where  = 'cmid = :cmid';
        $params = ['cmid' => $cmid];
        if ($scopeuid > 0) {
            $where .= ' AND userid = :userid';
            $params['userid'] = $scopeuid;
        }
        $allrows = $DB->get_records_sql(
            "SELECT id, userid, cmid, qslot, riskscore, risklevel, metricsjson, timemodified
               FROM {plagiarism_essayguard_sc}
              WHERE {$where}
           ORDER BY timemodified DESC",
            $params
        );
        foreach ($allrows as $row) {
            $uid  = (int)$row->userid;
            $slot = (int)$row->qslot;
            // Keep only the most-recent record per (userid, qslot) pair
            // (ORDER BY timemodified DESC guarantees first-wins = newest).
            if (!isset($egsccache[$cachekey][$uid][$slot])) {
                $egsccache[$cachekey][$uid][$slot] = $row;
            }
        }
    }
    /* ───────────────────────────────────────────────────────────────────────── */

    // Teachers see a class-report link when a full badge cannot be shown (qslot=0 path).
    $classreportlink = '';
    if ($isteacher) {
        $classreporturl  = new \moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]);
        $classreportlink = '<a href="' . $classreporturl->out(false) . '" class="essayguard-link">Essay Guard class report</a>';
    }

    // FIX-EG-CALLORDER-SKIP-RESOLVED (v1.2.165): Unified slot-resolution tracker.
    //
    // Previously each detection method tracked claimed/resolved slots independently:
    // • find_qslot_by_content() had its own internal $claimed_slots static.
    // • The call-order fallback had its own $co_idx sequential counter.
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
    static $resolved = [];  /* "cmid:userid" => [slot => true] — all methods combined */

    $rk = $cmid . ':' . $userid;

    // V1.2.14: attempt to retrieve a per-question score when Moodle passes a
    // question_attempt object (quiz essay grading / review view).
    // Moodle passes $linkarray['questionattempt'] = question_attempt instance,
    // which exposes ->get_slot() returning the 1-based question slot number.
    $qslot = 0;
    if (
        !empty($linkarray['questionattempt'])
            && method_exists($linkarray['questionattempt'], 'get_slot')
    ) {
        $qslot = (int)$linkarray['questionattempt']->get_slot();
        if ($qslot > 0) {
            $resolved[$rk][$qslot] = true;  // Record so call-order skips this slot.
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
    $egcontentplain = trim(strip_tags((string)($linkarray['content'] ?? '')));
    if ($qslot === 0 && mb_strlen($egcontentplain) > 30) {
        $qslot = plagiarism_essayguard_find_qslot_by_content($cmid, $userid, (string)$linkarray['content']);
        if ($qslot > 0) {
            $resolved[$rk][$qslot] = true;  // Record so call-order skips this slot.
        }
    }

    // FIX-EG-QSLOT-CALLORDER (v1.2.155): Last-resort call-order slot assignment.
    // FIX-EG-CALLORDER-SKIP-RESOLVED (v1.2.165): Replaced $co_idx sequential counter
    // with first-unresolved-slot iteration using the unified $resolved tracker above.
    // FIX-EG-CALLORDER-NO-CONTENT-GATE (v1.2.185): Content gate removed — see above.
    //
    // Both primary detection paths returned 0:
    // • get_slot() — 'questionattempt' absent (overview page) or Moodle < 4.1.
    // • find_qslot_by_content() — content < 30 chars (overview page status strings)
    // OR similarity < 60% (short answer, multi-attempt, different whitespace).
    //
    // When per-question records exist for this user+cmid, Moodle always calls
    // get_links() for quiz essay questions in question-slot order (slot 1 → slot 2 → …)
    // within a single page request. We pick the first slot that has NOT already been
    // resolved by a prior call, ensuring Q2 cannot re-use Q1's slot when Q1 was
    // resolved by a different detection method.
    //
    // Safety:
    // • If no per-question records exist (assignment / forum — only qslot=0 written),
    // $co_slots[$ck] is empty and the block is a no-op; aggregate is used as before.
    // • Static arrays are per-PHP request; they reset between page loads automatically.
    if ($qslot === 0) {
        static $coslots = [];  /* "cmid:userid" => ordered list of distinct per-Q slots */

        $ck = $cmid . ':' . $userid;
        if (!array_key_exists($ck, $coslots)) {
            // PERF-FIX-EG-BATCH-PRELOAD: serve from request-level cache; no DB query.
            $userslots = array_keys($egsccache[$cachekey][$userid] ?? []);
            $userslots = array_values(
                array_filter($userslots, function ($s) {
                    return $s > 0;
                    })
            );
            sort($userslots);
            $coslots[$ck] = $userslots;
        }

        // Pick the first slot not already resolved by any prior detection method.
        $already = $resolved[$rk] ?? [];
        foreach ($coslots[$ck] as $candidate) {
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
    $isaggregatefallback = false;   // FIX-EG-IS-AGGREGATE (v1.2.84).

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
    // 1. Fetch the per-question record (qslot=N).
    // 2. If it exists AND has riskscore > 0 → trust it; it is the accurate, question-
    // specific score written by the PHP observer using the final submitted text for
    // this slot. Do NOT compare against the aggregate.
    // 3. If it exists BUT has riskscore = 0 → qslot detection likely failed in
    // tracker.js (events not tagged → no events found for this slot → score=0).
    // Fall back to the aggregate which may have correctly captured paste behaviour
    // via the untagged qslot=0 event pool. (Preserves the v1.2.87 intent for the
    // broken-qslot-detection edge case only.)
    // 4. If no per-question record exists → fall back to aggregate as before.
    $pqrecord  = null;
    $aggrecord = null;

    if ($qslot > 0) {
        // PERF-FIX-EG-BATCH-PRELOAD: serve from request-level cache; no DB query.
        $pqrecord = $egsccache[$cachekey][$userid][$qslot] ?? null;
    }

    // Always fetch aggregate — needed as fallback for the riskscore=0 edge case.
    // PERF-FIX-EG-BATCH-PRELOAD: serve from request-level cache; no DB query.
    $aggrecord = $egsccache[$cachekey][$userid][0] ?? null;

    // Select the record to display for this question.
    // FIX-EG-FALLBACK-PASTE-ONLY (v1.2.110): The fallback rule must satisfy two
    // simultaneous user-reported failures from live testing:
    //
    // Bug A — pasted Q1 → LOW: Ctrl+V records 1–2 keydown events tagged with
    // this qslot, but the paste/large_insert event was tagged with a different
    // qslot (or none). The per-question scorer sees keystrokes but no paste
    // signal → riskscore=0, total_keystrokes>0. v1.2.109 trusted that 0% and
    // never consulted the aggregate, so the paste detected at the attempt
    // level was hidden.
    // Bug B — typed naturally → MEDIUM: qslot detection failed entirely,
    // per-question record has riskscore=0 and no activity → v1.2.109 fell
    // back to the aggregate. The aggregate, scoring all of the student's
    // clean typing, hit Signal 4 (no long pauses) + Signal 5 (no backspaces)
    // ≈ 35 and badged the honest typist MEDIUM (OVERALL).
    //
    // The shared root cause is that v1.2.109's discriminator (per-question
    // activity) does not distinguish between "the aggregate genuinely detected
    // a paste" and "the aggregate is just typing-signal noise". A single rule
    // resolves both bugs:
    //
    // Fall back to the aggregate ONLY when the aggregate has CONCRETE paste
    // evidence (paste_events > 0 OR riskscore >= 0.70 — the HIGH threshold,
    // which is reachable only when Signal 1 fires from a paste/large_insert).
    // Typing-only aggregate scores in the 35–69 range are never used as a
    // fallback because they have no meaning for an individual question that
    // captured no events of its own.
    //
    // Outcomes:
    // • Bug A: per-question riskscore=0, aggregate has paste → fallback to
    // aggregate → HIGH (correct).
    // • Bug B: per-question riskscore=0, aggregate has no paste → no
    // fallback → per-question shown as LOW (correct).
    // • Honest typist with cleanly tagged events → per-question riskscore
    // reflects real signals → shown as-is (no change).
    // • Real per-question paste (riskscore > 0 from tagged paste) → trusted
    // directly, never overwritten by aggregate (no change from v1.2.93).
    if ($pqrecord && $aggrecord) {
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
        $aggmetricsarr  = !empty($aggrecord->metricsjson)
            ? (json_decode($aggrecord->metricsjson, true) ?: [])
            : [];
        $aggsignal1pts  = (int)(($aggmetricsarr['signal_breakdown'][1] ?? 0));
        $agghaspasteevidence = ($aggsignal1pts >= 30)
                               || ((float)$aggrecord->riskscore >= 0.70);

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
        $pqlookslikemissedpaste = ((float)$pqrecord->riskscore <= 0.0);
        if ($pqlookslikemissedpaste && $agghaspasteevidence) {
            // Per-question scorer found no paste for this slot AND the aggregate
            // clearly detected a paste somewhere in the attempt: surface it.
            $record = $aggrecord;
            $isaggregatefallback = true;
        } else {
            // Either the per-question score is meaningful, OR the aggregate has
            // no paste evidence to rescue it. Trust the per-question record.
            $record = $pqrecord;
        }
    } else {
        $record = $pqrecord ?? $aggrecord;
        if ($record && $aggrecord && $qslot > 0 && $record === $aggrecord) {
            $isaggregatefallback = true;
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
    $riskpct  = max(0, min(100, (int)round(((float)$record->riskscore) * 100)));
    $risklevel = \plagiarism_essayguard\local\service\analyser::risk_level($riskpct);
    $errmsg    = (string)($record->errormsg ?? '');

    return plagiarism_essayguard_render_badge(
        'analysed',
        (float)$riskpct,
        $risklevel,
        $errmsg,
        $cmid,
        $userid,
        $isteacher,
        $isaggregatefallback
    );
}
