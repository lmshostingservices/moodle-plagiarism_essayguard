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
 * Tests for fingerprint service.
 *
 * Covers EWMA updating, deviation scoring, and baseline status thresholds.
 * Uses MockDB to inspect fingerprint records without Moodle.
 *
 * Run: php tests/test_fingerprint.php
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once __DIR__ . '/bootstrap.php';

use plagiarism_essayguard\local\service\fingerprint;

TestRunner::suite('Fingerprint — Baseline Engine');

// ── Expose private helpers via Reflection ────────────────────────────────────
$ref = new ReflectionClass(fingerprint::class);

$ewma_method = $ref->getMethod('ewma');
$ewma_method->setAccessible(true);
$ewma = fn($old, $new, $an, $ao) => $ewma_method->invoke(null, $old, $new, $an, $ao);

$rel_dev_method = $ref->getMethod('relative_deviation');
$rel_dev_method->setAccessible(true);
$rel_dev = fn($base, $cur, $threshold) => $rel_dev_method->invoke(null, $base, $cur, $threshold);

$status_method = $ref->getMethod('status_from_count');
$status_method->setAccessible(true);
$status = fn($count) => $status_method->invoke(null, $count);


// ══════════════════════════════════════════════════════════════════════════════
// EWMA (Exponential Weighted Moving Average)
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  EWMA helper\n" . CLR_RESET;

// When old=0, result = new value (no history yet)
$result = $ewma(0.0, 50.0, 0.25, 0.75);
TestRunner::assert_equals('EWMA: old=0 → result = new value (50.0)', 50.0, $result);

// Weighted blend: 0.75*100 + 0.25*60 = 75 + 15 = 90
$result = $ewma(100.0, 60.0, 0.25, 0.75);
TestRunner::assert_equals('EWMA: 0.75*100 + 0.25*60 = 90.0', 90.0, $result);

// New value same as old → result = old
$result = $ewma(45.0, 45.0, 0.25, 0.75);
TestRunner::assert_equals('EWMA: new == old → unchanged', 45.0, $result);


// ══════════════════════════════════════════════════════════════════════════════
// relative_deviation helper
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  relative_deviation helper\n" . CLR_RESET;

// Baseline 0 → cannot calculate, returns -1
$result = $rel_dev(0.0, 50.0, 0.5);
TestRunner::assert_equals('relative_deviation: baseline=0 → -1.0', -1.0, $result);

// Current = baseline → deviation = 0%  < threshold → 0.0
$result = $rel_dev(100.0, 100.0, 0.5);
TestRunner::assert_equals('relative_deviation: current == baseline → 0.0', 0.0, $result);

// Deviation within threshold (20% < 50%) → 0.0
$result = $rel_dev(100.0, 120.0, 0.5); // 20% deviation, threshold=50% → 0
TestRunner::assert_equals('relative_deviation: 20% deviation below 50% threshold → 0.0', 0.0, $result);

// Deviation equals threshold exactly → 0.0
$result = $rel_dev(100.0, 150.0, 0.5); // 50% deviation, threshold=50% → (0.0)/(0.5) = 0.0
TestRunner::assert_equals('relative_deviation: exactly at threshold → 0.0', 0.0, $result);

// Deviation above threshold: 200% relative (baseline=50, current=150 → rel=2.0, threshold=0.5)
// (2.0 - 0.5) / (1.0 - 0.5) = 1.5 / 0.5 = 3.0 → capped at 1.0
$result = $rel_dev(50.0, 150.0, 0.5);
TestRunner::assert_equals('relative_deviation: large deviation → capped at 1.0', 1.0, $result);


// ══════════════════════════════════════════════════════════════════════════════
// status_from_count
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  status_from_count\n" . CLR_RESET;

TestRunner::assert_equals('count=1 → none',        'none',        $status(1));
TestRunner::assert_equals('count=2 → none',        'none',        $status(2));
TestRunner::assert_equals('count=3 → preliminary', 'preliminary', $status(3));
TestRunner::assert_equals('count=4 → preliminary', 'preliminary', $status(4));
TestRunner::assert_equals('count=5 → stable',      'stable',      $status(5));
TestRunner::assert_equals('count=10 → stable',     'stable',      $status(10));


