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

namespace plagiarism_essayguard\local\service;

/**
 * Linguistic metric scaffold.
 *
 * Analyses the final submitted text for structural and vocabulary signals
 * that may indicate AI-generated or externally sourced content.
 * All processing is local — no external APIs used.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class linguistic {
    /**
     * Run all linguistic metrics on submitted text.
     * Returns an associative array of metric name => value.
     *
     * @param string $text The submitted text.
     * @return array sentence_variance, avg_sentence_length, sentence_count,
     *               vocab_diversity and rare_word_ratio; all zeroed below 20 characters.
     */
    public static function analyse(string $text): array {
        $text = trim($text);

        // V1.2.224 FIX-EG-LINGUISTIC-NOT-UNICODE: this whole class was byte-based and
        // ASCII-only, so it produced nothing at all for a non-Latin submission and wrong
        // numbers for an accented Latin one.
        //
        // - This floor counted BYTES, so a 7-character CJK answer (21 bytes) passed a
        // test meant to reject anything under 20 characters, while a 19-character
        // English one did not.
        // - The word patterns below were /\b[a-z]+\b/i and /\b\w+\b/ with no /u, so
        // "crème" matched as the two fragments "cr" and "me": a French or Spanish
        // cohort had its vocabulary diversity inflated and its rare-word ratio
        // deflated, systematically, against the same thresholds as an English one.
        // - CJK text matched no words at all, giving every metric 0.0 and
        // sentence_count 0 - and the analyser's linguistic fallback is gated on
        // sentence_count >= 1, so a non-Latin answer contributed no linguistic
        // evidence anywhere in the pipeline. Silently.
        //
        // core_text::strlen() counts characters, and the patterns now carry /u with
        // \p{L}\p{M}\p{N} in place of the ASCII classes.
        if (\core_text::strlen($text) < 20) {
            return self::empty_metrics();
        }

        $sentencelengths = self::sentence_lengths($text);
        $sentencevariance = self::variance($sentencelengths);
        $avgsentencelength = !empty($sentencelengths)
            ? array_sum($sentencelengths) / count($sentencelengths)
            : 0.0;

        $vocabdiversity  = self::vocab_diversity($text);
        $rarewordratio  = self::rare_word_ratio($text);
        $sentencecount   = count($sentencelengths);

        return [
            'sentence_variance'    => round($sentencevariance, 4),
            'avg_sentence_length'  => round($avgsentencelength, 2),
            'sentence_count'       => $sentencecount,
            'vocab_diversity'      => round($vocabdiversity, 4),
            'rare_word_ratio'      => round($rarewordratio, 4),
        ];
    }

    /**
     * Split text into word tokens, treating space-free scripts by character.
     *
     * v1.2.224: making the word patterns Unicode-aware was only half the job. In Chinese,
     * Japanese and Thai an entire sentence is one unbroken run of letters, so
     * `[\p{L}\p{M}\p{N}]+` matches it as a SINGLE token. Left there, that would have
     * been worse than the ASCII-only behaviour it replaced: every CJK sentence would
     * report a length of 1, giving a standard deviation of 0 across sentences, and
     * Signal 8 reads uniform sentence length as evidence of AI authorship. Vocabulary
     * diversity would be 1.0 for the same reason - unique tokens over total tokens, with
     * one token per sentence - and Signal 10 reads an extreme type-token ratio as AI
     * polish. Every Chinese submission on the site would have acquired two false signals.
     *
     * A run containing CJK or Thai characters is therefore counted per character, which
     * is the standard word proxy for these scripts. Text in a spaced script contains none
     * of these characters and takes the whole-token path, so every existing Latin,
     * Cyrillic, Greek and Arabic result is unchanged.
     *
     * @param string $text The text to tokenise.
     * @return string[] The word tokens, in order.
     */
    private static function tokens(string $text): array {
        // CJK Unified Ideographs (+ Ext A and compatibility), Hiragana, Katakana, Hangul
        // syllables and Thai.
        $cjk = '\x{3040}-\x{30ff}\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}'
             . '\x{ac00}-\x{d7af}\x{0e00}-\x{0e7f}\x{f900}-\x{faff}';

        if (!preg_match_all('/[\p{L}\p{M}\p{N}]+/u', $text, $m)) {
            return [];
        }

        $tokens = [];
        foreach ($m[0] as $run) {
            if (!preg_match('/[' . $cjk . ']/u', $run)) {
                $tokens[] = $run;
                continue;
            }
            $chars = preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY);
            if ($chars === false) {
                $tokens[] = $run;
                continue;
            }
            foreach ($chars as $ch) {
                $tokens[] = $ch;
            }
        }
        return $tokens;
    }

    /**
     * Split text into sentences and return word-counts per sentence.
     *
     * @param string $text The submitted text.
     * @return int[] The word count of each sentence, in document order.
     */
    public static function sentence_lengths(string $text): array {
        // V1.2.224: the split was `(?<=[.!?])\s+` - an ASCII terminator FOLLOWED BY
        // whitespace. CJK writing uses 。！？ and puts no space after them, so a Chinese
        // or Japanese answer of any length was one sentence, which makes
        // sentence_variance and avg_sentence_length meaningless for those cohorts.
        // Two alternatives: the original rule for spaced scripts, and a zero-width split
        // after a full-width terminator. The first branch is unchanged, so every existing
        // Latin-script result is identical.
        $sentences = preg_split('/(?<=[.!?])\s+|(?<=[。！？])/u', $text);
        if ($sentences === false) {
            /* The preg_split /u variant returns false on malformed UTF-8; fall back to the
             * ASCII-only rule rather than losing the text entirely.
             */
            $sentences = preg_split('/(?<=[.!?])\s+/', $text);
        }
        $lengths = [];
        foreach ($sentences as $s) {
            $s = trim($s);
            if ($s === '') {
                continue;
            }
            // V1.2.224: \w without /u is [A-Za-z0-9_] and matches nothing in a CJK or
            // Cyrillic sentence. tokens() also handles the space-free scripts, where one
            // regex run is a whole sentence rather than a word.
            $words = count(self::tokens($s));
            if ($words > 0) {
                $lengths[] = $words;
            }
        }
        return $lengths;
    }

    /**
     * Type-Token Ratio: unique words / total words.
     * Higher = more diverse vocabulary.
     * AI often produces 0.4–0.55; natural human writing varies more.
     *
     * @param string $text The submitted text.
     * @return float Type-token ratio from 0.0 to 1.0; 0.0 when no words were found.
     */
    public static function vocab_diversity(string $text): float {
        // V1.2.224: was /\b[a-z]+\b/i on strtolower() - ASCII-only in both the pattern
        // and the case fold. core_text::strtolower() is multibyte-aware and tokens()
        // covers every script. See FIX-EG-LINGUISTIC-NOT-UNICODE in analyse().
        // Bare numbers are excluded here but counted by sentence_lengths(), which is what
        // the pre-v1.2.224 code did: its word pattern was [a-z]+ for these two ratios and
        // \w+ (digits included) for sentence length. Keeping that split means an English
        // answer containing figures scores exactly as it did before.
        //
        // One deliberate difference remains: an ALPHANUMERIC token is now kept whole,
        // where [a-z]+ used to cut it at the first digit. "HLTWHS001" was two things -
        // the word "hltwhs" for these ratios and nothing at all for the digits - and is
        // now one token. That is the right reading for this plugin's actual domain, where
        // VET unit codes appear in most answers, but it does move vocab_diversity and
        // rare_word_ratio very slightly for English text containing such codes.
        $words = array_values(
            array_filter(
                self::tokens(\core_text::strtolower($text)),
                static fn($w) => (bool)preg_match('/[\p{L}\p{M}]/u', $w)
                )
        );
        if (empty($words)) {
            return 0.0;
        }
        $unique = array_unique($words);
        return count($unique) / count($words);
    }

    /**
     * Ratio of "complex" words (8+ characters) to total words.
     * AI output tends to use longer, more formal vocabulary.
     *
     * @param string $text The submitted text.
     * @return float Proportion of words of eight or more characters, 0.0 to 1.0.
     */
    public static function rare_word_ratio(string $text): float {
        // V1.2.224: was /\b[a-z]+\b/i on strtolower() - ASCII-only in both the pattern
        // and the case fold. core_text::strtolower() is multibyte-aware and tokens()
        // covers every script. See FIX-EG-LINGUISTIC-NOT-UNICODE in analyse().
        // Bare numbers are excluded here but counted by sentence_lengths(), which is what
        // the pre-v1.2.224 code did: its word pattern was [a-z]+ for these two ratios and
        // \w+ (digits included) for sentence length. Keeping that split means an English
        // answer containing figures scores exactly as it did before.
        //
        // One deliberate difference remains: an ALPHANUMERIC token is now kept whole,
        // where [a-z]+ used to cut it at the first digit. "HLTWHS001" was two things -
        // the word "hltwhs" for these ratios and nothing at all for the digits - and is
        // now one token. That is the right reading for this plugin's actual domain, where
        // VET unit codes appear in most answers, but it does move vocab_diversity and
        // rare_word_ratio very slightly for English text containing such codes.
        $words = array_values(
            array_filter(
                self::tokens(\core_text::strtolower($text)),
                static fn($w) => (bool)preg_match('/[\p{L}\p{M}]/u', $w)
                )
        );
        if (empty($words)) {
            return 0.0;
        }
        // V1.2.224: strlen() counts bytes, so an 8-byte accented word such as "élève"
        // (5 characters) was scored as rare and every CJK word as rare on sight.
        $rare = array_filter($words, fn($w) => \core_text::strlen($w) >= 8);
        return count($rare) / count($words);
    }

    /**
     * Population variance of an array of numbers.
     *
     * @param float[] $values The values to measure.
     * @return float The population variance, or 0.0 for fewer than two values.
     */
    public static function variance(array $values): float {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        $sqdiff = array_reduce($values, fn($carry, $x) => $carry + ($x - $mean) ** 2, 0.0);
        return $sqdiff / $n;
    }

    /**
     * Population standard deviation.
     *
     * @param float[] $values The values to measure.
     * @return float The population standard deviation, or 0.0 for fewer than two values.
     */
    public static function std_dev(array $values): float {
        return sqrt(self::variance($values));
    }

    /**
     * The zeroed metric set returned when there is no text to analyse.
     *
     * @return array Every linguistic metric at its neutral value.
     */
    private static function empty_metrics(): array {
        return [
            'sentence_variance'   => 0.0,
            'avg_sentence_length' => 0.0,
            'sentence_count'      => 0,
            'vocab_diversity'     => 0.0,
            'rare_word_ratio'     => 0.0,
        ];
    }
}
