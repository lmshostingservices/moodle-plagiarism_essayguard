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

namespace plagiarism_essayguard;

use plagiarism_essayguard\local\service\linguistic;

/**
 * Unit tests for the linguistic metric scaffold.
 *
 * Every method on this class is pure: it touches neither the database nor plugin
 * configuration, so each one is called directly. Every expected number below was
 * observed from a run of the real method against the exact input given, not derived
 * from the documentation.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_essayguard\local\service\linguistic
 */
final class linguistic_test extends \advanced_testcase {
    /**
     * Text shorter than the twenty-character floor is not analysed at all.
     *
     * @dataProvider short_text_provider
     * @param string $text The submitted text.
     * @return void
     */
    public function test_analyse_returns_zeroed_metrics_below_the_floor(string $text): void {
        $this->assertSame([
            'sentence_variance'   => 0.0,
            'avg_sentence_length' => 0.0,
            'sentence_count'      => 0,
            'vocab_diversity'     => 0.0,
            'rare_word_ratio'     => 0.0,
        ], linguistic::analyse($text));
    }

    /**
     * Texts at or below the analysis floor.
     *
     * The floor is strlen() of the trimmed text, so it counts bytes rather than
     * characters -- see test_non_latin_text_is_admitted_but_yields_no_signal.
     *
     * @return array[] Each dataset: the text.
     */
    public static function short_text_provider(): array {
        return [
            'empty string'          => [''],
            'whitespace only'       => ["   \n  "],
            'nineteen characters'   => ['Nineteen chars her'],
            'trimmed to below floor' => ['   short text   '],
        ];
    }

    /**
     * analyse() reports the metric set observed for each shape of answer.
     *
     * @dataProvider analyse_provider
     * @param string $text     The submitted text.
     * @param array  $expected The full metric array observed from the real method.
     * @return void
     */
    public function test_analyse_reports_the_observed_metrics(string $text, array $expected): void {
        $this->assertSame($expected, linguistic::analyse($text));
    }

    /**
     * Answers of each characteristic shape, with the metrics observed for them.
     *
     * @return array[] Each dataset: the text, then the expected metric array.
     */
    public static function analyse_provider(): array {
        return [
            'exactly twenty characters' => [
                'Twenty characters ok',
                [
                    'sentence_variance'   => 0.0,
                    'avg_sentence_length' => 3.0,
                    'sentence_count'      => 1,
                    'vocab_diversity'     => 1.0,
                    'rare_word_ratio'     => 0.3333,
                ],
            ],
            'four sentences of equal length' => [
                'The cat sat on the mat. The dog ran in the park. '
                    . 'The bird flew to the tree. The fish swam in the pond.',
                [
                    'sentence_variance'   => 0.0,
                    'avg_sentence_length' => 6.0,
                    'sentence_count'      => 4,
                    'vocab_diversity'     => 0.6667,
                    'rare_word_ratio'     => 0.0,
                ],
            ],
            'wildly varied sentence lengths' => [
                'Yes. The quick brown fox jumped over the lazy dog while the farmer '
                    . 'watched from his porch and considered the harvest. No.',
                [
                    'sentence_variance'   => 80.2222,
                    'avg_sentence_length' => 7.33,
                    'sentence_count'      => 3,
                    'vocab_diversity'     => 0.8636,
                    'rare_word_ratio'     => 0.0455,
                ],
            ],
            'one long unpunctuated sentence' => [
                'This is one single sentence that runs on for quite a while without '
                    . 'any terminal punctuation at all',
                [
                    'sentence_variance'   => 0.0,
                    'avg_sentence_length' => 18.0,
                    'sentence_count'      => 1,
                    'vocab_diversity'     => 1.0,
                    'rare_word_ratio'     => 0.1667,
                ],
            ],
            'formal register, long words' => [
                'Consequently, the implementation demonstrates significant improvements. '
                    . 'Furthermore, the methodology establishes reproducible outcomes.',
                [
                    'sentence_variance'   => 0.0,
                    'avg_sentence_length' => 6.0,
                    'sentence_count'      => 2,
                    'vocab_diversity'     => 0.9167,
                    'rare_word_ratio'     => 0.8333,
                ],
            ],
        ];
    }

