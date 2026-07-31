<?php
// EssayGuard — Badge Accuracy & Teacher Report Diagnostic v1.2.119
// Focus: (1) why LOW/MEDIUM/HIGH badges may not match observed student behaviour
//         (copy-paste → HIGH, natural typing with pauses → LOW), and
//        (2) diagnostics for the teacher stats/keystroke-patterns report view.
//
// Access: /plagiarism/essayguard/badge_diag.php?cmid=<cmid>
//         /plagiarism/essayguard/badge_diag.php?cmid=<cmid>&userid=<userid>
//         /plagiarism/essayguard/badge_diag.php?cmid=<cmid>&userid=<userid>&slot=<qslot>
// Requires: site admin or moodle/site:config capability.
// Safe to ship — no data is modified and no external requests are made.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$cmid   = optional_param('cmid',   0, PARAM_INT) ?: optional_param('id', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$focus_slot = optional_param('slot', -1, PARAM_INT); // -1 = all slots

if (!$cmid) {
    throw new \moodle_exception('missingparam', 'error', '', 'cmid or id');
}

$cm = get_coursemodule_from_id(false, $cmid, 0, false, IGNORE_MISSING);
if (!$cm) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>EssayGuard Badge Diag</title></head><body>';
    echo '<p style="color:#c0392b;font-family:sans-serif;margin:2rem;">cmid=' . (int)$cmid . ' is not a valid activity id.</p>';
    echo '</body></html>';
    exit;
}

$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

// Resolve sample user.
$sample_userid = $userid;
if (!$sample_userid) {
    $row = $DB->get_record_sql(
        "SELECT userid FROM {plagiarism_essayguard_sc} WHERE cmid = :cmid ORDER BY timemodified DESC",
        ['cmid' => $cmid], IGNORE_MULTIPLE
    );
    if ($row) {
        $sample_userid = (int)$row->userid;
    }
}

// ── HTML helpers (match diag.php style) ──────────────────────────────────────

function bd_pass(string $label, string $value = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="pass">PASS</td>'
         . '<td class="val">' . htmlspecialchars($value) . '</td></tr>';
}
function bd_fail(string $label, string $detail = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="fail">FAIL</td>'
         . '<td class="val">' . htmlspecialchars($detail) . '</td></tr>';
}
function bd_info(string $label, string $value = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="info">INFO</td>'
         . '<td class="val">' . htmlspecialchars($value) . '</td></tr>';
}
function bd_warn(string $label, string $detail = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="warn">WARN</td>'
         . '<td class="val">' . htmlspecialchars($detail) . '</td></tr>';
}
function bd_head(string $text, int $cols = 3): string {
    return '<tr><td colspan="' . $cols . '" class="section-head">'
         . htmlspecialchars($text) . '</td></tr>';
}
function bd_badge_label(float $score_pct): string {
    if ($score_pct >= 66) return 'HIGH';
    if ($score_pct >= 30) return 'MEDIUM';
    return 'LOW';
}
function bd_badge_css(string $label): string {
    if ($label === 'HIGH')   return 'background:#f8d7da;color:#721c24;font-weight:700;padding:1px 6px;border-radius:3px;';
    if ($label === 'MEDIUM') return 'background:#fff3cd;color:#856404;font-weight:700;padding:1px 6px;border-radius:3px;';
    return 'background:#d4edda;color:#155724;font-weight:700;padding:1px 6px;border-radius:3px;';
}
function bd_badge_span(float $pct): string {
    $lbl = bd_badge_label($pct);
    return '<span style="' . bd_badge_css($lbl) . '">' . $lbl . ' ' . $pct . '%</span>';
}

// Signal names (same as diag.php Section 6).
$signal_names = [
    1  => 'S1: Paste / drop events',
    2  => 'S2: Large insert burst (TinyMCE paste proxy)',
    3  => 'S3: Superhuman typing speed (>8 cps)',
    4  => 'S4: No long pauses (robotic cadence)',
    5  => 'S5: Low backspace / correction ratio',
    6  => 'S6: Near-zero session typing time',
    7  => 'S7: Suspicious rhythm / IKI entropy',
    8  => 'S8: Sentence length uniformity (linguistic)',
    9  => 'S9: Low vocabulary diversity (linguistic)',
    10 => 'S10: IKI autocorrelation deviation',
    11 => 'S11: Speed-burst coefficient of variation',
    12 => 'S12: Keystroke-to-character ratio (low)',
];

// ── Per-slot SC records for sample user ──────────────────────────────────────

$latest_by_slot = [];
if ($sample_userid) {
    $all_sc = $DB->get_records_sql(
        "SELECT * FROM {plagiarism_essayguard_sc}
          WHERE userid = :uid AND cmid = :cmid
       ORDER BY qslot ASC, timemodified DESC",
        ['uid' => $sample_userid, 'cmid' => $cmid]
    );
    foreach ($all_sc as $r) {
        $s = (int)$r->qslot;
        if (!isset($latest_by_slot[$s])) {
            $latest_by_slot[$s] = $r;
        }
    }
    ksort($latest_by_slot);
}

// Which slots to show (all or one).
$slots_to_show = ($focus_slot >= 0 && isset($latest_by_slot[$focus_slot]))
    ? [$focus_slot => $latest_by_slot[$focus_slot]]
    : $latest_by_slot;

// ── Raw events for sample user ────────────────────────────────────────────────

$raw_events_by_slot = [];
if ($sample_userid) {
    // FIX-EG-BADGE-DIAG-QSLOT-COL (v1.2.139): plagiarism_essayguard_ev has NO qslot
    // column — qslot is stored inside payloadjson for each event. The previous
    // ORDER BY qslot caused a DB exception that crashed the entire page on load.
    // Fix: sort by eventtime only; extract qslot from decoded payloadjson in PHP.
    $ev_rows = $DB->get_records_sql(
        "SELECT * FROM {plagiarism_essayguard_ev}
          WHERE userid = :uid AND cmid = :cmid
       ORDER BY eventtime ASC",
        ['uid' => $sample_userid, 'cmid' => $cmid]
    );
    foreach ($ev_rows as $ev) {
        $payload = !empty($ev->payloadjson) ? json_decode($ev->payloadjson, true) : [];
        $s = (int)($payload['qslot'] ?? 0);
        $raw_events_by_slot[$s][] = $ev;
    }
}

// ── SECTION 1: Raw event trace ────────────────────────────────────────────────

$rows_trace = '';
$rows_trace .= bd_info('What this shows',
    'Every raw keystroke/paste event stored in plagiarism_essayguard_ev for this student on this activity, '
  . 'ordered by time. Columns: event type | time offset from session start (ms) | '
  . 'inter-event delta (ms) | payload summary. Paste, drop_paste and large_insert events '
  . 'are highlighted — these are the primary HIGH-badge triggers.'
);

