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
 * Essay Guard site settings page.
 *
 * Plagiarism plugins provide a standalone admin page (admin_externalpage
 * 'plagiarismessayguard') rather than an admin settings tree.
 *
 * Checking the licence status and purchasing the unlock are separate actions: the
 * purchase only happens after the administrator confirms the amount, through a POST
 * carrying a valid sesskey.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/plagiarismlib.php');
require_once($CFG->dirroot . '/plagiarism/essayguard/lib.php');

require_login();
admin_externalpage_setup('plagiarismessayguard');
require_capability('moodle/site:config', context_system::instance());

$pageurl = new moodle_url('/plagiarism/essayguard/settings.php');
$action  = optional_param('action', '', PARAM_ALPHA);
$unlockcosts = (object)[
    'credits' => PLAGIARISM_ESSAYGUARD_UNLOCK_CREDITS,
    'price'   => PLAGIARISM_ESSAYGUARD_UNLOCK_PRICE_USD,
];

/**
 * Forget the cached licence status so the next check is live.
 *
 * @return void
 */
function plagiarism_essayguard_settings_clear_unlock_cache(): void {
    set_config('unlock_cache_result', '', 'plagiarism_essayguard');
    set_config('unlock_cache_time', 0, 'plagiarism_essayguard');
}

/* ── Actions ─────────────────────────────────────────────────────────────────── */

// Live status check (no purchase).
if ($action === 'testconnection') {
    require_sesskey();
    plagiarism_essayguard_settings_clear_unlock_cache();
    plagiarism_essayguard_check_unlock(true);
    redirect($pageurl);
}

// Purchase confirmation screen.
if ($action === 'unlock') {
    require_sesskey();
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('unlockheading', 'plagiarism_essayguard'));
    echo $OUTPUT->confirm(
        get_string('unlockconfirm', 'plagiarism_essayguard', $unlockcosts),
        new single_button(
            new moodle_url($pageurl, ['action' => 'unlockconfirmed', 'sesskey' => sesskey()]),
            get_string('unlockconfirmbutton', 'plagiarism_essayguard', $unlockcosts),
            'post',
            single_button::BUTTON_PRIMARY
        ),
        new single_button($pageurl, get_string('cancel'), 'get')
    );
    echo $OUTPUT->footer();
    die();
}

// Confirmed purchase: POST only.
if ($action === 'unlockconfirmed') {
    require_sesskey();
    if (!data_submitted()) {
        redirect($pageurl);
    }
    $siteid = plagiarism_essayguard_get_siteid();
    $apikey = plagiarism_essayguard_get_apikey();
    $unlocked = !empty($siteid) && !empty($apikey) && plagiarism_essayguard_auto_unlock($siteid, $apikey);
    plagiarism_essayguard_settings_clear_unlock_cache();
    if ($unlocked) {
        set_config('unlock_cache_result', 1, 'plagiarism_essayguard');
        set_config('unlock_cache_time', time(), 'plagiarism_essayguard');
        redirect(
            $pageurl,
            get_string('unlocksuccess', 'plagiarism_essayguard'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect(
        $pageurl,
        get_string('unlockfailed', 'plagiarism_essayguard', $unlockcosts),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

/* ── Settings form ───────────────────────────────────────────────────────────── */
$form = new \plagiarism_essayguard\form\settings_form($pageurl);
$cfg  = get_config('plagiarism_essayguard');
$form->set_data([
    'enabled'         => !empty($cfg->enabled),
    'siteid'          => $cfg->siteid ?? '',
    'apikey'          => $cfg->apikey ?? '',
    'captureinterval' => plagiarism_essayguard_config_int('captureinterval', 5000),
    'minchars'        => plagiarism_essayguard_config_int('minchars', 120),
    'maxburstchars'   => plagiarism_essayguard_config_int('maxburstchars', 150),
    'allowpaste'      => !empty($cfg->allowpaste),
    'paste_weight'    => plagiarism_essayguard_config_int('paste_weight', 100),
    'retentiondays'   => plagiarism_essayguard_config_int('retentiondays', 90),
]);

if ($data = $form->get_data()) {
    $credentialschanged = ($data->siteid !== ($cfg->siteid ?? '')) || ($data->apikey !== ($cfg->apikey ?? ''));
    foreach (
        ['enabled', 'siteid', 'apikey', 'captureinterval', 'minchars', 'maxburstchars',
            'allowpaste', 'paste_weight', 'retentiondays'] as $name
    ) {
        set_config($name, $data->$name, 'plagiarism_essayguard');
    }
    if ($credentialschanged) {
        // New credentials: drop the cached status. Saving never purchases anything.
        plagiarism_essayguard_settings_clear_unlock_cache();
    }
    redirect(
        $pageurl,
        get_string('savedconfigsuccess', 'plagiarism_essayguard'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

/* ── Status panel data ───────────────────────────────────────────────────────── */
$credsconfigured = !empty(plagiarism_essayguard_get_siteid()) && !empty(plagiarism_essayguard_get_apikey());
$cachedtime   = (int)get_config('plagiarism_essayguard', 'unlock_cache_time');
$cachedresult = !empty(get_config('plagiarism_essayguard', 'unlock_cache_result'));
$checked      = $cachedtime > 0;
$agemin       = $checked ? (int)round((time() - $cachedtime) / 60) : 0;

if (!$credsconfigured) {
    $state = 'nocreds';
} else if (!$checked) {
    $state = 'unknown';
} else {
    $state = $cachedresult ? 'unlocked' : 'locked';
}

$templatedata = [
    'enableplagiarismoff' => empty($CFG->enableplagiarism),
    'state'          => $state,
    'isnocreds'      => $state === 'nocreds',
    'isunknown'      => $state === 'unknown',
    'isunlocked'     => $state === 'unlocked',
    'islocked'       => $state === 'locked',
    'agemin'         => $agemin,
    'canunlock'      => $state === 'locked',
    'cancheck'       => $credsconfigured,
    'actionurl'      => $pageurl->out(false),
    'sesskey'        => sesskey(),
    'unlockcredits'  => PLAGIARISM_ESSAYGUARD_UNLOCK_CREDITS,
    'unlockprice'    => PLAGIARISM_ESSAYGUARD_UNLOCK_PRICE_USD,
    'form'           => $form->render(),
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'plagiarism_essayguard'));
echo $OUTPUT->render_from_template('plagiarism_essayguard/settings', $templatedata);
echo $OUTPUT->footer();
