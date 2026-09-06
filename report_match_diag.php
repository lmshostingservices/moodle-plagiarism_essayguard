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
 * EssayGuard — Report-Match Diagnostic v1.2.147
 *
 * Diagnoses the full stack of issues that cause:
 *   (A) Every question in Review Attempt showing the same badge/score.
 *   (B) Review Attempt badge not matching EssayGuard Report badge.
 *   (C) H4 scenario: Q1 typed + Q2 pasted → both show "good results" (LOW).
 *   (D) H3 scenario: Q1 pasted + Q2 pasted → neither caught (LOW).
 *
 * Root causes investigated:
 *   1. Qslot detection failure — find_qslot_by_content() similarity < 60% →
 *      both questions fall back to aggregate (qslot=0) record → identical badges.
 *   2. Aggregate dilution — H4: Q1 typing keystrokes dilute Q2 paste signal in
 *      the aggregate record → aggregate score stays MEDIUM or LOW.
 *   3. Paste not flushed before submit — student pastes and submits in < 5s →
 *      paste_events=0 in DB → per-question score is LOW despite real paste.
 *   4. pq_looks_like_missed_paste conditions not met → fallback to aggregate
 *      never fires even when aggregate detected a paste.
 *   5. agg_has_paste_evidence guard too strict (Signal 1 < 30 pts) → per-question
 *      fallback suppressed even when a genuine paste was detected at aggregate level.
 *
 * Access:
 *   /plagiarism/essayguard/report_match_diag.php?cmid=X
 *   /plagiarism/essayguard/report_match_diag.php?cmid=X&userid=Y
 *
 * Requires: moodle/site:config (site admin).
 * Safe to ship — read-only, no external requests, no data modified.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$cmid   = optional_param('cmid',   0, PARAM_INT) ?: optional_param('id', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

if (!$cmid) {
    throw new \moodle_exception('missingparam', 'error', '', 'cmid or id');
}

$cm     = get_coursemodule_from_id(false, $cmid, 0, false, IGNORE_MISSING);
if (!$cm) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<title>EssayGuard Report-Match Diag</title></head><body>';
    echo '<p style="font-family:sans-serif;color:#c0392b;margin:2rem;">cmid='
       . (int)$cmid . ' is not a valid activity id.</p></body></html>';
    exit;
}
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

// ── CSS ───────────────────────────────────────────────────────────────────────