if (!$sample_userid) {
    $rows_trace .= bd_info('No student data', 'No submissions found for this activity yet.');
} elseif (empty($raw_events_by_slot)) {
    $rows_trace .= bd_warn('No events in DB',
        'plagiarism_essayguard_ev has no rows for this student. '
      . 'This means tracker.js either never loaded or its Ajax flushes all failed. '
      . 'Score was derived from linguistic signals only (or from a rescore task). '
      . 'Check browser console for [EssayGuard DIAG] flush messages.'
    );
} else {
    foreach ($raw_events_by_slot as $slot => $evs) {
        if ($focus_slot >= 0 && $slot !== $focus_slot) continue;
        $slot_label = $slot === 0 ? 'qslot=0 (aggregate / untagged events)' : "qslot=$slot (per-question)";
        $sc_rec = $latest_by_slot[$slot] ?? null;
        $score_str = $sc_rec ? bd_badge_label(round((float)$sc_rec->riskscore * 100, 1))
                              . ' ' . round((float)$sc_rec->riskscore * 100, 1) . '%' : 'no SC record';

        $rows_trace .= '<tr><td colspan="3" style="background:#1e3a5f;color:#fff;'
                     . 'font-weight:600;padding:0.5rem 0.7rem;">'
                     . htmlspecialchars($slot_label) . ' &mdash; badge: ' . $score_str
                     . ' &mdash; ' . count($evs) . ' events</td></tr>';

        $session_start_ms = (int)$evs[0]->eventtime;
        $prev_time = $session_start_ms;

        // Counters for the slot.
        $paste_count   = 0;
        $large_ins     = 0;
        $keydown_count = 0;
        $bs_count      = 0;
        $pause_count   = 0;

        foreach ($evs as $ev) {
            $t      = (int)$ev->eventtime;
            $offset = $t - $session_start_ms;
            $delta  = $t - $prev_time;
            $prev_time = $t;

            $payload = !empty($ev->payloadjson) ? json_decode($ev->payloadjson, true) : [];

            // Build payload summary.
            $psum = '';
            switch ($ev->eventname) {
                case 'paste':
                    $paste_count++;
                    $il = $payload['insertlen'] ?? '?';
                    $psum = "insertlen={$il}";
                    break;
                case 'drop_paste':
                    $paste_count++;
                    $psum = 'drop-paste event';
                    break;
                case 'large_insert':
                    $large_ins++;
                    $d = $payload['delta'] ?? '?';
                    $psum = "delta={$d} chars";
                    break;
                case 'keydown':
                    $keydown_count++;
                    $ikd = $payload['ikd'] ?? null;
                    $psum = $ikd !== null ? "ikd={$ikd}ms" : '';
                    break;
                case 'backspace':
                    $bs_count++;
                    $ikd = $payload['ikd'] ?? null;
                    $psum = $ikd !== null ? "ikd={$ikd}ms" : '';
                    break;
                case 'wpm_snapshot':
                    $wpm = $payload['wpm'] ?? '?';
                    $psum = "wpm={$wpm}";
                    break;
                case 'input':
                    $ac = $payload['addedchars'] ?? '?';
                    $psum = "addedchars={$ac}";
                    break;
                case 'burst_end':
                    $wc = $payload['wordcount'] ?? '?';
                    $psum = "wordcount={$wc}";
                    break;
                default:
                    foreach ($payload as $k => $v) {
                        $psum .= "{$k}={$v} ";
                    }
                    $psum = trim($psum);
            }

            // Classify pauses > 2000 ms in the keydown stream.
            if (in_array($ev->eventname, ['keydown', 'input']) && $delta >= 2000) {
                $pause_count++;
            }

            // Colour-code suspicious event types.
            $is_paste = in_array($ev->eventname, ['paste', 'drop_paste', 'large_insert']);
            $row_style = $is_paste ? 'background:#fdecea;' : '';

            $rows_trace .= '<tr style="' . $row_style . '">'
                         . '<td class="label" style="font-family:monospace;font-size:0.8rem;">'
                         . ($is_paste ? '<strong>' : '')
                         . htmlspecialchars($ev->eventname)
                         . ($is_paste ? '</strong>' : '')
                         . '</td>'
                         . '<td class="info" style="font-family:monospace;white-space:nowrap;">'
                         . '+' . number_format($offset) . ' ms</td>'
                         . '<td class="val" style="font-family:monospace;">'
                         . 'delta=' . number_format($delta) . 'ms'
                         . ($psum ? ' | ' . htmlspecialchars($psum) : '')
                         . '</td></tr>';
        }

        // Slot event summary row.
        $rows_trace .= '<tr style="background:#eef2ff;">'
                     . '<td class="label" style="font-style:italic;">Slot ' . $slot . ' event summary</td>'
                     . '<td class="info">INFO</td>'
                     . '<td class="val">'
                     . "total=" . count($evs) . " | keydown={$keydown_count} | backspace={$bs_count} | "
                     . "paste/drop={$paste_count} | large_insert={$large_ins} | "
                     . "pauses≥2s={$pause_count}"
                     . '</td></tr>';
    }
}

// ── SECTION 2: Behaviour pattern classifier ───────────────────────────────────
// Looks at the event totals and SC metrics to classify the session archetype,
// then explains exactly why the badge came out as it did.

$rows_pattern = '';
$rows_pattern .= bd_info('What this shows',
    'Classifies each scored slot into a behaviour archetype based on the stored metrics '
  . '(from metricsjson) and the raw event counts. Archetypes: PURE_PASTE, NEAR_PURE_PASTE, '
  . 'MIXED_PASTE, NATURAL_TYPING, FAST_TYPING, ROBOTIC, NO_EVENTS. '
  . 'Also explains, in plain English, why the badge was assigned — covering the exact '
  . 'false-positive cap logic and which signals pushed the score over each threshold.'
);

