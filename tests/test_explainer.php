<?php
/**
 * Tests for explainer::explain()
 *
 * Verifies that the right explanation strings are generated for each
 * behavioural signal and combination.
 *
 * get_string() is stubbed in bootstrap.php to return "[key:param]"
 * so we can assert on key names directly.
 *
 * Run: php tests/test_explainer.php
 */

require_once __DIR__ . '/bootstrap.php';

use plagiarism_essayguard\local\service\explainer;

TestRunner::suite('Explainer — Explanation Engine');

// ── Base clean metrics (no signals triggered) ────────────────────────────────
function clean_metrics(): array {
    return [
        'paste_events'        => 0,
        'pastecount'          => 0,
        'burstsuspicious'     => 0,
        'backspace_ratio'     => 0.09,
        'entropy_score'       => 0.75,
        'charsadded'          => 250,
        'pausecount'          => 3,
        'pause_count'         => 3,
        'sentence_variance'   => 20.0,
        'vocab_diversity'     => 0.65,
        'thinking_pause_score'=> 0.5,
        'interkey_mean'       => 180.0,
        'average_wpm'         => 40.0,
    ];
}


// ══════════════════════════════════════════════════════════════════════════════
// Test 1: Clean, low-risk session → explain_clean explanation
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Clean session (low risk)\n" . CLR_RESET;

$explanations = explainer::explain(clean_metrics(), 'low', null);
TestRunner::assert_contains('Clean session → contains explain_clean',
    'explain_clean', $explanations);
TestRunner::assert_equals('Clean session → exactly 1 explanation', 1, count($explanations));


// ══════════════════════════════════════════════════════════════════════════════
// Test 2: Low risk — gets explain_clean (no concerning signals)
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Low risk, no specific signals → explain_clean\n" . CLR_RESET;

$explanations = explainer::explain(clean_metrics(), 'low', null);
TestRunner::assert_contains('Low with no signals → contains explain_clean',
    'explain_clean', $explanations);


// ══════════════════════════════════════════════════════════════════════════════
// Test 3: Single paste event → explain_paste
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Single paste event\n" . CLR_RESET;

$m = clean_metrics();
$m['paste_events'] = 1;
$m['pastecount']   = 1;
$explanations = explainer::explain($m, 'medium', null);
TestRunner::assert_contains('1 paste → explain_paste',  'explain_paste', $explanations);
// Must NOT contain explain_manypastes for count=1
$has_many = array_filter($explanations, fn($e) => str_contains($e, 'explain_manypastes'));
TestRunner::assert_equals('1 paste → NOT explain_manypastes', 0, count($has_many));


// ══════════════════════════════════════════════════════════════════════════════
// Test 4: Three or more paste events → explain_manypastes
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Three+ paste events\n" . CLR_RESET;

$m = clean_metrics();
$m['paste_events'] = 3;
$m['pastecount']   = 3;
$explanations = explainer::explain($m, 'high', null);
TestRunner::assert_contains('3 pastes → explain_manypastes', 'explain_manypastes', $explanations);
$has_single = array_filter($explanations, fn($e) => str_contains($e, 'explain_paste') && !str_contains($e, 'explain_manypastes'));
TestRunner::assert_equals('3 pastes → NOT explain_paste (single)', 0, count($has_single));


// ══════════════════════════════════════════════════════════════════════════════
// Test 5: Suspicious burst insertion → explain_burst
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Suspicious burst insertion\n" . CLR_RESET;

$m = clean_metrics();
$m['burstsuspicious'] = 2;
$explanations = explainer::explain($m, 'medium', null);
TestRunner::assert_contains('Burst > 0 → explain_burst', 'explain_burst', $explanations);


// ══════════════════════════════════════════════════════════════════════════════
// Test 6: Very low backspace ratio (< 0.02) → explain_lowbackspace
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Very low backspace ratio\n" . CLR_RESET;

$m = clean_metrics();
$m['backspace_ratio'] = 0.008; // < 0.02
$explanations = explainer::explain($m, 'medium', null);
TestRunner::assert_contains('backspace_ratio < 0.02 → explain_lowbackspace',
    'explain_lowbackspace', $explanations);


// ══════════════════════════════════════════════════════════════════════════════
// Test 7: Slightly low backspace ratio (0.02–0.05) → explain_slightlylowbackspace
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Slightly low backspace ratio\n" . CLR_RESET;

$m = clean_metrics();
$m['backspace_ratio'] = 0.035; // >= 0.02 and < 0.05
$explanations = explainer::explain($m, 'medium', null);
TestRunner::assert_contains('backspace_ratio 0.02–0.05 → explain_slightlylowbackspace',
    'explain_slightlylowbackspace', $explanations);


// ══════════════════════════════════════════════════════════════════════════════
// Test 8: Entropy < 0.3 (very robotic) → explain_lowentropy
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Very low entropy (robotic rhythm)\n" . CLR_RESET;

$m = clean_metrics();
$m['entropy_score'] = 0.15; // < 0.3
$explanations = explainer::explain($m, 'medium', null);
TestRunner::assert_contains('entropy < 0.3 → explain_lowentropy', 'explain_lowentropy', $explanations);


// ══════════════════════════════════════════════════════════════════════════════
// Test 9: Entropy 0.3–0.5 → explain_medentropy
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Moderate entropy\n" . CLR_RESET;

