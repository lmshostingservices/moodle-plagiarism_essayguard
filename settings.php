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
 * @copyright  2026 LMS-Labs
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

/* ── Handle form submission ──────────────────────────────────────────────────── */
if (optional_param('save', false, PARAM_BOOL) && confirm_sesskey()) {
    set_config('siteid', optional_param('siteid', '', PARAM_ALPHANUMEXT), 'plagiarism_essayguard');
    set_config('apikey', optional_param('apikey', '', PARAM_TEXT), 'plagiarism_essayguard');
    set_config('enabled', optional_param('enabled', 0, PARAM_BOOL), 'plagiarism_essayguard');
    set_config('captureinterval', optional_param('captureinterval', 5000, PARAM_INT), 'plagiarism_essayguard');
    set_config('minchars', optional_param('minchars', 120, PARAM_INT), 'plagiarism_essayguard');
    set_config('maxburstchars', optional_param('maxburstchars', 150, PARAM_INT), 'plagiarism_essayguard');
    set_config('allowpaste', optional_param('allowpaste', 0, PARAM_BOOL), 'plagiarism_essayguard');
    set_config('paste_weight', optional_param('paste_weight', 100, PARAM_INT), 'plagiarism_essayguard');
    set_config('retentiondays', optional_param('retentiondays', 90, PARAM_INT), 'plagiarism_essayguard');

    // Clear the 30-minute unlock cache so new credentials are verified immediately.
    // Without this, a fresh check_unlock() call could serve a stale cached "false"
    // for up to 30 minutes after valid credentials are entered.
    set_config('unlock_cache_result', '', 'plagiarism_essayguard');
    set_config('unlock_cache_time', 0, 'plagiarism_essayguard');

    // FIX-EG-UNKNOWN-STATUS (v1.2.57): Immediately re-verify with the newly saved
    // credentials so the cache is repopulated before the redirect. Without this, the
    // settings page always showed "Unlock status unknown" after every Save because the
    // cache was cleared but never refilled until the next Test Connection click or a
    // student page load. Now the save itself does the live check (one HTTP request to
    // lms-labs.com), caches the result, and the redirected page shows the real
    // unlock status straight away. check_unlock() is fail-open, so slow/unreachable
    // servers just return true and the cache reflects that gracefully.
    plagiarism_essayguard_check_unlock(true);

    redirect(
        new moodle_url('/plagiarism/essayguard/settings.php'),
        get_string('savedconfigsuccess', 'plagiarism_essayguard'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// V1.2.219: The three check_unlock() calls on this page now pass $allowfetch = true.
// The function is cache-only everywhere else (see lib.php) so the vendor HTTPS call no
// longer lands on student/teacher page renders; the admin settings page is the one place
// where a live check is what the admin actually asked for.
/* ── Handle "Test Connection" action ────────────────────────────────────────── */
$connectiontestresult = null;
if (optional_param('testconnection', false, PARAM_BOOL) && confirm_sesskey()) {
    // Force a fresh unlock check by clearing the runtime and persistent caches.
    set_config('unlock_cache_result', '', 'plagiarism_essayguard');
    set_config('unlock_cache_time', 0, 'plagiarism_essayguard');
    $connectiontestresult = plagiarism_essayguard_check_unlock(true);
}

/* ── Read current config ─────────────────────────────────────────────────────── */
$cfg = (array) get_config('plagiarism_essayguard');
$siteid          = $cfg['siteid'] ?? '';
$apikey          = $cfg['apikey'] ?? '';
$enabled         = !empty($cfg['enabled']);
$captureinterval = (int) ($cfg['captureinterval'] ?? 5000);
$minchars        = (int) ($cfg['minchars'] ?? 120);
$maxburstchars   = (int) ($cfg['maxburstchars'] ?? 150);
$allowpaste      = !empty($cfg['allowpaste']);
$pasteweight    = (int) ($cfg['paste_weight'] ?? 100);
$retentiondays   = (int) ($cfg['retentiondays'] ?? 90);

/* ── Determine connection status for display ─────────────────────────────────── */
$credsconfigured = (!empty($siteid) || !empty(get_config('local_aiconfig', 'siteid')))
                 && (!empty($apikey) || !empty(get_config('local_aiconfig', 'apikey')));

// FIX-EG-UNKNOWN-STATUS (v1.2.57): Auto-refresh stale/empty cache on plain page
// load when credentials are configured. Previously the settings page would show
// "Unlock status unknown" whenever the cache was older than 30 minutes or had
// never been populated, forcing the admin to manually click "Test Connection".
// Now a live check is made automatically so the page always shows the real status.
// The check is skipped when credentials are missing to avoid unnecessary API calls.
// v1.2.224 FIX-EG-UNLOCK-GET-SIDEEFFECT: this refresh ran on ANY page load, with no
// sesskey. plagiarism_essayguard_check_unlock(true) writes two site config values and,
// when the vendor reports the site as not unlocked, chains into
// plagiarism_essayguard_auto_unlock(), which DEDUCTS 5,000 CREDITS. A GET request that
// spends money and writes configuration is a Moodle review finding on its own, and it is
// CSRF-triggerable: any page that can make a logged-in admin's browser issue a GET to
// this URL - an <img src> in a forum post is enough - fires it. The two POST actions on
// this page already call confirm_sesskey(); this one did not.
//
// The refresh now requires a valid sesskey, which the "Refresh status" link below
// carries. The intent of FIX-EG-UNKNOWN-STATUS (v1.2.57) - never leave the admin looking
// at "Unlock status unknown" with no way forward - is kept: a plain load shows the cached
// status and offers the one-click refresh, rather than performing it unasked.
$refreshrequested = optional_param('refreshstatus', false, PARAM_BOOL);
$cachedresult = get_config('plagiarism_essayguard', 'unlock_cache_result');
$cachedtime   = (int)get_config('plagiarism_essayguard', 'unlock_cache_time');
if (
    $credsconfigured && $refreshrequested && confirm_sesskey()
        && ($cachedtime === 0 || (time() - $cachedtime) >= 1800)
) {
    plagiarism_essayguard_check_unlock(true);
    // Re-read after the live check so the display reflects the fresh result.
    $cachedresult = get_config('plagiarism_essayguard', 'unlock_cache_result');
    $cachedtime   = (int)get_config('plagiarism_essayguard', 'unlock_cache_time');
}
$statusstale = $credsconfigured && ($cachedtime === 0 || (time() - $cachedtime) >= 1800);

$cacheagemin      = $cachedtime > 0 ? (int)round((time() - $cachedtime) / 60) : null;
$isunlockedcached = $cachedtime > 0 && !empty($cachedresult);
$cachefresh        = $cachedtime > 0 && (time() - $cachedtime) < 1800;

/* ── Render page ─────────────────────────────────────────────────────────────── */
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'plagiarism_essayguard'));

// V1.2.219: db/install.php and db/upgrade.php used to call set_config('enableplagiarism', 1),
// writing a CORE site setting on the administrator's behalf. That has been removed, so the
// admin has to make the decision themselves — which means we have to tell them. This notice
// is the replacement for the silent write.
if (empty($CFG->enableplagiarism)) {
    echo $OUTPUT->notification(
        get_string('notice_enableplagiarism', 'plagiarism_essayguard'),
        \core\output\notification::NOTIFY_WARNING
    );
}

/* ── Connection / Unlock status panel ───────────────────────────────────────── */
$panelstyle  = 'padding:1rem 1.25rem;border-radius:6px;margin-bottom:1.5rem;';
$panelstyle .= 'border:1px solid ';

if ($connectiontestresult !== null) {
    // Result from the "Test Connection" button just clicked.
    if ($connectiontestresult) {
        $panel = '<div style="' . $panelstyle . '#4caf5066;background:#e8f5e9;color:#1b5e20;">'
            . get_string('panelunlocked', 'plagiarism_essayguard')
            . '</div>';
    } else {
        $paneldetail = !$credsconfigured
            ? get_string('paneldetailnocreds', 'plagiarism_essayguard')
            : get_string('paneldetaillocked', 'plagiarism_essayguard');
        $panel = '<div style="' . $panelstyle . '#e5393566;background:#ffebee;color:#b71c1c;">'
            . get_string('panelnotunlocked', 'plagiarism_essayguard') . $paneldetail
            . '</div>';
    }
} else if (!$credsconfigured) {
    $panel = '<div style="' . $panelstyle . '#ff980066;background:#fff3e0;color:#e65100;">'
        . get_string('panelnocreds', 'plagiarism_essayguard')
        . '</div>';
} else if ($cachefresh) {
    if ($isunlockedcached) {
        $panel = '<div style="' . $panelstyle . '#4caf5066;background:#e8f5e9;color:#1b5e20;">'
            . get_string('panelunlockedcached', 'plagiarism_essayguard', $cacheagemin)
            . '</div>';
    } else {
        $panel = '<div style="' . $panelstyle . '#e5393566;background:#ffebee;color:#b71c1c;">'
            . get_string('panelnotunlockedcached', 'plagiarism_essayguard', $cacheagemin)
            . '</div>';
    }
} else {
    $panel = '<div style="' . $panelstyle . '#9e9e9e66;background:#fafafa;color:#424242;">'
        . get_string('panelunknown', 'plagiarism_essayguard')
        . '</div>';
}

echo $panel;

// V1.2.224: the status refresh is no longer performed on a plain page load - see
// FIX-EG-UNLOCK-GET-SIDEEFFECT above - so offer it explicitly. The sesskey on this link
// is what authorises the config write and the possible credit spend behind it.
if ($statusstale) {
    $refreshurl = new moodle_url(
        '/plagiarism/essayguard/settings.php',
        ['refreshstatus' => 1, 'sesskey' => sesskey()]
    );
    echo '<p style="margin:0.5rem 0 1rem;">'
        . html_writer::link(
            $refreshurl,
            get_string('refreshstatus', 'plagiarism_essayguard'),
            ['class' => 'btn btn-secondary btn-sm']
        )
        . '</p>';
}

$actionurl = new moodle_url('/plagiarism/essayguard/settings.php');

// Everything below is an HTML form with inline PHP echo tags. moodle-cs's MissingDocblock
// sniff registers on every T_OPEN_TAG in a file and asks each one for a file docblock, so
// each of those inline tags is reported even though the file docblock at the top of this
// file is present and correct. Silenced for the HTML region only.
// phpcs:disable moodle.Commenting.MissingDocblock.File
?>
<form action="<?php echo $actionurl; ?>" method="post">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <input type="hidden" name="save" value="1">
    <table class="admintable generaltable" cellspacing="0">
        <tbody>

            <tr>
                <td class="cell c0"><label for="id_siteid">
                    <?php echo get_string('siteid', 'plagiarism_essayguard'); ?>
                </label></td>
                <td class="cell c1">
                    <input type="text" id="id_siteid" name="siteid" value="<?php echo s($siteid); ?>" size="40">
                    <div class="form-text"><?php echo get_string('siteid_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_apikey">
                    <?php echo get_string('apikey', 'plagiarism_essayguard'); ?>
                </label></td>
                <td class="cell c1">
                    <input type="password" id="id_apikey" name="apikey"
                           value="<?php echo s($apikey); ?>" size="40" autocomplete="new-password">
                    <div class="form-text"><?php echo get_string('apikey_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_enabled">
                    <?php echo get_string('enabled', 'plagiarism_essayguard'); ?>
                </label></td>
                <td class="cell c1">
                    <input type="checkbox" id="id_enabled" name="enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                    <div class="form-text"><?php echo get_string('enabled_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_captureinterval">
                    <?php echo get_string('captureinterval', 'plagiarism_essayguard'); ?>
                </label></td>
                <td class="cell c1">
                    <input type="number" id="id_captureinterval" name="captureinterval"
                           value="<?php echo (int)$captureinterval; ?>" min="1000" max="60000">
                    <div class="form-text"><?php echo get_string('captureinterval_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_minchars">
                    <?php echo get_string('minchars', 'plagiarism_essayguard'); ?>
                </label></td>
                <td class="cell c1">
                    <input type="number" id="id_minchars" name="minchars" value="<?php echo (int)$minchars; ?>" min="0">
                    <div class="form-text"><?php echo get_string('minchars_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_maxburstchars">
                    <?php echo get_string('maxburstchars', 'plagiarism_essayguard'); ?>
                </label></td>
                <td class="cell c1">
                    <input type="number" id="id_maxburstchars" name="maxburstchars"
                           value="<?php echo (int)$maxburstchars; ?>" min="1">
                    <div class="form-text"><?php echo get_string('maxburstchars_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_allowpaste">
                    <?php echo get_string('allowpaste', 'plagiarism_essayguard'); ?>
                </label></td>
                <td class="cell c1">
                    <input type="checkbox" id="id_allowpaste" name="allowpaste" value="1"
                           <?php echo $allowpaste ? 'checked' : ''; ?>>
                    <div class="form-text"><?php echo get_string('allowpaste_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_paste_weight">
                    <?php echo get_string('paste_weight', 'plagiarism_essayguard'); ?>
                </label></td>
                <td class="cell c1">
                    <input type="number" id="id_paste_weight" name="paste_weight"
                           value="<?php echo (int)$pasteweight; ?>" min="0" max="100">
                    <span style="margin-left:0.4rem;">%</span>
                    <div class="form-text"><?php echo get_string('paste_weight_desc', 'plagiarism_essayguard'); ?></div>
                </td>
            </tr>

            <tr>
                <td class="cell c0"><label for="id_retentiondays">
                    <?php echo get_string('retentiondays', 'plagiarism_essayguard'); ?>
                </label></td>
                <td class="cell c1">
                    <input type="number" id="id_retentiondays" name="retentiondays"
                           value="<?php echo (int)$retentiondays; ?>" min="0" max="3650">
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
        <?php echo get_string('testconnection', 'plagiarism_essayguard'); ?>
    </button>
    <small style="margin-left:0.5rem;color:#666;">
        <?php echo get_string('testconnection_desc', 'plagiarism_essayguard'); ?>
    </small>
</form>

<?php
echo html_writer::tag(
    'p',
    html_writer::tag('strong', get_string('whereunlock', 'plagiarism_essayguard')) .
    get_string('whereunlockbody', 'plagiarism_essayguard') .
    html_writer::link('https://lms-labs.com', 'lms-labs.com', ['target' => '_blank']) .
    get_string('whereunlocktail', 'plagiarism_essayguard'),
    ['style' => 'margin-top:1.25rem;padding:0.75rem 1rem;background:#f5f5f5;border-radius:4px;font-size:0.9rem;']
);

echo $OUTPUT->footer();