// ══════════════════════════════════════════════════════════════════════════════
// deviation_score: no baseline → returns 0.0
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  deviation_score with no baseline\n" . CLR_RESET;

global $DB;
$DB->load_fingerprint(null);
$result = fingerprint::deviation_score(1, ['average_wpm' => 80, 'backspace_ratio' => 0.01]);
TestRunner::assert_equals('No baseline → deviation_score = 0.0', 0.0, $result);


// ══════════════════════════════════════════════════════════════════════════════
// deviation_score: baseline status = 'none' → returns 0.0
// (samplecount < 3, not yet trusted)
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  deviation_score: baseline_status = none\n" . CLR_RESET;

$DB->load_fingerprint([
    'id'                          => 1,
    'userid'                      => 1,
    'samplecount'                 => 2,
    'baseline_wpm'               => 30.0,
    'baseline_pause_mean'        => 3000.0,
    'baseline_backspace_ratio'   => 0.10,
    'baseline_burst_mean'        => 0.0,
    'baseline_sentence_variance' => 20.0,
    'baseline_vocab_diversity'   => 0.70,
    'baseline_entropy'           => 0.75,
    'baseline_interkey_mean'     => 220.0,
    'baseline_status'            => 'none',  // <-- not trusted yet
    'timemodified'               => time(),
]);

$result = fingerprint::deviation_score(1, ['average_wpm' => 90]);
TestRunner::assert_equals('baseline_status=none → deviation_score = 0.0', 0.0, $result);


// ══════════════════════════════════════════════════════════════════════════════
// deviation_score: stable baseline, current session matches → low deviation
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  deviation_score: stable baseline — current session matches\n" . CLR_RESET;

$DB->load_fingerprint([
    'id'                          => 1,
    'userid'                      => 1,
    'samplecount'                 => 6,
    'baseline_wpm'               => 40.0,
    'baseline_pause_mean'        => 3000.0,
    'baseline_backspace_ratio'   => 0.09,
    'baseline_burst_mean'        => 0.0,
    'baseline_sentence_variance' => 20.0,
    'baseline_vocab_diversity'   => 0.70,
    'baseline_entropy'           => 0.75,
    'baseline_interkey_mean'     => 200.0,
    'baseline_status'            => 'stable',
    'timemodified'               => time(),
]);

$metrics_matching = [
    'average_wpm'         => 42.0,    // +5%  → within 50% threshold
    'backspace_ratio'     => 0.09,    // same → 0 deviation
    'sentence_variance'   => 22.0,    // +10% → within threshold
    'entropy_score'       => 0.75,    // same → 0 deviation
    'pause_mean'          => 3100.0,  // +3%  → within threshold
];

$result = fingerprint::deviation_score(1, $metrics_matching);
TestRunner::assert_lte('Matching session → deviation_score ≈ 0 (< 0.15)', 0.15, $result);


// ══════════════════════════════════════════════════════════════════════════════
// deviation_score: stable baseline, session is radically different → high deviation
// ══════════════════════════════════════════════════════════════════════════════
echo CLR_CYAN . "\n  deviation_score: stable baseline — session is radically different\n" . CLR_RESET;

$metrics_different = [
    'average_wpm'         => 120.0,   // 200% above baseline (30 WPM typical)
    'backspace_ratio'     => 0.005,   // 95% below baseline (was 0.09)
    'sentence_variance'   => 2.0,     // 90% below baseline (was 20)
    'entropy_score'       => 0.15,    // 80% below baseline (was 0.75)
    'pause_mean'          => 0.0,     // no pauses at all
];

$result = fingerprint::deviation_score(1, $metrics_different);
TestRunner::assert('Radically different session → deviation_score > 0.3',
    $result > 0.3, "got {$result}");

TestRunner::summary();