$m = clean_metrics();
$m['entropy_score'] = 0.40; // 0.3–0.5
$explanations = explainer::explain($m, 'medium', null);
TestRunner::assert_contains('entropy 0.3–0.5 → explain_medentropy', 'explain_medentropy', $explanations);


// ══════════════════════════════════════════════════════════════════════════════
// Test 10: No pauses with > 300 chars → explain_nopauses
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  No pauses on long submission\n" . CLR_RESET;

$m = clean_metrics();
$m['pausecount']  = 0;
$m['pause_count'] = 0;
$m['charsadded']  = 350;
$explanations = explainer::explain($m, 'high', null);
TestRunner::assert_contains('no pauses + >300 chars → explain_nopauses', 'explain_nopauses', $explanations);


// ══════════════════════════════════════════════════════════════════════════════
// Test 11: Uniform sentence structure → explain_lowsentencevariance
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Low sentence variance (AI-uniform structure)\n" . CLR_RESET;

$m = clean_metrics();
$m['sentence_variance'] = 3.5; // < 6
$explanations = explainer::explain($m, 'medium', null);
TestRunner::assert_contains('sentence_variance < 6 → explain_lowsentencevariance',
    'explain_lowsentencevariance', $explanations);


// ══════════════════════════════════════════════════════════════════════════════
// Test 12: Medium sentence variance → explain_medsentencevariance
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Moderate sentence variance\n" . CLR_RESET;

$m = clean_metrics();
$m['sentence_variance'] = 9.0; // 6–12
$explanations = explainer::explain($m, 'medium', null);
TestRunner::assert_contains('sentence_variance 6–12 → explain_medsentencevariance',
    'explain_medsentencevariance', $explanations);


// ══════════════════════════════════════════════════════════════════════════════
// Test 13: Low vocab diversity → explain_lowvocab
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Low vocabulary diversity\n" . CLR_RESET;

$m = clean_metrics();
$m['vocab_diversity'] = 0.25; // < 0.30
$explanations = explainer::explain($m, 'medium', null);
TestRunner::assert_contains('vocab_diversity < 0.30 → explain_lowvocab', 'explain_lowvocab', $explanations);


// ══════════════════════════════════════════════════════════════════════════════
// Test 14: Fast interkey mean → explain_fastkeys
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Abnormally fast interkey timing\n" . CLR_RESET;

$m = clean_metrics();
$m['interkey_mean'] = 70.0; // < 100ms
$explanations = explainer::explain($m, 'medium', null);
TestRunner::assert_contains('interkey_mean < 100 → explain_fastkeys', 'explain_fastkeys', $explanations);


// ══════════════════════════════════════════════════════════════════════════════
// Test 15: Baseline WPM comparison — current much faster than baseline
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  WPM far above baseline → explain_fasterthanbaseline\n" . CLR_RESET;

$m = clean_metrics();
$m['average_wpm'] = 95.0; // > 1.8 × baseline (40 WPM)

$baseline = (object)[
    'baseline_wpm'               => 40.0,  // ratio: 95/40 = 2.375 > 1.8 → triggers
    'baseline_backspace_ratio'   => 0.05,
    'baseline_sentence_variance' => 10.0,
];

$explanations = explainer::explain($m, 'high', $baseline);
TestRunner::assert_contains('WPM >> baseline → explain_fasterthanbaseline',
    'explain_fasterthanbaseline', $explanations);


// ══════════════════════════════════════════════════════════════════════════════
// Test 16: Backspace far below baseline → explain_backspacebelowbaseline
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Backspace ratio far below baseline\n" . CLR_RESET;

$m = clean_metrics();
$m['backspace_ratio'] = 0.008; // < 0.02

$baseline = (object)[
    'baseline_wpm'               => 42.0,
    'baseline_backspace_ratio'   => 0.12, // > 0.05 → triggers with current < 0.02
    'baseline_sentence_variance' => 10.0,
];

$explanations = explainer::explain($m, 'high', $baseline);
TestRunner::assert_contains('Backspace << baseline → explain_backspacebelowbaseline',
    'explain_backspacebelowbaseline', $explanations);


// ══════════════════════════════════════════════════════════════════════════════
// Test 17: Multiple signals fire together
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  Multiple signals combined\n" . CLR_RESET;

$m = [
    'paste_events'        => 2,
    'pastecount'          => 2,
    'burstsuspicious'     => 2,
    'backspace_ratio'     => 0.006,
    'entropy_score'       => 0.15,
    'charsadded'          => 320,
    'pausecount'          => 0,
    'pause_count'         => 0,
    'sentence_variance'   => 4.0,
    'vocab_diversity'     => 0.28,
    'thinking_pause_score'=> 0.0,
    'interkey_mean'       => 70.0,
    'average_wpm'         => 90.0,
];

$explanations = explainer::explain($m, 'high', null);
TestRunner::assert('Multiple signals → paste explanation present',
    count(array_filter($explanations, fn($e) => str_contains($e, 'explain_paste'))) > 0);
TestRunner::assert('Multiple signals → burst explanation present',
    count(array_filter($explanations, fn($e) => str_contains($e, 'explain_burst'))) > 0);
TestRunner::assert('Multiple signals → low entropy present',
    count(array_filter($explanations, fn($e) => str_contains($e, 'explain_lowentropy'))) > 0);
TestRunner::assert('Multiple signals → ≥ 4 explanations generated',
    count($explanations) >= 4, "got " . count($explanations));

TestRunner::summary();