    /**
     * sentence_lengths() splits on terminal punctuation and counts words per sentence.
     *
     * @dataProvider sentence_lengths_provider
     * @param string $text     The submitted text.
     * @param int[]  $expected Word count per sentence, in document order.
     * @return void
     */
    public function test_sentence_lengths(string $text, array $expected): void {
        $this->assertSame($expected, linguistic::sentence_lengths($text));
    }

    /**
     * Sentence-splitting cases.
     *
     * @return array[] Each dataset: the text, then the expected per-sentence word counts.
     */
    public static function sentence_lengths_provider(): array {
        return [
            'no text'                  => ['', []],
            'punctuation only'         => ['... !!! ???', []],
            'one sentence, no stop'    => ['four words go here', [4]],
            'three sentences'          => ['Yes. Two words here. No.', [1, 3, 1]],
            'question and exclamation' => ['Is this so? It is! Indeed.', [3, 2, 1]],
            'no space after full stop' => ['One.Two.Three.', [3]],
            'newline separated'        => ["First one here.\nSecond one here.", [3, 3]],
        ];
    }

    /**
     * variance() is the population variance and needs at least two values.
     *
     * @dataProvider variance_provider
     * @param float[] $values   The values to measure.
     * @param float   $expected The observed population variance.
     * @return void
     */
    public function test_variance(array $values, float $expected): void {
        $this->assertSame($expected, linguistic::variance($values));
    }

    /**
     * Variance cases, including both degenerate inputs.
     *
     * @return array[] Each dataset: the values, then the expected variance.
     */
    public static function variance_provider(): array {
        return [
            'no values'        => [[], 0.0],
            'single value'     => [[5], 0.0],
            'all identical'    => [[4, 4, 4], 0.0],
            'two values'       => [[10, 20], 25.0],
            'four consecutive' => [[1, 2, 3, 4], 1.25],
            'textbook set'     => [[2, 4, 4, 4, 5, 5, 7, 9], 4.0],
        ];
    }

    /**
     * std_dev() is the square root of variance() and inherits its two-value floor.
     *
     * @dataProvider std_dev_provider
     * @param float[] $values   The values to measure.
     * @param float   $expected The observed population standard deviation.
     * @return void
     */
    public function test_std_dev(array $values, float $expected): void {
        $this->assertSame($expected, linguistic::std_dev($values));
    }

    /**
     * Standard deviation cases.
     *
     * @return array[] Each dataset: the values, then the expected standard deviation.
     */
    public static function std_dev_provider(): array {
        return [
            'no values'     => [[], 0.0],
            'single value'  => [[5], 0.0],
            'all identical' => [[4, 4, 4], 0.0],
            'two values'    => [[10, 20], 5.0],
            'textbook set'  => [[2, 4, 4, 4, 5, 5, 7, 9], 2.0],
        ];
    }

    /**
     * vocab_diversity() is a case-insensitive type-token ratio over ASCII words.
     *
     * @dataProvider vocab_diversity_provider
     * @param string $text     The submitted text.
     * @param float  $expected The observed type-token ratio.
     * @return void
     */
    public function test_vocab_diversity(string $text, float $expected): void {
        $this->assertSame($expected, linguistic::vocab_diversity($text));
    }

    /**
     * Type-token ratio cases.
     *
     * @return array[] Each dataset: the text, then the expected ratio.
     */
    public static function vocab_diversity_provider(): array {
        return [
            'every word unique'  => ['alpha beta gamma delta', 1.0],
            'one word repeated'  => ['word word word word', 0.25],
            'case folded'        => ['Apple apple APPLE banana', 0.5],
            'no words at all'    => ['12345 !!! ...', 0.0],
            'half repeated'      => ['one two one two', 0.5],
        ];
    }

    /**
     * rare_word_ratio() counts words of eight or more bytes.
     *
     * @dataProvider rare_word_ratio_provider
     * @param string $text     The submitted text.
     * @param float  $expected The observed ratio of long words.
     * @return void
     */
    public function test_rare_word_ratio(string $text, float $expected): void {
        $this->assertSame($expected, linguistic::rare_word_ratio($text));
    }

