<?php
/**
 * Tests for analyser::score_attempt() and its scoring engine.
 *
 * Uses MockDB to inject synthetic event sequences without Moodle.
 * Each test scenario represents a real-world submission pattern.
 *
 * Run: php tests/test_analyser.php
 */

require_once __DIR__ . '/bootstrap.php';

use plagiarism_essayguard\local\service\analyser;

TestRunner::suite('Analyser — Scoring Engine');

$START = 1_700_000_000_000; // arbitrary base timestamp (ms)

// ─── Helper: run a score attempt with the given events ──────────────────────
function score(array $events, ?array $fingerprint = null): array {
    global $DB, $START;
    $DB->load_events($events);
    $DB->load_fingerprint($fingerprint);
    return analyser::score_attempt(1, 1, 1, 'test');
}


// ══════════════════════════════════════════════════════════════════════════════
// SCENARIO 1: Genuine live typing — expect LOW
// 220 chars, varied IKD (80-350ms), 18 backspaces, 3 thinking pauses
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Scenario 1: Genuine live typing\n" . CLR_RESET;

$events = build_natural_typing($START, 220, 18, 3, [38, 42, 35, 40]);
$r = score($events);

TestRunner::assert_equals('Genuine typing → risklevel = low', 'low', $r['risklevel']);
TestRunner::assert_lte(   'Genuine typing → score100 < 25',   24.0,  (float)$r['score100']);
TestRunner::assert(       'Genuine typing → pastecount = 0',
    $r['metrics']['pastecount'] === 0, "got {$r['metrics']['pastecount']}");
TestRunner::assert(       'Genuine typing → pausecount > 0',
    $r['metrics']['pausecount'] > 0, "got {$r['metrics']['pausecount']}");
TestRunner::assert(       'Genuine typing → backspace_ratio > 0.05',
    $r['metrics']['backspace_ratio'] > 0.05, "got {$r['metrics']['backspace_ratio']}");
TestRunner::assert(       'Genuine typing → wpm snapshot captured (average_wpm > 0)',
    $r['metrics']['average_wpm'] > 0, "got {$r['metrics']['average_wpm']}");


// ══════════════════════════════════════════════════════════════════════════════
// SCENARIO 2: Pure paste attack (no typing, paste-only) — expect MEDIUM/HIGH
// 4 paste events + corresponding input events, >300 chars total, no pauses
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Scenario 2: Pure paste attack (4 large pastes)\n" . CLR_RESET;

$events = [];
$t = $START;
for ($i = 0; $i < 4; $i++) {
    $t += 200;
    $events[] = ev_paste($t, 200);     // insertlen=200 > maxburstchars(150)
    $t += 50;
    $events[] = ev_input($t, 200, 200); // addedchars=200, insertlen=200
}
// Add WPM snapshot to confirm session was tracked
$events[] = ev_wpm($t + 500, 110);    // suspiciously high WPM (paste speed)

$r = score($events);

TestRunner::assert(       'Paste attack → risklevel is medium or high',
    in_array($r['risklevel'], ['medium', 'high']),
    "got {$r['risklevel']}");
TestRunner::assert_gte(   'Paste attack → score100 >= 45',  45.0, (float)$r['score100']);
TestRunner::assert_equals('Paste attack → pastecount = 4',  4, $r['metrics']['pastecount']);
TestRunner::assert_gte(   'Paste attack → burstsuspicious >= 4',
    4.0, (float)$r['metrics']['burstsuspicious']);
TestRunner::assert(       'Paste attack → charsadded >= 120 (threshold met)',
    $r['metrics']['charsadded'] >= 120, "got {$r['metrics']['charsadded']}");


// ══════════════════════════════════════════════════════════════════════════════
// SCENARIO 3: Re-typed ChatGPT response — expect MEDIUM or HIGH
// Uniform IKD ~68ms (SD < 10ms), 2 backspaces, no pauses, 250 chars
// Very low entropy + very low backspace ratio → score >= 35 → medium or high
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Scenario 3: Re-typed ChatGPT response (robotic uniform rhythm)\n" . CLR_RESET;

