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
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

global $DB, $CFG, $OUTPUT, $PAGE, $USER;

$cmid   = required_param('cmid',   PARAM_INT);
$userid = required_param('userid', PARAM_INT);

$cm      = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cmid);
$student = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

require_login($course, false, $cm);
require_capability('plagiarism/essayguard:viewreport', $context);

$PAGE->set_url(new moodle_url('/plagiarism/essayguard/student.php', ['cmid' => $cmid, 'userid' => $userid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title(get_string('pluginname', 'plagiarism_essayguard') . ' — ' . fullname($student));
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/plagiarism/essayguard/styles.css');

// ── Load score record ─────────────────────────────────────────────────────────

$sc = $DB->get_record_sql(
    "SELECT * FROM {plagiarism_essayguard_sc}
      WHERE userid = :userid AND cmid = :cmid
   ORDER BY qslot ASC, timemodified DESC",
    ['userid' => $userid, 'cmid' => $cmid],
    IGNORE_MULTIPLE
);

// ── Colour map ────────────────────────────────────────────────────────────────

$risk_colours = [
    'low'     => ['bg' => '#f0fdf4', 'text' => '#166534', 'bar' => '#22c55e',  'label' => 'Low'],
    'medium'  => ['bg' => '#fff7ed', 'text' => '#7c2d12', 'bar' => '#f97316',  'label' => 'Medium'],
    'high'    => ['bg' => '#fef2f2', 'text' => '#991b1b', 'bar' => '#ef4444',  'label' => 'High'],
    'partial' => ['bg' => '#fff7ed', 'text' => '#7c2d12', 'bar' => '#f97316',  'label' => 'Medium'],
    'mild'    => ['bg' => '#fff7ed', 'text' => '#7c2d12', 'bar' => '#f97316',  'label' => 'Medium'],
];

echo $OUTPUT->header();

$backurl = new moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]);
echo html_writer::tag('p',
    html_writer::link($backurl, '← Back to class report', ['class' => 'btn btn-sm btn-secondary']),
    ['style' => 'margin-bottom:1.5rem;']
);

// ── Page header ───────────────────────────────────────────────────────────────

$modinfo = get_fast_modinfo($cm->course);
$actname = $modinfo->get_cm($cmid)->name;

echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem;">';
echo '<div>';
echo html_writer::tag('h2',
    get_string('pluginname', 'plagiarism_essayguard') . ' — ' . fullname($student),
    ['style' => 'margin:0 0 0.25rem;']
);
echo '<p style="margin:0;opacity:0.75;font-size:0.9rem;">' . s($actname) . ' &nbsp;|&nbsp; ' . s($course->fullname) . '</p>';
echo '</div>';
echo '</div>';

if (!$sc) {
    echo $OUTPUT->notification('No Essay Guard data found for this student in this activity.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// FIX-EG-STUDENT-LEVEL (v1.2.73): derive level from score, not DB column.
$score  = isset($sc->riskscore) ? (int)round((float)($sc->riskscore ?? 0) * 100) : 0;
$level  = \plagiarism_essayguard\local\service\analyser::risk_level($score);
$c      = $risk_colours[$level] ?? $risk_colours['low'];

$explanations = json_decode($sc->explanationsjson ?? '[]', true) ?: [];
$metrics      = json_decode($sc->metricsjson      ?? '{}', true) ?: [];

// ── Overall score card + visual bar ──────────────────────────────────────────

$bar_w      = min(100, $score);
$bar_colour = $c['bar'];

echo '<div style="background:' . $c['bg'] . ';border:1px solid ' . $c['text'] . '33;border-radius:8px;padding:1.25rem 1.5rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:2rem;flex-wrap:wrap;">';

echo '<div style="text-align:center;">';
echo '<div style="font-size:2.8rem;font-weight:800;color:' . $c['text'] . ';">' . $score . '</div>';
echo '<div style="font-size:0.8rem;color:' . $c['text'] . ';font-weight:600;">/ 100</div>';
echo '</div>';

echo '<div style="flex:1;min-width:200px;">';
echo '<div style="font-size:1.2rem;font-weight:700;color:' . $c['text'] . ';margin-bottom:0.25rem;">' . strtoupper($c['label']) . ' RISK</div>';
echo '<div style="width:100%;max-width:300px;height:10px;background:#e5e7eb;border-radius:5px;overflow:hidden;margin-bottom:0.35rem;">';
echo '<div style="width:' . $bar_w . '%;height:100%;background:' . $bar_colour . ';border-radius:5px;"></div>';
echo '</div>';
echo '<div style="font-size:0.82rem;color:#6b7280;">';
echo 'Last scored: ' . userdate((int)($sc->timemodified ?? 0), get_string('strftimedatetimeshort', 'langconfig'));
echo '</div>';
echo '</div>';

echo '<div style="font-size:0.8rem;color:#6b7280;line-height:1.9;">';
echo '<span style="color:#166534;font-weight:600;">&#9679; LOW</span>: 0–29 &nbsp; ';
echo '<span style="color:#c2410c;font-weight:600;">&#9679; MEDIUM</span>: 30–64 &nbsp; ';
echo '<span style="color:#991b1b;font-weight:600;">&#9679; HIGH</span>: 65–100';
echo '</div>';

echo '</div>';

// ── Baseline confidence ───────────────────────────────────────────────────────

$bs      = $sc->baseline_status ?? 'none';
$bs_fp   = $DB->get_record('plagiarism_essayguard_fp', ['userid' => $userid]);
$bs_samples = $bs_fp ? (int)$bs_fp->samplecount : 0;
switch ($bs) {
    case 'stable':
        $bslabel = 'Stable (' . $bs_samples . ' submissions)';
        $bsbg    = '#e8f5e9';
        $bstc = '#166534';
        break;
    case 'preliminary':
        $bslabel = 'Building (' . $bs_samples . '/5 submissions)';
        $bsbg    = '#fffde7';
        $bstc = '#92400e';
        break;
    default:
        $bslabel = 'No baseline yet';
        $bsbg    = '#f5f5f5';
        $bstc = '#6b7280';
        break;
}
echo '<div style="display:inline-block;padding:0.5rem 1rem;border-radius:6px;background:' . $bsbg . ';margin-bottom:1.5rem;font-size:0.85rem;">';
echo '<span style="color:' . $bstc . ';font-weight:600;">Baseline Confidence:</span> <span style="color:#374151;">' . s($bslabel) . '</span>';
echo '</div>';

// ── Indicators ────────────────────────────────────────────────────────────────

if (!empty($explanations)) {
    echo html_writer::tag('h3', 'Indicators', ['style' => 'margin-bottom:0.75rem;']);
    echo html_writer::start_tag('ul', ['style' => 'padding-left:1.25rem;margin-bottom:2rem;']);
    foreach ($explanations as $exp) {
        echo html_writer::tag('li', s($exp), ['style' => 'margin-bottom:0.35rem;']);
    }
    echo html_writer::end_tag('ul');
}

// ── Key metrics table ─────────────────────────────────────────────────────────

$metric_rows = [
    ['Total keystrokes',      (int)($sc->total_keystrokes  ?? 0)],
    ['Paste events',          (int)($sc->paste_events      ?? 0)],
    ['Backspace count',       (int)($sc->backspace_count   ?? 0)],
    ['Delete count',          (int)($sc->delete_count      ?? 0)],
    ['Avg WPM',               number_format((float)($sc->average_wpm    ?? 0), 1)],
    ['WPM std dev',           number_format((float)($sc->wpm_std_dev    ?? 0), 1)],
    ['Pause count',           (int)($sc->pause_count       ?? 0)],
    ['Typing time (s)',       round((int)($sc->typing_time ?? 0) / 1000, 1)],
    ['Idle time (s)',         round((int)($sc->idle_time   ?? 0) / 1000, 1)],
    ['Entropy score',         number_format((float)($sc->entropy_score  ?? 0), 3)],
    ['Interkey mean (ms)',    number_format((float)($sc->interkey_mean  ?? 0), 1)],
    ['Sentence variance',     number_format((float)($sc->sentence_variance ?? 0), 3)],
    ['Vocab diversity',       number_format((float)($sc->vocab_diversity   ?? 0), 3)],
    ['Baseline deviation',    number_format((float)($sc->baseline_deviation ?? 0), 3)],
];

echo html_writer::tag('h3', 'Behavioural Metrics', ['style' => 'margin-bottom:0.75rem;']);
echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'max-width:520px;']);
echo html_writer::start_tag('tbody');
foreach ($metric_rows as [$label, $value]) {
    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', $label, ['style' => 'padding:0.4rem 0.75rem;font-weight:500;']);
    echo html_writer::tag('td', $value, ['style' => 'padding:0.4rem 0.75rem;']);
    echo html_writer::end_tag('tr');
}
echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

// ── Signal Breakdown (v1.2.113+) ──────────────────────────────────────────────

$signal_breakdown = isset($metrics['signal_breakdown']) ? $metrics['signal_breakdown'] : null;

// Signal definitions: number → [name, max_pts, description]
$signal_info = [
    1  => ['Paste / clipboard insert',            60, 'Any paste event or large clipboard insertion detected.'],
    2  => ['Large text insertions (>20 chars)',    20, 'Input events with delta >20 characters (TinyMCE clipboard proxy).'],
    3  => ['Typing speed',                         30, 'Characters-per-second across the session (>8 cps = suspicious, >15 = superhuman).'],
    4  => ['Thinking pauses absent',               20, 'Zero major pauses (>2 s) for substantial content — no inter-typing gaps recorded.'],
    5  => ['Correction / backspace rate',          15, 'Backspace ratio <2% of keystrokes, or zero corrections on a paste-only session.'],
    6  => ['Near-zero session time',               25, 'Entire session typing time <10 s with >50 chars — content appeared almost instantly.'],
    7  => ['Typing rhythm entropy',                10, 'Shannon IKI entropy <0.35 (TypeShield threshold) or SD-based entropy <0.3.'],
    8  => ['Sentence length uniformity',           10, 'Sentence length variance <6 (very uniform) or <12 (somewhat uniform).'],
    9  => ['Vocabulary diversity',                  5, 'Type-token ratio <0.30 (very low) or <0.40 (low).'],
    10 => ['IKI autocorrelation [TypeShield]',     10, '|autocorr − 0.1| > 0.5 flags robotic or artificially jittered rhythm.'],
    11 => ['Speed-burst consistency [TypeShield]', 10, 'Speed CV <0.30 across ≥3 WPM windows — implausibly constant typing rate.'],
    12 => ['Keystroke ratio [TypeShield]',         10, 'keystrokes ÷ text_chars <0.5 — most content inserted rather than typed.'],
    13 => ['Server-side CPS [fallback]',           50, 'Server timing: text_chars ÷ attempt duration >10 cps (impossible to type) or >4 cps (very fast).'],
];

/**
 * Build evidence detail strings for a given signal number.
 * Returns an array of human-readable strings showing the actual computed values.
 */
function essayguard_signal_evidence(int $num, object $sc, array $m): array {
    $parts = [];
    switch ($num) {
        case 1:
            $pf = isset($m['paste_frac']) ? round((float)$m['paste_frac'] * 100, 1) : null;
            $pe = (int)($sc->paste_events ?? 0);
            if ($pf !== null) {
                $parts[] = 'Paste fraction: ' . $pf . '% of answer';
            }
            if ($pe > 0) {
                $parts[] = 'Paste events captured: ' . $pe;
            }
            $kr = isset($m['keystroke_ratio']) ? round((float)$m['keystroke_ratio'], 3) : null;
            if ($kr !== null && $kr < 0.25) {
                $parts[] = 'Keystroke ratio: ' . $kr . ' (very low — paste suspected)';
            }
            break;
        case 2:
            $ks = (int)($sc->total_keystrokes ?? 0);
            if ($ks > 0) {
                $parts[] = 'Keystrokes captured: ' . $ks;
            }
            break;
        case 3:
            $cps = isset($m['chars_per_sec']) ? round((float)$m['chars_per_sec'], 2) : null;
            if ($cps !== null) {
                $threshold = $cps > 15 ? ' > 15 — superhuman' : ($cps > 8 ? ' > 8 — very fast' : '');
                $parts[] = 'Speed: ' . $cps . ' chars/sec' . $threshold;
            }
            break;
        case 4:
            $pc = (int)($sc->pause_count ?? 0);
            $parts[] = 'Pauses > 2 s recorded: ' . $pc;
            break;
        case 5:
            $ks = (int)($sc->total_keystrokes ?? 0);
            $bs = (int)($sc->backspace_count ?? 0) + (int)($sc->delete_count ?? 0);
            if ($ks > 0) {
                $ratio = round($bs / $ks * 100, 1);
                $parts[] = 'Correction ratio: ' . $ratio . '% of keystrokes (' . $bs . ' backspace/delete)';
            } elseif ($bs === 0) {
                $parts[] = 'Zero corrections recorded';
            }
            break;
        case 6:
            $tt = (int)($sc->typing_time ?? 0);
            $parts[] = 'Typing time: ' . round($tt / 1000, 1) . ' s';
            break;
        case 7:
            $es = (float)($sc->entropy_score ?? 0);
            if ($es > 0) {
                $threshold = $es < 0.3 ? ' < 0.30 — robotic' : ($es < 0.5 ? ' < 0.50 — borderline' : '');
                $parts[] = 'Entropy score: ' . number_format($es, 3) . $threshold;
            }
            if (isset($m['iki_shannon'])) {
                $iki = round((float)$m['iki_shannon'], 3);
                $threshold2 = $iki < 0.35 ? ' < 0.35 — TypeShield fired' : ($iki < 0.55 ? ' < 0.55 — borderline' : '');
                $parts[] = 'Shannon IKI entropy: ' . $iki . $threshold2;
            }
            break;
        case 8:
            $sv = (float)($sc->sentence_variance ?? 0);
            if ($sv > 0 || $sv === 0.0) {
                $threshold = $sv < 6 ? ' < 6 — very uniform' : ($sv < 12 ? ' < 12 — somewhat uniform' : '');
                $parts[] = 'Sentence length variance: ' . number_format($sv, 3) . $threshold;
            }
            $sc_ling = isset($m['sentence_count_ling']) ? (int)$m['sentence_count_ling'] : null;
            if ($sc_ling !== null) {
                $parts[] = 'Sentences analysed: ' . $sc_ling;
            }
            break;
        case 9:
            $vd = (float)($sc->vocab_diversity ?? 0);
            if ($vd > 0) {
                $threshold = $vd < 0.30 ? ' < 0.30 — very low' : ($vd < 0.40 ? ' < 0.40 — low' : '');
                $parts[] = 'Type-token ratio: ' . number_format($vd, 3) . $threshold;
            }
            break;
        case 10:
            if (isset($m['iki_autocorr'])) {
                $ac = round((float)$m['iki_autocorr'], 3);
                $dev = round(abs($ac - 0.1), 3);
                $threshold = $dev > 0.5 ? ' dev > 0.5 — robotic' : ($dev > 0.3 ? ' dev > 0.3 — suspicious' : '');
                $parts[] = 'IKI autocorrelation: ' . $ac . ' (|autocorr − 0.1| = ' . $dev . $threshold . ')';
            }
            if (isset($m['iki_sample_count'])) {
                $parts[] = 'IKI samples: ' . (int)$m['iki_sample_count'];
            }
            break;
        case 11:
            if (isset($m['speed_cv'])) {
                $cv = round((float)$m['speed_cv'], 3);
                $threshold = $cv < 0.30 ? ' < 0.30 — constant rate' : ($cv < 0.50 ? ' < 0.50 — low variation' : '');
                $parts[] = 'Speed coefficient of variation: ' . $cv . $threshold;
            }
            break;
        case 12:
            $kr = isset($m['keystroke_ratio']) ? round((float)$m['keystroke_ratio'], 3) : null;
            if ($kr !== null) {
                $threshold = $kr < 0.5 ? ' < 0.5 — TypeShield fired' : ($kr < 0.8 ? ' < 0.8 — low' : '');
                $parts[] = 'Keystroke ratio: ' . $kr . ' (keystrokes ÷ chars)' . $threshold;
            }
            break;
        case 13:
            $scps = isset($m['server_cps']) ? round((float)$m['server_cps'], 2) : null;
            if ($scps !== null && $scps > 0) {
                $threshold = $scps > 10 ? ' > 10 — impossible to type' : ($scps > 4 ? ' > 4 — very fast' : '');
                $parts[] = 'Server-side speed: ' . $scps . ' chars/sec' . $threshold;
            }
            break;
    }
    return $parts;
}

/**
 * Render a signal breakdown table for one record.
 * @param array|null  $breakdown   signal_breakdown array from metricsjson, or null for pre-v1.2.113.
 * @param object      $sc_rec      The DB score record (for metric columns).
 * @param array       $mets        Decoded metricsjson array.
 * @param array       $sig_info    Signal definitions array.
 * @param array       $riskc       Risk colour map.
 * @param int         $final_score The final capped score (0-100).
 */
function essayguard_render_signal_table(
    ?array $breakdown,
    object $sc_rec,
    array  $mets,
    array  $sig_info,
    array  $riskc,
    int    $final_score
): void {
    if ($breakdown === null) {
        echo '<p style="color:#9ca3af;font-style:italic;font-size:0.85rem;">Signal breakdown available for v1.2.113+ records only.</p>';
        return;
    }

    $baseline_dev     = (float)($sc_rec->baseline_deviation ?? 0);
    $baseline_dev_pts = $baseline_dev > 0.3
        ? (int)round(min(15.0, $baseline_dev * 15)) : 0;
    $ling_fallback_pts = (int)($mets['linguistic_fallback_pts'] ?? 0);
    $signal_sum        = array_sum($breakdown);
    $total_pre_cap     = $signal_sum + $baseline_dev_pts + $ling_fallback_pts;

    echo '<p style="font-size:0.8rem;color:#6b7280;margin-bottom:0.5rem;">Each row shows how many points the signal contributed. The false-positive cap may reduce the final score below the pre-cap total.</p>';

    echo '<details style="font-size:0.82rem;margin-bottom:0.65rem;">';
    echo '<summary style="cursor:pointer;display:inline-flex;align-items:center;gap:0.4rem;padding:0.2rem 0.65rem;border-radius:5px;border:1px solid #e5e7eb;background:#f9fafb;color:#374151;font-weight:600;font-size:0.8rem;list-style:none;-webkit-appearance:none;">&#9432;&nbsp;Signal status key</summary>';
    echo '<div style="margin-top:0.4rem;padding:0.65rem 0.9rem;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;max-width:620px;display:flex;flex-direction:column;gap:0.5rem;">';
    echo '<div style="display:flex;gap:0.65rem;align-items:baseline;">'
        . '<span style="min-width:72px;font-size:0.8rem;font-weight:700;color:#991b1b;white-space:nowrap;flex-shrink:0;">&#9679;&nbsp;Fired</span>'
        . '<span style="font-size:0.82rem;color:#374151;">This signal detected a suspicious behaviour pattern and its points were added to the risk score.</span>'
        . '</div>';
    echo '<div style="display:flex;gap:0.65rem;align-items:baseline;">'
        . '<span style="min-width:72px;font-size:0.8rem;font-weight:600;color:#9ca3af;white-space:nowrap;flex-shrink:0;">&#9711;&nbsp;Silent</span>'
        . '<span style="font-size:0.82rem;color:#374151;">This signal was evaluated but found nothing suspicious. No points were added.</span>'
        . '</div>';
    echo '<div style="display:flex;gap:0.65rem;align-items:baseline;">'
        . '<span style="min-width:72px;font-size:0.8rem;font-weight:600;color:#b45309;white-space:nowrap;flex-shrink:0;">Applied</span>'
        . '<span style="font-size:0.82rem;color:#374151;">A supplementary factor (baseline deviation or linguistic fallback) was active and contributed additional points to the score.</span>'
        . '</div>';
    echo '</div></details>';

    echo '<table class="generaltable" style="max-width:800px;">';
    echo '<thead><tr>';
    foreach (['#', 'Signal', 'Max', 'Pts', 'Status', 'Evidence'] as $h) {
        echo '<th style="padding:0.4rem 0.75rem;white-space:nowrap;">' . $h . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($sig_info as $num => [$sname, $smax, $sdesc]) {
        $pts   = (int)($breakdown[$num] ?? 0);
        $fired = $pts > 0;

        $pts_cell = $fired
            ? '<span style="font-weight:700;color:#991b1b;">+' . $pts . '</span>'
            : '<span style="color:#9ca3af;">0</span>';

        $status_cell = $fired
            ? '<span style="display:inline-block;padding:0.15rem 0.5rem;border-radius:4px;font-size:0.78rem;font-weight:600;background:#fef2f2;color:#991b1b;" title="Fired — This signal detected a suspicious behaviour pattern and its points were added to the risk score.">&#9679; Fired</span>'
            : '<span style="color:#d1d5db;" title="Silent — This signal was evaluated but found nothing suspicious. No points were added.">&#9711; Silent</span>';

        $evidence_parts = essayguard_signal_evidence($num, $sc_rec, $mets);
        $evidence_html  = '';
        if (!empty($evidence_parts)) {
            $evidence_html = implode('<br>', array_map('s', $evidence_parts));
        }
        $evidence_html .= '<div style="margin-top:3px;color:#9ca3af;font-size:0.77rem;">' . s($sdesc) . '</div>';

        $row_style = $fired ? 'background:#fffbeb;' : '';
        echo '<tr style="' . $row_style . '">';
        echo '<td style="padding:0.4rem 0.75rem;color:#9ca3af;font-size:0.85rem;">' . $num . '</td>';
        echo '<td style="padding:0.4rem 0.75rem;font-weight:' . ($fired ? '600' : '400') . ';">' . s($sname) . '</td>';
        echo '<td style="padding:0.4rem 0.75rem;color:#9ca3af;">' . $smax . '</td>';
        echo '<td style="padding:0.4rem 0.75rem;">' . $pts_cell . '</td>';
        echo '<td style="padding:0.4rem 0.75rem;">' . $status_cell . '</td>';
        echo '<td style="padding:0.4rem 0.75rem;font-size:0.82rem;">' . $evidence_html . '</td>';
        echo '</tr>';
    }

    if ($baseline_dev_pts > 0) {
        echo '<tr style="background:#fffbeb;border-top:1px solid #e5e7eb;">';
        echo '<td style="padding:0.4rem 0.75rem;color:#9ca3af;font-size:0.85rem;">—</td>';
        echo '<td style="padding:0.4rem 0.75rem;font-weight:600;"><em>Baseline deviation bonus</em></td>';
        echo '<td style="padding:0.4rem 0.75rem;color:#9ca3af;">15</td>';
        echo '<td style="padding:0.4rem 0.75rem;"><span style="font-weight:700;color:#991b1b;">+' . $baseline_dev_pts . '</span></td>';
        echo '<td style="padding:0.4rem 0.75rem;"><span style="display:inline-block;padding:0.15rem 0.5rem;border-radius:4px;font-size:0.78rem;font-weight:600;background:#fef2f2;color:#991b1b;" title="Applied — This supplementary factor was active and its points were added to the risk score.">Applied</span></td>';
        echo '<td style="padding:0.4rem 0.75rem;font-size:0.82rem;"><div style="color:#6b7280;">Writing speed / pattern deviates significantly from this student\'s baseline.</div><div style="margin-top:3px;color:#9ca3af;font-size:0.77rem;">Deviation score: ' . number_format($baseline_dev, 3) . '</div></td>';
        echo '</tr>';
    }

    if ($ling_fallback_pts > 0) {
        echo '<tr style="background:#fffbeb;border-top:1px solid #e5e7eb;">';
        echo '<td style="padding:0.4rem 0.75rem;color:#9ca3af;font-size:0.85rem;">—</td>';
        echo '<td style="padding:0.4rem 0.75rem;font-weight:600;"><em>Linguistic pattern fallback</em></td>';
        echo '<td style="padding:0.4rem 0.75rem;color:#9ca3af;">35</td>';
        echo '<td style="padding:0.4rem 0.75rem;"><span style="font-weight:700;color:#991b1b;">+' . $ling_fallback_pts . '</span></td>';
        echo '<td style="padding:0.4rem 0.75rem;"><span style="display:inline-block;padding:0.15rem 0.5rem;border-radius:4px;font-size:0.78rem;font-weight:600;background:#fef2f2;color:#991b1b;" title="Applied — This supplementary factor was active and its points were added to the risk score.">Applied</span></td>';
        echo '<td style="padding:0.4rem 0.75rem;font-size:0.82rem;color:#6b7280;">No keystroke events captured — sentence uniformity and vocabulary diversity used at elevated weights as the only available evidence.</td>';
        echo '</tr>';
    }

    // Totals row.
    $level    = \plagiarism_essayguard\local\service\analyser::risk_level($final_score);
    $lc_text  = ($riskc[$level] ?? $riskc['low'])['text'];
    echo '<tr style="background:#f9fafb;border-top:2px solid #e5e7eb;font-weight:700;">';
    echo '<td style="padding:0.4rem 0.75rem;"></td>';
    echo '<td style="padding:0.4rem 0.75rem;">Final Score (after cap)</td>';
    echo '<td style="padding:0.4rem 0.75rem;color:#9ca3af;font-weight:400;">100</td>';
    echo '<td style="padding:0.4rem 0.75rem;"><span style="font-weight:700;color:' . $lc_text . ';">' . $final_score . '/100</span></td>';
    $cap_note = '';
    if ($total_pre_cap > $final_score) {
        $cap_note = '<span style="font-size:0.8rem;color:#6b7280;font-weight:400;">Pre-cap total: ' . $total_pre_cap . '</span>';
    }
    echo '<td style="padding:0.4rem 0.75rem;">' . $cap_note . '</td>';
    echo '<td style="padding:0.4rem 0.75rem;"></td>';
    echo '</tr>';

    echo '</tbody></table>';
}

echo html_writer::tag('h3', 'Signal Breakdown',
    ['style' => 'margin-top:2rem;margin-bottom:0.5rem;']);

essayguard_render_signal_table($signal_breakdown, $sc, $metrics, $signal_info, $risk_colours, $score);

// ── Per-question breakdown (quizzes with multiple essay questions) ─────────────

$question_records = $DB->get_records_sql(
    "SELECT * FROM {plagiarism_essayguard_sc}
      WHERE userid = :userid AND cmid = :cmid AND qslot > 0
   ORDER BY qslot ASC, timemodified DESC",
    ['userid' => $userid, 'cmid' => $cmid]
);

$per_question = [];
foreach ($question_records as $qr) {
    if (!isset($per_question[(int)$qr->qslot])) {
        $per_question[(int)$qr->qslot] = $qr;
    }
}

// ── Load question texts + student answers from quiz DB ─────────────────────
// Retrieves question text (from question.questiontext) and student answer
// (from question_attempt_step_data) for each slot, keyed by slot number.
// Only runs for quiz attempts (attemptkey format: qa_{id}).
$eg_question_texts  = [];  // slot => plain-text question
$eg_answer_texts    = [];  // slot => plain-text student answer

if (!empty($per_question)) {
    $ak_row = $DB->get_record_sql(
        "SELECT attemptkey FROM {plagiarism_essayguard_sc}
          WHERE userid = :userid AND cmid = :cmid AND qslot > 0
       ORDER BY timemodified DESC",
        ['userid' => $userid, 'cmid' => $cmid],
        IGNORE_MISSING
    );
    if ($ak_row && preg_match('/^qa_(\d+)$/', (string)$ak_row->attemptkey, $ak_m)) {
        $eg_qattemptid = (int)$ak_m[1];
        $eg_qa_attempt = $DB->get_record('quiz_attempts', ['id' => $eg_qattemptid], 'uniqueid');
        if ($eg_qa_attempt) {
            // Question texts — one row per slot (question_attempts joins question).
            $eg_qt_rows = $DB->get_records_sql(
                "SELECT qa.id, qa.slot, q.questiontext
                   FROM {question_attempts} qa
                   JOIN {question} q ON q.id = qa.questionid
                  WHERE qa.questionusageid = :qubaid
               ORDER BY qa.slot ASC",
                ['qubaid' => $eg_qa_attempt->uniqueid]
            );
            foreach ($eg_qt_rows as $eg_qtr) {
                $eg_s = (int)$eg_qtr->slot;
                if (!isset($eg_question_texts[$eg_s])) {
                    $eg_qt = strip_tags(html_entity_decode((string)($eg_qtr->questiontext ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $eg_qt = trim(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', $eg_qt)));
                    if ($eg_qt !== '') {
                        $eg_question_texts[$eg_s] = $eg_qt;
                    }
                }
            }
            // Student answers — most-recent step per slot (ORDER BY qas.id DESC).
            $eg_ans_rows = $DB->get_records_sql(
                "SELECT qas.id, qa.slot, qasd.value
                   FROM {question_attempt_steps} qas
                   JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
                   JOIN {question_attempts} qa             ON qa.id = qas.questionattemptid
                  WHERE qa.questionusageid = :qubaid AND qasd.name = 'answer'
               ORDER BY qa.slot ASC, qas.id DESC",
                ['qubaid' => $eg_qa_attempt->uniqueid]
            );
            foreach ($eg_ans_rows as $eg_ar) {
                $eg_s = (int)$eg_ar->slot;
                if (!isset($eg_answer_texts[$eg_s])) {
                    $eg_at = strip_tags(html_entity_decode((string)($eg_ar->value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $eg_at = trim(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', $eg_at)));
                    if ($eg_at !== '') {
                        $eg_answer_texts[$eg_s] = $eg_at;
                    }
                }
            }
        }
    }
}

if (!empty($per_question)) {
    echo html_writer::tag('h3', 'Per-Question Analysis',
        ['style' => 'margin-top:2.5rem;margin-bottom:0.5rem;']);
    echo '<p style="font-size:0.85rem;color:#6b7280;margin-bottom:1.25rem;">Individual scores per quiz essay question. Behavioural metrics reflect events tagged with each question\'s slot number during the live session. Expand each card for the full signal breakdown.</p>';

    foreach ($per_question as $slot => $qsc) {
        $qmetrics   = json_decode($qsc->metricsjson ?? '{}', true) ?: [];
        $qbreakdown = isset($qmetrics['signal_breakdown']) ? $qmetrics['signal_breakdown'] : null;
        $qscore     = (int)round((float)($qsc->riskscore ?? 0) * 100);
        $qlevel     = \plagiarism_essayguard\local\service\analyser::risk_level($qscore);
        $qc         = $risk_colours[$qlevel] ?? $risk_colours['low'];

        $qbar_w     = min(100, $qscore);
        $badge_style = 'display:inline-flex;align-items:center;gap:5px;padding:0.2rem 0.65rem;border-radius:4px;font-weight:700;font-size:0.82rem;background:' . $qc['bg'] . ';color:' . $qc['text'] . ';border:1px solid ' . $qc['text'] . '33;';

        $card_id  = 'eg-q-card-' . $slot;
        $body_id  = 'eg-q-body-' . $slot;
        $qt_text  = $eg_question_texts[$slot] ?? '';
        $ans_text = $eg_answer_texts[$slot] ?? '';

        // Section card header (always visible)
        echo '<div id="' . $card_id . '" style="border:1px solid #e5e7eb;border-radius:8px;margin-bottom:1rem;overflow:hidden;">';

        // Clickable header row
        echo '<div onclick="(function (b){b.style.display=b.style.display===\'none\'?\'block\':\'none\';})(document.getElementById(\'' . $body_id . '\'))" ';
        echo 'style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;padding:0.85rem 1rem;background:#f9fafb;cursor:pointer;border-bottom:1px solid #e5e7eb;">';

        echo '<span style="' . $badge_style . '">';
        echo '<span style="width:7px;height:7px;border-radius:50%;background:' . $qc['bar'] . ';display:inline-block;flex-shrink:0;"></span>';
        echo strtoupper($qc['label']);
        echo '</span>';

        echo '<strong style="font-size:0.95rem;">Question ' . (int)$slot . '</strong>';

        echo '<span style="margin-left:auto;display:flex;align-items:center;gap:1.5rem;font-size:0.83rem;color:#6b7280;">';
        echo '<span><strong style="color:' . $qc['text'] . ';">' . $qscore . '</strong>/100</span>';
        echo '<span>Keystrokes: ' . (int)($qsc->total_keystrokes ?? 0) . '</span>';
        echo '<span>Pastes: ' . (int)($qsc->paste_events ?? 0) . '</span>';
        echo '<span>Avg WPM: ' . number_format((float)($qsc->average_wpm ?? 0), 1) . '</span>';
        echo '<span>Typing: ' . round((int)($qsc->typing_time ?? 0) / 1000, 1) . ' s</span>';
        echo '<span style="color:#9ca3af;">&#9660; signals</span>';
        echo '</span>';

        echo '</div>'; // end clickable header

        // ── Question text + Student answer (always visible) ─────────────────
        if ($qt_text || $ans_text) {
            echo '<div style="padding:0.85rem 1rem;border-bottom:1px solid #f3f4f6;display:flex;flex-direction:column;gap:0.65rem;">';

            if ($qt_text) {
                $qt_long  = mb_strlen($qt_text) > 400;
                $qt_short = $qt_long ? mb_substr($qt_text, 0, 400) : $qt_text;
                $qtsid    = 'eg-qt-' . $slot;
                echo '<div>';
                echo '<div style="font-size:0.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Question</div>';
                echo '<div style="background:#f0f4ff;border:1px solid #c7d2fe;border-radius:6px;padding:0.65rem 0.9rem;font-size:0.875rem;color:#1e293b;line-height:1.65;">';
                echo '<span id="' . $qtsid . '-short">' . s($qt_short);
                if ($qt_long) {
                    echo '&hellip; <a href="#" onclick="document.getElementById(\'' . $qtsid . '-short\').style.display=\'none\';document.getElementById(\'' . $qtsid . '-full\').style.display=\'inline\';return false;" style="font-size:0.8rem;color:#6366f1;white-space:nowrap;">more</a>';
                }
                echo '</span>';
                if ($qt_long) {
                    echo '<span id="' . $qtsid . '-full" style="display:none;">' . s($qt_text) . ' <a href="#" onclick="document.getElementById(\'' . $qtsid . '-full\').style.display=\'none\';document.getElementById(\'' . $qtsid . '-short\').style.display=\'inline\';return false;" style="font-size:0.8rem;color:#6366f1;white-space:nowrap;">less</a></span>';
                }
                echo '</div>';
                echo '</div>';
            }

            if ($ans_text) {
                $ans_long  = mb_strlen($ans_text) > 600;
                $ans_short = $ans_long ? mb_substr($ans_text, 0, 600) : $ans_text;
                $anssid    = 'eg-ans-' . $slot;
                echo '<div>';
                echo '<div style="font-size:0.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Student\'s Answer</div>';
                echo '<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:0.65rem 0.9rem;font-size:0.875rem;color:#374151;line-height:1.65;">';
                echo '<span id="' . $anssid . '-short">' . s($ans_short);
                if ($ans_long) {
                    echo '&hellip; <a href="#" onclick="document.getElementById(\'' . $anssid . '-short\').style.display=\'none\';document.getElementById(\'' . $anssid . '-full\').style.display=\'inline\';return false;" style="font-size:0.8rem;color:#6366f1;white-space:nowrap;">show more</a>';
                }
                echo '</span>';
                if ($ans_long) {
                    echo '<span id="' . $anssid . '-full" style="display:none;">' . s($ans_text) . ' <a href="#" onclick="document.getElementById(\'' . $anssid . '-full\').style.display=\'none\';document.getElementById(\'' . $anssid . '-short\').style.display=\'inline\';return false;" style="font-size:0.8rem;color:#6366f1;white-space:nowrap;">show less</a></span>';
                }
                echo '</div>';
                echo '</div>';
            }

            echo '</div>'; // end question/answer panel
        }

        // Expandable signal breakdown body (hidden by default)
        echo '<div id="' . $body_id . '" style="display:none;padding:1rem;">';

        // Visual bar for question score
        echo '<div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem;">';
        echo '<div style="flex:1;max-width:300px;height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;">';
        echo '<div style="width:' . $qbar_w . '%;height:100%;background:' . $qc['bar'] . ';border-radius:4px;"></div>';
        echo '</div>';
        echo '<span style="font-size:0.82rem;color:#6b7280;">' . $qscore . '/100 &mdash; ' . $qc['label'] . ' Risk</span>';
        echo '</div>';

        essayguard_render_signal_table($qbreakdown, $qsc, $qmetrics, $signal_info, $risk_colours, $qscore);

        echo '</div>'; // end expandable body
        echo '</div>'; // end card
    }
}

// ── Interpretation guide ──────────────────────────────────────────────────────

echo '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:1rem 1.25rem;margin-top:2.5rem;font-size:0.83rem;">';
echo '<strong>Interpretation Guide</strong><br>';
echo '<ul style="margin:0.5rem 0 0;padding-left:1.25rem;color:#555;">';
echo '<li><strong style="color:#166534;">LOW (0–29):</strong> Writing behaviour appears consistent with authentic student patterns. No significant concern detected.</li>';
echo '<li><strong style="color:#c2410c;">MEDIUM (30–64):</strong> Some signals triggered. Human review is recommended — contextual factors may explain the result.</li>';
echo '<li><strong style="color:#991b1b;">HIGH (65–100):</strong> Multiple strong indicators detected. A detailed review is strongly recommended before drawing conclusions.</li>';
echo '<li>Essay Guard uses behavioural and linguistic heuristics — it does not make definitive academic misconduct determinations. Always apply professional judgement.</li>';
echo '</ul>';
echo '</div>';

echo $OUTPUT->footer();
