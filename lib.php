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

/** Credits deducted from the LMS Labs balance by the one-time site unlock. */
define('PLAGIARISM_ESSAYGUARD_UNLOCK_CREDITS', 50);
/** The same one-time unlock expressed in US dollars. */
define('PLAGIARISM_ESSAYGUARD_UNLOCK_PRICE_USD', 5);

/**
 * Whether the site administrator has switched Essay Guard on.
 *
 * The 'enabled' setting is written as 0 at install (db/install.php), so a missing
 * value means "never switched on" and is treated as off. Capture never starts on a
 * site until an administrator saves the setting as enabled.
 *
 * @return bool
 */
function plagiarism_essayguard_is_enabled(): bool {
    return !empty(get_config('plagiarism_essayguard', 'enabled'));
}

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
 * Report whether this site has been unlocked on the LMS Labs licence server.
 *
 * This is a status check only: it never purchases anything. Request paths read the
 * cached answer (30 minutes); only the refresh_licence task and an explicit
 * administrator action on the settings page pass $allowfetch = true. A site with no
 * cached answer at all fails open.
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
        // SEC-EG-TLS (v1.3.0): verify the certificate chain and refuse redirects.
        //
        // Moodle's \curl class resets CURLOPT_SSL_VERIFYPEER to 0, and VERIFYHOST
        // without VERIFYPEER verifies nothing — so an attacker on the path between the
        // Moodle server and the licence host could present any certificate, return
        // {"unlocked":true}, and harvest the site's API key. FOLLOWLOCATION defaults to
        // on with up to 10 redirects, which would forward the credential to any host a
        // 302 named.
        'CURLOPT_SSL_VERIFYPEER' => 1,
        'CURLOPT_SSL_VERIFYHOST' => 2,
        'CURLOPT_FOLLOWLOCATION' => 0,
    ]);
    // The API key travels in a request header, never in the URL, so it cannot end up
    // in web server, proxy or load balancer access logs.
    $curl->setHeader(plagiarism_essayguard_api_headers($apikey));
    $response  = $curl->get(
        'https://lms-labs.com/api/plugin-unlock/verify',
        [
            'pluginId' => 'essayguard',
            'siteId'   => $siteid,
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
    // Status check only. Purchasing (plagiarism_essayguard_auto_unlock()) happens solely
    // from the confirmed unlock action on the settings page, never from here.
    $result = !empty($data['unlocked']);

    // Persist result for 30 minutes.
    set_config('unlock_cache_result', (int)$result, 'plagiarism_essayguard');
    set_config('unlock_cache_time', time(), 'plagiarism_essayguard');

    $runtimecache = $result;
    return $result;
}

/**
 * Request headers for calls to the LMS Labs API, carrying the key as a bearer token.
 *
 * @param string $apikey The site API key.
 * @return string[]
 */
function plagiarism_essayguard_api_headers(string $apikey): array {
    return [
        'Authorization: Bearer ' . $apikey,
        'Accept: application/json',
    ];
}

/**
 * Purchase the Essay Guard unlock for this site from the LMS Labs credit balance.
 *
 * Spends PLAGIARISM_ESSAYGUARD_UNLOCK_CREDITS credits. Called only from the settings
 * page after the administrator has confirmed the purchase with a POST carrying a valid
 * sesskey; never from cron, a status check or a settings save.
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
            // SEC-EG-TLS (v1.3.0): see check_unlock().
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_FOLLOWLOCATION' => 0,
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
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => 5,
            'CURLOPT_CONNECTTIMEOUT' => 3,
            // SEC-EG-TLS (v1.3.0): see check_unlock().
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_FOLLOWLOCATION' => 0,
            ]);
        $curl->setHeader(plagiarism_essayguard_api_headers($apikey));
        $response  = $curl->get(
            'https://lms-labs.com/api/plagiarism-settings',
            ['siteId' => $siteid]
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

    // Opt-in: an activity is monitored only when a teacher has saved it as enabled.
    // A missing value (an activity created before Essay Guard was installed, or one
    // restored without Essay Guard settings) is off.
    return !empty(get_config('plagiarism_essayguard', 'enabled_cm_' . $cmid));
}

/**
 * Declare the user preferences this plugin writes.
 *
 * Each name is per course module, so each is declared as a regexp preference. None can
 * be written over the user-preference web service; only the plugin's own server-side
 * code writes them.
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
        // Written by finalize_attempt as the per-activity finalise throttle marker.
        'essayguard_fin_.*' => [
            'isregexp'           => true,
            'null'               => NULL_NOT_ALLOWED,
            'default'            => '0',
            'type'               => PARAM_INT,
            'permissioncallback' => 'plagiarism_essayguard_pref_never_writable',
        ],
    ];
}

/**
 * Permission callback for the plugin's user preferences: always refuses.
 *
 * A student able to rewrite their own attempt key could detach their telemetry from
 * their submission, so these preferences are server-side only.
 *
 * @param \stdClass $user The user whose preference is being written.
 * @param string $preferencename The name of the essayguard_ak_* preference being written.
 * @return bool Always false — the preference is never writable over the AJAX endpoint.
 */
function plagiarism_essayguard_pref_never_writable($user, $preferencename): bool {
    return false;
}

/**
 * Student-facing notice shown above a monitored submission form.
 *
 * States what is recorded, why, who can see it and how long it is kept. The retention
 * period is read from config so the notice always matches what the cleanup task does.
 *
 * @param int $cmid Course module id.
 * @return string HTML, or '' when Essay Guard is not active for this activity.
 */
function plagiarism_essayguard_print_disclosure(int $cmid): string {
    global $OUTPUT;

    // Say nothing when nothing is being captured.
    if (!plagiarism_essayguard_is_enabled()) {
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

    $keys = ['disclosure_what', 'disclosure_why', 'disclosure_who'];
    $paragraphs = [];
    foreach ($keys as $key) {
        $paragraphs[] = ['text' => get_string($key, 'plagiarism_essayguard')];
    }
    $paragraphs[] = ['text' => $retentiontext];
    foreach (['disclosure_profile', 'disclosure_assistive', 'disclosure_contest'] as $key) {
        $paragraphs[] = ['text' => get_string($key, 'plagiarism_essayguard')];
    }

    return $OUTPUT->render_from_template('plagiarism_essayguard/disclosure', ['paragraphs' => $paragraphs]);
}

/**
 * Shared tracker injection logic used by both the legacy callback (Moodle < 4.3)
 * and the new hook callback (Moodle 4.3+).
 *
 * @return void
 */
function plagiarism_essayguard_inject_tracker() {
    /* $DB is needed by the v1.2.224 attempt-ownership check below. */
    global $PAGE, $USER, $DB, $CFG;

    if (isguestuser() || !isloggedin()) {
        return;
    }

    // SEC-EG-ENABLEPLAGIARISM (v1.3.0): honour Moodle's master switch.
    //
    // $CFG->enableplagiarism was read only by settings.php and the activity settings
    // form. The capture path ignored it entirely, so on a site where the administrator
    // had never switched the plagiarism subsystem on — which is the default — the
    // tracker was injected and every keystroke was recorded, while core's
    // plagiarism_get_links() and plagiarism_print_disclosure() both returned early.
    //
    // That is the worst possible combination: full collection, no output, and no
    // disclosure to the student. The plugin's own install notice told administrators
    // "Essay Guard will not run until Moodle's plagiarism subsystem is switched on",
    // which was not true.
    if (empty($CFG->enableplagiarism)) {
        return;
    }
    // Site switch: off until an administrator enables Essay Guard.
    if (!plagiarism_essayguard_is_enabled()) {
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
        return; // The tracker is for students only.
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
    // SEC-EG-SUPPORTS-MOD-GATE (v1.3.0): only capture in the module types this
    // plugin declares support for. inject_tracker() runs from a head hook on EVERY
    // page of the site, and its only module gate was "is there a course module and
    // is it active". Combined with the tracker binding any textarea or contenteditable
    // outside a .que container, that meant keystrokes were recorded in wikis,
    // glossaries, databases, lesson pages, book comments and comment boxes — none of
    // which are assessment submissions and none of which show the disclosure.
    if (!plagiarism_essayguard_supports_mod($cm->modname)) {
        return;
    }

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
        // Translated badge and toast text; tracker.js substitutes the {placeholders}.
        'strings'        => [
            'pluginname'    => get_string('pluginname', 'plagiarism_essayguard'),
            'risklow'       => get_string('risklow', 'plagiarism_essayguard'),
            'riskmedium'    => get_string('riskmedium', 'plagiarism_essayguard'),
            'riskhigh'      => get_string('riskhigh', 'plagiarism_essayguard'),
            'overallresult' => get_string('tracker_overallresult', 'plagiarism_essayguard'),
            'riskresult'    => get_string('tracker_riskresult', 'plagiarism_essayguard'),
            'perquestion'   => get_string('tracker_perquestion', 'plagiarism_essayguard'),
            'questionshort' => get_string('tracker_questionshort', 'plagiarism_essayguard'),
            'levelscore'    => get_string('tracker_levelscore', 'plagiarism_essayguard'),
        ],
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
    if (empty($CFG->enableplagiarism) || !plagiarism_essayguard_is_enabled()) {
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

    // Show the activity's real state: the course module id (not the instance id) keys
    // the setting, and an activity with no saved value is not monitored.
    $cmid = $formwrapper->get_current()->coursemodule ?? 0;
    $mform->setDefault('essayguard_enabled', $cmid ? (int)plagiarism_essayguard_is_cm_active((int)$cmid) : 0);
    $mform->addHelpButton('essayguard_enabled', 'enabled', 'plagiarism_essayguard');
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

// FIX-EG-LEGACY-CALLBACK-REMOVED (v1.3.0): plagiarism_essayguard_before_standard_html_head()
// deleted. Moodle's get_plugins_with_function() emits "Callback X should be migrated to new
// hook callback" at DEBUG_DEVELOPER for ANY plugin that defines a legacy output callback,
// whatever the body does — which is exactly why the top-of-body sibling was removed. This one
// was left behind, so the notice it was meant to silence kept firing. Its body was also
// unreachable: it returned immediately when the hook class exists, and at the declared floor
// of Moodle 4.4 that class always exists.

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
 * SEC-EG-ATTEMPTKEY-ALLOWLIST (v1.3.0): validate an attempt key positively.
 *
 * The previous check was negative: a key matching qa_<digits> had to resolve to an
 * attempt owned by the caller, and **anything else was accepted unconditionally**.
 * PARAM_ALPHANUMEXT permits 64 characters of [A-Za-z0-9_-], and the legitimate
 * non-quiz key is a plain sha1 hex string, so the server had no way to tell a
 * forged key from a real one.
 *
 * The consequence was a complete bypass of the product's purpose. A student scored
 * HIGH could call finalize_attempt with attemptkey "clean1" and a plausible essay.
 * That inserts a brand new score row with timemodified = now, which
 * plagiarism_essayguard_scope_to_current_attempt() then elects as "the current
 * attempt" — hiding the real evidence from the badge, the class report and the
 * student page. Repeat with clean2, clean3 after each teacher visit.
 *
 * Only two keys can ever be legitimate for a given user and activity, so both are
 * now named explicitly and everything else is refused.
 *
 * @param string $attemptkey The key supplied by the caller.
 * @param object $cm         The course module the call is scoped to.
 * @param int    $userid     The calling user.
 * @return bool True when the key is one this user could legitimately own.
 */
function plagiarism_essayguard_attemptkey_is_valid(string $attemptkey, $cm, int $userid): bool {
    global $DB;

    if ($attemptkey === '' || \core_text::strlen($attemptkey) > 64) {
        return false;
    }

    // Form 1: the hash key used for assignments, forums and for quiz pages where the
    // attempt id was not available.
    if ($attemptkey === sha1($userid . ':' . $cm->id)) {
        return true;
    }

    // Form 2: a quiz attempt owned by this user IN THIS ACTIVITY. The activity check
    // matters: without it an attempt id from quiz A is accepted against quiz B's cmid,
    // writing rows under the wrong activity. inject_tracker() has always checked the
    // activity; the web services did not.
    if ($cm->modname === 'quiz' && preg_match('/^qa_(\d+)$/', $attemptkey, $m)) {
        return $DB->record_exists(
            'quiz_attempts',
            [
                'id'     => (int)$m[1],
                'userid' => $userid,
                'quiz'   => $cm->instance,
                ]
        );
    }

    return false;
}

/**
 * FIX-EG-ONE-TRUTH (v1.2.234): Load this user's score records for ONE attempt.
 *
 * Every display surface previously ran its own query with its own ordering and
 * none of them filtered by attemptkey, so lib.php (badge), report.php (class
 * report) and student.php (detail page) could each select a different row for
 * the same question. Symptoms: a question badged HIGH on the attempt page and
 * LOW in the report, and scores from attempt 1 mixed with scores from attempt 2
 * on the same screen.
 *
 * Rules, applied identically everywhere:
 *   - The current attempt is the one owning the most recently written record.
 *   - Only records from that attempt are returned.
 *   - Ordering is fully deterministic: timemodified DESC, id DESC. Per-question
 *     rows are written in the same second by the observer loop, so without the
 *     id tiebreak the database is free to return them in any order, and two
 *     queries on the same data could legitimately disagree.
 *
 * @param array $rows Score records for one user (any order).
 * @return array [qslot => record] for the current attempt only.
 */
function plagiarism_essayguard_scope_to_current_attempt(array $rows): array {
    if (empty($rows)) {
        return [];
    }

    usort($rows, function ($a, $b) {
        $ta = (int)($a->timemodified ?? 0);
        $tb = (int)($b->timemodified ?? 0);
        if ($ta !== $tb) {
            return $tb <=> $ta;
        }
        return (int)($b->id ?? 0) <=> (int)($a->id ?? 0);
    });

    $current = (string)($rows[0]->attemptkey ?? '');
    $byslot  = [];
    foreach ($rows as $row) {
        if ((string)($row->attemptkey ?? '') !== $current) {
            continue;
        }
        $slot = (int)($row->qslot ?? 0);
        if (!isset($byslot[$slot])) {
            $byslot[$slot] = $row;
        }
    }

    return $byslot;
}

/**
 * FIX-EG-ONE-TRUTH (v1.2.234): The single per-question record-selection rule.
 *
 * lib.php and report.php each carried their own copy of this logic. The copies
 * were written at different times and drifted, which is one of the ways the two
 * pages came to disagree. There is now one implementation and both call it.
 *
 * A per-question score of exactly 0 means the slot captured no scoring signal
 * at all. Only in that case may the attempt-level record speak for the question,
 * and only when it holds real paste evidence.
 *
 * @param object|null $pqrecord   The per-question record (qslot = N).
 * @param object|null $aggrecord  The attempt-level record (qslot = 0).
 * @param bool        $isfallback Set true when the aggregate was substituted.
 * @return object|null The record to display.
 */
function plagiarism_essayguard_resolve_question_record($pqrecord, $aggrecord, &$isfallback) {
    $isfallback = false;

    if (!$pqrecord) {
        if ($aggrecord) {
            $isfallback = true;
        }
        return $aggrecord;
    }

    if (!$aggrecord || (float)$pqrecord->riskscore > 0.0) {
        return $pqrecord;
    }

    // FIX-EG-ELEVATED-AGG-LOOP (v1.2.234): an aggregate that was itself elevated
    // to the worst per-question score (analyser FIX-EG-AGG-PERQ-CONSISTENCY) is
    // not independent evidence. Letting it rescue a different question copies
    // Q1's HIGH onto Q2 and is exactly the false HIGH reported from live use.
    $aggmetrics = !empty($aggrecord->metricsjson)
        ? (json_decode($aggrecord->metricsjson, true) ?: [])
        : [];
    if (!empty($aggmetrics['agg_elevated_from_perq'])) {
        return $pqrecord;
    }

    $aggsignal1 = (int)($aggmetrics['signal_breakdown'][1] ?? 0);
    $haspaste   = ($aggsignal1 >= 30) || ((float)$aggrecord->riskscore >= 0.70);
    if ($haspaste) {
        $isfallback = true;
        return $aggrecord;
    }

    return $pqrecord;
}

/**
 * FIX-EG-NO-DATA-IS-NOT-LOW (v1.2.234): did this record measure anything at all?
 *
 * A question whose events were never attributed to its slot scores 0 and renders
 * as a green LOW badge — the same badge an honest typist earns. That is the most
 * dangerous output the plugin can produce: a pasted answer whose events failed to
 * tag is reported to the teacher as clean. Live example: Q1 HIGH 100/100 with
 * 2 pastes, Q2 LOW 0/100 with 0 keystrokes, 0 pastes, 0 s typing — Q2 was never
 * assessed, but the report said it was fine.
 *
 * An unmeasured record must be shown as "no data", never as a risk level.
 *
 * @param object|null $record A plagiarism_essayguard_sc record.
 * @return bool True when no behavioural events were attributed to this record.
 */
function plagiarism_essayguard_is_unmeasured($record): bool {
    if (!$record) {
        return true;
    }

    $metrics = !empty($record->metricsjson)
        ? (json_decode($record->metricsjson, true) ?: [])
        : [];

    // Records written by v1.2.234 and later carry the event count directly.
    if (array_key_exists('event_count', $metrics)) {
        return (int)$metrics['event_count'] === 0;
    }

    // Older records: infer it. No keystrokes, no pastes and no typing time means
    // nothing was ever captured for this slot.
    $keystrokes = (int)($metrics['total_keystrokes'] ?? $record->total_keystrokes ?? 0);
    $pastes     = (int)($metrics['paste_events'] ?? $record->paste_events ?? 0);
    $typing     = (int)($metrics['typing_time'] ?? $record->typing_time ?? 0);

    return ($keystrokes === 0) && ($pastes === 0) && ($typing === 0);
}

/**
 * FIX-EG-ONE-TRUTH (v1.2.234): The single definition of a student's overall score.
 *
 * Three surfaces previously computed "overall" three different ways: the badge
 * used the attempt-level record (which the analyser may have silently raised to
 * the worst question), the class report used the mean of the per-question scores,
 * and the student page used the raw qslot=0 row. On a two-question quiz scoring
 * 100 and 0 those three rules produce HIGH, MEDIUM and HIGH from identical data.
 *
 * The rule is now stated once here. 'max' is the default because an integrity
 * indicator should not be diluted by the questions that were fine: one pasted
 * answer in a quiz is a pasted answer. Change the constant to 'mean' to average
 * instead; both behaviours are then consistent across all three pages.
 *
 * @param array $byslot [qslot => record] from scope_to_current_attempt().
 * @return float Overall risk score, 0.0–1.0.
 */
function plagiarism_essayguard_overall_score(array $byslot): float {
    $rule = 'max';

    $agg    = $byslot[0] ?? null;
    $scores = [];
    foreach ($byslot as $slot => $record) {
        if ((int)$slot <= 0) {
            continue;
        }
        $isfallback = false;
        $resolved   = plagiarism_essayguard_resolve_question_record($record, $agg, $isfallback);
        $scores[]   = (float)($resolved->riskscore ?? 0.0);
    }

    if (empty($scores)) {
        return (float)($agg->riskscore ?? 0.0);
    }

    if ($rule === 'mean') {
        return array_sum($scores) / count($scores);
    }

    return max($scores);
}

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
              WHERE cm.id = :cmid AND qa.userid = :userid
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

    // FIX-EG-MATCH-QUALITY-FIRST (v1.2.234): score every slot, then decide.
    //
    // The previous rule returned the best UNCLAIMED slot even when a claimed slot
    // was a far better match. On a quiz where two answers resemble each other
    // (same student, related questions, 60 % similarity is easy to reach) that
    // handed Q2 the record belonging to Q1 — the reported "attempt page says
    // HIGH, report says LOW" mismatch. Match quality now decides; the claim is
    // only a tiebreak between candidates that are genuinely close.
    //
    // similar_text() is O(n^3) in the worst case, so long answers are compared
    // on a bounded prefix. A 2,000-character prefix identifies an answer well
    // beyond any doubt and keeps a 200-student report from timing out.
    $maxcompare = 2000;
    if (mb_strlen($decodedcontent) > $maxcompare) {
        $decodedcontent = mb_substr($decodedcontent, 0, $maxcompare);
    }

    $candidates = [];
    foreach ($answercache[$cachekey] as $slot => $slottext) {
        $decodedslot = html_entity_decode($slottext, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (mb_strlen($decodedslot) > $maxcompare) {
            $decodedslot = mb_substr($decodedslot, 0, $maxcompare);
        }
        similar_text($decodedcontent, $decodedslot, $simpct);
        if ($simpct >= 60.0) {
            $candidates[$slot] = (float)$simpct;
        }
    }

    if (empty($candidates)) {
        return 0; // No match found.
    }

    arsort($candidates);
    $slots  = array_keys($candidates);
    $best   = $slots[0];
    $second = $slots[1] ?? 0;

    // Only let the claim override the best match when the runner-up is within
    // five points of it — i.e. when the two are too close to separate on
    // similarity alone and call order is the better evidence.
    if (
        $second > 0
        && isset($claimed[$best])
        && !isset($claimed[$second])
        && ($candidates[$best] - $candidates[$second]) <= 5.0
    ) {
        $best = $second;
    }

    $claimedslots[$cachekey][$best] = true;
    return (int)$best;
}

/**
 * Render the Essay Guard risk badge for a submission (called by get_links).
 *
 * @param string $status  Analysis state of the record: 'analysed', 'pending' or 'error'.
 * @param float  $score   Risk score 0-100.
 * @param string $level   Risk band: 'low', 'medium', 'high', 'unmeasured' (legacy 'partial'/'mild' map to medium).
 * @param string $errmsg  Error message (status='error' only).
 * @param int    $cmid    Course-module ID.
 * @param int    $userid  Student's user ID.
 * @param bool   $isteacher  True when the viewer has viewreport capability.
 * @param bool   $isaggregatefallback  True when the aggregate record was used.
 * @return string The badge HTML.
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
    global $OUTPUT;

    if ($status === 'analysed' && $level === 'unmeasured') {
        $state   = 'unmeasured';
        $label   = get_string('badgeunmeasured', 'plagiarism_essayguard');
        $tooltip = get_string('tooltipunmeasured', 'plagiarism_essayguard');
    } else if ($status === 'analysed') {
        // An unrecognised band falls back to medium so a human looks at it, never to low.
        $levelmap = ['low' => 'low', 'medium' => 'medium', 'high' => 'high', 'partial' => 'medium', 'mild' => 'medium'];
        $state    = $levelmap[$level] ?? 'medium';
        $scoreint = (int)$score;
        $label    = get_string('badgelabel', 'plagiarism_essayguard', (object)[
            'level'  => get_string('risk' . $state, 'plagiarism_essayguard'),
            'suffix' => $isaggregatefallback ? get_string('badgesuffixoverall', 'plagiarism_essayguard') : '',
            'score'  => $scoreint,
        ]);
        $tooltip  = get_string('tooltip' . $state, 'plagiarism_essayguard', $scoreint);
    } else if ($status === 'error') {
        $state   = 'error';
        $label   = get_string('badgeerror', 'plagiarism_essayguard');
        $tooltip = get_string(
            'tooltiperror',
            'plagiarism_essayguard',
            trim($errmsg) ?: get_string('tooltiperrordefault', 'plagiarism_essayguard')
        );
    } else {
        $state   = 'pending';
        $label   = get_string('badgepending', 'plagiarism_essayguard');
        $tooltip = get_string('tooltippending', 'plagiarism_essayguard');
    }

    $data = [
        'state'     => $state,
        'label'     => $label,
        'tooltip'   => $tooltip,
        'isteacher' => $isteacher,
    ];
    if ($isteacher) {
        if ($status === 'analysed') {
            $data['detailurl'] = (new \moodle_url(
                '/plagiarism/essayguard/student.php',
                ['cmid' => $cmid, 'userid' => $userid]
            ))->out(false);
        }
        $data['reporturl'] = (new \moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]))->out(false);
        if ($status === 'error' && $errmsg !== '') {
            $data['errmsg'] = \core_text::substr($errmsg, 0, 120);
        }
    }

    return $OUTPUT->render_from_template('plagiarism_essayguard/badge', $data);
}

/**
 * Badge HTML for one submission, called by plagiarism_plugin_essayguard::get_links().
 *
 * Teachers (plagiarism/essayguard:viewreport) get the badge with links to the detail
 * page and class report; students get only their own badge.
 *
 * @param array $linkarray Moodle plagiarism link data. Keys used: cmid, userid,
 *                         content, and the quiz question attempt when reviewing one.
 * @return string HTML for the badge, or the empty string when nothing is shown.
 */
function plagiarism_essayguard_get_links($linkarray) {
    global $DB, $USER, $OUTPUT;

    // Assignments pass cmid. Quiz essay questions (qtype_essay renderer) pass only the
    // module context id, plus the question slot as itemid.
    if (empty($linkarray['cmid']) && !empty($linkarray['context'])) {
        $ctx = \context::instance_by_id((int)$linkarray['context'], IGNORE_MISSING);
        if ($ctx && $ctx->contextlevel == CONTEXT_MODULE) {
            $linkarray['cmid'] = (int)$ctx->instanceid;
        }
    }
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
            "SELECT id, userid, cmid, qslot, attemptkey, riskscore, risklevel, metricsjson, timemodified
               FROM {plagiarism_essayguard_sc}
              WHERE {$where}
           ORDER BY timemodified DESC, id DESC",
            $params
        );
        // FIX-EG-ONE-TRUTH (v1.2.234): group by user, then scope each user to a
        // single attempt through the shared rule. The old code kept the newest
        // row per (userid, qslot) with no attempt filter and no deterministic
        // tiebreak, so one screen could show Q1 from attempt 2 beside Q2 from
        // attempt 1, and two screens could disagree on identical data.
        $rowsbyuser = [];
        foreach ($allrows as $row) {
            $rowsbyuser[(int)$row->userid][] = $row;
        }
        foreach ($rowsbyuser as $uid => $userrows) {
            $egsccache[$cachekey][$uid] = plagiarism_essayguard_scope_to_current_attempt($userrows);
        }
    }
    /* ───────────────────────────────────────────────────────────────────────── */

    // Teachers see a class-report link when a full badge cannot be shown (qslot=0 path).
    $classreportlink = '';
    if ($isteacher) {
        $classreporturl  = new \moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]);
        $classreportlink = $OUTPUT->render_from_template('plagiarism_essayguard/report_link', [
            'reporturl' => $classreporturl->out(false),
        ]);
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
    // The core essay renderer identifies the question by slot in itemid.
    if (
        $qslot === 0 && ($linkarray['component'] ?? '') === 'qtype_essay'
            && !empty($linkarray['itemid'])
    ) {
        $qslot = (int)$linkarray['itemid'];
        $resolved[$rk][$qslot] = true;
    }

    $egcontentplain = trim(strip_tags((string)($linkarray['content'] ?? '')));
    if ($qslot === 0 && mb_strlen($egcontentplain) > 30) {
        $qslot = plagiarism_essayguard_find_qslot_by_content($cmid, $userid, (string)$linkarray['content']);
        if ($qslot > 0) {
            $resolved[$rk][$qslot] = true;  // Record so call-order skips this slot.
        }
    }

    // FIX-EG-NO-CALL-ORDER-GUESSING (v1.3.0): REMOVED the call-order fallback.
    //
    // It assigned a question slot by counting get_links() calls: the Nth call for this
    // user and activity was taken to be the Nth question. That is an integrity
    // judgement assigned by coincidence. It rested on an assumption about the order in
    // which core renders questions — not part of any API contract, free to change in a
    // point release — and nothing detected when it broke. One extra get_links() call
    // on the page (a student-name cell, a second render of the response) shifted every
    // subsequent question by one, so Q2 displayed Q1's score. That is the "attempt page
    // says HIGH, report says LOW" mismatch, and it was patched four times in fifteen
    // releases without being removed.
    //
    // When the slot genuinely cannot be determined, no badge is shown and the teacher
    // is sent to the report, which reads the per-question records directly. Saying
    // nothing is better than attributing one student's pasted answer to a different
    // question.

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
    // FIX-EG-ONE-TRUTH (v1.2.234): one shared rule, see
    // plagiarism_essayguard_resolve_question_record(). lib.php and report.php
    // previously each carried their own copy of the selection logic; the copies
    // drifted and the two pages disagreed about the same question.
    $record = plagiarism_essayguard_resolve_question_record($pqrecord, $aggrecord, $isaggregatefallback);

    // Scoring queued by the submit observer but not yet run: any record on file is
    // provisional (written by the browser during the attempt), so say "pending".
    static $pending = [];
    if (!isset($pending[$rk])) {
        $pending[$rk] = \plagiarism_essayguard\task\score_attempt::is_pending($cmid, $userid);
    }
    if ($pending[$rk]) {
        return plagiarism_essayguard_render_badge('pending', 0.0, '', '', $cmid, $userid, $isteacher, false);
    }

    if (!$record) {
        // No score — teachers still see the report link; students see nothing.
        return $classreportlink;
    }

    // FIX-EG-BADGE-RENDER (v1.2.177): Delegate all badge HTML to render_badge().
    // Replaces the Mustache template (riskbadge.mustache) + manual link assembly with
    // the inline PHP renderer — inline styles, hover tooltip, pending/error states,
    // essayguard-wrap div, essayguard-link class. See render_badge() for details.
    $riskpct  = max(0, min(100, (int)round(((float)$record->riskscore) * 100)));
    $risklevel = \plagiarism_essayguard\local\service\analyser::risk_level($riskpct);
    $errmsg    = (string)($record->errormsg ?? '');

    // FIX-EG-NO-DATA-IS-NOT-LOW (v1.2.234): a question that captured no events was
    // never assessed. Render it as pending rather than as a green LOW badge, which
    // reads as "this answer is fine" when nothing was checked.
    // FIX-EG-BADGE-NOT-PENDING (v1.3.0): an unmeasured record is final, not in
    // progress. Rendering it as "Essay Guard is analysing this submission — reload the
    // page in a moment" told the teacher to wait for a result that will never arrive,
    // while render_badge()'s purpose-built 'unmeasured' state sat unused.
    if ($qslot > 0 && !$isaggregatefallback && plagiarism_essayguard_is_unmeasured($record)) {
        return plagiarism_essayguard_render_badge(
            'analysed',
            0.0,
            'unmeasured',
            '',
            $cmid,
            $userid,
            $isteacher,
            false
        );
    }

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