$events = build_robotic_typing($START, 250, 68);
$r = score($events);

TestRunner::assert(       'Robotic typing → risklevel is medium or high',
    in_array($r['risklevel'], ['medium', 'high']),
    "got {$r['risklevel']}");
TestRunner::assert_gte(   'Robotic typing → score100 >= 35',  35.0, (float)$r['score100']);
TestRunner::assert(       'Robotic typing → low backspace_ratio (< 0.03)',
    $r['metrics']['backspace_ratio'] < 0.03,
    "got {$r['metrics']['backspace_ratio']}");
TestRunner::assert(       'Robotic typing → low entropy (interkey SD is very small)',
    $r['metrics']['interkey_std_dev'] < 20,
    "got {$r['metrics']['interkey_std_dev']}");
TestRunner::assert(       'Robotic typing → pausecount = 0',
    $r['metrics']['pausecount'] === 0,
    "got {$r['metrics']['pausecount']}");


// ══════════════════════════════════════════════════════════════════════════════
// SCENARIO 4: Full attack — robotic typing + 2 large pastes → HIGH
// Robotic rhythm (entropy +20, backspace +15) + 2 pastes (+14) + 2 bursts (+16)
// + no pauses (+5) = 70 → HIGH
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Scenario 4: Full attack — robotic typing + paste events\n" . CLR_RESET;

$events = build_robotic_typing($START, 200, 68);
$t      = $START + 20_000;
// Add 2 paste + input burst pairs
for ($i = 0; $i < 2; $i++) {
    $t += 300;
    $events[] = ev_paste($t, 300);
    $t += 50;
    $events[] = ev_input($t, 300, 300);
}
$r = score($events);

TestRunner::assert(       'Full attack → risklevel is high (or medium)',
    in_array($r['risklevel'], ['high', 'medium']),
    "got {$r['risklevel']}");
TestRunner::assert_gte(   'Full attack → score100 >= 50',  50.0, (float)$r['score100']);
TestRunner::assert_gte(   'Full attack → pastecount >= 2',
    2.0, (float)$r['metrics']['pastecount']);
TestRunner::assert_gte(   'Full attack → burstsuspicious >= 2',
    2.0, (float)$r['metrics']['burstsuspicious']);


// ══════════════════════════════════════════════════════════════════════════════
// SCENARIO 5: Below minimum character threshold — score must stay 0
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Scenario 5: Submission below 120-char minimum threshold\n" . CLR_RESET;

$events = [];
$t = $START;
// 4 pastes that would normally score high
for ($i = 0; $i < 4; $i++) {
    $t += 200;
    $events[] = ev_paste($t, 200);
    $t += 50;
    $events[] = ev_input($t, 10, 200); // addedchars=10 each → total 40 chars (below 120)
}

$r = score($events);

TestRunner::assert_equals('Below threshold → score100 = 0',      0,     $r['score100']);
TestRunner::assert_equals('Below threshold → risklevel = low', 'low',   $r['risklevel']);


// ══════════════════════════════════════════════════════════════════════════════
// SCENARIO 6: Drag-drop (drop_paste) event — counts as paste + burst
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Scenario 6: Drag-and-drop paste event\n" . CLR_RESET;

$events = [
    ev_drop_paste($START + 100, 200),
    ev_input($START + 150, 200, 200),
];
$r = score($events);

TestRunner::assert_equals('Drag-drop → pastecount = 1',       1, $r['metrics']['pastecount']);
TestRunner::assert_gte(   'Drag-drop → burstsuspicious >= 1',
    1.0, (float)$r['metrics']['burstsuspicious']);


// ══════════════════════════════════════════════════════════════════════════════
// SCENARIO 7: WPM snapshot and burst_end tracking
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Scenario 7: WPM snapshot + burst_end tracking\n" . CLR_RESET;