if (!$sample_userid || empty($slots_to_show)) {
    $rows_pattern .= bd_info('No data', 'No SC records found for this student.');
} else {
    foreach ($slots_to_show as $slot => $r) {
        $score_pct   = round((float)$r->riskscore * 100, 1);
        $badge       = bd_badge_label($score_pct);
        $slot_label  = $slot === 0 ? 'Aggregate (qslot=0)' : "Per-question qslot={$slot}";

        $rows_pattern .= '<tr><td colspan="3" style="background:#2d4a7a;color:#fff;'
                       . 'font-weight:600;padding:0.5rem 0.7rem;">'
                       . htmlspecialchars($slot_label) . ' &mdash; badge: '
                       . htmlspecialchars($badge) . ' ' . $score_pct . '%'
                       . '</td></tr>';

        // Decode stored metrics.
        $m = [];
        if (!empty($r->metricsjson)) {
            $m = json_decode($r->metricsjson, true) ?? [];
        }

        $paste_ev    = (int)($r->paste_events      ?? 0);
        $keystrokes  = (int)($r->total_keystrokes  ?? 0);
        $text_chars  = (int)($m['text_chars']       ?? 0);
        $large_ins   = (int)($m['large_inserts']    ?? 0);
        $pause_count = (int)($r->pause_count        ?? 0);
        $bs_count    = (int)($r->backspace_count    ?? 0);
        $bs_ratio    = (float)($m['backspace_ratio'] ?? ($keystrokes > 0 ? $bs_count / $keystrokes : 0.0));
        $avg_wpm     = (float)($r->average_wpm      ?? 0.0);
        $typing_time = (int)($r->typing_time        ?? 0);
        $cps_val     = isset($m['chars_per_sec'])   ? (float)$m['chars_per_sec'] : null;
        $entropy     = (float)($r->entropy_score    ?? $m['entropy_score'] ?? 0.0);
        $iki_sh      = (float)($m['iki_shannon']    ?? 0.0);
        $ksr         = isset($m['keystroke_ratio']) ? (float)$m['keystroke_ratio'] : null;
        $paste_frac  = isset($m['paste_frac'])      ? (float)$m['paste_frac']      : null;
        $sb          = is_array($m['signal_breakdown'] ?? null) ? $m['signal_breakdown'] : [];
        $s100        = isset($m['score100'])         ? (int)$m['score100']          : null;
        $ling_pts    = (int)($m['linguistic_fallback_pts'] ?? 0);
        $raw_total   = (int)(array_sum($sb)) + $ling_pts;

        // Determine archetype.
        $archetype = 'UNKNOWN';
        if ($keystrokes === 0 && ($paste_ev > 0 || $large_ins > 0)) {
            $archetype = 'PURE_PASTE';
        } elseif ($paste_ev > 0 || $large_ins > 0) {
            if ($ksr !== null && $ksr < 0.25 && $text_chars > 100) {
                $archetype = 'NEAR_PURE_PASTE';
            } elseif ($paste_frac !== null && $paste_frac >= 0.75) {
                $archetype = 'NEAR_PURE_PASTE';
            } else {
                $archetype = 'MIXED_PASTE';
            }
        } elseif (empty($raw_events_by_slot[$slot]) && $keystrokes === 0) {
            $archetype = 'NO_EVENTS';
        } elseif ($cps_val !== null && $cps_val > 8.0) {
            $archetype = 'FAST_TYPING';
        } elseif ($entropy > 0 && $entropy < 0.30) {
            $archetype = 'ROBOTIC';
        } elseif ($pause_count >= 2 && $bs_ratio >= 0.02) {
            $archetype = 'NATURAL_TYPING';
        } else {
            $archetype = 'NATURAL_TYPING';
        }

        // Archetype description.
        $arch_desc = [
            'PURE_PASTE'      => 'All content arrived via paste/drop — no real keystrokes detected.',
            'NEAR_PURE_PASTE' => 'Effectively all-paste: very few keystrokes relative to text length (keystroke ratio < 0.25 or paste fraction ≥ 75%).',
            'MIXED_PASTE'     => 'Student typed some content but also pasted. Signal 1 awards proportional points based on paste fraction.',
            'NATURAL_TYPING'  => 'Appears to be genuine typing: pauses present, backspace corrections present, normal speed.',
            'FAST_TYPING'     => 'Very fast typing (>8 cps) — may be genuine fast typist or AI-streamed content.',
            'ROBOTIC'         => 'Low IKI entropy — keystrokes arrive at suspiciously uniform intervals (AI re-typing pattern).',
            'NO_EVENTS'       => 'No JS events reached the server. Score was derived from linguistic signals only.',
            'UNKNOWN'         => 'Could not classify — insufficient metrics.',
        ];
        $rows_pattern .= bd_info('Behaviour archetype', $archetype . ' — ' . $arch_desc[$archetype]);

        // Key metrics summary.
        $rows_pattern .= bd_info('Stored metrics',
            "keystrokes={$keystrokes} | paste_events={$paste_ev} | large_inserts={$large_ins} | "
          . "text_chars={$text_chars} | pause_count={$pause_count} | backspace_ratio=" . round($bs_ratio, 3)
          . ($avg_wpm > 0 ? " | avg_wpm={$avg_wpm}" : '')
          . ($typing_time > 0 ? " | typing_time=" . round($typing_time / 1000, 1) . "s" : '')
          . ($cps_val !== null ? " | cps=" . round($cps_val, 2) : '')
          . ($ksr !== null ? " | keystroke_ratio=" . round($ksr, 3) : '')
          . ($paste_frac !== null ? " | paste_frac=" . round($paste_frac, 3) : '')
        );

        // Threshold analysis: show distance to each band boundary.
        $dist_to_medium = max(0.0, 30.0 - $score_pct);
        $dist_to_high   = max(0.0, 66.0 - $score_pct);
        $dist_to_low    = max(0.0, $score_pct - 29.0);

        if ($badge === 'LOW') {
            $needed_for_medium = ceil(30.0 - $score_pct);
            $rows_pattern .= bd_info(
                'LOW — distance to MEDIUM',
                "Score {$score_pct}% is " . max(0.0, 30.0 - $score_pct)
              . " pts below the MEDIUM threshold (30%). "
              . "Needs " . $needed_for_medium . " more raw points to become MEDIUM."
            );
        } elseif ($badge === 'MEDIUM') {
            $rows_pattern .= bd_info(
                'MEDIUM — distance to boundaries',
                "Score {$score_pct}% is " . round($score_pct - 30.0, 1)
              . " pts above LOW→MEDIUM boundary (30%) and "
              . round(66.0 - $score_pct, 1) . " pts below MEDIUM→HIGH boundary (66%)."
            );
        } else {
            $rows_pattern .= bd_info(
                'HIGH — above threshold',
                "Score {$score_pct}% exceeds HIGH threshold (66%) by " . round($score_pct - 66.0, 1) . " pts."
            );
        }

        // False-positive cap check.
        if ($s100 !== null && $raw_total > 0 && $s100 < $raw_total) {
            $rows_pattern .= bd_warn(
                'False-positive cap FIRED',
                "Raw signal total = {$raw_total} pts was capped at {$s100} pts. "
              . "Cap fires when: no paste detected AND typing speed ≤ 8 cps AND entropy ≥ 0.30. "
              . "This protects honest fast typists from a MEDIUM badge caused by S4+S5 alone (35 pts)."
            );
        } elseif ($s100 !== null) {
            $rows_pattern .= bd_pass(
                'False-positive cap did NOT fire',
                "Raw total {$raw_total} pts = final {$s100} pts. "
              . "Cap only fires when: no paste AND normal speed (≤8 cps) AND natural entropy (≥0.30). "
              . "At least one of those conditions was not met — badge is unrestricted."
            );
        }

        // Signal breakdown — plain-English reason for each signal.
        if (!empty($sb)) {
            $rows_pattern .= bd_info('Signals that fired', '');
            $signal_explain = [
                1  => ['Copy-paste detected',
                       'paste or large_insert events found in the event log. '
                     . 'Pure/near-pure paste → +60 pts (full award); '
                     . 'Mixed session → +max(30, 60×paste_fraction) pts; '
                     . 'Incidental paste (<5% of text) → +10 pts.'],
                2  => ['Large input burst (TinyMCE paste proxy)',
                       'tracker.js emitted large_insert events (single input delta > 20 chars). '
                     . 'Catches clipboard pastes where the native paste event was intercepted by the editor. '
                     . 'Worth up to +20 pts (8 pts × large_insert count, capped at 20).'],
                3  => ['Superhuman typing speed',
                       'chars-per-second across the session exceeded 8 cps (very fast) or 15 cps (superhuman). '
                     . 'Human peak is ~7–8 cps; >15 cps is effectively impossible without paste or AI streaming. '
                     . 'Awards +15 or +30 pts.'],
                4  => ['No long thinking pauses',
                       'Fewer than 2 pauses longer than 2 seconds for an answer > 100 chars. '
                     . 'Genuine writers pause to recall facts, re-read, and think. '
                     . 'A paste session has zero typing activity → zero pauses. Awards +20 pts.'],
                5  => ['Very low backspace / correction ratio',
                       'Backspace ratio < 2% of total keystrokes. '
                     . 'Genuine writers make typos and fix them (~5–15% correction rate). '
                     . 'A paste session has no keystrokes at all → ratio = 0. Awards +15 pts (< 2%) or +8 pts (< 4%).'],
                6  => ['Near-zero typing time',
                       'typing_time > 0 but < 10 seconds despite > 50 chars added. '
                     . 'Content appeared almost instantly — consistent with paste or programmatic insertion. '
                     . 'Awards +25 pts.'],
                7  => ['Suspiciously smooth keystroke rhythm',
                       'IKI Shannon entropy < 0.35 (TypeShield threshold, requires ≥ 30 IKI samples) '
                     . 'OR SD-based entropy < 0.30 for shorter sessions. '
                     . 'Human typing has highly variable inter-key intervals; robotic/AI re-typing is unnaturally uniform. '
                     . 'Awards +10 pts (< 0.35) or +5 pts (< 0.55).'],
                8  => ['Uniform sentence lengths (linguistic)',
                       'Sentence length variance < 6. Human writing has varied sentence lengths. '
                     . 'AI-generated text tends to produce sentences of similar length. Awards +10 pts.'],
                9  => ['Low vocabulary diversity (linguistic)',
                       'Type-token ratio < 0.30. Repetitive vocabulary typical of AI-generated content. '
                     . 'Awards +5 pts.'],
                10 => ['Abnormal IKI autocorrelation',
                       '|autocorr_lag1 − 0.1| > 0.5 (TypeShield threshold, ≥ 30 IKI samples). '
                     . 'Human lag-1 autocorrelation is ~0.1. Robotic/jittered patterns deviate significantly. '
                     . 'Awards +10 pts (> 0.5 deviation) or +5 pts (> 0.3 deviation).'],
                11 => ['Constant typing speed (low CV)',
                       'Speed-burst coefficient of variation < 0.30 across WPM snapshot windows '
                     . '(requires ≥ 3 snapshots AND session ≥ 30 s). '
                     . 'Human speed varies significantly; AI-streamed or robotic typing has constant rate. '
                     . 'Awards +10 pts (< 0.30 CV) or +5 pts (< 0.50 CV).'],
                12 => ['Low keystroke-to-character ratio',
                       'total_keystrokes / text_chars < 0.50. '
                     . 'Genuine typists produce ~1.4 keystrokes per final character (edits, corrections). '
                     . 'A ratio well below 1.0 means most characters arrived via paste/insertion. '
                     . 'Awards +10 pts (< 0.50) or +5 pts (< 0.80). Requires text_chars > 100.'],
            ];

            foreach ($sb as $sig_num => $pts) {
                $n = (int)$sig_num;
                [$short_name, $long_explain] = $signal_explain[$n] ?? [$signal_names[$n] ?? "Signal $n", ''];
                $rows_pattern .= '<tr>'
                               . '<td class="label" style="padding-left:1.5rem;">'
                               . htmlspecialchars("S{$n}: {$short_name} (+{$pts} pts)") . '</td>'
                               . '<td class="pass" style="color:#1a7a3f;">FIRED</td>'
                               . '<td class="val">' . htmlspecialchars($long_explain) . '</td></tr>';
            }

            if ($ling_pts > 0) {
                $rows_pattern .= '<tr>'
                               . '<td class="label" style="padding-left:1.5rem;">Linguistic fallback (+' . $ling_pts . ' pts)</td>'
                               . '<td class="info">INFO</td>'
                               . '<td class="val">No behavioural events available — linguistic signals used as fallback proxy. '
                               . 'Up to 35 extra pts (S8×20 + S9×15 inflated weights) to push AI-uniform text to MEDIUM.</td></tr>';
            }

            // Signals that did NOT fire — briefly note why.
            $all_sigs_fired = array_map('intval', array_keys($sb));
            $did_not_fire = array_diff([1,2,3,4,5,7,10,11,12], $all_sigs_fired);
            if (!empty($did_not_fire)) {
                $rows_pattern .= bd_info('Signals that did NOT fire',
                    'S' . implode(', S', $did_not_fire) . ' — either thresholds not met or insufficient data (< 30 IKI samples for S7/S10, < 3 WPM snapshots for S11, no paste for S1/S2).'
                );
            }
        } elseif ($s100 !== null) {
            $rows_pattern .= bd_warn('signal_breakdown missing',
                'Session was scored before v1.2.113. The per-signal breakdown is not stored. '
              . 'Re-submit the attempt to populate signal_breakdown in metricsjson.'
            );
        }

        // Plain-English verdict.
        $verdict = '';
        if ($archetype === 'PURE_PASTE' || $archetype === 'NEAR_PURE_PASTE') {
            $verdict = "The student copy-pasted all or nearly all of their answer. "
                     . "Signal 1 (paste detection) contributed 60 pts — the primary HIGH trigger. "
                     . "Signals 4 (no pauses), 5 (no corrections), and 7 (no rhythm data) added a further "
                     . (isset($sb[4]) ? $sb[4] : 0) + (isset($sb[5]) ? $sb[5] : 0) + (isset($sb[7]) ? $sb[7] : 0)
                     . " pts, pushing the final score firmly into HIGH.";
        } elseif ($archetype === 'MIXED_PASTE') {
            $verdict = "The student typed part of the answer and pasted the rest. "
                     . "Signal 1 awards points proportional to the paste fraction, "
                     . "guaranteeing at least MEDIUM (30 pts floor) for any meaningful paste. "
                     . "The false-positive cap does NOT apply because paste was detected.";
        } elseif ($archetype === 'NATURAL_TYPING') {
            $verdict = "The event pattern is consistent with genuine typing: "
                     . "pauses present ({$pause_count} × ≥2 s), backspace corrections present (ratio=" . round($bs_ratio, 3) . "). "
                     . "No paste detected. The false-positive cap fires when no paste AND normal speed AND natural entropy, "
                     . "protecting honest fast typists from a false MEDIUM caused by S4+S5 alone (35 pts).";
        } elseif ($archetype === 'FAST_TYPING') {
            $verdict = "Typing speed exceeds 8 cps. This triggers Signal 3 (+15 or +30 pts) and bypasses the false-positive cap. "
                     . "Could be a legitimate fast typist or AI-streamed content without a paste event. "
                     . "Check whether the student has prior attempts at this speed (baseline comparison).";
        } elseif ($archetype === 'ROBOTIC') {
            $verdict = "Keystroke intervals are suspiciously uniform (entropy < 0.30). "
                     . "This pattern matches AI re-typing tools that replay content with artificial jitter. "
                     . "Signal 7 fires, and the false-positive cap does NOT apply (entropy condition fails).";
        } elseif ($archetype === 'NO_EVENTS') {
            $verdict = "No tracker.js events reached the server. "
                     . "Score was derived entirely from linguistic signals (sentence variance, vocab diversity). "
                     . "Linguistic fallback can push AI-uniform text to MEDIUM (up to 35 pts) but cannot reach HIGH. "
                     . "The false-positive cap does NOT apply when events are absent.";
        }
        if ($verdict) {
            $rows_pattern .= bd_info('Plain-English verdict', $verdict);
        }
    }
}