    /**
     * Long-word ratio cases, including the eight-character boundary.
     *
     * @return array[] Each dataset: the text, then the expected ratio.
     */
    public static function rare_word_ratio_provider(): array {
        return [
            'no long words'      => ['alpha beta gamma delta', 0.0],
            'half long'          => ['elephants cat marvellous dog', 0.5],
            'seven characters'   => ['sevench sevench', 0.0],
            'eight characters'   => ['eightchr eightchr', 1.0],
            'no words at all'    => ['12345 !!! ...', 0.0],
        ];
    }

    /**
     * Recorded behaviour, not endorsed behaviour: the twenty-character floor counts
     * bytes, and the word regexes are not Unicode-aware.
     *
     * A ten-character CJK answer is thirty bytes, so it clears the floor and is
     * analysed -- but /\b\w+\b/ and /\b[a-z]+\b/i match nothing in it, so every
     * metric comes back zero and sentence_count is 0. The analyser's linguistic
     * fallback gates on sentence_count >= 1, so a non-Latin answer contributes no
     * linguistic evidence whatsoever. This test pins the behaviour so that a fix
     * (adding the /u modifier) is a deliberate, visible change.
     *
     * @return void
     */
    public function test_non_latin_text_is_admitted_but_yields_no_signal(): void {
        $cjk = '這是一個測試句子好的';
        $this->assertSame(10, \core_text::strlen($cjk));
        $this->assertSame(30, strlen($cjk));
        $this->assertSame([
            'sentence_variance'   => 0.0,
            'avg_sentence_length' => 0.0,
            'sentence_count'      => 0,
            'vocab_diversity'     => 0.0,
            'rare_word_ratio'     => 0.0,
        ], linguistic::analyse($cjk));
    }

    /**
     * Regression test for FIX-EG-LINGUISTIC-NOT-UNICODE (v1.2.224): an accented word is
     * one word.
     *
     * Before v1.2.224 the word patterns were /\b[a-z]+\b/i and /\b\w+\b/ with no /u,
     * so "creme" spelled with a grave accent matched as the two fragments "cr" and "me".
     * A French, Spanish or Portuguese cohort was therefore scored as writing more,
     * shorter words than it really did -- inflating vocab_diversity and deflating
     * rare_word_ratio against thresholds calibrated on English.
     *
     * The accented and unaccented spellings must now give identical word counts.
     *
     * @return void
     */
    public function test_accented_words_count_as_one_word(): void {
        $text = "cr\u{00E8}me brulee zzz";
        $this->assertSame([3], linguistic::sentence_lengths($text));
        $this->assertSame(
            linguistic::sentence_lengths('creme brulee zzz'),
            linguistic::sentence_lengths($text)
        );
        $this->assertSame(1.0, linguistic::vocab_diversity($text));
        $this->assertSame(0.0, linguistic::rare_word_ratio($text));
    }

    /**
     * Regression test for FIX-EG-LINGUISTIC-NOT-UNICODE (v1.2.224): a CJK answer now
     * produces real metrics instead of silence.
     *
     * Before v1.2.224 a Chinese submission matched no words at all, so every metric was
     * 0.0 and sentence_count was 0 -- and the analyser's linguistic fallback is gated on
     * sentence_count >= 1, so a non-Latin answer contributed no linguistic evidence
     * anywhere in the pipeline.
     *
     * The character-level tokenisation matters as much as the Unicode patterns: without
     * it, each CJK sentence would be a SINGLE token, giving every sentence a length of 1
     * (uniform sentence length, which Signal 8 reads as AI authorship) and a
     * vocab_diversity of 1.0 (an extreme type-token ratio, which Signal 10 reads as AI
     * polish). This test pins values that only hold when both halves are present.
     *
     * @return void
     */
    public function test_cjk_text_produces_real_metrics(): void {
        $cjk = '這是一個測試句子。這是第二個句子好的。這是第三個句子。';
        $result = linguistic::analyse($cjk);
        $this->assertSame(3, $result['sentence_count']);
        $this->assertSame([8, 9, 7], linguistic::sentence_lengths($cjk));
        $this->assertSame(8.0, $result['avg_sentence_length']);
        // Sentence lengths vary, so no false uniformity signal.
        $this->assertGreaterThan(0.0, $result['sentence_variance']);
        // Repeated characters across the three sentences, so no false 1.0 diversity.
        $this->assertLessThan(1.0, $result['vocab_diversity']);
    }
}