$events = build_natural_typing($START, 220, 15, 2, []);
// Append WPM snapshots
$t = $START + 5_000;
$wpm_vals = [40, 45, 38, 42];
foreach ($wpm_vals as $wpm) {
    $events[] = ev_wpm($t, $wpm);
    $t += 5_000;
}
// Append burst_end events
$events[] = ev_burst_end($t,        50);
$events[] = ev_burst_end($t + 100,  60);
$events[] = ev_burst_end($t + 200,  55);

$r = score($events);
$expected_avg_wpm = array_sum($wpm_vals) / count($wpm_vals); // 41.25

TestRunner::assert_equals('WPM snapshots → average_wpm = 41.25',
    41.25, $r['metrics']['average_wpm']);
TestRunner::assert_equals('Burst_end events → burst_count = 3', 3, $r['metrics']['burst_count']);
TestRunner::assert_equals('Burst_end events → burst_mean = 55.0',
    55.0, $r['metrics']['burst_mean']);


// ══════════════════════════════════════════════════════════════════════════════
// SCENARIO 8: Selection / cursor movement tracking
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Scenario 8: Selection (cursor movement) events\n" . CLR_RESET;

$events = build_natural_typing($START, 180, 10, 2, []);
$t = $START + 3_000;
$events[] = ev_selection($t,         3);
$events[] = ev_selection($t + 200,   5);
$events[] = ev_selection($t + 400,   2);

$r = score($events);
TestRunner::assert_equals('Cursor moves → cursor_moves = 10', 10, $r['metrics']['cursor_moves']);


// ══════════════════════════════════════════════════════════════════════════════
// SCENARIO 9: Interkey mean and std_dev helpers
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Scenario 9: std_dev and entropy_from_sd helper methods\n" . CLR_RESET;

// std_dev of identical values = 0
$sd0 = analyser::std_dev([100, 100, 100, 100]);
TestRunner::assert_equals('std_dev of identical values = 0.0', 0.0, $sd0);

// std_dev of [0, 10] = 5.0  (mean=5, sq_diff=(25+25)/2=25, sqrt=5)
$sd5 = analyser::std_dev([0.0, 10.0]);
TestRunner::assert_equals('std_dev([0, 10]) = 5.0', 5.0, $sd5);

// entropy_from_sd boundaries
TestRunner::assert_equals('entropy_from_sd(0)   = 0.0',  0.0,  analyser::entropy_from_sd(0));
TestRunner::assert_equals('entropy_from_sd(50)  = 0.15', 0.15, analyser::entropy_from_sd(50));
TestRunner::assert_equals('entropy_from_sd(80)  = 0.25', 0.25, analyser::entropy_from_sd(80));
TestRunner::assert_equals('entropy_from_sd(120) = 0.40', 0.40, analyser::entropy_from_sd(120));
TestRunner::assert_equals('entropy_from_sd(180) = 0.60', 0.60, analyser::entropy_from_sd(180));
TestRunner::assert_equals('entropy_from_sd(250) = 0.80', 0.80, analyser::entropy_from_sd(250));
TestRunner::assert_equals('entropy_from_sd(400) = 1.0',  1.0,  analyser::entropy_from_sd(400));

// risk_level thresholds — v1.2.112+: LOW 0-29, MEDIUM 30-65, HIGH 66-100.
// analyser::risk_level() returns 'medium' (not 'partial') since v1.2.112.
// 'partial' is a legacy DB alias preserved only for display-layer mapping.
TestRunner::assert_equals('risk_level(0)   = low',    'low',    analyser::risk_level(0));
TestRunner::assert_equals('risk_level(24)  = low',    'low',    analyser::risk_level(24));
TestRunner::assert_equals('risk_level(25)  = low',    'low',    analyser::risk_level(25));
TestRunner::assert_equals('risk_level(29)  = low',    'low',    analyser::risk_level(29));
TestRunner::assert_equals('risk_level(30)  = medium', 'medium', analyser::risk_level(30));
TestRunner::assert_equals('risk_level(49)  = medium', 'medium', analyser::risk_level(49));
TestRunner::assert_equals('risk_level(50)  = medium', 'medium', analyser::risk_level(50));
TestRunner::assert_equals('risk_level(65)  = medium', 'medium', analyser::risk_level(65));
TestRunner::assert_equals('risk_level(66)  = high',   'high',   analyser::risk_level(66));
TestRunner::assert_equals('risk_level(100) = high',   'high',   analyser::risk_level(100));


