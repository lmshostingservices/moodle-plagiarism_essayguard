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
 * Essay Guard global settings page.
 *
 * PLAGIARISM PLUGIN PATTERN — this is a STANDALONE admin page, NOT a file
 * included by Moodle's admin framework. Moodle's admin tree registers an
 * admin_externalpage for each plagiarism plugin that links to this file
 * directly. This file must:
 *   1. Bootstrap Moodle (require config.php)
 *   2. Call admin_externalpage_setup('plagiarismessayguard') — section name
 *      is plagiarism + pluginname with NO underscore in between
 *   3. Handle form submission
 *   4. Render a full Moodle HTML page
 *
 * Using $settings->add() here (the pattern for mod/local/block plugins) does
 * NOT work for plagiarism plugins and is what caused the sectionerror.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(__FILE__)) . '/../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/plagiarismlib.php');
require_once($CFG->dirroot . '/plagiarism/essayguard/lib.php');

require_login();
admin_externalpage_setup('plagiarismessayguard');

$context = context_system::instance();
require_capability('moodle/site:config', $context);

// ── Handle form submission ────────────────────────────────────────────────────
if (optional_param('save', false, PARAM_BOOL) && confirm_sesskey()) {
    set_config('siteid',          optional_param('siteid',          '',    PARAM_ALPHANUMEXT), 'plagiarism_essayguard');
    set_config('apikey',          optional_param('apikey',          '',    PARAM_TEXT), 'plagiarism_essayguard');
    set_config('enabled',         optional_param('enabled',         0,     PARAM_BOOL), 'plagiarism_essayguard');
    set_config('captureinterval', optional_param('captureinterval', 5000,  PARAM_INT),  'plagiarism_essayguard');
    set_config('minchars',        optional_param('minchars',        120,   PARAM_INT),  'plagiarism_essayguard');
    set_config('maxburstchars',   optional_param('maxburstchars',   150,   PARAM_INT),  'plagiarism_essayguard');
    set_config('allowpaste',      optional_param('allowpaste',      0,     PARAM_BOOL), 'plagiarism_essayguard');
    set_config('paste_weight',    optional_param('paste_weight',    100,   PARAM_INT),  'plagiarism_essayguard');
    set_config('retentiondays',   optional_param('retentiondays',   90,    PARAM_INT),  'plagiarism_essayguard');

    // Clear the 30-minute unlock cache so new credentials are verified immediately.
    // Without this, a fresh check_unlock() call could serve a stale cached "false"
    // for up to 30 minutes after valid credentials are entered.
    set_config('unlock_cache_result', '', 'plagiarism_essayguard');
    set_config('unlock_cache_time',   0,  'plagiarism_essayguard');

    // FIX-EG-UNKNOWN-STATUS (v1.2.57): Immediately re-verify with the newly saved
    // credentials so the cache is repopulated before the redirect. Without this, the
    // settings page always showed "Unlock status unknown" after every Save because the
    // cache was cleared but never refilled until the next Test Connection click or a
    // student page load. Now the save itself does the live check (one HTTP request to
    // lms-labs.com), caches the result, and the redirected page shows the real
    // unlock status straight away. check_unlock() is fail-open, so slow/unreachable
    // servers just return true and the cache reflects that gracefully.
    plagiarism_essayguard_check_unlock();

    redirect(
        new moodle_url('/plagiarism/essayguard/settings.php'),
        get_string('savedconfigsuccess', 'plagiarism_essayguard'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── Handle "Test Connection" action ──────────────────────────────────────────
$connection_test_result = null;
if (optional_param('testconnection', false, PARAM_BOOL) && confirm_sesskey()) {
    // Force a fresh unlock check by clearing the runtime and persistent caches.
    set_config('unlock_cache_result', '', 'plagiarism_essayguard');
    set_config('unlock_cache_time',   0,  'plagiarism_essayguard');
    $connection_test_result = plagiarism_essayguard_check_unlock();
}

// ── Read current config ───────────────────────────────────────────────────────
$cfg = (array) get_config('plagiarism_essayguard');
$siteid          = $cfg['siteid']          ?? '';
$apikey          = $cfg['apikey']          ?? '';
$enabled         = !empty($cfg['enabled']);
$captureinterval = (int) ($cfg['captureinterval'] ?? 5000);
$minchars        = (int) ($cfg['minchars']        ?? 120);
$maxburstchars   = (int) ($cfg['maxburstchars']   ?? 150);
$allowpaste      = !empty($cfg['allowpaste']);
$paste_weight    = (int) ($cfg['paste_weight']    ?? 100);
$retentiondays   = (int) ($cfg['retentiondays']   ?? 90);

// ── Determine connection status for display ───────────────────────────────────
$creds_configured = (!empty($siteid) || !empty(get_config('local_aiconfig', 'siteid')))
                 && (!empty($apikey) || !empty(get_config('local_aiconfig', 'apikey')));

// FIX-EG-UNKNOWN-STATUS (v1.2.57): Auto-refresh stale/empty cache on plain page
// load when credentials are configured. Previously the settings page would show
// "Unlock status unknown" whenever the cache was older than 30 minutes or had
// never been populated, forcing the admin to manually click "Test Connection".
// Now a live check is made automatically so the page always shows the real status.
// The check is skipped when credentials are missing to avoid unnecessary API calls.
$cached_result = get_config('plagiarism_essayguard', 'unlock_cache_result');
$cached_time   = (int)get_config('plagiarism_essayguard', 'unlock_cache_time');
if ($creds_configured && ($cached_time === 0 || (time() - $cached_time) >= 1800)) {
    plagiarism_essayguard_check_unlock();
    // Re-read after the live check so the display reflects the fresh result.
    $cached_result = get_config('plagiarism_essayguard', 'unlock_cache_result');
    $cached_time   = (int)get_config('plagiarism_essayguard', 'unlock_cache_time');
}

$cache_age_min      = $cached_time > 0 ? (int)round((time() - $cached_time) / 60) : null;
$is_unlocked_cached = $cached_time > 0 && !empty($cached_result);
$cache_fresh        = $cached_time > 0 && (time() - $cached_time) < 1800;

// ── Render page ───────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'plagiarism_essayguard'));

// ── Connection / Unlock status panel ─────────────────────────────────────────
$panel_style  = 'padding:1rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;';
$panel_style .= 'border:1px solid ';

if ($connection_test_result !== null) {
    // Result from the "Test Connection" button just clicked.
    if ($connection_test_result) {
        $panel = '<div style="' . $panel_style . '#4caf5066;background:#e8f5e9;color:#1b5e20;">'
            . '<strong>&#x2714; Essay Guard is UNLOCKED</strong> — tracking is active and data will be recorded for all enabled activities.'
            . '</div>';
    } else {
        $panel_detail = !$creds_configured
            ? '<br><small>Your Site ID or API Key is not configured. Enter them below and save, then click Test Connection again.</small>'
            : '<br><small>Your credentials were accepted but Essay Guard is not unlocked for this site. '
              . 'Visit the <a href="https://lms-labs.com" target="_blank">EssayGraderAI dashboard</a> '
              . 'to unlock Essay Guard (5,000 credits). Once unlocked, click <strong>Test Connection</strong> again to confirm.</small>';
        $panel = '<div style="' . $panel_style . '#e5393566;background:#ffebee;color:#b71c1c;">'
            . '<strong>&#x2718; Essay Guard is NOT UNLOCKED</strong>' . $panel_detail
            . '</div>';
    }
} elseif (!$creds_configured) {
    $panel = '<div style="' . $panel_style . '#ff980066;background:#fff3e0;color:#e65100;">'
        . '<strong>&#x26a0; Credentials not configured</strong> — enter your Site ID and API Key below and click Save. '
        . 'Until credentials are configured Essay Guard will not capture any data.'
        . '</div>';
} elseif ($cache_fresh) {
    if ($is_unlocked_cached) {
        $panel = '<div style="' . $panel_style . '#4caf5066;background:#e8f5e9;color:#1b5e20;">'
            . '<strong>&#x2714; Essay Guard UNLOCKED</strong> (last verified ' . $cache_age_min . ' min ago). '
            . 'Tracking is active.'
            . '</div>';
    } else {
        $panel = '<div style="' . $panel_style . '#e5393566;background:#ffebee;color:#b71c1c;">'
            . '<strong>&#x2718; Essay Guard NOT UNLOCKED</strong> (last checked ' . $cache_age_min . ' min ago). '
            . 'Visit the <a href="https://lms-labs.com" target="_blank">EssayGraderAI dashboard</a> '
            . 'to unlock Essay Guard (5,000 credits), then click <strong>Test Connection</strong> below.'
            . '</div>';
    }
} else {
    $panel = '<div style="' . $panel_style . '#9e9e9e66;background:#fafafa;color:#424242;">'
        . '<strong>&#x25cc; Unlock status unknown</strong> — click <strong>Test Connection</strong> below to verify.'
        . '</div>';
}

echo $panel;

$actionurl = new moodle_url('/plagiarism/essayguard/settings.php');
?>
<form action="<?php echo $actionurl; ?>" method="post">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <input type="hidden" name="save" value="1">
    <table class="admintable generaltable" cellspacing="0">
        <tbody>

            <tr>
                <td class="cell c0"><label for="id_siteid"><?php echo get_string('siteid', 'plagiarism_essayguard'); ?></label></td>
                <td class="cell c1">
                    <input type="text" id="id_siteid" name="siteid" value="<?php echo s($siteid); ?>" size="40">
                    <div class="form-text"><?php echo get_string('siteid_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_apikey"><?php echo get_string('apikey', 'plagiarism_essayguard'); ?></label></td>
                <td class="cell c1">
                    <input type="password" id="id_apikey" name="apikey" value="<?php echo s($apikey); ?>" size="40" autocomplete="new-password">
                    <div class="form-text"><?php echo get_string('apikey_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_enabled"><?php echo get_string('enabled', 'plagiarism_essayguard'); ?></label></td>
                <td class="cell c1">
                    <input type="checkbox" id="id_enabled" name="enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                    <div class="form-text"><?php echo get_string('enabled_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_captureinterval"><?php echo get_string('captureinterval', 'plagiarism_essayguard'); ?></label></td>
                <td class="cell c1">
                    <input type="number" id="id_captureinterval" name="captureinterval" value="<?php echo (int)$captureinterval; ?>" min="1000" max="60000">
                    <div class="form-text"><?php echo get_string('captureinterval_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_minchars"><?php echo get_string('minchars', 'plagiarism_essayguard'); ?></label></td>
                <td class="cell c1">
                    <input type="number" id="id_minchars" name="minchars" value="<?php echo (int)$minchars; ?>" min="0">
                    <div class="form-text"><?php echo get_string('minchars_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_maxburstchars"><?php echo get_string('maxburstchars', 'plagiarism_essayguard'); ?></label></td>
                <td class="cell c1">
                    <input type="number" id="id_maxburstchars" name="maxburstchars" value="<?php echo (int)$maxburstchars; ?>" min="1">
                    <div class="form-text"><?php echo get_string('maxburstchars_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_allowpaste"><?php echo get_string('allowpaste', 'plagiarism_essayguard'); ?></label></td>
                <td class="cell c1">
                    <input type="checkbox" id="id_allowpaste" name="allowpaste" value="1" <?php echo $allowpaste ? 'checked' : ''; ?>>
                    <div class="form-text"><?php echo get_string('allowpaste_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_paste_weight"><?php echo get_string('paste_weight', 'plagiarism_essayguard'); ?></label></td>
                <td class="cell c1">
                    <input type="number" id="id_paste_weight" name="paste_weight" value="<?php echo (int)$paste_weight; ?>" min="0" max="100">
                    <span style="margin-left:0.4rem;">%</span>
                    <div class="form-text"><?php echo get_string('paste_weight_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_retentiondays"><?php echo get_string('retentiondays', 'plagiarism_essayguard'); ?></label></td>
                <td class="cell c1">
                    <input type="number" id="id_retentiondays" name="retentiondays" value="<?php echo (int)$retentiondays; ?>" min="0" max="3650">
                    <div class="form-text"><?php echo get_string('retentiondays_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

        </tbody>
    </table>
    <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
        <input type="submit" class="btn btn-primary" value="<?php echo get_string('savechanges'); ?>">
    </div>
</form>

<!-- Test Connection form (separate POST so save and test are independent) -->
<form action="<?php echo $actionurl; ?>" method="post" style="margin-top:1rem;">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <input type="hidden" name="testconnection" value="1">
    <button type="submit" class="btn btn-secondary">
        Test Connection &amp; Unlock Status
    </button>
    <small style="margin-left:0.5rem;color:#666;">
        Forces a live check to lms-labs.com and refreshes the unlock cache. Use this after
        unlocking Essay Guard in the dashboard to confirm tracking will activate immediately.
    </small>
</form>

<?php
echo html_writer::tag('p',
    html_writer::tag('strong', 'Where to unlock Essay Guard:') .
    ' Log in to ' .
    html_writer::link('https://lms-labs.com', 'lms-labs.com', ['target' => '_blank']) .
    ' → Dashboard → Plugins → Essay Guard → Unlock (5,000 credits). ' .
    'Once unlocked, click <strong>Test Connection &amp; Unlock Status</strong> above to confirm.',
    ['style' => 'margin-top:1.25rem;padding:0.75rem 1rem;background:#f5f5f5;border-radius:4px;font-size:0.9rem;']
);

echo $OUTPUT->footer();
