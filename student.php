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
 * This page only performs access checks and data retrieval; all markup lives in
 * the plagiarism_essayguard/student* templates and behaviour in the
 * plagiarism_essayguard/student AMD module.
 *
 * URL: /plagiarism/essayguard/student.php?cmid=X&userid=Y
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
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

// The target must be enrolled in this activity's course, and in SEPARATEGROUPS mode
// must share a group with the viewer, otherwise any profile is reachable by URL edit.
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

$egpageheading = get_string(
    'student_pageheading',
    'plagiarism_essayguard',
    (object) [
        'plugin'  => get_string('pluginname', 'plagiarism_essayguard'),
        'student' => fullname($student),
    ]
);

$PAGE->set_url(new moodle_url('/plagiarism/essayguard/student.php', ['cmid' => $cmid, 'userid' => $userid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title($egpageheading);
$PAGE->set_heading($course->fullname);

/**
 * Map an analyser risk level (or 'nodata') to the CSS level modifier used by the templates.
 *
 * 'partial' and 'mild' are legacy DB aliases of 'medium'; anything unknown falls back to 'low'.
 *
 * @param string $level The risk level.
 * @return string One of low, medium, high, nodata.
 */
function plagiarism_essayguard_student_level_class(string $level): string {
    $map = [
        'low'     => 'low',
        'medium'  => 'medium',
        'high'    => 'high',
        'partial' => 'medium',
        'mild'    => 'medium',
        'nodata'  => 'nodata',
    ];
    return $map[$level] ?? 'low';
}

/**
 * Display label for a risk level (as used in the badges and headings).
 *
 * @param string $levelclass A value returned by plagiarism_essayguard_student_level_class().
 * @return string
 */
function plagiarism_essayguard_student_level_label(string $levelclass): string {
    switch ($levelclass) {
        case 'high':
            return get_string('riskhigh', 'plagiarism_essayguard');
        case 'medium':
            return get_string('riskmedium', 'plagiarism_essayguard');
        case 'nodata':
            return get_string('nodatabadge', 'plagiarism_essayguard');
        default:
            return get_string('risklow', 'plagiarism_essayguard');
    }
}

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
                        'value' => $ac,
                        'dev' => $dev,
                        'threshold' => $threshold,
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
 * Build the template context for one signal breakdown table
 * (rendered by plagiarism_essayguard/student_signal_table).
 *
 * @param array|null $breakdown  The signal_breakdown array from metricsjson, or null for
 *                               records written before v1.2.113, which did not store one.
 * @param object     $screc      The DB score record (for metric columns).
 * @param array      $mets       Decoded metricsjson array.
 * @param array      $siginfo    Signal definitions: num => [name, max, description].
 * @param int        $finalscore The final capped score (0-100).
 * @return array
 */
function plagiarism_essayguard_signal_table_context(
    ?array $breakdown,
    object $screc,
    array $mets,
    array $siginfo,
    int $finalscore
): array {
    if ($breakdown === null) {
        return ['hasbreakdown' => false];
    }

    $baselinedev     = (float)($screc->baseline_deviation ?? 0);
    $baselinedevpts  = $baselinedev > 0.3 ? (int)round(min(15.0, $baselinedev * 15)) : 0;
    $lingfallbackpts = (int)($mets['linguistic_fallback_pts'] ?? 0);
    $signalsum       = array_sum($breakdown);
    $totalprecap     = $signalsum + $baselinedevpts + $lingfallbackpts;

    $rows = [];
    foreach ($siginfo as $num => [$sname, $smax, $sdesc]) {
        $pts = (int)($breakdown[$num] ?? 0);
        $rows[] = [
            'num'         => $num,
            'name'        => $sname,
            'max'         => $smax,
            'points'      => $pts,
            'fired'       => $pts > 0,
            'evidence'    => array_values(plagiarism_essayguard_signal_evidence($num, $screc, $mets)),
            'description' => $sdesc,
        ];
    }

    $finallevel = plagiarism_essayguard_student_level_class(
        \plagiarism_essayguard\local\service\analyser::risk_level($finalscore)
    );

    return [
        'hasbreakdown'   => true,
        'rows'           => $rows,
        'baselinebonus'  => $baselinedevpts > 0 ? [
            'points'    => $baselinedevpts,
            'deviation' => get_string('deviationscore', 'plagiarism_essayguard', number_format($baselinedev, 3)),
        ] : false,
        'lingfallback'   => $lingfallbackpts > 0 ? ['points' => $lingfallbackpts] : false,
        'finalscore'     => get_string('student_scoreoutof', 'plagiarism_essayguard', $finalscore),
        'finallevel'     => $finallevel,
        'precapnote'     => $totalprecap > $finalscore
            ? get_string('precaptotal', 'plagiarism_essayguard', $totalprecap) : '',
    ];
}

/**
 * Build the context for a short/full expandable text block
 * (rendered by plagiarism_essayguard/student_truncated_text).
 *
 * @param string $text      The full plain text.
 * @param int    $limit     Number of characters shown before "more".
 * @param string $id        Unique id prefix for the short/full spans.
 * @param string $morelabel Label of the expand link.
 * @param string $lesslabel Label of the collapse link.
 * @return array
 */
function plagiarism_essayguard_truncated_text_context(
    string $text,
    int $limit,
    string $id,
    string $morelabel,
    string $lesslabel
): array {
    $islong = mb_strlen($text) > $limit;
    return [
        'id'        => $id,
        'islong'    => $islong,
        'short'     => $islong ? mb_substr($text, 0, $limit) : $text,
        'full'      => $text,
        'morelabel' => $morelabel,
        'lesslabel' => $lesslabel,
    ];
}

/**
 * Normalise stored question/answer HTML to a single line of plain text.
 *
 * @param string|null $html
 * @return string
 */
function plagiarism_essayguard_student_plaintext(?string $html): string {
    $text = strip_tags(html_entity_decode((string)$html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return trim(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', $text)));
}

/* ── Load score record ───────────────────────────────────────────────────────── */

// Load every record for this student, then scope to a single attempt with the shared
// rule so the header score and per-question rows always come from the same attempt.
$egallrecords = $DB->get_records_sql(
    "SELECT * FROM {plagiarism_essayguard_sc}
      WHERE userid = :userid AND cmid = :cmid
   ORDER BY timemodified DESC, id DESC",
    ['userid' => $userid, 'cmid' => $cmid]
);
$egbyslot = plagiarism_essayguard_scope_to_current_attempt(array_values($egallrecords));
$sc = $egbyslot[0] ?? (reset($egbyslot) ?: null);

$modinfo = get_fast_modinfo($cm->course);
$actname = $modinfo->get_cm($cmid)->name;

$data = [
    'backurl'      => (new moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]))->out(false),
    'heading'      => $egpageheading,
    'activityname' => $actname,
    'coursename'   => $course->fullname,
    'hasdata'      => (bool)$sc,
];

if (!$sc) {
    $data['nodatanotification'] = $OUTPUT->notification(get_string('nodata', 'plagiarism_essayguard'), 'info');
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('plagiarism_essayguard/student', $data);
    echo $OUTPUT->footer();
    exit;
}

$PAGE->requires->js_call_amd('plagiarism_essayguard/student', 'init');

/* ── Overall score ───────────────────────────────────────────────────────────── */

// Level is derived from the score, not the stored DB column.
$score = isset($sc->riskscore) ? (int)round((float)($sc->riskscore ?? 0) * 100) : 0;
$level = plagiarism_essayguard_student_level_class(\plagiarism_essayguard\local\service\analyser::risk_level($score));

// An attempt where the tracker never ran must not read as a confident LOW.
$egheaderunmeasured = plagiarism_essayguard_is_unmeasured($sc);
if ($egheaderunmeasured) {
    $level = 'nodata';
}

$explanations = json_decode($sc->explanationsjson ?? '[]', true) ?: [];
$metrics      = json_decode($sc->metricsjson ?? '{}', true) ?: [];

$egheaderlabel = core_text::strtoupper(plagiarism_essayguard_student_level_label($level));
if (!$egheaderunmeasured) {
    $egheaderlabel .= ' ' . core_text::strtoupper(get_string('riskword', 'plagiarism_essayguard'));
}

$data['overall'] = [
    'level'      => $level,
    'unmeasured' => $egheaderunmeasured,
    'score'      => $score,
    'label'      => $egheaderlabel,
    'barwidth'   => $egheaderunmeasured ? 0 : min(100, $score),
    'lastscored' => get_string(
        'lastscored',
        'plagiarism_essayguard',
        userdate((int)($sc->timemodified ?? 0), get_string('strftimedatetimeshort', 'langconfig'))
    ),
    'legendlow'    => core_text::strtoupper(get_string('risklow', 'plagiarism_essayguard')),
    'legendmedium' => core_text::strtoupper(get_string('riskmedium', 'plagiarism_essayguard')),
    'legendhigh'   => core_text::strtoupper(get_string('riskhigh', 'plagiarism_essayguard')),
];

/* ── Baseline confidence ─────────────────────────────────────────────────────── */

// Report the comparison that actually ran: label and drivers come from the same
// per-metric statistics the scorer used.
$bs        = $sc->baseline_status ?? 'none';
$bssamples = (int)($metrics['baseline_samples'] ?? 0);
$bscontext = (string)($metrics['baseline_contexttype'] ?? 'other');
switch ($bs) {
    case 'stable':
        $bslabel = get_string('baselinestable', 'plagiarism_essayguard', $bssamples);
        $bsstatus = 'stable';
        break;
    case 'preliminary':
        $bslabel = get_string('baselinebuilding', 'plagiarism_essayguard', $bssamples);
        $bsstatus = 'preliminary';
        break;
    default:
        $bslabel = get_string('no_baseline', 'plagiarism_essayguard');
        $bsstatus = 'none';
        break;
}

$bsrows = [];
if ($bs === 'stable') {
    // Show the measurements behind the comparison: a number a teacher can check beats
    // a label they have to trust, and it is what an appeal will ask to see.
    $bsdetail  = (array)($metrics['baseline_detail'] ?? []);
    $bsdrivers = (array)($metrics['baseline_drivers'] ?? []);
    foreach ($bsdrivers as $bsname) {
        if (!isset($bsdetail[$bsname])) {
            continue;
        }
        $bd = $bsdetail[$bsname];
        $bslabelkey = 'blmetric_' . $bsname;
        $bsrows[] = [
            'measurement' => get_string_manager()->string_exists($bslabelkey, 'plagiarism_essayguard')
                ? get_string($bslabelkey, 'plagiarism_essayguard')
                : (string)$bsname,
            'observed'    => (string)($bd['observed'] ?? ''),
            'mean'        => (string)($bd['mean'] ?? ''),
            'sd'          => (string)($bd['sd'] ?? ''),
            'z'           => (string)($bd['z'] ?? ''),
        ];
    }
}

$data['baseline'] = [
    'status'  => $bsstatus,
    'label'   => $bslabel,
    'stable'  => $bs === 'stable',
    'context' => get_string('baselinecontext', 'plagiarism_essayguard', $bscontext),
    'hasrows' => !empty($bsrows),
    'rows'    => $bsrows,
];

/* ── Indicators ──────────────────────────────────────────────────────────────── */

$data['hasexplanations'] = !empty($explanations);
$data['explanations'] = array_values(array_map('strval', $explanations));

/* ── Key metrics table ───────────────────────────────────────────────────────── */

$metricrows = [
    ['metric_totalkeystrokes', (int)($sc->total_keystrokes ?? 0)],
    ['metric_pastes', (int)($sc->paste_events ?? 0)],
    ['metric_backspace', (int)($sc->backspace_count ?? 0)],
    ['metric_delete', (int)($sc->delete_count ?? 0)],
    ['metric_avgwpm', number_format((float)($sc->average_wpm ?? 0), 1)],
    ['metric_wpmstddev', number_format((float)($sc->wpm_std_dev ?? 0), 1)],
    ['metric_pausecount', (int)($sc->pause_count ?? 0)],
    ['metric_typingtime', round((int)($sc->typing_time ?? 0) / 1000, 1)],
    ['metric_idletime', round((int)($sc->idle_time ?? 0) / 1000, 1)],
    ['metric_entropy', number_format((float)($sc->entropy_score ?? 0), 3)],
    ['metric_interkeymean', number_format((float)($sc->interkey_mean ?? 0), 1)],
    ['metric_sentencevariance', number_format((float)($sc->sentence_variance ?? 0), 3)],
    ['metric_vocabdiversity', number_format((float)($sc->vocab_diversity ?? 0), 3)],
    ['metric_baselinedev', number_format((float)($sc->baseline_deviation ?? 0), 3)],
];
$data['metrics'] = [];
foreach ($metricrows as [$egkey, $egvalue]) {
    $data['metrics'][] = [
        'label' => get_string($egkey, 'plagiarism_essayguard'),
        'value' => (string)$egvalue,
    ];
}

/* ── Signal breakdown ────────────────────────────────────────────────────────── */

// Names, maxima and descriptions come from the signal registry (maxima honour the
// site's configured weights).
$signalinfo = [];
foreach (\plagiarism_essayguard\local\signals::definitions() as $egsignum => $egsigdef) {
    $signalinfo[$egsignum] = [
        get_string('sig' . $egsignum . 'name', 'plagiarism_essayguard'),
        \plagiarism_essayguard\local\signals::max_points($egsignum),
        get_string('sig' . $egsignum . 'desc', 'plagiarism_essayguard'),
    ];
}

$signalbreakdown = isset($metrics['signal_breakdown']) ? $metrics['signal_breakdown'] : null;
$data['signaltable'] = plagiarism_essayguard_signal_table_context($signalbreakdown, $sc, $metrics, $signalinfo, $score);

/* ── Per-question breakdown (quizzes with multiple essay questions) ───────────── */

// Per-question rows come from the same scoped attempt as the header score.
$perquestion = [];
foreach ($egbyslot as $egslot => $egrecord) {
    if ((int)$egslot > 0) {
        $perquestion[(int)$egslot] = $egrecord;
    }
}
ksort($perquestion);

// Question text and the student's verbatim answer are core quiz data, so they need a
// core quiz/grade capability, not just this plugin's viewreport.
$egquestiontexts = [];  // Slot => plain-text question.
$eganswertexts   = [];  // Slot => plain-text student answer.
$egcanreadanswers = has_capability('mod/quiz:viewreports', $context)
    || has_capability('mod/quiz:grade', $context)
    || has_capability('moodle/grade:viewall', $context);

if (!empty($perquestion) && $egcanreadanswers) {
    $akrow = reset($perquestion) ?: null;
    if ($akrow && preg_match('/^qa_(\d+)$/', (string)$akrow->attemptkey, $akm)) {
        $egqattemptid = (int)$akm[1];
        $egqaattempt = $DB->get_record('quiz_attempts', ['id' => $egqattemptid], 'uniqueid');
        if ($egqaattempt) {
            // Question texts — one row per slot.
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
                    $egqt = plagiarism_essayguard_student_plaintext($egqtr->questiontext ?? '');
                    if ($egqt !== '') {
                        $egquestiontexts[$egs] = $egqt;
                    }
                }
            }
            // Student answers — most-recent step per slot.
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
                    $egat = plagiarism_essayguard_student_plaintext($egar->value ?? '');
                    if ($egat !== '') {
                        $eganswertexts[$egs] = $egat;
                    }
                }
            }
        }
    }
}