$css = <<<'CSS'
<style>
*{box-sizing:border-box;}
body{font-family:system-ui,-apple-system,sans-serif;margin:0;padding:1.5rem 2rem;background:#f5f6f8;color:#1f2937;font-size:14px;}
h1{font-size:1.25rem;font-weight:700;margin:0 0 0.25rem;}
h2{font-size:1rem;font-weight:700;margin:0;}
.meta{color:#6b7280;font-size:0.85rem;margin-bottom:1.5rem;}
.meta a{color:#2563eb;text-decoration:none;}
.section{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.25rem 1.5rem;margin-bottom:1.25rem;}
.section-title{font-size:0.95rem;font-weight:700;margin:0 0 1rem;display:flex;align-items:center;gap:0.5rem;}
.num{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#e5e7eb;color:#374151;font-size:0.75rem;font-weight:700;flex-shrink:0;}
table{width:100%;border-collapse:collapse;font-size:0.82rem;}
th{text-align:left;padding:6px 10px;background:#f9fafb;border-bottom:2px solid #e5e7eb;font-weight:600;white-space:nowrap;}
td{padding:5px 10px;border-bottom:1px solid #f3f4f6;vertical-align:top;}
tr:last-child td{border-bottom:none;}
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:0.78rem;font-weight:700;white-space:nowrap;}
.badge-high{background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;}
.badge-medium{background:#fff7ed;color:#7c2d12;border:1px solid #fdba74;}
.badge-low{background:#f0fdf4;color:#166534;border:1px solid #86efac;}
.badge-agg{background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;}
.badge-na{background:#f3f4f6;color:#6b7280;border:1px solid #d1d5db;}
.badge-mismatch{background:#fef9c3;color:#713f12;border:1px solid #fde047;}
.step{padding:0.5rem 0.75rem;border-radius:6px;margin-bottom:0.4rem;font-size:0.82rem;line-height:1.5;}
.step-pass{background:#f0fdf4;border-left:3px solid #22c55e;color:#166534;}
.step-fail{background:#fef2f2;border-left:3px solid #ef4444;color:#991b1b;}
.step-warn{background:#fffbeb;border-left:3px solid #f59e0b;color:#92400e;}
.step-info{background:#eff6ff;border-left:3px solid #3b82f6;color:#1e40af;}
.step-dead{background:#f9fafb;border-left:3px solid #d1d5db;color:#6b7280;}
.slot-header{background:#1e3a5f;color:#fff;font-weight:700;padding:0.5rem 0.75rem;border-radius:6px;margin:0.75rem 0 0.4rem;font-size:0.88rem;}
.scenario-box{border-radius:6px;padding:0.75rem 1rem;margin-bottom:0.75rem;font-size:0.85rem;}
.scenario-caught{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;}
.scenario-missed{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;}
.scenario-partial{background:#fffbeb;border:1px solid #fde68a;color:#92400e;}
.scenario-unknown{background:#f9fafb;border:1px solid #e5e7eb;color:#6b7280;}
.metric-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:0.5rem;margin-bottom:0.75rem;}
.metric-card{background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:0.5rem 0.75rem;}
.metric-label{font-size:0.73rem;color:#6b7280;margin-bottom:2px;}
.metric-val{font-size:0.95rem;font-weight:700;color:#111827;}
.picker{margin-bottom:1rem;display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;}
.picker select{padding:4px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.85rem;}
.picker label{font-size:0.85rem;font-weight:600;color:#374151;}
.back{color:#2563eb;font-size:0.85rem;text-decoration:none;margin-right:1rem;}
.warn-box{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:0.75rem 1rem;font-size:0.85rem;color:#92400e;margin-bottom:0.75rem;}
.ok-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:0.75rem 1rem;font-size:0.85rem;color:#166534;margin-bottom:0.75rem;}
.err-box{background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:0.75rem 1rem;font-size:0.85rem;color:#991b1b;margin-bottom:0.75rem;}
.summary-row-ok td{background:#f0fdf4;}
.summary-row-warn td{background:#fffbeb;}
.summary-row-err td{background:#fef2f2;}
code{background:#f3f4f6;padding:1px 4px;border-radius:3px;font-family:monospace;font-size:0.85em;}
details summary{cursor:pointer;font-weight:600;padding:0.3rem 0;font-size:0.85rem;}
hr.slot-sep{border:none;border-top:2px solid #e5e7eb;margin:1rem 0;}
</style>
CSS;

// ── Helpers ───────────────────────────────────────────────────────────────────

function rmd_badge(float $pct): string {
    $label = $pct >= 66 ? 'HIGH' : ($pct >= 30 ? 'MEDIUM' : 'LOW');
    $cls   = strtolower($label);
    return '<span class="badge badge-' . $cls . '">' . $label . ' ' . round($pct, 1) . '%</span>';
}
function rmd_badge_agg(float $pct): string {
    $label = $pct >= 66 ? 'HIGH' : ($pct >= 30 ? 'MEDIUM' : 'LOW');
    return '<span class="badge badge-agg">' . $label . ' ' . round($pct, 1) . '% (overall)</span>';
}
function rmd_badge_na(): string {
    return '<span class="badge badge-na">No record</span>';
}
function rmd_risk_label(float $pct): string {
    return $pct >= 66 ? 'HIGH' : ($pct >= 30 ? 'MEDIUM' : 'LOW');
}
function rmd_step(string $type, string $label, string $detail = ''): string {
    $cls = match($type) {
        'pass' => 'step-pass',
        'fail' => 'step-fail',
        'warn' => 'step-warn',
        'info' => 'step-info',
        'dead' => 'step-dead',
        default => 'step-info',
    };
    $icon = match($type) {
        'pass' => '&#10003;',
        'fail' => '&#10007;',
        'warn' => '&#9888;',
        'info' => '&#9432;',
        'dead' => '&mdash;',
        default => '&#9432;',
    };
    return '<div class="step ' . $cls . '"><strong>' . $icon . ' ' . htmlspecialchars($label) . '</strong>'
         . ($detail ? ': ' . $detail : '')
         . '</div>';
}

// Replicate find_qslot_by_content() with per-slot similarity scores returned.
function rmd_simulate_qslot_detection(
    array $answer_cache,
    string $content,
    array &$sim_scores_out
): int {
    $norm_content = trim(preg_replace('/\s+/', ' ', strip_tags($content)));
    if ($norm_content === '') {
        $sim_scores_out = [];
        return 0;
    }
    $decoded_content = html_entity_decode($norm_content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $best_slot = 0;
    $best_pct  = 0.0;
    $sim_scores_out = [];
    foreach ($answer_cache as $slot => $slot_text) {
        $decoded_slot = html_entity_decode($slot_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        similar_text($decoded_content, $decoded_slot, $sim_pct);
        $sim_scores_out[$slot] = round((float)$sim_pct, 1);
        if ($sim_pct >= 60.0 && $sim_pct > $best_pct) {
            $best_pct  = $sim_pct;
            $best_slot = $slot;
        }
    }
    return $best_slot;
}

// Replicate the lib.php record-selection logic step by step, returning an
// annotated decision trace array.
function rmd_select_record(
    ?object $pq_record,
    ?object $agg_record,
    int $qslot
): array {
    $trace  = [];
    $record = null;
    $is_agg = false;

    if ($qslot === 0) {
        // No qslot resolved — only aggregate available.
        $record = $agg_record;
        $is_agg = true;
        $trace[] = ['fail', 'Qslot resolved to 0 (aggregate fallback)', 'No per-question record can be fetched. Review Attempt shows the same aggregate badge for every question on this page.'];
        if ($record) {
            $pct = round((float)$record->riskscore * 100, 1);
            $trace[] = ['warn', 'Using aggregate record', 'riskscore=' . $record->riskscore . ' (' . $pct . '%) — this is the SAME score shown on all questions'];
        } else {
            $trace[] = ['dead', 'No aggregate record either', 'No badge rendered'];
        }
        return compact('record', 'is_agg', 'trace');
    }

    $trace[] = ['pass', 'Qslot resolved to ' . $qslot, 'Per-question record lookup possible'];

    if ($pq_record && $agg_record) {
        $trace[] = ['info', 'Both per-question and aggregate records exist', 'Running fallback guard logic (lib.php v1.2.132+)'];

        // Note: agg_has_paste_evidence
        $agg_metrics = !empty($agg_record->metricsjson)
            ? (json_decode($agg_record->metricsjson, true) ?: [])
            : [];
        $agg_s1_pts = (int)(($agg_metrics['signal_breakdown'][1] ?? 0));
        $agg_has_paste = ($agg_s1_pts >= 30) || ((float)$agg_record->riskscore >= 0.70);

        if ($agg_has_paste) {
            $trace[] = ['warn', 'Aggregate has paste evidence',
                'agg Signal1=' . $agg_s1_pts . 'pts OR agg riskscore=' . round((float)$agg_record->riskscore * 100, 1) . '% ≥ 70%'
                . ' → aggregate fallback is ELIGIBLE to override per-question LOW'];
        } else {
            $trace[] = ['pass', 'Aggregate has NO paste evidence',
                'agg Signal1=' . $agg_s1_pts . 'pts (< 30) AND agg riskscore=' . round((float)$agg_record->riskscore * 100, 1) . '% (< 70%)'
                . ' → aggregate fallback will NOT fire regardless of per-question score'];
        }

        // Note: pq_looks_like_missed_paste
        $pq_ks = (int)($pq_record->total_keystrokes ?? 0);
        $pq_pe = (int)($pq_record->paste_events     ?? 0);
        $pq_rs = (float)$pq_record->riskscore;

        $cond_a = $pq_rs <= 0.0;
        $cond_b = ($pq_rs < 0.30 && $pq_pe === 0 && $pq_ks <= 10);
        $pq_looks_missed = $cond_a || $cond_b;

        $cond_a_str = 'riskscore≤0 (' . ($cond_a ? 'TRUE' : 'false') . ')';
        $cond_b_str = 'riskscore<0.30 AND paste_events=0 AND keystrokes≤10 '
                    . '(' . ($cond_b ? 'TRUE' : 'false') . ': '
                    . round($pq_rs * 100, 1) . '% / pe=' . $pq_pe . ' / ks=' . $pq_ks . ')';

        if ($pq_looks_missed) {
            $trace[] = ['warn', 'pq_looks_like_missed_paste = TRUE',
                'Condition A: ' . $cond_a_str . '  |  Condition B: ' . $cond_b_str];
        } else {
            $trace[] = ['pass', 'pq_looks_like_missed_paste = FALSE',
                'Condition A: ' . $cond_a_str . '  |  Condition B: ' . $cond_b_str
                . ' → per-question record is trusted as-is'];
        }

        if ($pq_looks_missed && $agg_has_paste) {
            $record = $agg_record;
            $is_agg = true;
            $trace[] = ['warn', 'DECISION: Using AGGREGATE record',
                'pq_looks_like_missed_paste=TRUE AND agg_has_paste_evidence=TRUE → '
                . 'aggregate record surfaced (badge shows "(overall)"). '
                . 'This is the correct rescue behaviour for a paste not attributed to this slot.'];
        } elseif ($pq_looks_missed && !$agg_has_paste) {
            $record = $pq_record;
            $is_agg = false;
            $pq_pct = round($pq_rs * 100, 1);
            $trace[] = ['fail', 'DECISION: Using per-question record (LOW) — paste missed entirely',
                'pq_looks_like_missed_paste=TRUE but agg_has_paste_evidence=FALSE → '
                . 'per-question score shown (' . $pq_pct . '%). '
                . 'ROOT CAUSE: The paste event was not flushed before submission (paste_events=0 in both records). '
                . 'Check Section 4 (Paste delivery) to confirm.'];
        } else {
            $record = $pq_record;
            $is_agg = false;
            $pq_pct = round($pq_rs * 100, 1);
            $trace[] = ['pass', 'DECISION: Trusting per-question record',
                'Score ' . $pq_pct . '% — per-question scorer found genuine signals. '
                . 'This is the correct outcome.'];
        }

    } elseif ($pq_record) {
        $trace[] = ['info', 'Only per-question record exists (no aggregate)', 'Using per-question directly'];
        $record = $pq_record;
    } elseif ($agg_record) {
        $trace[] = ['warn', 'No per-question record — falling back to aggregate',
            'qslot=' . $qslot . ' exists but has no SC record. '
            . 'Possible cause: the observer scored the attempt before qslot detection was available, '
            . 'or the attempt was not yet finalized.'];
        $record = $agg_record;
        $is_agg = true;
    } else {
        $trace[] = ['dead', 'No record of any kind', 'No badge rendered for this question'];
    }

    return compact('record', 'is_agg', 'trace');
}

// ── Resolve student list ──────────────────────────────────────────────────────

$all_students = $DB->get_records_sql(
    "SELECT DISTINCT s.userid, u.firstname, u.lastname
       FROM {plagiarism_essayguard_sc} s
       JOIN {user} u ON u.id = s.userid
      WHERE s.cmid = :cmid
   ORDER BY u.lastname, u.firstname",
    ['cmid' => $cmid]
);

if (!$userid && !empty($all_students)) {
    $first = reset($all_students);
    $userid = (int)$first->userid;
}

// ── Load quiz attempt answers for selected user ───────────────────────────────

$answer_cache   = []; // slot => normalised answer text
$attempt_id     = null;
$attempt_uniqueid = null;

if ($userid) {
    $attempt = $DB->get_record_sql(
        "SELECT qa.uniqueid, qa.id as attemptid, qa.timefinish
           FROM {quiz_attempts} qa
           JOIN {quiz} q ON q.id = qa.quiz
           JOIN {course_modules} cm ON cm.instance = q.id
          WHERE cm.id = :cmid AND qa.userid = :userid AND qa.state = 'finished'
       ORDER BY qa.id DESC",
        ['cmid' => $cmid, 'userid' => $userid],
        IGNORE_MULTIPLE
    );

    if ($attempt) {
        $attempt_id       = (int)$attempt->attemptid;
        $attempt_uniqueid = (int)$attempt->uniqueid;

        $rows_q = $DB->get_records_sql(
            "SELECT qas.id, qa.slot, qasd.value
               FROM {question_attempt_steps} qas
               JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
               JOIN {question_attempts} qa ON qa.id = qas.questionattemptid
              WHERE qa.questionusageid = :qubaid AND qasd.name = 'answer'
           ORDER BY qa.slot ASC, qas.id DESC",
            ['qubaid' => $attempt->uniqueid]
        );

        $seen = [];
        foreach ($rows_q as $rq) {
            $slot = (int)$rq->slot;
            if (isset($seen[$slot])) continue;
            $seen[$slot] = true;
            $norm = trim(preg_replace('/\s+/', ' ', strip_tags((string)($rq->value ?? ''))));
            if ($norm !== '') {
                $answer_cache[$slot] = $norm;
            }
        }
        ksort($answer_cache);
    }
}

// ── Load SC records for selected user ────────────────────────────────────────

$sc_by_slot = [];  // slot => most-recent SC record
if ($userid) {
    $sc_all = $DB->get_records_sql(
        "SELECT * FROM {plagiarism_essayguard_sc}
          WHERE userid = :userid AND cmid = :cmid
       ORDER BY qslot ASC, timemodified DESC",
        ['userid' => $userid, 'cmid' => $cmid]
    );
    foreach ($sc_all as $r) {
        $s = (int)$r->qslot;
        if (!isset($sc_by_slot[$s])) {
            $sc_by_slot[$s] = $r;
        }
    }
    ksort($sc_by_slot);
}

$agg_record = $sc_by_slot[0] ?? null;

// ── Load raw events for selected user ────────────────────────────────────────

$ev_by_slot  = [];   // slot (from payloadjson) => events[]
$ev_all_list = [];
if ($userid) {
    $ev_rows = $DB->get_records_sql(
        "SELECT * FROM {plagiarism_essayguard_ev}
          WHERE userid = :userid AND cmid = :cmid
       ORDER BY eventtime ASC",
        ['userid' => $userid, 'cmid' => $cmid]
    );
    foreach ($ev_rows as $ev) {
        $ev_all_list[] = $ev;
        $p    = !empty($ev->payloadjson) ? json_decode($ev->payloadjson, true) : [];
        $slot = isset($p['qslot']) ? (int)$p['qslot'] : 0;
        $ev_by_slot[$slot][] = $ev;
    }
}

// ── Page output ───────────────────────────────────────────────────────────────

$user_obj  = $userid ? $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname', IGNORE_MISSING) : null;
$user_name = $user_obj ? fullname($user_obj) : 'Unknown';

$diag_url        = new moodle_url('/plagiarism/essayguard/diag.php',          ['cmid' => $cmid]);
$attempt_diag_url= new moodle_url('/plagiarism/essayguard/attempt_diag.php',  ['cmid' => $cmid]);
$badge_diag_url  = new moodle_url('/plagiarism/essayguard/badge_diag.php',    ['cmid' => $cmid]);
$report_url      = new moodle_url('/plagiarism/essayguard/report.php',        ['cmid' => $cmid]);
$self_url        = new moodle_url('/plagiarism/essayguard/report_match_diag.php', ['cmid' => $cmid]);

$plugin = new stdClass();
include(__DIR__ . '/version.php');

echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
   . '<title>EssayGuard Report-Match Diagnostic</title>'
   . $css . '</head><body>';

echo '<a class="back" href="' . $diag_url->out() . '">&larr; Main Diag</a>'
   . '<a class="back" href="' . $attempt_diag_url->out() . '">Attempt Diag</a>'
   . '<a class="back" href="' . $badge_diag_url->out() . '">Badge Diag</a>'
   . '<a class="back" href="' . $report_url->out() . '">EssayGuard Report</a>';

echo '<h1>EssayGuard — Report-Match Diagnostic <span style="font-size:0.75rem;font-weight:400;color:#6b7280;">v' . htmlspecialchars($plugin_obj->release ?? '?') . '</span></h1>';
echo '<p class="meta"><strong>' . htmlspecialchars($cm->name) . '</strong> (cmid=' . (int)$cmid . ') &mdash; '
   . htmlspecialchars($course->fullname) . '</p>';

// ── Student picker ────────────────────────────────────────────────────────────

echo '<div class="section">';
echo '<div class="section-title"><span class="num">&#9660;</span> Select student</div>';
echo '<form method="get" action="' . $self_url->out(false) . '">';
echo '<input type="hidden" name="cmid" value="' . (int)$cmid . '">';
echo '<div class="picker">';
echo '<label>Student:</label>';
echo '<select name="userid" onchange="this.form.submit()">';
if (empty($all_students)) {
    echo '<option value="">— No submissions yet —</option>';
} else {
    foreach ($all_students as $u) {
        $sel = ($u->userid == $userid) ? ' selected' : '';
        echo '<option value="' . (int)$u->userid . '"' . $sel . '>'
           . htmlspecialchars($u->firstname . ' ' . $u->lastname) . ' (id=' . (int)$u->userid . ')</option>';
    }
}
echo '</select>';
echo '<button type="submit" style="padding:4px 12px;font-size:0.85rem;border:1px solid #d1d5db;border-radius:4px;background:#fff;cursor:pointer;">Load</button>';
echo '</div></form>';
echo '</div>';

if (!$userid || empty($all_students)) {
    echo '<div class="warn-box">No student data found for this activity. Have students attempt the quiz first.</div>';
    echo '</body></html>';
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// SECTION 1: Student overview — all SC records and "same badge" detector
// ════════════════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<div class="section-title"><span class="num">1</span> Student score records overview</div>';
echo '<p class="meta" style="margin-bottom:0.75rem;">All <code>plagiarism_essayguard_sc</code> records for this student. '
   . 'If every per-question row shows the same score as the aggregate (qslot=0), that confirms '
   . 'qslot detection is failing and the aggregate badge is being shown for every question.</p>';

if (empty($sc_by_slot)) {
    echo '<div class="warn-box">No SC records found for this student. The attempt may not have been finalised yet.</div>';
} else {
    // Detect "same badge on all questions" pattern.
    $agg_pct = $agg_record ? round((float)$agg_record->riskscore * 100, 1) : null;
    $pq_slots = array_filter(array_keys($sc_by_slot), fn($s) => $s > 0);
    $same_badge_count = 0;
    foreach ($pq_slots as $s) {
        $pct = round((float)$sc_by_slot[$s]->riskscore * 100, 1);
        if ($agg_pct !== null && abs($pct - $agg_pct) < 1.0) {
            $same_badge_count++;
        }
    }
    $has_same_badge_issue = $same_badge_count > 0 && count($pq_slots) > 0;

    if ($has_same_badge_issue) {
        echo '<div class="err-box"><strong>&#9888; Same-badge symptom detected:</strong> '
           . $same_badge_count . ' of ' . count($pq_slots) . ' per-question record(s) have essentially '
           . 'the same score as the aggregate (qslot=0). This is the signature of qslot detection '
           . 'failure — see Section 2 for the root cause.</div>';
    } elseif (!empty($pq_slots)) {
        echo '<div class="ok-box"><strong>&#10003; Per-question scores differ from aggregate</strong> — '
           . 'qslot detection appears to be working. Badges should be unique per question.</div>';
    }

    echo '<table>';
    echo '<thead><tr><th>Slot</th><th>Badge (Review Attempt)</th><th>EssayGuard Report badge</th>'
       . '<th>paste_events</th><th>keystrokes</th><th>riskscore</th><th>Signal1 pts</th><th>Modified</th></tr></thead><tbody>';

    foreach ($sc_by_slot as $slot => $sc) {
        $pct    = round((float)$sc->riskscore * 100, 1);
        $label  = rmd_risk_label($pct);
        $m      = !empty($sc->metricsjson) ? (json_decode($sc->metricsjson, true) ?: []) : [];
        $s1_pts = (int)(($m['signal_breakdown'][1] ?? 0));

        // "Review Attempt" badge = what get_links() would show if it loaded this exact slot.
        // Per-question slots show the per-question badge (not aggregate) when qslot detected.
        // We'll annotate properly in Section 3; here just show the raw record.
        $badge_html = ($slot === 0) ? rmd_badge_agg($pct) : rmd_badge($pct);

        // Flag if this per-question record matches aggregate.
        $row_class = '';
        if ($slot > 0 && $agg_pct !== null && abs($pct - $agg_pct) < 1.0) {
            $row_class = ' class="summary-row-warn"';
        } elseif ($slot > 0) {
            $row_class = ' class="summary-row-ok"';
        }

        echo '<tr' . $row_class . '>';
        echo '<td>' . ($slot === 0 ? '<em style="color:#6b7280;">Aggregate (qslot=0)</em>' : 'Q' . $slot) . '</td>';
        echo '<td>' . $badge_html . '</td>';
        echo '<td>' . $badge_html . ($slot === 0 ? ' <span style="font-size:0.75rem;color:#6b7280;">(report reads this directly)</span>' : '') . '</td>';
        echo '<td>' . (int)($sc->paste_events ?? 0) . '</td>';
        echo '<td>' . (int)($sc->total_keystrokes ?? 0) . '</td>';
        echo '<td>' . round((float)$sc->riskscore, 4) . '</td>';
        echo '<td>' . ($s1_pts > 0 ? '<strong style="color:' . ($s1_pts >= 30 ? '#dc2626' : '#b45309') . ';">' . $s1_pts . '</strong>' : '0') . '</td>';
        echo '<td>' . ($sc->timemodified ? date('d M Y H:i', (int)$sc->timemodified) : '&mdash;') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

// ════════════════════════════════════════════════════════════════════════════
// SECTION 2: Qslot detection simulation
// ════════════════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<div class="section-title"><span class="num">2</span> Qslot detection simulation</div>';
echo '<p class="meta" style="margin-bottom:0.75rem;">Replays <code>find_qslot_by_content()</code> for each question answer '
   . 'stored in the most recent finished quiz attempt. Shows the character-level similarity score '
   . 'between the rendered content (what Moodle passes to <code>get_links()</code>) and each stored '
   . 'answer. Threshold: 60%. Below 60% → qslot=0 → aggregate record used for ALL questions.</p>';

if (!$attempt_uniqueid) {
    echo '<div class="warn-box">No finished quiz attempt found for this student on this activity. '
       . 'The student must complete and submit the quiz before qslot detection can be tested.</div>';
} elseif (empty($answer_cache)) {
    echo '<div class="warn-box">Quiz attempt found (id=' . $attempt_id . ') but no essay answers '
       . 'found in <code>question_attempt_step_data</code>. This quiz may not have essay-type questions, '
       . 'or the answers were not yet submitted.</div>';
} else {
    echo '<div class="ok-box" style="margin-bottom:0.75rem;">Quiz attempt id=' . $attempt_id
       . ' loaded — ' . count($answer_cache) . ' question slot(s) with answers found.</div>';

    // For each answer slot, simulate qslot detection using that answer as the "content" input.
    // This mirrors what Moodle passes to get_links() on the Review Attempt page.
    foreach ($answer_cache as $slot => $norm_text) {
        echo '<div class="slot-header">Q' . $slot . ' — Qslot detection</div>';

        $sim_scores = [];
        $resolved   = rmd_simulate_qslot_detection($answer_cache, $norm_text, $sim_scores);

        $len = strlen($norm_text);
        $preview = strlen($norm_text) > 120 ? htmlspecialchars(substr($norm_text, 0, 120)) . '&hellip;' : htmlspecialchars($norm_text);
        echo rmd_step('info', 'Answer preview (' . $len . ' chars)', '"' . $preview . '"');

        // Show similarity against every slot.
        foreach ($sim_scores as $cmp_slot => $sim_pct) {
            $is_self = ($cmp_slot === $slot);
            $over_threshold = $sim_pct >= 60.0;
            $type = $is_self ? ($over_threshold ? 'pass' : 'fail') : 'info';
            $note = $is_self ? ' ← this question' : '';
            echo rmd_step(
                $type,
                'Similarity vs Q' . $cmp_slot . $note,
                round($sim_pct, 1) . '% ' . ($over_threshold ? '(≥ 60% threshold ✓)' : '(< 60% threshold ✗ — would not match)')
            );
        }

        if ($resolved === $slot) {
            echo rmd_step('pass', 'Result: Q' . $slot . ' correctly resolved',
                'best_pct=' . ($sim_scores[$slot] ?? 0) . '% → qslot=' . $resolved
                . '. Review Attempt will load the per-question SC record for this slot.');
        } elseif ($resolved > 0) {
            echo rmd_step('fail', 'Result: wrong slot resolved!',
                'find_qslot_by_content() returned qslot=' . $resolved . ' instead of ' . $slot
                . '. This means the wrong per-question record will be shown for Q' . $slot . '.');
        } else {
            echo rmd_step('fail', 'Result: qslot detection FAILED (returned 0)',
                'No slot exceeded the 60% threshold. Review Attempt will show the AGGREGATE record '
                . 'for Q' . $slot . '. If all questions fail, every question shows the same badge. '
                . 'Possible causes: (a) answer text differs significantly between rendering '
                . 'and DB storage (entities, whitespace); (b) answer is too short for reliable matching; '
                . '(c) quiz uses a non-standard question type that stores answers differently.');
        }

        // Encode/entity difference check.
        $decoded_text = html_entity_decode($norm_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $diff = abs(strlen($norm_text) - strlen($decoded_text));
        if ($diff > 0) {
            $diff_pct = round(($diff / max(1, strlen($norm_text))) * 100, 1);
            echo rmd_step('warn', 'HTML entity difference detected',
                'Normalised length=' . strlen($norm_text) . ' vs entity-decoded length=' . strlen($decoded_text)
                . ' (difference=' . $diff . ' chars, ' . $diff_pct . '%). html_entity_decode() is applied '
                . 'before similar_text() in v1.2.114+ — verify this fix is active in your build.');
        } else {
            echo rmd_step('pass', 'No HTML entity difference', 'Normalised and entity-decoded lengths match');
        }
    }
}
echo '</div>';

// ════════════════════════════════════════════════════════════════════════════
// SECTION 3: Record selection walkthrough (replicates lib.php get_links() logic)
// ════════════════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<div class="section-title"><span class="num">3</span> Record selection walkthrough — what Review Attempt shows per question</div>';
echo '<p class="meta" style="margin-bottom:0.75rem;">Replays the exact <code>get_links()</code> / <code>rmd_select_record()</code> '
   . 'logic for each question slot. This is what the student and teacher see in Review Attempt. '
   . 'Compare against the EssayGuard Report column (which reads SC records directly, bypassing qslot detection).</p>';

if (empty($answer_cache) && empty($sc_by_slot)) {
    echo '<div class="warn-box">No quiz answers or SC records found. Cannot simulate record selection.</div>';
} else {
    // Build slot list from union of answer_cache and sc_by_slot (excluding aggregate).
    $all_slots = array_unique(array_merge(
        array_keys($answer_cache),
        array_filter(array_keys($sc_by_slot), fn($s) => $s > 0)
    ));
    sort($all_slots);

    if (empty($all_slots)) {
        echo '<div class="warn-box">No per-question slots found (only aggregate record exists). '
           . 'This means the observer did not write per-question scores — check attempt_diag.php.</div>';
    }

    foreach ($all_slots as $slot) {
        echo '<hr class="slot-sep">';
        echo '<div class="slot-header">Q' . $slot . ' — Record selection</div>';

        $pq_record = $sc_by_slot[$slot] ?? null;

        // Simulate qslot detection for this slot (to determine if qslot=slot or qslot=0 resolves).
        $norm_content = $answer_cache[$slot] ?? '';
        $resolved_qslot = 0;
        $sim_out = [];
        if ($norm_content !== '' && !empty($answer_cache)) {
            $resolved_qslot = rmd_simulate_qslot_detection($answer_cache, $norm_content, $sim_out);
        }

        // If qslot resolved to a different slot, use that SC record.
        $effective_pq = ($resolved_qslot > 0) ? ($sc_by_slot[$resolved_qslot] ?? null) : null;

        $result = rmd_select_record($effective_pq, $agg_record, $resolved_qslot);

        foreach ($result['trace'] as $t) {
            echo rmd_step($t[0], $t[1], $t[2] ?? '');
        }

        // Final badge comparison.
        $ra_record  = $result['record'];
        $ra_is_agg  = $result['is_agg'];
        $eg_record  = $pq_record ?? $agg_record;

        $ra_pct     = $ra_record  ? round((float)$ra_record->riskscore  * 100, 1) : null;
        $eg_pct     = $eg_record  ? round((float)$eg_record->riskscore  * 100, 1) : null;

        echo '<div class="metric-grid" style="margin-top:0.75rem;">';
        echo '<div class="metric-card"><div class="metric-label">Review Attempt badge</div><div class="metric-val" style="font-size:0.85rem;">'
           . ($ra_pct !== null ? ($ra_is_agg ? rmd_badge_agg($ra_pct) : rmd_badge($ra_pct)) : rmd_badge_na())
           . '</div></div>';
        echo '<div class="metric-card"><div class="metric-label">EssayGuard Report badge</div><div class="metric-val" style="font-size:0.85rem;">'
           . ($eg_pct !== null ? rmd_badge($eg_pct) : rmd_badge_na())
           . '</div></div>';

        if ($ra_pct !== null && $eg_pct !== null) {
            $mismatch = abs($ra_pct - $eg_pct) >= 5.0
                     || rmd_risk_label($ra_pct) !== rmd_risk_label($eg_pct);
            echo '<div class="metric-card"><div class="metric-label">Badges match?</div><div class="metric-val" style="font-size:0.85rem;">';
            if ($mismatch) {
                echo '<span class="badge badge-mismatch">&#9888; MISMATCH</span>';
            } else {
                echo '<span class="badge badge-low" style="border-color:#86efac;">&#10003; Match</span>';
            }
            echo '</div></div>';

            if ($mismatch) {
                echo '<div class="metric-card"><div class="metric-label">Why different?</div><div class="metric-val" style="font-size:0.75rem;color:#92400e;">';
                if ($ra_is_agg && !empty($pq_record)) {
                    echo 'Review Attempt fell back to aggregate; EssayGuard Report reads per-question directly.';
                } elseif ($resolved_qslot === 0) {
                    echo 'Qslot detection failed → Review Attempt used aggregate; Report reads Q' . $slot . ' record.';
                } else {
                    echo 'Record selection chose different records. Check trace above.';
                }
                echo '</div></div>';
            }
        }
        echo '</div>';
    }
}
echo '</div>';

// ════════════════════════════════════════════════════════════════════════════
// SECTION 4: Paste delivery audit — was paste flushed before scoring?
// ════════════════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<div class="section-title"><span class="num">4</span> Paste delivery audit</div>';
echo '<p class="meta" style="margin-bottom:0.75rem;">Checks whether paste events reached the server '
   . 'before the attempt was scored. A paste submitted faster than the 5-second flush interval '
   . '(pre-v1.2.126) causes <code>paste_events=0</code> in the SC record even though the student '
   . 'copy-pasted. Also checks for the Ctrl+V modifier-key pattern (2 keystrokes, no paste event tagged '
   . 'to the correct slot).</p>';

if (empty($ev_all_list)) {
    echo '<div class="warn-box">No events in <code>plagiarism_essayguard_ev</code> for this student. '
       . 'Either the JS tracker did not load, all events were pruned after scoring, or the student '
       . 'submitted without any keyboard/paste activity.</div>';
} else {
    // Count paste events per slot (from payloadjson).
    $paste_ev_by_slot   = [];
    $ks_ev_by_slot      = [];
    $large_ins_by_slot  = [];

    foreach ($ev_all_list as $ev) {
        $p    = !empty($ev->payloadjson) ? json_decode($ev->payloadjson, true) : [];
        $slot = isset($p['qslot']) ? (int)$p['qslot'] : 0;
        if (in_array($ev->eventname, ['paste', 'drop_paste'])) {
            $paste_ev_by_slot[$slot] = ($paste_ev_by_slot[$slot] ?? 0) + 1;
        }
        if (in_array($ev->eventname, ['keydown', 'backspace', 'delete'])) {
            $ks_ev_by_slot[$slot] = ($ks_ev_by_slot[$slot] ?? 0) + 1;
        }
        if ($ev->eventname === 'large_insert') {
            $large_ins_by_slot[$slot] = ($large_ins_by_slot[$slot] ?? 0) + 1;
        }
    }

    // Per-slot comparison: events vs SC record.
    $all_ev_slots = array_unique(array_merge(
        [0],
        array_keys($paste_ev_by_slot),
        array_keys($ks_ev_by_slot),
        array_keys($large_ins_by_slot)
    ));
    sort($all_ev_slots);

    echo '<table>';
    echo '<thead><tr><th>Slot</th><th>paste events (EV table)</th><th>large_insert (EV)</th>'
       . '<th>keystrokes (EV)</th><th>paste_events (SC record)</th><th>keystrokes (SC record)</th><th>Assessment</th></tr></thead><tbody>';

    foreach ($all_ev_slots as $slot) {
        $ev_paste  = $paste_ev_by_slot[$slot]  ?? 0;
        $ev_large  = $large_ins_by_slot[$slot] ?? 0;
        $ev_ks     = $ks_ev_by_slot[$slot]     ?? 0;
        $sc_paste  = (int)(($sc_by_slot[$slot]->paste_events    ?? 0));
        $sc_ks     = (int)(($sc_by_slot[$slot]->total_keystrokes ?? 0));

        // Diagnose discrepancy.
        $assessment = '';
        $row_class  = '';

        if ($ev_paste > 0 && $sc_paste === 0) {
            $assessment = '&#9888; Paste events in EV table but paste_events=0 in SC record. '
                        . 'Paste was not flushed before scoring. Upgrade to v1.2.126+ (immediate paste flush fix).';
            $row_class  = ' class="summary-row-err"';
        } elseif ($ev_paste === 0 && $ev_large === 0 && $ev_ks <= 10 && $sc_ks <= 10) {
            if ($sc_by_slot[$slot] ?? false) {
                $pq_pct = round((float)($sc_by_slot[$slot]->riskscore ?? 0) * 100, 1);
                if ($pq_pct < 30) {
                    $assessment = '&#9888; Very few keystrokes and no paste events. '
                                . 'Could be a Ctrl+V paste (2 keydown events: Ctrl + V) where the paste event '
                                . 'was not tagged to this slot. Check aggregate (slot=0) paste events.';
                    $row_class  = ' class="summary-row-warn"';
                } else {
                    $assessment = 'Low event count but score reflects paste-level confidence.';
                }
            } else {
                $assessment = 'No SC record for this slot.';
                $row_class  = ' class="summary-row-warn"';
            }
        } elseif ($ev_paste > 0 && $sc_paste > 0) {
            $assessment = '&#10003; Paste correctly captured and stored.';
            $row_class  = ' class="summary-row-ok"';
        } elseif ($ev_paste === 0 && $ev_large > 0 && $sc_paste === 0) {
            $assessment = 'Large_insert events present (TinyMCE paste proxy) but no native paste event. '
                        . 'Signal 2 (S2) should have fired — check metricsjson signal_breakdown.';
            $row_class  = ' class="summary-row-warn"';
        } else {
            $assessment = 'OK — no paste activity detected for this slot.';
        }

        echo '<tr' . $row_class . '>';
        echo '<td>' . ($slot === 0 ? '<em style="color:#6b7280;">Aggregate</em>' : 'Q' . $slot) . '</td>';
        echo '<td>' . ($ev_paste > 0 ? '<strong style="color:#dc2626;">' . $ev_paste . '</strong>' : $ev_paste) . '</td>';
        echo '<td>' . ($ev_large > 0 ? '<strong style="color:#b45309;">' . $ev_large . '</strong>' : $ev_large) . '</td>';
        echo '<td>' . $ev_ks . '</td>';
        echo '<td>' . ($sc_paste > 0 ? '<strong style="color:#dc2626;">' . $sc_paste . '</strong>' : $sc_paste) . '</td>';
        echo '<td>' . $sc_ks . '</td>';
        echo '<td style="font-size:0.8rem;">' . $assessment . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

// ════════════════════════════════════════════════════════════════════════════
// SECTION 5: Scenario detector — H3/H4 pattern classification
// ════════════════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<div class="section-title"><span class="num">5</span> Scenario detector — H3/H4 pattern analysis</div>';
echo '<p class="meta" style="margin-bottom:0.75rem;">Classifies each per-question slot into one of the '
   . 'known failure scenarios based on the raw evidence. '
   . '<strong>H4</strong>: typed Q1, pasted Q2 → both show "good results" (LOW). '
   . '<strong>H3</strong>: pasted Q1 and Q2 → neither caught.</p>';

if (empty($sc_by_slot) || (count($sc_by_slot) <= 1 && isset($sc_by_slot[0]))) {
    echo '<div class="warn-box">Insufficient SC records (only aggregate or nothing). '
       . 'Per-question scenario analysis requires at least one qslot > 0 SC record.</div>';
} else {
    $pq_slots = array_filter(array_keys($sc_by_slot), fn($s) => $s > 0);
    sort($pq_slots);

    foreach ($pq_slots as $slot) {
        $sc  = $sc_by_slot[$slot];
        $pct = round((float)$sc->riskscore * 100, 1);
        $pe  = (int)($sc->paste_events     ?? 0);
        $ks  = (int)($sc->total_keystrokes ?? 0);
        $m   = !empty($sc->metricsjson) ? (json_decode($sc->metricsjson, true) ?: []) : [];
        $s1  = (int)(($m['signal_breakdown'][1] ?? 0));

        $agg_pct  = $agg_record ? round((float)$agg_record->riskscore * 100, 1) : null;
        $agg_pe   = $agg_record ? (int)($agg_record->paste_events ?? 0) : 0;
        $agg_m    = ($agg_record && !empty($agg_record->metricsjson))
                  ? (json_decode($agg_record->metricsjson, true) ?: []) : [];
        $agg_s1   = (int)(($agg_m['signal_breakdown'][1] ?? 0));
        $ev_paste = $paste_ev_by_slot[$slot]  ?? 0;
        $ev_paste_agg = $paste_ev_by_slot[0]  ?? 0;

        echo '<hr class="slot-sep">';
        echo '<div class="slot-header">Q' . $slot . ' — Scenario detection</div>';
        echo '<div class="metric-grid">';
        echo '<div class="metric-card"><div class="metric-label">Per-question badge</div><div class="metric-val" style="font-size:0.85rem;">' . rmd_badge($pct) . '</div></div>';
        echo '<div class="metric-card"><div class="metric-label">Aggregate badge</div><div class="metric-val" style="font-size:0.85rem;">' . ($agg_pct !== null ? rmd_badge_agg($agg_pct) : rmd_badge_na()) . '</div></div>';
        echo '<div class="metric-card"><div class="metric-label">paste_events (this slot)</div><div class="metric-val">' . $pe . '</div></div>';
        echo '<div class="metric-card"><div class="metric-label">keystrokes (this slot)</div><div class="metric-val">' . $ks . '</div></div>';
        echo '<div class="metric-card"><div class="metric-label">Signal1 pts (this slot)</div><div class="metric-val">' . $s1 . '</div></div>';
        echo '<div class="metric-card"><div class="metric-label">EV paste events (this slot)</div><div class="metric-val">' . $ev_paste . '</div></div>';
        echo '</div>';

        // Classify scenario.
        $scenario      = 'UNKNOWN';
        $scenario_cls  = 'scenario-unknown';
        $scenario_text = '';

        if ($pct >= 66) {
            // HIGH — correctly caught.
            $scenario     = 'CAUGHT_HIGH';
            $scenario_cls = 'scenario-caught';
            $scenario_text = 'HIGH badge — paste correctly detected and scored. No issue.';

        } elseif ($pe > 0 && $pct >= 30) {
            // MEDIUM with paste — partially caught.
            $scenario     = 'CAUGHT_MEDIUM';
            $scenario_cls = 'scenario-caught';
            $scenario_text = 'MEDIUM badge with paste events — paste detected but score below HIGH threshold. '
                           . 'This is correct for a mixed (typed + pasted) session. Check if more content was typed than pasted.';

        } elseif ($pe === 0 && $ev_paste > 0 && $pct < 30) {
            // Paste in EV table but paste_events=0 in SC — flush race.
            $scenario     = 'H3_H4_FLUSH_RACE';
            $scenario_cls = 'scenario-missed';
            $scenario_text = 'MISSED (flush race condition). '
                           . 'Paste events exist in the EV table for this slot (' . $ev_paste . ') '
                           . 'but paste_events=0 in the SC record. The student pasted and submitted '
                           . 'faster than the 5-second flush interval. '
                           . 'FIX: Upgrade to v1.2.126+ (immediate paste flush on paste/drop events). '
                           . 'Badge will be LOW until the attempt is rescored after the fix is applied.';

        } elseif ($pe === 0 && $ks <= 10 && $pct < 30 && $agg_pct !== null && $agg_s1 >= 30) {
            // Note: pq_looks_like_missed_paste should have triggered aggregate fallback — but didn't?
            $scenario     = 'H4_MISSED_PASTE_FALLBACK_MISSED';
            $scenario_cls = 'scenario-missed';
            $scenario_text = 'MISSED (fallback should have fired). '
                           . 'Per-question: paste_events=0, keystrokes=' . $ks . ' (≤ 10), score=' . $pct . '% (LOW). '
                           . 'Aggregate: Signal1=' . $agg_s1 . 'pts (≥ 30 = paste evidence). '
                           . 'pq_looks_like_missed_paste=TRUE and agg_has_paste_evidence=TRUE should trigger '
                           . 'the aggregate fallback → badge should show "(overall)". '
                           . 'If Review Attempt shows LOW for this question, qslot detection may have failed '
                           . '(qslot=0 returned → per-question record never fetched → fallback logic never ran). '
                           . 'See Section 2 for qslot detection results.';

        } elseif ($pe === 0 && $ks <= 10 && $pct < 30 && ($agg_pct === null || $agg_s1 < 30)) {
            // Ctrl+V paste with no aggregate evidence either — fully missed.
            $scenario     = 'H3_FULLY_MISSED';
            $scenario_cls = 'scenario-missed';
            $scenario_text = 'MISSED (no paste evidence anywhere). '
                           . 'Per-question: paste_events=0, keystrokes=' . $ks . ' (≤ 10), score=' . $pct . '% (LOW). '
                           . 'Aggregate: Signal1=' . $agg_s1 . 'pts (< 30 — no meaningful paste evidence). '
                           . 'Possible causes: (1) Student pasted via right-click or browser autofill (no Ctrl+V keydown). '
                           . '(2) TinyMCE intercepted the paste and suppressed the native paste event before tracker.js saw it. '
                           . '(3) The large_insert proxy did not fire (answer too short, or large_insert threshold not met). '
                           . '(4) The paste flush race (see H3_H4_FLUSH_RACE above). '
                           . 'Check EV table for large_insert events: aggregate large_insert count = ' . ($large_ins_by_slot[0] ?? 0) . '.';

        } elseif ($pe === 0 && $ks > 10 && $pct < 30) {
            // Typed naturally — LOW is correct.
            $scenario     = 'NATURAL_TYPING_LOW';
            $scenario_cls = 'scenario-caught';
            $scenario_text = 'LOW badge — genuine typing detected (keystrokes=' . $ks . ', no paste events). '
                           . 'This is the expected outcome for a student who typed their answer. '
                           . 'If you believe this student pasted, check the EV table paste counts above.';

        } elseif ($pct >= 30 && $pct < 66 && $pe === 0) {
            // MEDIUM without paste — typing signals.
            $scenario     = 'MEDIUM_TYPING_SIGNALS';
            $scenario_cls = 'scenario-partial';
            $scenario_text = 'MEDIUM badge without paste events — typing pattern signals (speed/rhythm/linguistic) '
                           . 'pushed the score into MEDIUM. This may be a false positive for a very fast typist. '
                           . 'Check badge_diag.php Section 2 (behaviour archetype) for details.';
        } else {
            $scenario     = 'OTHER';
            $scenario_cls = 'scenario-unknown';
            $scenario_text = 'Score=' . $pct . '%, paste_events=' . $pe . ', keystrokes=' . $ks . '. '
                           . 'Does not match a known failure pattern. Review metricsjson manually.';
        }

        echo '<div class="scenario-box ' . $scenario_cls . '">';
        echo '<strong>Scenario: ' . $scenario . '</strong><br>';
        echo '<span style="font-size:0.82rem;">' . $scenario_text . '</span>';
        echo '</div>';
    }
}
echo '</div>';

// ════════════════════════════════════════════════════════════════════════════
// SECTION 6: All-students badge summary (same-badge matrix)
// ════════════════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<div class="section-title"><span class="num">6</span> All-students badge matrix — same-badge detector</div>';
echo '<p class="meta" style="margin-bottom:0.75rem;">Shows every student\'s per-question SC records. '
   . 'Rows where every qslot > 0 score matches the aggregate indicate qslot detection '
   . 'failure for that student\'s submission.</p>';

if (empty($all_students)) {
    echo '<div class="warn-box">No students have SC records for this activity.</div>';
} else {
    // Collect all qslots used across all students.
    $all_sc_all = $DB->get_records_sql(
        "SELECT userid, qslot, riskscore, paste_events, total_keystrokes
           FROM {plagiarism_essayguard_sc}
          WHERE cmid = :cmid
       ORDER BY userid ASC, qslot ASC, timemodified DESC",
        ['cmid' => $cmid]
    );

    // Group: userid -> slot -> record (most recent).
    $matrix = [];
    $all_matrix_slots = [];
    foreach ($all_sc_all as $r) {
        $uid  = (int)$r->userid;
        $slot = (int)$r->qslot;
        if (!isset($matrix[$uid][$slot])) {
            $matrix[$uid][$slot] = $r;
        }
        $all_matrix_slots[$slot] = true;
    }
    ksort($all_matrix_slots);
    $pq_matrix_slots = array_filter(array_keys($all_matrix_slots), fn($s) => $s > 0);
    sort($pq_matrix_slots);

    echo '<table>';
    echo '<thead><tr><th>Student</th><th>Aggregate (Q0)</th>';
    foreach ($pq_matrix_slots as $s) {
        echo '<th>Q' . $s . '</th>';
    }
    echo '<th>Same-badge issue?</th></tr></thead><tbody>';

    foreach ($all_students as $u) {
        $uid      = (int)$u->userid;
        $u_slots  = $matrix[$uid] ?? [];
        $agg_r    = $u_slots[0] ?? null;
        $agg_pp   = $agg_r ? round((float)$agg_r->riskscore * 100, 1) : null;

        $same_count = 0;
        $pq_count   = 0;
        foreach ($pq_matrix_slots as $s) {
            if (!isset($u_slots[$s])) continue;
            $pq_count++;
            $pp = round((float)$u_slots[$s]->riskscore * 100, 1);
            if ($agg_pp !== null && abs($pp - $agg_pp) < 1.0) {
                $same_count++;
            }
        }

        $has_issue = ($pq_count > 0 && $same_count === $pq_count);
        $row_class = $has_issue ? ' class="summary-row-warn"' : '';

        echo '<tr' . $row_class . '>';
        echo '<td>';
        $uid_link = new moodle_url('/plagiarism/essayguard/report_match_diag.php', ['cmid' => $cmid, 'userid' => $uid]);
        echo '<a href="' . $uid_link->out() . '" style="color:#2563eb;text-decoration:none;">'
           . htmlspecialchars($u->firstname . ' ' . $u->lastname)
           . '</a> <span style="color:#6b7280;font-size:0.8rem;">(id=' . $uid . ')</span>';
        echo '</td>';
        echo '<td>' . ($agg_pp !== null ? rmd_badge_agg($agg_pp) : rmd_badge_na()) . '</td>';

        foreach ($pq_matrix_slots as $s) {
            if (!isset($u_slots[$s])) {
                echo '<td>' . rmd_badge_na() . '</td>';
                continue;
            }
            $pp = round((float)$u_slots[$s]->riskscore * 100, 1);
            $is_same = $agg_pp !== null && abs($pp - $agg_pp) < 1.0;
            echo '<td>';
            echo rmd_badge($pp);
            if ($is_same) {
                echo ' <span style="font-size:0.72rem;color:#b45309;">= agg</span>';
            }
            echo '</td>';
        }

        echo '<td>';
        if ($has_issue) {
            echo '<span class="badge badge-mismatch">&#9888; All same as aggregate</span>';
        } elseif ($pq_count === 0) {
            echo '<span class="badge badge-na">No per-Q records</span>';
        } else {
            echo '<span class="badge badge-low" style="border-color:#86efac;">&#10003; OK</span>';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}
echo '</div>';

// ════════════════════════════════════════════════════════════════════════════
// SECTION 7: Action checklist
// ════════════════════════════════════════════════════════════════════════════

echo '<div class="section">';
echo '<div class="section-title"><span class="num">7</span> Diagnostic action checklist</div>';
echo '<table>';
echo '<thead><tr><th>Symptom</th><th>Root cause</th><th>Fix</th><th>Verify</th></tr></thead><tbody>';

$rows = [
    [
        'Every question shows same badge in Review Attempt',
        'qslot detection failure — find_qslot_by_content() returned 0 for all questions. '
      . 'All questions fall through to aggregate (qslot=0) record.',
        'Check Section 2 similarity scores. If < 60%: verify html_entity_decode fix is in build (v1.2.114+). '
      . 'If quiz uses non-essay question types, ensure those are excluded from tracking.',
        'After fix: per-question SC records will have unique riskscores. '
      . 'Section 2 will show PASS for each slot. Section 6 matrix will show different badges.',
    ],
    [
        'H4: Q1 typed + Q2 pasted → both show LOW in Review Attempt',
        'Either (a) qslot detection failed for Q2 so Q2 reads aggregate; '
      . 'or (b) pq_looks_like_missed_paste not triggered (Q2 keystrokes > 10 due to Ctrl+V + other keys); '
      . 'or (c) paste not flushed before submit.',
        '(a) See qslot fix above. '
      . '(b) Check Section 5 scenario for Q2 — if MISSED_PASTE_FALLBACK_MISSED, qslot detection is the root cause. '
      . '(c) Upgrade to v1.2.126+ for immediate paste flush.',
        'After fix: Q2 badge shows HIGH or MEDIUM (overall) in Review Attempt. '
      . 'Section 3 record selection for Q2 will show aggregate fallback fired.',
    ],
    [
        'H3: Q1 pasted + Q2 pasted → neither caught (both LOW)',
        'Paste events not reaching server (flush race) OR TinyMCE intercepting native paste event '
      . 'before tracker.js capture-phase listener. Aggregate also shows LOW (no paste evidence at all).',
        'Upgrade to v1.2.126+ (immediate flush on paste). '
      . 'Check Section 4 — if EV table has paste events but SC paste_events=0, that confirms the flush race. '
      . 'If EV table also shows 0 paste events, check large_insert count — if large_insert=0 too, '
      . 'TinyMCE is fully suppressing the paste event; check tracker.js TinyMCE capture path.',
        'After fix: Section 4 EV paste events > 0 AND SC paste_events > 0. '
      . 'Badge will be HIGH or MEDIUM for pasted questions.',
    ],
    [
        'EssayGuard Report shows HIGH but Review Attempt shows LOW (or vice versa)',
        'EssayGuard Report reads SC records directly (per-question qslot=N). '
      . 'Review Attempt uses get_links() → qslot detection → record selection logic. '
      . 'If qslot detection fails, Review Attempt uses aggregate which may differ from per-question.',
        'See Section 3 mismatch column. If qslot detection failed, fix as above. '
      . 'If record selection chose wrong record, check pq_looks_like_missed_paste conditions in Section 3.',
        'After fix: Section 3 "Badges match?" column shows ✓ for all questions.',
    ],
    [
        'Badge shows "(overall)" in Review Attempt',
        'Expected behaviour — the aggregate fallback fired. The per-question record had riskscore ≤ 0 '
      . 'or (riskscore < 30% AND paste_events=0 AND keystrokes ≤ 10), AND the aggregate had '
      . 'paste evidence (Signal1 ≥ 30pts or riskscore ≥ 70%). This is the rescue for missed-paste scenarios.',
        'No fix needed if the aggregate badge level is appropriate. '
      . 'If the aggregate is incorrectly HIGH, check Section 4 — the paste may be a false positive '
      . 'or from a different question being scored at the aggregate level.',
        'If the "(overall)" badge level is accurate, no action required.',
    ],
];

foreach ($rows as $row) {
    echo '<tr>';
    foreach ($row as $cell) {
        echo '<td style="font-size:0.82rem;">' . htmlspecialchars($cell) . '</td>';
    }
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';

// ── Links ─────────────────────────────────────────────────────────────────────

echo '<p style="font-size:0.82rem;color:#6b7280;margin-top:0.5rem;">'
   . 'Related tools: '
   . '<a href="' . $diag_url->out() . '" style="color:#2563eb;">diag.php</a> &bull; '
   . '<a href="' . $attempt_diag_url->out() . '" style="color:#2563eb;">attempt_diag.php</a> &bull; '
   . '<a href="' . $badge_diag_url->out() . '" style="color:#2563eb;">badge_diag.php</a> &bull; '
   . '<a href="' . $report_url->out() . '" style="color:#2563eb;">report.php</a>'
   . '</p>';

echo '</body></html>';