// ── SECTION 3: Signal threshold reference ─────────────────────────────────────
// For the selected slot, shows each signal's actual metric vs its threshold.

$rows_thresholds = '';
$rows_thresholds .= bd_info('What this shows',
    'For each of the 12 scoring signals, shows the exact threshold criteria '
  . 'and the actual stored metric value, so you can see exactly how close or far '
  . 'the student was from triggering each signal. Uses stored metricsjson values.'
);

if (!$sample_userid || empty($slots_to_show)) {
    $rows_thresholds .= bd_info('No data', 'No SC records.');
} else {
    foreach ($slots_to_show as $slot => $r) {
        $score_pct  = round((float)$r->riskscore * 100, 1);
        $slot_label = $slot === 0 ? 'Aggregate (qslot=0)' : "Per-question qslot={$slot}";

        $rows_thresholds .= '<tr><td colspan="3" style="background:#3a5a3a;color:#fff;'
                          . 'font-weight:600;padding:0.5rem 0.7rem;">'
                          . htmlspecialchars($slot_label) . ' &mdash; '
                          . bd_badge_label($score_pct) . ' ' . $score_pct . '%'
                          . '</td></tr>';

        $m = [];
        if (!empty($r->metricsjson)) {
            $m = json_decode($r->metricsjson, true) ?? [];
        }

        $paste_ev   = (int)($r->paste_events     ?? 0);
        $keystrokes = (int)($r->total_keystrokes ?? 0);
        $text_chars = (int)($m['text_chars']      ?? 0);
        $large_ins  = (int)($m['large_inserts']   ?? 0);
        $pause_cnt  = (int)($r->pause_count       ?? 0);
        $bs_count   = (int)($r->backspace_count   ?? 0);
        $bs_ratio   = (float)($m['backspace_ratio'] ?? 0.0);
        $typing_ms  = (int)($r->typing_time       ?? 0);
        $entropy    = (float)($r->entropy_score   ?? $m['entropy_score'] ?? 0.0);
        $iki_sh     = (float)($m['iki_shannon']   ?? 0.0);
        $iki_ac     = (float)($m['iki_autocorr']  ?? 0.0);
        $speed_cv   = (float)($m['speed_burst_cv'] ?? 1.0);
        $cps_val    = isset($m['chars_per_sec'])  ? (float)$m['chars_per_sec'] : null;
        $charsadded = (int)($m['charsadded']      ?? 0);
        $ksr        = isset($m['keystroke_ratio']) ? (float)$m['keystroke_ratio'] : null;
        $paste_frac = isset($m['paste_frac'])      ? (float)$m['paste_frac']      : null;
        $wpm_snaps  = (int)($m['burst_count']     ?? 0);  // rough proxy
        $ikd_count  = (int)($m['total_keystrokes'] ?? 0); // IKI samples ≈ keystrokes
        $sent_var   = (float)($r->sentence_variance ?? $m['sentence_variance'] ?? 0.0);
        $vocab_div  = (float)($r->vocab_diversity   ?? $m['vocab_diversity']   ?? 0.0);

        // S1.
        $s1_actual = $paste_ev > 0 ? "paste_events={$paste_ev}" : ($large_ins > 0 ? "large_inserts={$large_ins}" : "none");
        $s1_fires  = $paste_ev > 0 || $large_ins > 0;
        $rows_thresholds .= $s1_fires
            ? bd_pass('S1 threshold: paste_events > 0 OR large_inserts > 0', "FIRES — actual: {$s1_actual}")
            : bd_info('S1 threshold: paste_events > 0 OR large_inserts > 0', "SILENT — actual: {$s1_actual}");

        // S2.
        $s2_fires = $large_ins > 0;
        $rows_thresholds .= $s2_fires
            ? bd_pass('S2 threshold: large_inserts > 0', "FIRES — large_inserts={$large_ins}, pts=min(20, large_inserts×8)=" . min(20, $large_ins * 8))
            : bd_info('S2 threshold: large_inserts > 0', "SILENT — no large_insert events");

        // S3.
        if ($cps_val !== null) {
            if ($cps_val > 15.0 && $charsadded > 50) {
                $rows_thresholds .= bd_pass('S3 threshold: cps > 15 (superhuman)', "FIRES +30 — actual cps=" . round($cps_val, 2));
            } elseif ($cps_val > 8.0 && $charsadded > 50) {
                $rows_thresholds .= bd_warn('S3 threshold: cps > 8 (very fast)', "FIRES +15 — actual cps=" . round($cps_val, 2));
            } else {
                $rows_thresholds .= bd_info('S3 threshold: cps > 8 (very fast)', "SILENT — actual cps=" . round($cps_val, 2) . " (threshold: 8.0)");
            }
        } else {
            $rows_thresholds .= bd_info('S3 threshold: cps > 8', 'chars_per_sec not in metricsjson (session pre-v1.2.113)');
        }

        // S4.
        $s4_chars = max($charsadded, $text_chars);
        $s4_fires = $pause_cnt < 2 && $s4_chars > 100;
        $rows_thresholds .= $s4_fires
            ? bd_warn('S4 threshold: pause_count < 2 AND chars > 100', "FIRES +20 — actual pause_count={$pause_cnt}, chars={$s4_chars}")
            : bd_pass('S4 threshold: pause_count < 2 AND chars > 100', "SILENT — actual pause_count={$pause_cnt}, chars={$s4_chars} (need pause_count≥2 or chars≤100 to be clear)");

        // S5.
        if ($keystrokes === 0 && ($paste_ev > 0 || $large_ins > 0)) {
            $rows_thresholds .= bd_warn('S5 threshold: pure paste → no corrections', "FIRES +15 — zero keystrokes, pure paste session");
        } elseif ($keystrokes > 0) {
            if ($bs_ratio < 0.02) {
                $rows_thresholds .= bd_warn('S5 threshold: backspace_ratio < 0.02', "FIRES +15 — actual ratio=" . round($bs_ratio, 4));
            } elseif ($bs_ratio < 0.04) {
                $rows_thresholds .= bd_warn('S5 threshold: backspace_ratio < 0.04', "FIRES +8 — actual ratio=" . round($bs_ratio, 4));
            } else {
                $rows_thresholds .= bd_pass('S5 threshold: backspace_ratio < 0.04', "SILENT — actual ratio=" . round($bs_ratio, 4) . " ≥ 0.04 (normal correction rate)");
            }
        } else {
            $rows_thresholds .= bd_info('S5 threshold', 'No keystrokes and no paste detected — signal not evaluated');
        }

        // S6.
        $s6_fires = $typing_ms > 0 && $typing_ms < 10000 && $charsadded > 50;
        $rows_thresholds .= $s6_fires
            ? bd_warn('S6 threshold: 0 < typing_time < 10 s AND charsadded > 50',
                      "FIRES +25 — typing_time=" . round($typing_ms / 1000, 1) . "s, charsadded={$charsadded}")
            : bd_pass('S6 threshold: 0 < typing_time < 10 s AND charsadded > 50',
                      "SILENT — typing_time=" . round($typing_ms / 1000, 1) . "s (need < 10s and charsadded > 50)");

        // S7.
        if ($iki_sh > 0 && $ikd_count >= 30) {
            if ($iki_sh < 0.35) {
                $rows_thresholds .= bd_warn('S7 threshold: IKI Shannon entropy < 0.35 (≥30 samples)',
                                            "FIRES +10 — actual iki_shannon=" . round($iki_sh, 4));
            } elseif ($iki_sh < 0.55) {
                $rows_thresholds .= bd_warn('S7 threshold: IKI Shannon entropy < 0.55',
                                            "FIRES +5 — actual iki_shannon=" . round($iki_sh, 4));
            } else {
                $rows_thresholds .= bd_pass('S7 threshold: IKI Shannon entropy < 0.35',
                                            "SILENT — actual iki_shannon=" . round($iki_sh, 4) . " ≥ 0.55 (natural rhythm)");
            }
        } elseif ($entropy > 0) {
            if ($entropy < 0.30) {
                $rows_thresholds .= bd_warn('S7 threshold: SD-based entropy < 0.30 (fallback)',
                                            "FIRES +10 — actual entropy=" . round($entropy, 4));
            } elseif ($entropy < 0.50) {
                $rows_thresholds .= bd_warn('S7 threshold: SD-based entropy < 0.50 (fallback)',
                                            "FIRES +5 — actual entropy=" . round($entropy, 4));
            } else {
                $rows_thresholds .= bd_pass('S7 threshold: SD-based entropy < 0.30 (fallback)',
                                            "SILENT — actual entropy=" . round($entropy, 4) . " ≥ 0.50 (natural variation)");
            }
        } else {
            $rows_thresholds .= bd_info('S7 threshold', 'Entropy data unavailable (< 30 IKI samples or no keystrokes)');
        }

        // S8.
        if ($sent_var > 0) {
            if ($sent_var < 6) {
                $rows_thresholds .= bd_warn('S8 threshold: sentence_variance < 6 (uniform)',
                                            "FIRES +10 — actual sentence_variance=" . round($sent_var, 2));
            } elseif ($sent_var < 12) {
                $rows_thresholds .= bd_warn('S8 threshold: sentence_variance < 12',
                                            "FIRES +5 — actual sentence_variance=" . round($sent_var, 2));
            } else {
                $rows_thresholds .= bd_pass('S8 threshold: sentence_variance < 6',
                                            "SILENT — sentence_variance=" . round($sent_var, 2) . " ≥ 12 (varied sentence lengths)");
            }
        } else {
            $rows_thresholds .= bd_info('S8 threshold', 'sentence_variance = 0 (not computed or short answer)');
        }

        // S9.
        if ($vocab_div > 0) {
            if ($vocab_div < 0.30) {
                $rows_thresholds .= bd_warn('S9 threshold: vocab_diversity < 0.30',
                                            "FIRES +5 — actual=" . round($vocab_div, 3));
            } elseif ($vocab_div < 0.40) {
                $rows_thresholds .= bd_warn('S9 threshold: vocab_diversity < 0.40',
                                            "FIRES +2 — actual=" . round($vocab_div, 3));
            } else {
                $rows_thresholds .= bd_pass('S9 threshold: vocab_diversity < 0.30',
                                            "SILENT — actual=" . round($vocab_div, 3) . " ≥ 0.40 (good vocabulary spread)");
            }
        } else {
            $rows_thresholds .= bd_info('S9 threshold', 'vocab_diversity = 0 (not computed or short answer)');
        }

        // S10.
        if ($iki_ac != 0 && $ikd_count >= 30) {
            $dev = abs($iki_ac - 0.1);
            if ($dev > 0.5) {
                $rows_thresholds .= bd_warn('S10 threshold: |iki_autocorr − 0.1| > 0.5',
                                            "FIRES +10 — actual autocorr=" . round($iki_ac, 4) . ", deviation=" . round($dev, 4));
            } elseif ($dev > 0.3) {
                $rows_thresholds .= bd_warn('S10 threshold: |iki_autocorr − 0.1| > 0.3',
                                            "FIRES +5 — actual autocorr=" . round($iki_ac, 4) . ", deviation=" . round($dev, 4));
            } else {
                $rows_thresholds .= bd_pass('S10 threshold: |iki_autocorr − 0.1| > 0.5',
                                            "SILENT — autocorr=" . round($iki_ac, 4) . " near human baseline (0.1)");
            }
        } else {
            $rows_thresholds .= bd_info('S10 threshold', 'Not evaluated — requires ≥ 30 IKI samples (actual samples ≈ ' . $ikd_count . ')');
        }

        // S11.
        if ($wpm_snaps >= 3 && $typing_ms >= 30000) {
            if ($speed_cv < 0.30) {
                $rows_thresholds .= bd_warn('S11 threshold: speed_burst_cv < 0.30',
                                            "FIRES +10 — actual CV=" . round($speed_cv, 4) . " (constant rate — robotic)");
            } elseif ($speed_cv < 0.50) {
                $rows_thresholds .= bd_warn('S11 threshold: speed_burst_cv < 0.50',
                                            "FIRES +5 — actual CV=" . round($speed_cv, 4));
            } else {
                $rows_thresholds .= bd_pass('S11 threshold: speed_burst_cv < 0.30',
                                            "SILENT — CV=" . round($speed_cv, 4) . " ≥ 0.50 (variable speed — human pattern)");
            }
        } else {
            $rows_thresholds .= bd_info('S11 threshold',
                'Not evaluated — requires ≥ 3 WPM snapshots AND typing_time ≥ 30 s '
              . '(actual: burst_count≈' . $wpm_snaps . ', typing_time=' . round($typing_ms / 1000, 1) . 's)');
        }

        // S12.
        if ($ksr !== null && $text_chars > 100 && $keystrokes > 0) {
            if ($ksr < 0.50) {
                $rows_thresholds .= bd_warn('S12 threshold: keystroke_ratio < 0.50',
                                            "FIRES +10 — actual ratio=" . round($ksr, 4)
                                          . " ({$keystrokes} keystrokes / {$text_chars} chars). Human baseline ≈ 1.4.");
            } elseif ($ksr < 0.80) {
                $rows_thresholds .= bd_warn('S12 threshold: keystroke_ratio < 0.80',
                                            "FIRES +5 — actual ratio=" . round($ksr, 4));
            } else {
                $rows_thresholds .= bd_pass('S12 threshold: keystroke_ratio < 0.50',
                                            "SILENT — ratio=" . round($ksr, 4) . " ≥ 0.80 (sufficient keystrokes for text length)");
            }
        } elseif ($text_chars > 100 && $keystrokes > 0) {
            $computed_ksr = round($keystrokes / max(1, $text_chars), 4);
            if ($computed_ksr < 0.50) {
                $rows_thresholds .= bd_warn('S12 threshold (computed): keystroke_ratio < 0.50',
                                            "FIRES +10 — computed ratio={$computed_ksr} ({$keystrokes}/{$text_chars}). "
                                          . "keystroke_ratio not stored — session pre-v1.2.113.");
            } else {
                $rows_thresholds .= bd_info('S12 threshold (computed)',
                                            "computed ratio={$computed_ksr} ≥ 0.50 — would not fire. "
                                          . "keystroke_ratio not stored — session pre-v1.2.113.");
            }
        } else {
            $rows_thresholds .= bd_info('S12 threshold', "Not evaluated — text_chars={$text_chars} (need > 100) or no keystrokes");
        }

        // False-positive cap summary row.
        $rows_thresholds .= bd_head('False-positive cap gate (no-paste + normal-speed + natural-entropy → cap score at 29)');
        $no_paste     = $paste_ev === 0 && $large_ins === 0;
        $normal_speed = $cps_val === null || $cps_val <= 8.0;
        $nat_entropy  = $entropy === 0.0 || $entropy >= 0.30;
        $cap_fires    = $no_paste && $normal_speed && $nat_entropy;
        $rows_thresholds .= $cap_fires
            ? bd_warn('Cap status',
                "ACTIVE — paste_events={$paste_ev}, large_inserts={$large_ins}, "
              . "cps=" . ($cps_val !== null ? round($cps_val, 2) : 'n/a') . ", entropy=" . round($entropy, 3)
              . ". Score capped at 29 (LOW) to protect honest fast typists.")
            : bd_pass('Cap status',
                "NOT ACTIVE — at least one bypass condition met: "
              . ($no_paste ? '' : "paste detected | ")
              . ($normal_speed ? '' : "speed > 8 cps | ")
              . ($nat_entropy ? '' : "entropy < 0.30 (robotic)")
              . ". Badge is unrestricted.");
    }
}