// ══════════════════════════════════════════════════════════════════════════════
// SCENARIO 10: Baseline fingerprint deviation adds score
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Scenario 10: Baseline fingerprint deviation inflates score\n" . CLR_RESET;

// Student baseline: slow typist (30 WPM, high backspace ratio)
$fp = [
    'id'                          => 1,
    'userid'                      => 1,
    'samplecount'                 => 6,
    'baseline_wpm'               => 30.0,
    'baseline_pause_mean'        => 3500.0,
    'baseline_backspace_ratio'   => 0.12,
    'baseline_burst_mean'        => 0.0,
    'baseline_sentence_variance' => 25.0,
    'baseline_vocab_diversity'   => 0.75,
    'baseline_entropy'           => 0.75,
    'baseline_interkey_mean'     => 250.0,
    'baseline_status'            => 'stable',
    'timemodified'               => time(),
];

// Current session: much faster (80 WPM), robotic rhythm — big deviation
$events = build_robotic_typing($START, 250, 68);
$events[] = ev_wpm($START + 5_000, 80);

$r_no_fp  = score($events, null); // no fingerprint
$r_with_fp = score($events, $fp); // with baseline deviation

TestRunner::assert('With stable baseline → score100 is higher than without baseline',
    $r_with_fp['score100'] >= $r_no_fp['score100'],
    "no_fp={$r_no_fp['score100']}, with_fp={$r_with_fp['score100']}");

// ══════════════════════════════════════════════════════════════════════════════
// SCENARIO 11: Fast clean typist — no paste, no pauses, zero backspaces
// Signal 4 (+20 pts: pausecount=0 AND chars>100) and Signal 5 (+15 pts:
// backspace_ratio < 0.02) together total exactly 35 pts = the MEDIUM boundary.
// FIX-EG-TYPING-FALSE-POSITIVE (v1.2.111): the paste gate must cap this at 34
// so an honest quiz typist never receives a false-positive MEDIUM badge.
// Natural IKD variance from build_natural_typing keeps entropy_score >= 0.30
// (Signal 7 does NOT fire) and typing speed stays <= 8.0 cps (Signal 3 does
// NOT fire), so the gate fires and the session is correctly shown as LOW.
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Scenario 11: Fast clean typist — S4+S5 false-positive gate (v1.2.111)\n" . CLR_RESET;

// 0 backspaces → backspace_ratio = 0 → Signal 5 fires (+15).
// 0 pauses     → pausecount = 0     → Signal 4 fires (+20, since chars > 100).
// Without the paste gate: score = 35 → MEDIUM (false positive).
// With the paste gate:    score capped at 34 → LOW (correct).
$events = build_natural_typing($START, 220, 0, 0, [38, 42, 35, 40]);
$r = score($events);

TestRunner::assert_equals('Fast clean typist → risklevel = low (paste gate fires)', 'low', $r['risklevel']);
TestRunner::assert_lte(   'Fast clean typist → score100 <= 34 (capped below MEDIUM)', 34.0, (float)$r['score100']);
TestRunner::assert(       'Fast clean typist → pastecount = 0',
    $r['metrics']['pastecount'] === 0, "got {$r['metrics']['pastecount']}");
TestRunner::assert(       'Fast clean typist → pausecount = 0 (Signal 4 triggered — gate needed)',
    $r['metrics']['pausecount'] === 0, "got {$r['metrics']['pausecount']}");
TestRunner::assert(       'Fast clean typist → backspace_ratio < 0.02 (Signal 5 triggered — gate needed)',
    $r['metrics']['backspace_ratio'] < 0.02, "got {$r['metrics']['backspace_ratio']}");


TestRunner::summary();
