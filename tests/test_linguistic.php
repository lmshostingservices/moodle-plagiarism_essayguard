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
 * Tests for linguistic::analyse()
 *
 * No DB required. Tests pure text analysis in isolation.
 * Run: php tests/test_linguistic.php
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once __DIR__ . '/bootstrap.php';

use plagiarism_essayguard\local\service\linguistic;

TestRunner::suite('Linguistic Service');

// ── Test 1: Empty / too-short text returns zero metrics ─────────────────────
$r = linguistic::analyse('Hi');
TestRunner::assert_equals('Short text (<20 chars) → sentence_variance = 0',   0.0, $r['sentence_variance']);
TestRunner::assert_equals('Short text (<20 chars) → vocab_diversity = 0',     0.0, $r['vocab_diversity']);
TestRunner::assert_equals('Short text (<20 chars) → rare_word_ratio = 0',     0.0, $r['rare_word_ratio']);
TestRunner::assert_equals('Short text (<20 chars) → sentence_count = 0',      0,   $r['sentence_count']);

// ── Test 2: Single sentence returns zero variance ───────────────────────────
$one_sentence = 'The quick brown fox jumps over the lazy dog today.';
$r = linguistic::analyse($one_sentence);
TestRunner::assert_equals('Single sentence → sentence_variance = 0 (no variance)',  0.0, $r['sentence_variance']);
TestRunner::assert('Single sentence → sentence_count = 1',  $r['sentence_count'] === 1,
    "got {$r['sentence_count']}");

// ── Test 3: Uniform sentences produce low sentence_variance ─────────────────
// Each sentence has ~8 words — AI-like regularity
$uniform = 'The student arrived at school very early today. ' .
           'She opened her book and started to read. ' .
           'The teacher walked into the room looking pleased. ' .
           'Everyone sat down quietly and waited for class.';
$r = linguistic::analyse($uniform);
TestRunner::assert('Uniform sentences → sentence_variance < 5',
    $r['sentence_variance'] < 5,
    "got {$r['sentence_variance']}");

// ── Test 4: Highly varied sentence lengths produce high variance ─────────────
// Sentence word counts: 1, 1, 1, 18, 1, 18, 1 — extreme variation
$varied = 'Yes. No. ' .
          'The student successfully completed the comprehensive multi-stage government-mandated assessment task and received full marks. ' .
          'Maybe. ' .
          'Absolutely extraordinary achievement was demonstrated by the entire student cohort throughout the long and challenging academic semester this year. ' .
          'OK. ' .
          'Remarkable and unprecedented levels of dedication were observed across all enrolled participants during the final examination period last month. ' .
          'Done.';
$r = linguistic::analyse($varied);
TestRunner::assert('Varied sentence lengths → sentence_variance > 30',
    $r['sentence_variance'] > 30,
    "got {$r['sentence_variance']}");

// ── Test 5: Repeated simple words → low vocab_diversity ─────────────────────
$repetitive = str_repeat('the cat sat on the mat and the cat sat again ', 8);
$r = linguistic::analyse($repetitive);
TestRunner::assert('Highly repetitive text → vocab_diversity < 0.25',
    $r['vocab_diversity'] < 0.25,
    "got {$r['vocab_diversity']}");

// ── Test 6: Diverse vocabulary → high vocab_diversity ───────────────────────
$diverse = 'Quantum entanglement perplexes physicists. Biodiversity sustains ecosystems globally. '
         . 'Cryptographic algorithms protect digital infrastructure effectively. '
         . 'Neuroplasticity enables remarkable cognitive adaptation throughout lifespan. '
         . 'Philosophical discourse challenges conventional assumptions persistently.';
$r = linguistic::analyse($diverse);
TestRunner::assert('Diverse vocabulary → vocab_diversity > 0.6',
    $r['vocab_diversity'] > 0.6,
    "got {$r['vocab_diversity']}");

// ── Test 7: Long formal words → high rare_word_ratio ────────────────────────
$formal = 'The comprehensive methodology demonstrates significant improvements. '
        . 'Implementation requires systematic administration of resources. '
        . 'Stakeholders considered alternative approaches throughout deliberations. '
        . 'Organisational restructuring necessitated significant investment decisions.';
$r = linguistic::analyse($formal);
TestRunner::assert('Formal text → rare_word_ratio > 0.20',
    $r['rare_word_ratio'] > 0.20,
    "got {$r['rare_word_ratio']}");

// ── Test 8: Simple short words → low rare_word_ratio ────────────────────────
$simple = 'I like dogs. They run and jump. Dogs can sit and stay. '
        . 'My dog is big. He eats and runs. We play in the yard.';
$r = linguistic::analyse($simple);
TestRunner::assert('Simple text → rare_word_ratio < 0.10',
    $r['rare_word_ratio'] < 0.10,
    "got {$r['rare_word_ratio']}");

// ── Test 9: sentence_lengths helper returns correct word counts ──────────────
$three = 'One two three. Four five six seven. Eight nine.';
$lengths = linguistic::sentence_lengths($three);
TestRunner::assert_equals('sentence_lengths → correct count of sentences', 3, count($lengths));
TestRunner::assert_equals('sentence_lengths → first sentence = 3 words',   3, $lengths[0]);
TestRunner::assert_equals('sentence_lengths → second sentence = 4 words',  4, $lengths[1]);
TestRunner::assert_equals('sentence_lengths → third sentence = 2 words',   2, $lengths[2]);

// ── Test 10: variance helper ─────────────────────────────────────────────────
$vals = [10.0, 10.0, 10.0, 10.0]; // all same → zero variance
TestRunner::assert_equals('variance([10,10,10,10]) = 0.0', 0.0, linguistic::variance($vals));

$vals2 = [2.0, 4.0]; // mean=3, sq_diff = (1+1)/2 = 1.0
TestRunner::assert_equals('variance([2,4]) = 1.0', 1.0, linguistic::variance($vals2));

TestRunner::summary();