$data['hasquestions'] = !empty($perquestion);
$data['questions'] = [];
foreach ($perquestion as $slot => $qsc) {
    $qmetrics   = json_decode($qsc->metricsjson ?? '{}', true) ?: [];
    $qbreakdown = isset($qmetrics['signal_breakdown']) ? $qmetrics['signal_breakdown'] : null;
    $qscore     = (int)round((float)($qsc->riskscore ?? 0) * 100);
    $qlevel     = plagiarism_essayguard_student_level_class(\plagiarism_essayguard\local\service\analyser::risk_level($qscore));

    // A slot that captured no events was never assessed; it must not show as LOW.
    $egunmeasured = plagiarism_essayguard_is_unmeasured($qsc);
    if ($egunmeasured) {
        $qlevel = 'nodata';
    }
    $qlabel = plagiarism_essayguard_student_level_label($qlevel);

    // Distinguish "no events attributed to this slot" from a genuine 0 s typing time.
    $egnobehaviour = ((int)($qsc->total_keystrokes ?? 0) === 0)
        && ((int)($qsc->paste_events ?? 0) === 0)
        && ((int)($qsc->typing_time ?? 0) === 0);

    $qttext  = $egquestiontexts[$slot] ?? '';
    $anstext = $eganswertexts[$slot] ?? '';

    $data['questions'][] = [
        'slot'         => (int)$slot,
        'cardid'       => 'eg-q-card-' . $slot,
        'bodyid'       => 'eg-q-body-' . $slot,
        'level'        => $qlevel,
        'badgelabel'   => core_text::strtoupper($qlabel),
        'title'        => get_string('questionlabel', 'plagiarism_essayguard', (int)$slot),
        'unmeasured'   => $egunmeasured,
        'score'        => $qscore,
        'keystrokes'   => get_string('keystrokeslabel', 'plagiarism_essayguard', (int)($qsc->total_keystrokes ?? 0)),
        'pastes'       => get_string('pasteslabel', 'plagiarism_essayguard', (int)($qsc->paste_events ?? 0)),
        'avgwpm'       => get_string(
            'avgwpmlabel',
            'plagiarism_essayguard',
            number_format((float)($qsc->average_wpm ?? 0), 1)
        ),
        'typing'       => $egnobehaviour
            ? get_string('notypingcaptured', 'plagiarism_essayguard')
            : get_string('typinglabel', 'plagiarism_essayguard', round((int)($qsc->typing_time ?? 0) / 1000, 1)),
        'hastexts'     => ($qttext !== '' || $anstext !== ''),
        'questiontext' => $qttext !== '' ? plagiarism_essayguard_truncated_text_context(
            $qttext,
            400,
            'eg-qt-' . $slot,
            get_string('moreword', 'plagiarism_essayguard'),
            get_string('lessword', 'plagiarism_essayguard')
        ) : false,
        'answertext'   => $anstext !== '' ? plagiarism_essayguard_truncated_text_context(
            $anstext,
            600,
            'eg-ans-' . $slot,
            get_string('showmore', 'plagiarism_essayguard'),
            get_string('showless', 'plagiarism_essayguard')
        ) : false,
        // A per-question score with no behavioural events is a linguistic guess.
        'shownodatanotice' => ($egunmeasured || $egnobehaviour),
        'barwidth'     => min(100, $qscore),
        'scoreline'    => get_string(
            'student_scorewithrisk',
            'plagiarism_essayguard',
            (object) [
                'score' => $qscore,
                'risk'  => get_string('riskscorelabel', 'plagiarism_essayguard', $qlabel),
            ]
        ),
        'signaltable'  => plagiarism_essayguard_signal_table_context($qbreakdown, $qsc, $qmetrics, $signalinfo, $qscore),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('plagiarism_essayguard/student', $data);
echo $OUTPUT->footer();