// ── SECTION 4: Teacher report data path ──────────────────────────────────────
// Shows exactly what report.php would display for every student in this cmid.

$rows_report = '';
$rows_report .= bd_info('What this shows',
    'Reproduces the exact data that report.php shows in the teacher stats view for every '
  . 'student who has submitted to this activity. For each student: headline aggregate badge, '
  . 'per-question breakdown rows, fallback logic, and any anomalies (badge inconsistent with '
  . 'raw event data, missing per-question records, score-clobber risk).'
);

// Gather all students with SC records for this cmid.
$all_students_sc = $DB->get_records_sql(
    "SELECT DISTINCT userid FROM {plagiarism_essayguard_sc} WHERE cmid = :cmid ORDER BY userid ASC",
    ['cmid' => $cmid]
);

if (empty($all_students_sc)) {
    $rows_report .= bd_info('No submissions', 'No SC records exist for this activity yet.');
} else {
    $rows_report .= bd_info('Students with SC records', count($all_students_sc) . ' student(s) found');

    foreach ($all_students_sc as $su) {
        $uid = (int)$su->userid;
        $u   = $DB->get_record('user', ['id' => $uid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename', IGNORE_MISSING);
        $uname = $u ? fullname($u) . " (id={$uid})" : "userid={$uid}";

        // Get all SC records for this user in this cm.
        $sc_rows = $DB->get_records_sql(
            "SELECT * FROM {plagiarism_essayguard_sc}
              WHERE userid = :uid AND cmid = :cmid
           ORDER BY qslot ASC, timemodified DESC",
            ['uid' => $uid, 'cmid' => $cmid]
        );

        // Build latest-by-slot map (same logic as report.php).
        $lbs = [];
        foreach ($sc_rows as $sc) {
            $s = (int)$sc->qslot;
            if (!isset($lbs[$s])) {
                $lbs[$s] = $sc;
            }
        }
        ksort($lbs);

        $agg_r   = $lbs[0] ?? null;
        $pq_keys = array_filter(array_keys($lbs), fn($k) => $k > 0);

        // Aggregate paste gate — matches lib.php FIX-EG-AGG-PASTE-MEANINGFUL (v1.2.143).
        // Previously used paste_events > 0, but any incidental autocorrect paste (Signal 1
        // = 10 pts) triggered the fallback. Now requires Signal 1 >= 30 pts (meaningful
        // paste covering >= 5% of the answer text) OR riskscore >= 0.70 (HIGH threshold).
        $agg_m_arr       = !empty($agg_r->metricsjson)
            ? (json_decode($agg_r->metricsjson, true) ?: []) : [];
        $agg_signal1_pts = (int)(($agg_m_arr['signal_breakdown'][1] ?? 0));
        $agg_has_paste   = $agg_r
            && ($agg_signal1_pts >= 30 || (float)$agg_r->riskscore >= 0.70);

        $rows_report .= '<tr><td colspan="3" style="background:#4a3060;color:#fff;'
                      . 'font-weight:600;padding:0.5rem 0.7rem;">'
                      . htmlspecialchars($uname) . '</td></tr>';

        // Headline: aggregate row.
        if ($agg_r) {
            $agg_pct  = round((float)$agg_r->riskscore * 100, 1);
            $agg_band = bd_badge_label($agg_pct);
            $agg_pe   = (int)($agg_r->paste_events ?? 0);
            $rows_report .= '<tr>'
                          . '<td class="label">Aggregate (qslot=0) — teacher headline badge</td>'
                          . '<td class="info">INFO</td>'
                          . '<td class="val"><strong>'
                          . htmlspecialchars($agg_band) . ' ' . $agg_pct . '%</strong>'
                          . ' | paste_events=' . $agg_pe
                          . ' | paste gate: ' . ($agg_has_paste ? 'YES (will override 0-score per-Q rows)' : 'NO')
                          . '</td></tr>';
        } else {
            $rows_report .= bd_warn('Aggregate record (qslot=0) missing',
                'No aggregate SC record. Teacher report will show no headline badge for this student. '
              . 'The PHP observer should always write qslot=0 — check if plagiarism_essayguard_observer is firing.');
        }

        if (empty($pq_keys)) {
            $rows_report .= bd_info('No per-question records',
                'Only aggregate record exists. Teacher report shows aggregate score for all questions. '
              . 'This is normal for assignment submissions (single question). '
              . 'For quizzes: if per-question records are missing, qslot detection failed — '
              . 'see Section 2 of the main diag.php for the similarity threshold check.'
            );
        } else {
            // Per-question rows.
            foreach ($pq_keys as $slot) {
                $pq = $lbs[$slot];
                $pq_pct = round((float)$pq->riskscore * 100, 1);

                // Simulate lib.php record selection — matches FIX-EG-MISSED-PERQ-PASTE (v1.2.132).
                // Fall back to aggregate when per-Q riskscore is 0 OR (< 0.30 AND no paste
                // AND keystrokes ≤ 10 — the "missed-paste" signature), AND aggregate has
                // meaningful paste evidence (Signal 1 >= 30 pts OR riskscore >= 0.70).
                $show_r       = $pq;
                $fallback     = false;
                $pq_ks_sim    = (int)($pq->total_keystrokes ?? 0);
                $pq_pe_sim    = (int)($pq->paste_events ?? 0);
                $pq_missed    = (
                    (float)$pq->riskscore <= 0.0
                    || (
                        (float)$pq->riskscore < 0.30
                        && $pq_pe_sim === 0
                        && $pq_ks_sim <= 10
                    )
                );
                if ($pq_missed && $agg_r && $agg_has_paste) {
                    $show_r   = $agg_r;
                    $fallback = true;
                }
                $show_pct  = round((float)$show_r->riskscore * 100, 1);
                $show_band = bd_badge_label($show_pct);

                $status = 'INFO';
                $detail = "{$show_band} {$show_pct}% "
                        . ($fallback ? '(FALLBACK from aggregate — per-Q score=0 + agg has paste evidence)' : '(per-question score)');

                // Anomaly checks.
                $pq_pe = (int)($pq->paste_events ?? 0);
                $pq_ks = (int)($pq->total_keystrokes ?? 0);
                $pq_m  = !empty($pq->metricsjson) ? (json_decode($pq->metricsjson, true) ?? []) : [];
                $pq_li = (int)($pq_m['large_inserts'] ?? 0);

                $anomaly = '';
                if ($show_band === 'LOW' && ($pq_pe > 0 || $pq_li > 0)) {
                    $anomaly = 'ANOMALY: badge=LOW but paste events exist on this slot — score may have been clobbered (FIX-EG-SCORE-NO-CLOBBER) or per-question re-score pending.';
                    $status = 'FAIL';
                } elseif ($show_band === 'MEDIUM' && $pq_ks === 0 && ($pq_pe > 0 || $pq_li > 0)) {
                    $anomaly = 'ANOMALY: badge=MEDIUM but session is pure-paste (keystrokes=0, paste evidence present). Score may not have reached HIGH. Check Signal 1 in signal_breakdown.';
                    $status = 'WARN';
                }

                $rows_report .= '<tr>'
                              . '<td class="label" style="padding-left:1.5rem;">Slot ' . $slot . '</td>'
                              . '<td class="' . strtolower($status) . '">' . $status . '</td>'
                              . '<td class="val">' . htmlspecialchars($detail . ($anomaly ? ' | ' . $anomaly : '')) . '</td></tr>';
            }
        }

        // Event count for this user.
        $ev_count = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {plagiarism_essayguard_ev} WHERE userid = :uid AND cmid = :cmid",
            ['uid' => $uid, 'cmid' => $cmid]
        );
        $rows_report .= '<tr style="border-top:1px solid #e0e0e0;">'
                      . '<td class="label" style="color:#888;font-style:italic;">Raw event rows (ev table)</td>'
                      . '<td class="info">INFO</td>'
                      . '<td class="val">' . $ev_count . ' events stored'
                      . ($ev_count === 0 ? ' — tracker.js events never reached the server; scores derived from linguistic signals only' : '')
                      . '</td></tr>';
    }
}

// ── SECTION 5: Keystroke pattern timeline ────────────────────────────────────
// Visual timeline of event types for the sample student to help teachers see
// the session structure at a glance.

$rows_timeline = '';
$rows_timeline .= bd_info('What this shows',
    'A compact timeline of the sample student\'s session for each scored slot. '
  . 'Events are grouped into 5-second windows. Each window shows the dominant activity type. '
  . 'Symbols: . = keystrokes | P = paste/large_insert | _ = pause (>2s gap) | W = WPM snapshot | B = backspace burst.'
);

if (!$sample_userid || empty($raw_events_by_slot)) {
    $rows_timeline .= bd_info('No event data', 'No raw events in plagiarism_essayguard_ev for this student.');
} else {
    foreach ($raw_events_by_slot as $slot => $evs) {
        if ($focus_slot >= 0 && $slot !== $focus_slot) continue;
        if (empty($evs)) continue;

        $slot_label = $slot === 0 ? 'Aggregate / untagged' : "qslot={$slot}";

        $session_start = (int)$evs[0]->eventtime;
        $session_end   = (int)end($evs)->eventtime;
        $duration_s    = max(1, ($session_end - $session_start) / 1000);

        $window_s    = 5;
        $num_windows = max(1, (int)ceil($duration_s / $window_s));
        $windows     = array_fill(0, $num_windows, []);

        $prev_time = $session_start;
        foreach ($evs as $ev) {
            $t       = (int)$ev->eventtime;
            $win_idx = min($num_windows - 1, (int)floor(($t - $session_start) / 1000 / $window_s));
            $delta   = $t - $prev_time;
            $prev_time = $t;

            $sym = '.';
            if (in_array($ev->eventname, ['paste', 'drop_paste', 'large_insert'])) {
                $sym = 'P';
            } elseif ($ev->eventname === 'wpm_snapshot') {
                $sym = 'W';
            } elseif ($ev->eventname === 'backspace') {
                $sym = 'b';
            } elseif ($delta >= 2000) {
                $sym = '_';
            }
            $windows[$win_idx][] = $sym;
        }

        // Build timeline string.
        $timeline = '';
        foreach ($windows as $w) {
            if (empty($w)) {
                $timeline .= ' ';
                continue;
            }
            // Dominant symbol.
            $counts = array_count_values($w);
            arsort($counts);
            $dom = key($counts);
            $timeline .= $dom;
        }

        // Count paste windows.
        $paste_windows = substr_count($timeline, 'P');
        $pause_windows = substr_count($timeline, '_');

        $rows_timeline .= '<tr><td colspan="3" style="background:#5a4040;color:#fff;'
                        . 'font-weight:600;padding:0.5rem 0.7rem;">'
                        . htmlspecialchars($slot_label) . ' — '
                        . round($duration_s, 0) . 's session | '
                        . count($evs) . ' events | '
                        . $num_windows . ' × ' . $window_s . 's windows'
                        . '</td></tr>';

        $rows_timeline .= '<tr>'
                        . '<td class="label">Timeline (each char = ' . $window_s . 's window)</td>'
                        . '<td class="info">INFO</td>'
                        . '<td class="val" style="font-family:monospace;letter-spacing:1px;word-break:break-all;">'
                        . htmlspecialchars($timeline)
                        . '</td></tr>';

        $rows_timeline .= bd_info(
            'Legend',
            '. = keystrokes  |  P = paste/large_insert  |  _ = pause ≥2s  |  b = backspace  |  W = WPM snapshot  |  (space) = no events'
        );

        $rows_timeline .= bd_info(
            'Session shape',
            "Paste windows: {$paste_windows}/{$num_windows} | Pause windows: {$pause_windows}/{$num_windows} | "
          . "Duration: " . round($duration_s, 0) . "s | Total events: " . count($evs)
        );

        // Quick interpretation.
        if ($paste_windows > 0 && $pause_windows < 2) {
            $rows_timeline .= bd_warn('Shape interpretation',
                'Paste event(s) present, very few/no thinking pauses. '
              . 'Consistent with copy-paste behaviour → HIGH badge expected.'
            );
        } elseif ($paste_windows === 0 && $pause_windows >= 2) {
            $rows_timeline .= bd_pass('Shape interpretation',
                'No paste events detected. Multiple thinking pauses present. '
              . 'Consistent with genuine writing → LOW badge expected. '
              . 'If badge is MEDIUM, check whether S3 (typing speed) or S7 (entropy) fired — '
              . 'or check if the false-positive cap was suppressed.'
            );
        } elseif ($paste_windows > 0 && $pause_windows >= 2) {
            $rows_timeline .= bd_info('Shape interpretation',
                'Paste events present AND thinking pauses present — likely a mixed session. '
              . 'Signal 1 awards proportional points. Badge likely MEDIUM or HIGH depending on paste fraction.'
            );
        } else {
            $rows_timeline .= bd_info('Shape interpretation',
                'Insufficient data for a clear interpretation. Check signal breakdown in Section 2 above.'
            );
        }
    }
}

// ── Page output ───────────────────────────────────────────────────────────────

$sample_user_obj = $sample_userid
    ? $DB->get_record('user', ['id' => $sample_userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename', IGNORE_MISSING)
    : null;
$sample_name = $sample_user_obj
    ? fullname($sample_user_obj) . ' (id=' . $sample_userid . ')'
    : 'none found';

// Gather all students for the switcher.
$switcher_students = $DB->get_records_sql(
    "SELECT DISTINCT userid FROM {plagiarism_essayguard_sc} WHERE cmid = :cmid ORDER BY userid DESC",
    ['cmid' => $cmid]
);

echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<title>EssayGuard Badge Diag — cmid ' . $cmid . '</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; background: #f0f0f5; color: #111; }
  h1 { font-size: 1.3rem; margin-bottom: 0.2rem; }
  h2 { font-size: 1rem; margin: 1.6rem 0 0.5rem; background: #1a1a2e; color: #fff; padding: 0.4rem 0.8rem; border-radius: 4px; }
  table { width: 100%; border-collapse: collapse; background: #fff; margin-bottom: 1rem; border-radius: 4px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
  td { padding: 0.45rem 0.7rem; font-size: 0.84rem; border-bottom: 1px solid #eee; vertical-align: top; }
  td.label { width: 40%; font-weight: 500; }
  td.pass  { width: 6%; color: #1a7a3f; font-weight: 700; white-space: nowrap; }
  td.fail  { width: 6%; color: #c0392b; font-weight: 700; white-space: nowrap; }
  td.info  { width: 6%; color: #7a6000; font-weight: 700; white-space: nowrap; }
  td.warn  { width: 6%; color: #a05000; font-weight: 700; white-space: nowrap; }
  td.val   { color: #444; font-size: 0.82rem; word-break: break-word; }
  td.section-head { background: #e8eaf6; font-weight: 600; padding: 0.4rem 0.8rem; font-size: 0.85rem; border-top: 2px solid #9fa8da; }
  .meta { font-size: 0.8rem; color: #555; margin-bottom: 0.5rem; }
  .pill-box { font-size: 0.8rem; background: #fff; border: 1px solid #ddd; padding: 0.5rem 0.8rem; border-radius: 4px; margin-bottom: 1rem; display: inline-block; }
  a { color: #0070f3; }
  .intro-box { background: #fff3e0; border: 1px solid #ffcc80; border-radius: 6px; padding: 0.8rem 1rem; margin-bottom: 1.2rem; font-size: 0.85rem; line-height: 1.6; }
</style></head><body>';

echo '<h1>EssayGuard — Badge Accuracy &amp; Teacher Report Diagnostic</h1>';
echo '<p class="meta">';
echo 'Activity: <strong>' . htmlspecialchars($cm->name) . '</strong> &nbsp;|&nbsp; ';
echo 'cmid: <strong>' . $cmid . '</strong> &nbsp;|&nbsp; ';
echo 'Module: <strong>' . $cm->modname . '</strong> &nbsp;|&nbsp; ';
echo 'Sample student: <strong>' . htmlspecialchars($sample_name) . '</strong>';
if ($focus_slot >= 0) {
    echo ' &nbsp;|&nbsp; Filtered to slot: <strong>' . $focus_slot . '</strong>';
    $clear_url = new moodle_url('/plagiarism/essayguard/badge_diag.php', ['cmid' => $cmid, 'userid' => $sample_userid]);
    echo ' <a href="' . $clear_url->out() . '">[show all slots]</a>';
}
echo '</p>';

echo '<div class="intro-box">';
echo '<strong>What this page explains:</strong> Why a student who copy-pasted everything gets a HIGH badge, '
   . 'and why a student who typed naturally with pauses and corrections gets a LOW badge — and how the '
   . 'false-positive cap prevents innocent fast typists from being mislabelled MEDIUM. '
   . 'Also shows the exact data the teacher report would display for every student in this activity, '
   . 'with anomaly detection for clobbered scores and missing per-question records.<br>'
   . '<strong>How to use:</strong> Start at the raw event trace (Section 1) to see what tracker.js captured, '
   . 'then read the behaviour pattern analysis (Section 2) for the plain-English verdict. '
   . 'Section 3 shows every signal threshold vs actual value. Section 4 shows what the teacher report displays.';
echo '</div>';

// Student / slot switcher.
echo '<div class="pill-box">';
echo 'Switch student: ';
foreach ($switcher_students as $s) {
    $su = $DB->get_record('user', ['id' => $s->userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename', IGNORE_MISSING);
    if ($su) {
        $url = new moodle_url('/plagiarism/essayguard/badge_diag.php', ['cmid' => $cmid, 'userid' => $s->userid]);
        $highlight = ($s->userid == $sample_userid) ? 'font-weight:700;' : '';
        echo '<a href="' . $url->out() . '" style="' . $highlight . '">' . htmlspecialchars(fullname($su)) . '</a> &nbsp;';
    }
}
echo '<br>Filter by qslot: ';
if ($sample_userid) {
    $slot_url = new moodle_url('/plagiarism/essayguard/badge_diag.php', ['cmid' => $cmid, 'userid' => $sample_userid]);
    echo '<a href="' . $slot_url->out() . '">[all]</a> &nbsp;';
    $slot_nums = array_keys($latest_by_slot);
    foreach ($slot_nums as $sn) {
        $slot_url2 = new moodle_url('/plagiarism/essayguard/badge_diag.php', ['cmid' => $cmid, 'userid' => $sample_userid, 'slot' => $sn]);
        $hl = ($focus_slot === $sn) ? 'font-weight:700;' : '';
        $lbl = $sn === 0 ? 'agg' : "Q{$sn}";
        echo '<a href="' . $slot_url2->out() . '" style="' . $hl . '">[' . $lbl . ']</a> &nbsp;';
    }
}
echo '</div>';

echo '<h2>1. Raw event trace — what tracker.js actually captured</h2>';
echo '<p class="meta">Every stored event from plagiarism_essayguard_ev for this student. '
   . 'Paste / large_insert rows are highlighted red — these are the primary HIGH-badge drivers. '
   . 'Long delta gaps (≥ 2 s) between keydown events represent thinking pauses (important for LOW scores).</p>';
echo '<table>' . $rows_trace . '</table>';

echo '<h2>2. Behaviour pattern analysis &amp; badge explanation</h2>';
echo '<p class="meta">Session archetype classification, key metric summary, distance to each badge boundary, '
   . 'signal-by-signal attribution with plain-English explanations, and a final plain-English verdict.</p>';
echo '<table>' . $rows_pattern . '</table>';

echo '<h2>3. Signal threshold vs actual values</h2>';
echo '<p class="meta">Every signal gate shown with the stored metric value and whether it would fire. '
   . 'WARN = signal fired (contributed points). PASS = signal silent (good sign for honest typist). '
   . 'INFO = signal not evaluated (insufficient data). '
   . 'The false-positive cap summary at the bottom of each slot shows whether the cap would apply.</p>';
echo '<table>' . $rows_thresholds . '</table>';

echo '<h2>4. Teacher report view — what report.php shows for all students</h2>';
echo '<p class="meta">Reproduces the exact headline aggregate badge and per-question breakdown rows '
   . 'that a teacher sees in the EssayGuard report for every student. '
   . 'FAIL = anomaly detected (e.g. LOW badge despite paste events). '
   . 'WARN = unexpected result worth investigating. '
   . 'INFO = normal expected output.</p>';
echo '<table>' . $rows_report . '</table>';

echo '<h2>5. Session timeline — visual event shape</h2>';
echo '<p class="meta">Compact timeline of the sample student\'s session. Each character represents a 5-second window. '
   . 'A session full of dots with underscores (e.g. <code>..._...__.._..</code>) = natural typing with pauses = LOW expected. '
   . 'A session with P characters and few underscores (e.g. <code>P..</code>) = paste-heavy = HIGH expected.</p>';
echo '<table>' . $rows_timeline . '</table>';

$diag_url = new moodle_url('/plagiarism/essayguard/diag.php', ['cmid' => $cmid]);
$rep_url  = new moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]);
echo '<p class="meta" style="margin-top:1.5rem;">';
echo '<a href="' . $diag_url->out() . '">&larr; Main EssayGuard diagnostic (7 sections)</a>';
echo ' &nbsp;|&nbsp; <a href="' . $rep_url->out() . '">&larr; EssayGuard teacher report</a>';
echo '</p>';
echo '</body></html>';
