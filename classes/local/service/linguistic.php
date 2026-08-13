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

defined('MOODLE_INTERNAL') || die();

/**
 * Linguistic metric scaffold.
 *
 * Analyses the final submitted text for structural and vocabulary signals
 * that may indicate AI-generated or externally sourced content.
 * All processing is local — no external APIs used.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class linguistic {
    /**
     * Run all linguistic metrics on submitted text.
     * Returns an associative array of metric name => value.
     */
    public static function analyse(string $text): array {
        $text = trim($text);

        if (strlen($text) < 20) {
            return self::empty_metrics();
        }

        $sentence_lengths = self::sentence_lengths($text);
        $sentence_variance = self::variance($sentence_lengths);
        $avg_sentence_length = !empty($sentence_lengths)
            ? array_sum($sentence_lengths) / count($sentence_lengths)
            : 0.0;

        $vocab_diversity  = self::vocab_diversity($text);
        $rare_word_ratio  = self::rare_word_ratio($text);
        $sentence_count   = count($sentence_lengths);

        return [
            'sentence_variance'    => round($sentence_variance, 4),
            'avg_sentence_length'  => round($avg_sentence_length, 2),
            'sentence_count'       => $sentence_count,
            'vocab_diversity'      => round($vocab_diversity, 4),
            'rare_word_ratio'      => round($rare_word_ratio, 4),
        ];
    }

    /**
     * Split text into sentences and return word-counts per sentence.
     */
    public static function sentence_lengths(string $text): array {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text);
        $lengths = [];
        foreach ($sentences as $s) {
            $s = trim($s);
            if ($s === '') {
                continue;
            }
            $words = preg_match_all('/\b\w+\b/', $s);
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
     */
    public static function vocab_diversity(string $text): float {
        preg_match_all('/\b[a-z]+\b/i', strtolower($text), $m);
        $words = $m[0];
        if (empty($words)) {
            return 0.0;
        }
        $unique = array_unique($words);
        return count($unique) / count($words);
    }

    /**
     * Ratio of "complex" words (8+ characters) to total words.
     * AI output tends to use longer, more formal vocabulary.
     */
    public static function rare_word_ratio(string $text): float {
        preg_match_all('/\b[a-z]+\b/i', strtolower($text), $m);
        $words = $m[0];
        if (empty($words)) {
            return 0.0;
        }
        $rare = array_filter($words, fn($w) => strlen($w) >= 8);
        return count($rare) / count($words);
    }

    /**
     * Population variance of an array of numbers.
     */
    public static function variance(array $values): float {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        $sq_diff = array_reduce($values, fn($carry, $x) => $carry + ($x - $mean) ** 2, 0.0);
        return $sq_diff / $n;
    }

    /**
     * Population standard deviation.
     */
    public static function std_dev(array $values): float {
        return sqrt(self::variance($values));
    }

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
