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
 * Essay Guard — Student Detail Report.
 *
 * Shows the full authenticity report for a single student's most recent
 * submission in a given course module. Includes risk badge, visual score bar,
 * key metrics, per-signal breakdown with computed evidence values, and
 * per-question cards (each with their own signal breakdown) for quizzes.
 *
 * URL: /plagiarism/essayguard/student.php?cmid=X&userid=Y
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
// V1.2.222: lib.php was never loaded here - see rescore.php. Every
// plagiarism_essayguard_*() call on this page was a fatal waiting to happen.
require_once(__DIR__ . '/lib.php');

global $DB, $CFG, $OUTPUT, $PAGE, $USER;

$cmid   = required_param('cmid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);

$cm      = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cmid);
$student = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

require_login($course, false, $cm);
require_capability('plagiarism/essayguard:viewreport', $context);

// V1.2.221: enforce group separation HERE too.
//
// v1.2.219 added a SEPARATEGROUPS restriction to report.php so a tutor sees only their own
// groups. This page had no group check at all - it took `userid` straight from the URL and
// rendered that student's entire behavioural profile: risk score, paste counts, WPM,
// per-signal explanations, name. A tutor restricted to Group 1 could read a Group 7
// student's profile simply by editing the URL, and the id is on every participants-list
// link in the course. The remediation was cosmetic without this.
//
// Also require the target to be enrolled: get_record('user', …, MUST_EXIST) accepts ANY
// site user id, so the page resolved arbitrary account ids to real names in its heading
// before it ever checked whether there was data.
if (!is_enrolled($context, $userid)) {
    throw new moodle_exception(
        'nopermissions',
        'error',
        '',
        get_string('viewreport', 'plagiarism_essayguard')
    );
}
if (
    groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS
        && !has_capability('moodle/site:accessallgroups', $context)
) {
    $egallowedgroups = groups_get_activity_allowed_groups($cm);
    $egshared = false;
    foreach (array_keys($egallowedgroups) as $eggid) {
        if (groups_is_member($eggid, $userid)) {
            $egshared = true;
            break;
        }
    }
    if (!$egshared) {
        throw new moodle_exception(
            'nopermissions',
            'error',
            '',
            get_string('viewreport', 'plagiarism_essayguard')
        );
    }
}

$PAGE->set_url(new moodle_url('/plagiarism/essayguard/student.php', ['cmid' => $cmid, 'userid' => $userid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title(get_string('pluginname', 'plagiarism_essayguard') . ' — ' . fullname($student));
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/plagiarism/essayguard/styles.css');

/* ── Load score record ───────────────────────────────────────────────────────── */

$sc = $DB->get_record_sql(
    "SELECT * FROM {plagiarism_essayguard_sc}
      WHERE userid = :userid AND cmid = :cmid
   ORDER BY qslot ASC, timemodified DESC",
    ['userid' => $userid, 'cmid' => $cmid],
    IGNORE_MULTIPLE
);

/* ── Colour map ──────────────────────────────────────────────────────────────── */

$egrisklow    = get_string('risklow', 'plagiarism_essayguard');
$egriskmedium = get_string('riskmedium', 'plagiarism_essayguard');
$egriskhigh   = get_string('riskhigh', 'plagiarism_essayguard');
$riskcolours = [
    'low'     => ['bg' => '#f0fdf4', 'text' => '#166534', 'bar' => '#22c55e', 'label' => $egrisklow],
    'medium'  => ['bg' => '#fff7ed', 'text' => '#7c2d12', 'bar' => '#f97316', 'label' => $egriskmedium],
    'high'    => ['bg' => '#fef2f2', 'text' => '#991b1b', 'bar' => '#ef4444', 'label' => $egriskhigh],
    'partial' => ['bg' => '#fff7ed', 'text' => '#7c2d12', 'bar' => '#f97316', 'label' => $egriskmedium],
    'mild'    => ['bg' => '#fff7ed', 'text' => '#7c2d12', 'bar' => '#f97316', 'label' => $egriskmedium],
];

echo $OUTPUT->header();

$backurl = new moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]);
echo html_writer::tag(
    'p',
    html_writer::link(
        $backurl,
        '← ' . get_string('backtoclassreport', 'plagiarism_essayguard'),
        ['class' => 'btn btn-sm btn-secondary']
    ),
    ['style' => 'margin-bottom:1.5rem;']
);

/* ── Page header ─────────────────────────────────────────────────────────────── */

$modinfo = get_fast_modinfo($cm->course);
$actname = $modinfo->get_cm($cmid)->name;

echo '<div '
    . 'style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem;">';
echo '<div>';
echo html_writer::tag(
    'h2',
    get_string('pluginname', 'plagiarism_essayguard') . ' — ' . fullname($student),
    ['style' => 'margin:0 0 0.25rem;']
);
echo '<p style="margin:0;opacity:0.75;font-size:0.9rem;">' . s($actname) . ' &nbsp;|&nbsp; ' . s($course->fullname) . '</p>';
echo '</div>';
echo '</div>';

if (!$sc) {
    echo $OUTPUT->notification(get_string('nodata', 'plagiarism_essayguard'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// FIX-EG-STUDENT-LEVEL (v1.2.73): derive level from score, not DB column.
$score  = isset($sc->riskscore) ? (int)round((float)($sc->riskscore ?? 0) * 100) : 0;
$level  = \plagiarism_essayguard\local\service\analyser::risk_level($score);
$c      = $riskcolours[$level] ?? $riskcolours['low'];

$explanations = json_decode($sc->explanationsjson ?? '[]', true) ?: [];
$metrics      = json_decode($sc->metricsjson ?? '{}', true) ?: [];

/* ── Overall score card + visual bar ────────────────────────────────────────── */

$barw      = min(100, $score);
$barcolour = $c['bar'];

echo '<div style="background:' . $c['bg'] . ';border:1px solid ' . $c['text'] . '33;border-radius:8px;padding:1.25rem '
    . '1.5rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:2rem;flex-wrap:wrap;">';

echo '<div style="text-align:center;">';
echo '<div style="font-size:2.8rem;font-weight:800;color:' . $c['text'] . ';">' . $score . '</div>';
echo '<div style="font-size:0.8rem;color:' . $c['text'] . ';font-weight:600;">/ 100</div>';
echo '</div>';

echo '<div style="flex:1;min-width:200px;">';
echo '<div style="font-size:1.2rem;font-weight:700;color:' . $c['text'] . ';margin-bottom:0.25rem;">'
    . strtoupper($c['label']) . ' '
    . core_text::strtoupper(get_string('riskword', 'plagiarism_essayguard')) . '</div>';
echo '<div '
    . 'style="width:100%;max-width:300px;height:10px;background:#e5e7eb;border-radius:5px;overflow:hidden;margin-bottom:0.35rem;">';
echo '<div style="width:' . $barw . '%;height:100%;background:' . $barcolour . ';border-radius:5px;"></div>';
echo '</div>';
echo '<div style="font-size:0.82rem;color:#6b7280;">';
echo get_string(
    'lastscored',
    'plagiarism_essayguard',
    userdate((int)($sc->timemodified ?? 0), get_string('strftimedatetimeshort', 'langconfig'))
);
echo '</div>';
echo '</div>';

echo '<div style="font-size:0.8rem;color:#6b7280;line-height:1.9;">';
echo '<span style="color:#166534;font-weight:600;">&#9679; ' . strtoupper($egrisklow) . '</span>: 0–29 &nbsp; ';
echo '<span style="color:#c2410c;font-weight:600;">&#9679; ' . strtoupper($egriskmedium) . '</span>: 30–64 &nbsp; ';
echo '<span style="color:#991b1b;font-weight:600;">&#9679; ' . strtoupper($egriskhigh) . '</span>: 65–100';
echo '</div>';

echo '</div>';

/* ── Baseline confidence ─────────────────────────────────────────────────────── */

$bs      = $sc->baseline_status ?? 'none';
$bsfp   = $DB->get_record('plagiarism_essayguard_fp', ['userid' => $userid]);
$bssamples = $bsfp ? (int)$bsfp->samplecount : 0;
switch ($bs) {
    case 'stable':
        $bslabel = get_string('baselinestable', 'plagiarism_essayguard', $bssamples);
        $bsbg    = '#e8f5e9';
        $bstc    = '#166534';
        break;
    case 'preliminary':
        $bslabel = get_string('baselinebuilding', 'plagiarism_essayguard', $bssamples);
        $bsbg    = '#fffde7';
        $bstc    = '#92400e';
        break;
    default:
        $bslabel = get_string('no_baseline', 'plagiarism_essayguard');
        $bsbg    = '#f5f5f5';
        $bstc    = '#6b7280';
        break;
}
echo '<div style="display:inline-block;padding:0.5rem 1rem;border-radius:6px;background:' . $bsbg
    . ';margin-bottom:1.5rem;font-size:0.85rem;">';
echo '<span style="color:' . $bstc . ';font-weight:600;">'
    . get_string('baseline_confidence', 'plagiarism_essayguard') . ':</span>'
    . ' <span style="color:#374151;">' . s($bslabel) . '</span>';
echo '</div>';

/* ── Indicators ──────────────────────────────────────────────────────────────── */

if (!empty($explanations)) {
    echo html_writer::tag(
        'h3',
        get_string('indicatorsheading', 'plagiarism_essayguard'),
        ['style' => 'margin-bottom:0.75rem;']
    );
    echo html_writer::start_tag('ul', ['style' => 'padding-left:1.25rem;margin-bottom:2rem;']);
    foreach ($explanations as $exp) {
        echo html_writer::tag('li', s($exp), ['style' => 'margin-bottom:0.35rem;']);
    }
    echo html_writer::end_tag('ul');
}

/* ── Key metrics table ───────────────────────────────────────────────────────── */

$metricrows = [
    [get_string('metric_totalkeystrokes', 'plagiarism_essayguard'), (int)($sc->total_keystrokes ?? 0)],
    [get_string('metric_pastes', 'plagiarism_essayguard'), (int)($sc->paste_events ?? 0)],
    [get_string('metric_backspace', 'plagiarism_essayguard'), (int)($sc->backspace_count ?? 0)],
    [get_string('metric_delete', 'plagiarism_essayguard'), (int)($sc->delete_count ?? 0)],
    [get_string(
        'metric_avgwpm',
        'plagiarism_essayguard'),
            number_format((float)($sc->average_wpm ?? 0),
        1
    )],
    [get_string(
        'metric_wpmstddev',
        'plagiarism_essayguard'),
            number_format((float)($sc->wpm_std_dev ?? 0),
        1
    )],
    [get_string('metric_pausecount', 'plagiarism_essayguard'), (int)($sc->pause_count ?? 0)],
    [get_string(
        'metric_typingtime',
        'plagiarism_essayguard'),
            round((int)($sc->typing_time ?? 0) / 1000,
        1
    )],
    [get_string(
        'metric_idletime',
        'plagiarism_essayguard'),
            round((int)($sc->idle_time ?? 0) / 1000,
        1
    )],
    [get_string(
        'metric_entropy',
        'plagiarism_essayguard'),
            number_format((float)($sc->entropy_score ?? 0),
        3
    )],
    [get_string(
        'metric_interkeymean',
        'plagiarism_essayguard'),
            number_format((float)($sc->interkey_mean ?? 0),
        1
    )],
    [get_string(
        'metric_sentencevariance',
        'plagiarism_essayguard'),
            number_format((float)($sc->sentence_variance ?? 0),
        3
    )],
    [get_string(
        'metric_vocabdiversity',
        'plagiarism_essayguard'),
            number_format((float)($sc->vocab_diversity ?? 0),
        3
    )],
    [get_string(
        'metric_baselinedev',
        'plagiarism_essayguard'),
            number_format((float)($sc->baseline_deviation ?? 0),
        3
    )],
];

echo html_writer::tag(
    'h3',
    get_string('metricsheading', 'plagiarism_essayguard'),
    ['style' => 'margin-bottom:0.75rem;']
);
echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'max-width:520px;']);
echo html_writer::start_tag('tbody');
foreach ($metricrows as [$label, $value]) {
    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', $label, ['style' => 'padding:0.4rem 0.75rem;font-weight:500;']);
    echo html_writer::tag('td', $value, ['style' => 'padding:0.4rem 0.75rem;']);
    echo html_writer::end_tag('tr');
}
echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

/* ── Signal Breakdown (v1.2.113+) ────────────────────────────────────────────── */

$signalbreakdown = isset($metrics['signal_breakdown']) ? $metrics['signal_breakdown'] : null;

// Signal definitions: number → [name, max_pts, description].
$signalinfo = [
    1  => [get_string(
        'sig1name',
        'plagiarism_essayguard'), 60,
            get_string('sig1desc',
        'plagiarism_essayguard'
    )],
    2  => [get_string(
        'sig2name',
        'plagiarism_essayguard'), 20,
            get_string('sig2desc',
        'plagiarism_essayguard'
    )],
    3  => [get_string(
        'sig3name',
        'plagiarism_essayguard'), 30,
            get_string('sig3desc',
        'plagiarism_essayguard'
    )],
    4  => [get_string(
        'sig4name',
        'plagiarism_essayguard'), 20,
            get_string('sig4desc',
        'plagiarism_essayguard'
    )],
    5  => [get_string(
        'sig5name',
        'plagiarism_essayguard'), 15,
            get_string('sig5desc',
        'plagiarism_essayguard'
    )],
    6  => [get_string(
        'sig6name',
        'plagiarism_essayguard'), 25,
            get_string('sig6desc',
        'plagiarism_essayguard'
    )],
    7  => [get_string(
        'sig7name',
        'plagiarism_essayguard'), 10,
            get_string('sig7desc',
        'plagiarism_essayguard'
    )],
    8  => [get_string(
        'sig8name',
        'plagiarism_essayguard'), 10,
            get_string('sig8desc',
        'plagiarism_essayguard'
    )],
    9  => [get_string(
        'sig9name',
        'plagiarism_essayguard'), 5,
            get_string('sig9desc',
        'plagiarism_essayguard'
    )],
    10 => [get_string(
        'sig10name',
        'plagiarism_essayguard'), 10,
            get_string('sig10desc',
        'plagiarism_essayguard'
    )],
    11 => [get_string(
        'sig11name',
        'plagiarism_essayguard'), 10,
            get_string('sig11desc',
        'plagiarism_essayguard'
    )],
    12 => [get_string(
        'sig12name',
        'plagiarism_essayguard'), 10,
            get_string('sig12desc',
        'plagiarism_essayguard'
    )],
    13 => [get_string(
        'sig13name',
        'plagiarism_essayguard'), 50,
            get_string('sig13desc',
        'plagiarism_essayguard'
    )],
];

/**
 * Build evidence detail strings for a given signal number.
 * Returns an array of human-readable strings showing the actual computed values.
 *
 * @param int    $num The signal number, 1 to 13.
 * @param object $sc  The score record holding the stored metric columns.
 * @param array  $m   The decoded metricsjson for the same record.
 * @return string[] Human-readable evidence lines for this signal; empty when the
 *                  signal has no measurements to show.
 */
function plagiarism_essayguard_signal_evidence(int $num, object $sc, array $m): array {
    $parts = [];
    switch ($num) {
        case 1:
            $pf = isset($m['paste_frac']) ? round((float)$m['paste_frac'] * 100, 1) : null;
            $pe = (int)($sc->paste_events ?? 0);
            if ($pf !== null) {
                $parts[] = get_string('evidence_pastefraction', 'plagiarism_essayguard', $pf);
            }
            if ($pe > 0) {
                $parts[] = get_string('evidence_pasteevents', 'plagiarism_essayguard', $pe);
            }
            $kr = isset($m['keystroke_ratio']) ? round((float)$m['keystroke_ratio'], 3) : null;
            if ($kr !== null && $kr < 0.25) {
                $parts[] = get_string('evidence_keystrokeratiolow', 'plagiarism_essayguard', $kr);
            }
            break;
        case 2:
            $ks = (int)($sc->total_keystrokes ?? 0);
            if ($ks > 0) {
                $parts[] = get_string('evidence_keystrokescaptured', 'plagiarism_essayguard', $ks);
            }
            break;
        case 3:
            $cps = isset($m['chars_per_sec']) ? round((float)$m['chars_per_sec'], 2) : null;
            if ($cps !== null) {
                $threshold = $cps > 15
                    ? get_string('thr_speedsuperhuman', 'plagiarism_essayguard')
                    : ($cps > 8 ? get_string('thr_speedveryfast', 'plagiarism_essayguard') : '');
                $parts[] = get_string('evidence_speed', 'plagiarism_essayguard', $cps) . $threshold;
            }
            break;
        case 4:
            $pc = (int)($sc->pause_count ?? 0);
            $parts[] = get_string('evidence_pauses', 'plagiarism_essayguard', $pc);
            break;
        case 5:
            $ks = (int)($sc->total_keystrokes ?? 0);
            $bs = (int)($sc->backspace_count ?? 0) + (int)($sc->delete_count ?? 0);
            if ($ks > 0) {
                $ratio = round($bs / $ks * 100, 1);
                $parts[] = get_string(
                    'evidence_correctionratio',
                    'plagiarism_essayguard',
                    (object) ['ratio' => $ratio, 'count' => $bs]
                );
            } else if ($bs === 0) {
                $parts[] = get_string('evidence_nocorrections', 'plagiarism_essayguard');
            }
            break;
        case 6:
            $tt = (int)($sc->typing_time ?? 0);
            $parts[] = get_string('evidence_typingtime', 'plagiarism_essayguard', round($tt / 1000, 1));
            break;
        case 7:
            $es = (float)($sc->entropy_score ?? 0);
            if ($es > 0) {
                $threshold = $es < 0.3
                    ? get_string('thr_entropyrobotic', 'plagiarism_essayguard')
                    : ($es < 0.5 ? get_string('thr_entropyborderline', 'plagiarism_essayguard') : '');
                $parts[] = get_string(
                    'evidence_entropyscore',
                    'plagiarism_essayguard',
                    number_format($es, 3)
                ) . $threshold;
            }
            if (isset($m['iki_shannon'])) {
                $iki = round((float)$m['iki_shannon'], 3);
                $threshold2 = $iki < 0.35
                    ? get_string('thr_ikifired', 'plagiarism_essayguard')
                    : ($iki < 0.55 ? get_string('thr_ikiborderline', 'plagiarism_essayguard') : '');
                $parts[] = get_string('evidence_shannoniki', 'plagiarism_essayguard', $iki) . $threshold2;
            }
            break;
        case 8:
            $sv = (float)($sc->sentence_variance ?? 0);
            if ($sv > 0 || $sv === 0.0) {
                $threshold = $sv < 6
                    ? get_string('thr_varveryuniform', 'plagiarism_essayguard')
                    : ($sv < 12 ? get_string('thr_varsomewhatuniform', 'plagiarism_essayguard') : '');
                $parts[] = get_string(
                    'evidence_sentencevariance',
                    'plagiarism_essayguard',
                    number_format($sv, 3)
                ) . $threshold;
            }
            $scling = isset($m['sentence_count_ling']) ? (int)$m['sentence_count_ling'] : null;
            if ($scling !== null) {
                $parts[] = get_string('evidence_sentencecount', 'plagiarism_essayguard', $scling);
            }
            break;
        case 9:
            $vd = (float)($sc->vocab_diversity ?? 0);
            if ($vd > 0) {
                $threshold = $vd < 0.30
                    ? get_string('thr_ttrverylow', 'plagiarism_essayguard')
                    : ($vd < 0.40 ? get_string('thr_ttrlow', 'plagiarism_essayguard') : '');
                $parts[] = get_string(
                    'evidence_typetoken',
                    'plagiarism_essayguard',
                    number_format($vd, 3)
                ) . $threshold;
            }
            break;
        case 10:
            if (isset($m['iki_autocorr'])) {
                $ac = round((float)$m['iki_autocorr'], 3);
                $dev = round(abs($ac - 0.1), 3);
                $threshold = $dev > 0.5
                    ? get_string('thr_autocorrrobotic', 'plagiarism_essayguard')
                    : ($dev > 0.3 ? get_string('thr_autocorrsuspicious', 'plagiarism_essayguard') : '');
                $parts[] = get_string(
                    'evidence_ikiautocorr',
                    'plagiarism_essayguard',
                    (object) [
                        'value' => $ac, 'dev' => $dev, 'threshold' => $threshold,
                        ]
                );
            }
            if (isset($m['iki_sample_count'])) {
                $parts[] = get_string(
                    'evidence_ikisamples',
                    'plagiarism_essayguard',
                    (int)$m['iki_sample_count']
                );
            }
            break;
        case 11:
            if (isset($m['speed_cv'])) {
                $cv = round((float)$m['speed_cv'], 3);
                $threshold = $cv < 0.30
                    ? get_string('thr_cvconstant', 'plagiarism_essayguard')
                    : ($cv < 0.50 ? get_string('thr_cvlowvariation', 'plagiarism_essayguard') : '');
                $parts[] = get_string('evidence_speedcv', 'plagiarism_essayguard', $cv) . $threshold;
            }
            break;
        case 12:
            $kr = isset($m['keystroke_ratio']) ? round((float)$m['keystroke_ratio'], 3) : null;
            if ($kr !== null) {
                $threshold = $kr < 0.5
                    ? get_string('thr_krfired', 'plagiarism_essayguard')
                    : ($kr < 0.8 ? get_string('thr_krlow', 'plagiarism_essayguard') : '');
                $parts[] = get_string('evidence_keystrokeratiochars', 'plagiarism_essayguard', $kr)
                    . $threshold;
            }
            break;
        case 13:
            $scps = isset($m['server_cps']) ? round((float)$m['server_cps'], 2) : null;
            if ($scps !== null && $scps > 0) {
                $threshold = $scps > 10
                    ? get_string('thr_scpsimpossible', 'plagiarism_essayguard')
                    : ($scps > 4 ? get_string('thr_scpsveryfast', 'plagiarism_essayguard') : '');
                $parts[] = get_string('evidence_servercps', 'plagiarism_essayguard', $scps) . $threshold;
            }
            break;
    }
    return $parts;
}

/**
 * Render a signal breakdown table for one record.
 * @param array|null  $breakdown   The signal_breakdown array from metricsjson, or null for
 *                                 records written before v1.2.113, which did not store one.
 * @param object      $screc      The DB score record (for metric columns).
 * @param array       $mets        Decoded metricsjson array.
 * @param array       $siginfo    Signal definitions array.
 * @param array       $riskc       Risk colour map.
 * @param int         $finalscore The final capped score (0-100).
 * @return void
 */
function plagiarism_essayguard_render_signal_table(
    ?array $breakdown,
    object $screc,
    array $mets,
    array $siginfo,
    array $riskc,
    int $finalscore
): void {
    if ($breakdown === null) {
        echo '<p style="color:#9ca3af;font-style:italic;font-size:0.85rem;">'
            . get_string('nobreakdown', 'plagiarism_essayguard') . '</p>';
        return;
    }

    $baselinedev     = (float)($screc->baseline_deviation ?? 0);
    $baselinedevpts = $baselinedev > 0.3
        ? (int)round(min(15.0, $baselinedev * 15)) : 0;
    $lingfallbackpts = (int)($mets['linguistic_fallback_pts'] ?? 0);
    $signalsum        = array_sum($breakdown);
    $totalprecap     = $signalsum + $baselinedevpts + $lingfallbackpts;

    echo '<p style="font-size:0.8rem;color:#6b7280;margin-bottom:0.5rem;">'
        . get_string('signalbreakdownnote', 'plagiarism_essayguard') . '</p>';

    echo '<details style="font-size:0.82rem;margin-bottom:0.65rem;">';
    echo '<summary style="cursor:pointer;display:inline-flex;align-items:center;gap:0.4rem;padding:0.2rem '
        . '0.65rem;border-radius:5px;border:1px solid '
        . '#e5e7eb;background:#f9fafb;color:#374151;font-weight:600;font-size:0.8rem;'
        . 'list-style:none;-webkit-appearance:none;">&#9432;&nbsp;'
        . get_string('statuskey', 'plagiarism_essayguard') . '</summary>';
    echo '<div style="margin-top:0.4rem;padding:0.65rem 0.9rem;background:#f9fafb;border:1px solid '
        . '#e5e7eb;border-radius:6px;max-width:620px;display:flex;flex-direction:column;gap:0.5rem;">';
    echo '<div style="display:flex;gap:0.65rem;align-items:baseline;">'
        . '<span style="min-width:72px;font-size:0.8rem;font-weight:700;color:#991b1b;white-space:nowrap;flex-shrink:0;">'
        . '&#9679;&nbsp;' . get_string('statusfired', 'plagiarism_essayguard') . '</span>'
        . '<span style="font-size:0.82rem;color:#374151;">'
        . get_string('statusfireddesc', 'plagiarism_essayguard') . '</span>'
        . '</div>';
    echo '<div style="display:flex;gap:0.65rem;align-items:baseline;">'
        . '<span style="min-width:72px;font-size:0.8rem;font-weight:600;color:#9ca3af;white-space:nowrap;flex-shrink:0;">'
        . '&#9711;&nbsp;' . get_string('statussilent', 'plagiarism_essayguard') . '</span>'
        . '<span style="font-size:0.82rem;color:#374151;">'
        . get_string('statussilentdesc', 'plagiarism_essayguard') . '</span>'
        . '</div>';
    echo '<div style="display:flex;gap:0.65rem;align-items:baseline;">'
        . '<span style="min-width:72px;font-size:0.8rem;font-weight:600;color:#b45309;white-space:nowrap;flex-shrink:0;">'
        . get_string('statusapplied', 'plagiarism_essayguard') . '</span>'
        . '<span style="font-size:0.82rem;color:#374151;">'
        . get_string('statusapplieddesc', 'plagiarism_essayguard') . '</span>'
        . '</div>';
    echo '</div></details>';

    echo '<table class="generaltable" style="max-width:800px;">';
    echo '<thead><tr>';
    $signalheaders = [
        get_string('colnum', 'plagiarism_essayguard'),
        get_string('colsignal', 'plagiarism_essayguard'),
        get_string('colmax', 'plagiarism_essayguard'),
        get_string('colpts', 'plagiarism_essayguard'),
        get_string('colstatus', 'plagiarism_essayguard'),
        get_string('colevidence', 'plagiarism_essayguard'),
    ];
    foreach ($signalheaders as $h) {
        echo '<th style="padding:0.4rem 0.75rem;white-space:nowrap;">' . $h . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($siginfo as $num => [$sname, $smax, $sdesc]) {
        $pts   = (int)($breakdown[$num] ?? 0);
        $fired = $pts > 0;

        $ptscell = $fired
            ? '<span style="font-weight:700;color:#991b1b;">+' . $pts . '</span>'
            : '<span style="color:#9ca3af;">0</span>';

        $statuscell = $fired
            ? '<span style="display:inline-block;padding:0.15rem '
                . '0.5rem;border-radius:4px;font-size:0.78rem;font-weight:600;background:#fef2f2;color:#991b1b;" '
                . 'title="'
                . s(get_string('statusfiredtitle', 'plagiarism_essayguard')) . '">&#9679; '
                . get_string('statusfired', 'plagiarism_essayguard') . '</span>'
            : '<span style="color:#d1d5db;" title="'
                . s(get_string('statussilenttitle', 'plagiarism_essayguard')) . '">&#9711; '
                . get_string('statussilent', 'plagiarism_essayguard') . '</span>';

        $evidenceparts = plagiarism_essayguard_signal_evidence($num, $screc, $mets);
        $evidencehtml  = '';
        if (!empty($evidenceparts)) {
            $evidencehtml = implode('<br>', array_map('s', $evidenceparts));
        }
        $evidencehtml .= '<div style="margin-top:3px;color:#9ca3af;font-size:0.77rem;">' . s($sdesc) . '</div>';

        $rowstyle = $fired ? 'background:#fffbeb;' : '';
        echo '<tr style="' . $rowstyle . '">';
        echo '<td style="padding:0.4rem 0.75rem;color:#9ca3af;font-size:0.85rem;">' . $num . '</td>';
        echo '<td style="padding:0.4rem 0.75rem;font-weight:' . ($fired ? '600' : '400') . ';">' . s($sname) . '</td>';
        echo '<td style="padding:0.4rem 0.75rem;color:#9ca3af;">' . $smax . '</td>';
        echo '<td style="padding:0.4rem 0.75rem;">' . $ptscell . '</td>';
        echo '<td style="padding:0.4rem 0.75rem;">' . $statuscell . '</td>';
        echo '<td style="padding:0.4rem 0.75rem;font-size:0.82rem;">' . $evidencehtml . '</td>';
        echo '</tr>';
    }

    if ($baselinedevpts > 0) {
        echo '<tr style="background:#fffbeb;border-top:1px solid #e5e7eb;">';
        echo '<td style="padding:0.4rem 0.75rem;color:#9ca3af;font-size:0.85rem;">—</td>';
        echo '<td style="padding:0.4rem 0.75rem;font-weight:600;"><em>'
            . get_string('baselinebonusname', 'plagiarism_essayguard') . '</em></td>';
        echo '<td style="padding:0.4rem 0.75rem;color:#9ca3af;">15</td>';
        echo '<td style="padding:0.4rem 0.75rem;"><span style="font-weight:700;color:#991b1b;">+'
            . $baselinedevpts . '</span></td>';
        echo '<td style="padding:0.4rem 0.75rem;"><span style="display:inline-block;padding:0.15rem '
            . '0.5rem;border-radius:4px;font-size:0.78rem;font-weight:600;background:#fef2f2;color:#991b1b;" title="'
            . s(get_string('appliedtitle', 'plagiarism_essayguard')) . '">'
            . get_string('statusapplied', 'plagiarism_essayguard') . '</span></td>';
        echo '<td style="padding:0.4rem 0.75rem;font-size:0.82rem;"><div style="color:#6b7280;">'
            . get_string('baselinebonusdesc', 'plagiarism_essayguard')
            . '</div><div style="margin-top:3px;color:#9ca3af;font-size:0.77rem;">'
            . get_string('deviationscore', 'plagiarism_essayguard', number_format($baselinedev, 3))
            . '</div></td>';
        echo '</tr>';
    }

    if ($lingfallbackpts > 0) {
        echo '<tr style="background:#fffbeb;border-top:1px solid #e5e7eb;">';
        echo '<td style="padding:0.4rem 0.75rem;color:#9ca3af;font-size:0.85rem;">—</td>';
        echo '<td style="padding:0.4rem 0.75rem;font-weight:600;"><em>'
            . get_string('lingfallbackname', 'plagiarism_essayguard') . '</em></td>';
        echo '<td style="padding:0.4rem 0.75rem;color:#9ca3af;">35</td>';
        echo '<td style="padding:0.4rem 0.75rem;"><span style="font-weight:700;color:#991b1b;">+'
            . $lingfallbackpts . '</span></td>';
        echo '<td style="padding:0.4rem 0.75rem;"><span style="display:inline-block;padding:0.15rem '
            . '0.5rem;border-radius:4px;font-size:0.78rem;font-weight:600;background:#fef2f2;color:#991b1b;" title="'
            . s(get_string('appliedtitle', 'plagiarism_essayguard')) . '">'
            . get_string('statusapplied', 'plagiarism_essayguard') . '</span></td>';
        echo '<td style="padding:0.4rem 0.75rem;font-size:0.82rem;color:#6b7280;">'
            . get_string('lingfallbackdesc', 'plagiarism_essayguard') . '</td>';
        echo '</tr>';
    }

    // Totals row.
    $level    = \plagiarism_essayguard\local\service\analyser::risk_level($finalscore);
    $lctext  = ($riskc[$level] ?? $riskc['low'])['text'];
    echo '<tr style="background:#f9fafb;border-top:2px solid #e5e7eb;font-weight:700;">';
    echo '<td style="padding:0.4rem 0.75rem;"></td>';
    echo '<td style="padding:0.4rem 0.75rem;">'
        . get_string('finalscore', 'plagiarism_essayguard') . '</td>';
    echo '<td style="padding:0.4rem 0.75rem;color:#9ca3af;font-weight:400;">100</td>';
    echo '<td style="padding:0.4rem 0.75rem;"><span style="font-weight:700;color:' . $lctext . ';">'
        . $finalscore . '/100</span></td>';
    $capnote = '';
    if ($totalprecap > $finalscore) {
        $capnote = '<span style="font-size:0.8rem;color:#6b7280;font-weight:400;">'
            . get_string('precaptotal', 'plagiarism_essayguard', $totalprecap) . '</span>';
    }
    echo '<td style="padding:0.4rem 0.75rem;">' . $capnote . '</td>';
    echo '<td style="padding:0.4rem 0.75rem;"></td>';
    echo '</tr>';

    echo '</tbody></table>';
}

echo html_writer::tag(
    'h3',
    get_string('signalbreakdownheading', 'plagiarism_essayguard'),
    ['style' => 'margin-top:2rem;margin-bottom:0.5rem;']
);

plagiarism_essayguard_render_signal_table($signalbreakdown, $sc, $metrics, $signalinfo, $riskcolours, $score);

/* ── Per-question breakdown (quizzes with multiple essay questions) ───────────── */

$questionrecords = $DB->get_records_sql(
    "SELECT * FROM {plagiarism_essayguard_sc}
      WHERE userid = :userid AND cmid = :cmid AND qslot > 0
   ORDER BY qslot ASC, timemodified DESC",
    ['userid' => $userid, 'cmid' => $cmid]
);

$perquestion = [];
foreach ($questionrecords as $qr) {
    if (!isset($perquestion[(int)$qr->qslot])) {
        $perquestion[(int)$qr->qslot] = $qr;
    }
}

/* ── Load question texts + student answers from quiz DB ───────────────────── */
// Retrieves question text (from question.questiontext) and student answer
// (from question_attempt_step_data) for each slot, keyed by slot number.
// Only runs for quiz attempts (attemptkey format: qa_{id}).
$egquestiontexts  = [];  // Slot => plain-text question.
$eganswertexts    = [];  // Slot => plain-text student answer.

if (!empty($perquestion)) {
    $akrow = $DB->get_record_sql(
        "SELECT attemptkey FROM {plagiarism_essayguard_sc}
          WHERE userid = :userid AND cmid = :cmid AND qslot > 0
       ORDER BY timemodified DESC",
        ['userid' => $userid, 'cmid' => $cmid],
        IGNORE_MISSING
    );
    if ($akrow && preg_match('/^qa_(\d+)$/', (string)$akrow->attemptkey, $akm)) {
        $egqattemptid = (int)$akm[1];
        $egqaattempt = $DB->get_record('quiz_attempts', ['id' => $egqattemptid], 'uniqueid');
        if ($egqaattempt) {
            // Question texts — one row per slot (question_attempts joins question).
            $egqtrows = $DB->get_records_sql(
                "SELECT qa.id, qa.slot, q.questiontext
                   FROM {question_attempts} qa
                   JOIN {question} q ON q.id = qa.questionid
                  WHERE qa.questionusageid = :qubaid
               ORDER BY qa.slot ASC",
                ['qubaid' => $egqaattempt->uniqueid]
            );
            foreach ($egqtrows as $egqtr) {
                $egs = (int)$egqtr->slot;
                if (!isset($egquestiontexts[$egs])) {
                    $egqt = strip_tags(html_entity_decode((string)($egqtr->questiontext ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $egqt = trim(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', $egqt)));
                    if ($egqt !== '') {
                        $egquestiontexts[$egs] = $egqt;
                    }
                }
            }
            // Student answers — most-recent step per slot (ORDER BY qas.id DESC).
            $egansrows = $DB->get_records_sql(
                "SELECT qas.id, qa.slot, qasd.value
                   FROM {question_attempt_steps} qas
                   JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
                   JOIN {question_attempts} qa             ON qa.id = qas.questionattemptid
                  WHERE qa.questionusageid = :qubaid AND qasd.name = 'answer'
               ORDER BY qa.slot ASC, qas.id DESC",
                ['qubaid' => $egqaattempt->uniqueid]
            );
            foreach ($egansrows as $egar) {
                $egs = (int)$egar->slot;
                if (!isset($eganswertexts[$egs])) {
                    $egat = strip_tags(html_entity_decode((string)($egar->value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $egat = trim(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', $egat)));
                    if ($egat !== '') {
                        $eganswertexts[$egs] = $egat;
                    }
                }
            }
        }
    }
}

if (!empty($perquestion)) {
    echo html_writer::tag(
        'h3',
        get_string('perquestionheading', 'plagiarism_essayguard'),
        ['style' => 'margin-top:2.5rem;margin-bottom:0.5rem;']
    );
    echo '<p style="font-size:0.85rem;color:#6b7280;margin-bottom:1.25rem;">Individual scores per quiz essay '
        . 'question. Behavioural metrics reflect events tagged with each question\'s slot number during the live '
        . 'session. Expand each card for the full signal breakdown.</p>';

    foreach ($perquestion as $slot => $qsc) {
        $qmetrics   = json_decode($qsc->metricsjson ?? '{}', true) ?: [];
        $qbreakdown = isset($qmetrics['signal_breakdown']) ? $qmetrics['signal_breakdown'] : null;
        $qscore     = (int)round((float)($qsc->riskscore ?? 0) * 100);
        $qlevel     = \plagiarism_essayguard\local\service\analyser::risk_level($qscore);
        $qc         = $riskcolours[$qlevel] ?? $riskcolours['low'];

        $qbarw     = min(100, $qscore);
        $badgestyle = 'display:inline-flex;align-items:center;gap:5px;padding:0.2rem '
            . '0.65rem;border-radius:4px;font-weight:700;font-size:0.82rem;background:' . $qc['bg']
            . ';color:' . $qc['text'] . ';border:1px solid ' . $qc['text'] . '33;';

        $cardid  = 'eg-q-card-' . $slot;
        $bodyid  = 'eg-q-body-' . $slot;
        $qttext  = $egquestiontexts[$slot] ?? '';
        $anstext = $eganswertexts[$slot] ?? '';

        // Section card header (always visible).
        echo '<div id="' . $cardid . '" style="border:1px solid #e5e7eb;border-radius:8px;margin-bottom:1rem;overflow:hidden;">';

        // Clickable header row.
        echo '<div onclick="(function '
            . '(b){b.style.display=b.style.display===\'none\'?\'block\':\'none\';})'
            . '(document.getElementById(\'' . $bodyid . '\'))" ';
        echo 'style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;padding:0.85rem '
            . '1rem;background:#f9fafb;cursor:pointer;border-bottom:1px solid #e5e7eb;">';

        echo '<span style="' . $badgestyle . '">';
        echo '<span style="width:7px;height:7px;border-radius:50%;background:' . $qc['bar']
            . ';display:inline-block;flex-shrink:0;"></span>';
        echo strtoupper($qc['label']);
        echo '</span>';

        echo '<strong style="font-size:0.95rem;">'
            . get_string('questionlabel', 'plagiarism_essayguard', (int)$slot) . '</strong>';

        echo '<span style="margin-left:auto;display:flex;align-items:center;gap:1.5rem;font-size:0.83rem;color:#6b7280;">';
        echo '<span><strong style="color:' . $qc['text'] . ';">' . $qscore . '</strong>/100</span>';
        echo '<span>' . get_string(
            'keystrokeslabel',
            'plagiarism_essayguard',
            (int)($qsc->total_keystrokes ?? 0)
        ) . '</span>';
        echo '<span>' . get_string(
            'pasteslabel',
            'plagiarism_essayguard',
            (int)($qsc->paste_events ?? 0)
        ) . '</span>';
        echo '<span>' . get_string(
            'avgwpmlabel',
            'plagiarism_essayguard',
            number_format((float)($qsc->average_wpm ?? 0), 1)
        ) . '</span>';
        echo '<span>' . get_string(
            'typinglabel',
            'plagiarism_essayguard',
            round((int)($qsc->typing_time ?? 0) / 1000, 1)
        ) . '</span>';
        echo '<span style="color:#9ca3af;">&#9660; '
            . get_string('signalsword', 'plagiarism_essayguard') . '</span>';
        echo '</span>';

        echo '</div>'; // End clickable header.

        /* ── Question text + Student answer (always visible) ───────────────── */
        if ($qttext || $anstext) {
            echo '<div style="padding:0.85rem 1rem;border-bottom:1px solid '
                . '#f3f4f6;display:flex;flex-direction:column;gap:0.65rem;">';

            if ($qttext) {
                $qtlong  = mb_strlen($qttext) > 400;
                $qtshort = $qtlong ? mb_substr($qttext, 0, 400) : $qttext;
                $qtsid    = 'eg-qt-' . $slot;
                echo '<div>';
                echo '<div '
                    . 'style="font-size:0.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;'
                    . 'letter-spacing:0.06em;margin-bottom:0.3rem;">'
                    . get_string('questionheading', 'plagiarism_essayguard') . '</div>';
                echo '<div style="background:#f0f4ff;border:1px solid #c7d2fe;border-radius:6px;padding:0.65rem '
                    . '0.9rem;font-size:0.875rem;color:#1e293b;line-height:1.65;">';
                echo '<span id="' . $qtsid . '-short">' . s($qtshort);
                if ($qtlong) {
                    echo '&hellip; <a href="#" onclick="document.getElementById(\'' . $qtsid
                        . '-short\').style.display=\'none\';document.getElementById(\'' . $qtsid
                        . '-full\').style.display=\'inline\';return false;" '
                        . 'style="font-size:0.8rem;color:#6366f1;white-space:nowrap;">'
                        . get_string('moreword', 'plagiarism_essayguard') . '</a>';
                }
                echo '</span>';
                if ($qtlong) {
                    echo '<span id="' . $qtsid . '-full" style="display:none;">' . s($qttext)
                        . ' <a href="#" onclick="document.getElementById(\'' . $qtsid
                        . '-full\').style.display=\'none\';document.getElementById(\'' . $qtsid
                        . '-short\').style.display=\'inline\';return false;" '
                        . 'style="font-size:0.8rem;color:#6366f1;white-space:nowrap;">'
                        . get_string('lessword', 'plagiarism_essayguard') . '</a></span>';
                }
                echo '</div>';
                echo '</div>';
            }

            if ($anstext) {
                $anslong  = mb_strlen($anstext) > 600;
                $ansshort = $anslong ? mb_substr($anstext, 0, 600) : $anstext;
                $anssid    = 'eg-ans-' . $slot;
                echo '<div>';
                echo '<div '
                    . 'style="font-size:0.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;'
                    . 'letter-spacing:0.06em;margin-bottom:0.3rem;">'
                    . get_string('studentanswer', 'plagiarism_essayguard') . '</div>';
                echo '<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:0.65rem '
                    . '0.9rem;font-size:0.875rem;color:#374151;line-height:1.65;">';
                echo '<span id="' . $anssid . '-short">' . s($ansshort);
                if ($anslong) {
                    echo '&hellip; <a href="#" onclick="document.getElementById(\'' . $anssid
                        . '-short\').style.display=\'none\';document.getElementById(\'' . $anssid
                        . '-full\').style.display=\'inline\';return false;" '
                        . 'style="font-size:0.8rem;color:#6366f1;white-space:nowrap;">'
                        . get_string('showmore', 'plagiarism_essayguard') . '</a>';
                }
                echo '</span>';
                if ($anslong) {
                    echo '<span id="' . $anssid . '-full" style="display:none;">' . s($anstext)
                        . ' <a href="#" onclick="document.getElementById(\'' . $anssid
                        . '-full\').style.display=\'none\';document.getElementById(\'' . $anssid
                        . '-short\').style.display=\'inline\';return false;" '
                        . 'style="font-size:0.8rem;color:#6366f1;white-space:nowrap;">'
                        . get_string('showless', 'plagiarism_essayguard') . '</a></span>';
                }
                echo '</div>';
                echo '</div>';
            }

            echo '</div>'; // End question/answer panel.
        }

        // Expandable signal breakdown body (hidden by default).
        echo '<div id="' . $bodyid . '" style="display:none;padding:1rem;">';

        // Visual bar for question score.
        echo '<div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem;">';
        echo '<div style="flex:1;max-width:300px;height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;">';
        echo '<div style="width:' . $qbarw . '%;height:100%;background:' . $qc['bar'] . ';border-radius:4px;"></div>';
        echo '</div>';
        echo '<span style="font-size:0.82rem;color:#6b7280;">' . $qscore . '/100 &mdash; '
            . get_string('riskscorelabel', 'plagiarism_essayguard', $qc['label']) . '</span>';
        echo '</div>';

        plagiarism_essayguard_render_signal_table($qbreakdown, $qsc, $qmetrics, $signalinfo, $riskcolours, $qscore);

        echo '</div>'; // End expandable body.
        echo '</div>'; // End card.
    }
}

/* ── Interpretation guide ────────────────────────────────────────────────────── */

echo '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:1rem '
    . '1.25rem;margin-top:2.5rem;font-size:0.83rem;">';
echo '<strong>' . get_string('interpretationguide', 'plagiarism_essayguard') . '</strong><br>';
echo '<ul style="margin:0.5rem 0 0;padding-left:1.25rem;color:#555;">';
echo '<li>' . get_string('interpretlow', 'plagiarism_essayguard') . '</li>';
echo '<li>' . get_string('interpretmedium', 'plagiarism_essayguard') . '</li>';
echo '<li>' . get_string('interprethigh', 'plagiarism_essayguard') . '</li>';
echo '<li>' . get_string('interpretnote', 'plagiarism_essayguard') . '</li>';
echo '</ul>';
echo '</div>';

echo $OUTPUT->footer();
