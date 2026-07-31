<?php

namespace plagiarism_essayguard\local\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Student Writing Fingerprint / Baseline Engine.
 *
 * Builds a behavioural baseline per student after multiple submissions.
 * Uses exponential weighted moving average for rolling updates.
 *
 * Baseline confidence:
 *   samplecount 1–2  → 'none'        (too few samples)
 *   samplecount 3–4  → 'preliminary' (early estimate)
 *   samplecount 5+   → 'stable'      (reliable baseline)
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fingerprint {

    const WEIGHT_NEW       = 0.25;
    const WEIGHT_OLD       = 0.75;
    const STATUS_NONE      = 'none';
    const STATUS_PRELIM    = 'preliminary';
    const STATUS_STABLE    = 'stable';
    const PRELIM_THRESHOLD = 3;
    const STABLE_THRESHOLD = 5;

    /**
     * Update the student fingerprint with metrics from the latest submission.
     * Metrics array must contain all behavioural keys (wpm, pause_mean, etc.).
     */
    public static function update(int $userid, array $metrics): void {
        global $DB;

        $existing = $DB->get_record('plagiarism_essayguard_fp', ['userid' => $userid]);

        if (!$existing) {
            $record = self::init_from_metrics($userid, $metrics);
            $DB->insert_record('plagiarism_essayguard_fp', $record);
            return;
        }

        $count   = (int)$existing->samplecount + 1;
        $alpha_n = self::WEIGHT_NEW;
        $alpha_o = self::WEIGHT_OLD;

        $record = (object)[
            'id'                          => $existing->id,
            'userid'                      => $userid,
            'samplecount'                 => $count,
            'baseline_wpm'               => self::ewma((float)$existing->baseline_wpm, (float)($metrics['average_wpm'] ?? 0), $alpha_n, $alpha_o),
            'baseline_pause_mean'        => self::ewma((float)$existing->baseline_pause_mean, (float)($metrics['pause_mean'] ?? 0), $alpha_n, $alpha_o),
            'baseline_backspace_ratio'   => self::ewma((float)$existing->baseline_backspace_ratio, (float)($metrics['backspace_ratio'] ?? 0), $alpha_n, $alpha_o),
            'baseline_burst_mean'        => self::ewma((float)$existing->baseline_burst_mean, (float)($metrics['burst_mean'] ?? 0), $alpha_n, $alpha_o),
            'baseline_sentence_variance' => self::ewma((float)$existing->baseline_sentence_variance, (float)($metrics['sentence_variance'] ?? 0), $alpha_n, $alpha_o),
            'baseline_vocab_diversity'   => self::ewma((float)$existing->baseline_vocab_diversity, (float)($metrics['vocab_diversity'] ?? 0), $alpha_n, $alpha_o),
            'baseline_entropy'           => self::ewma((float)$existing->baseline_entropy, (float)($metrics['entropy_score'] ?? 0), $alpha_n, $alpha_o),
            'baseline_interkey_mean'     => self::ewma((float)$existing->baseline_interkey_mean, (float)($metrics['interkey_mean'] ?? 0), $alpha_n, $alpha_o),
            'baseline_status'            => self::status_from_count($count),
            'timemodified'               => time(),
        ];

        $DB->update_record('plagiarism_essayguard_fp', $record);
    }

    /**
     * Retrieve the student's fingerprint record, or null if not enough samples.
     */
    public static function get(int $userid): ?object {
        global $DB;
        $record = $DB->get_record('plagiarism_essayguard_fp', ['userid' => $userid]);
        if (!$record || $record->baseline_status === self::STATUS_NONE) {
            return null;
        }
        return $record;
    }

    /**
     * Calculate a deviation score (0.0–1.0) comparing current metrics against baseline.
     * Returns 0.0 if no baseline is available.
     */
    public static function deviation_score(int $userid, array $metrics): float {
        $fp = self::get($userid);
        if (!$fp) {
            return 0.0;
        }

        $deviations = [];

        $deviations[] = self::relative_deviation(
            (float)$fp->baseline_wpm, (float)($metrics['average_wpm'] ?? 0), 0.5
        );
        $deviations[] = self::relative_deviation(
            (float)$fp->baseline_backspace_ratio, (float)($metrics['backspace_ratio'] ?? 0), 0.5
        );
        $deviations[] = self::relative_deviation(
            (float)$fp->baseline_sentence_variance, (float)($metrics['sentence_variance'] ?? 0), 0.5
        );
        $deviations[] = self::relative_deviation(
            (float)$fp->baseline_entropy, (float)($metrics['entropy_score'] ?? 0), 0.3
        );
        $deviations[] = self::relative_deviation(
            (float)$fp->baseline_pause_mean, (float)($metrics['pause_mean'] ?? 0), 0.5
        );

        $deviations = array_filter($deviations, fn($d) => $d >= 0);

        if (empty($deviations)) {
            return 0.0;
        }

        return min(1.0, array_sum($deviations) / count($deviations));
    }

    private static function ewma(float $old, float $new, float $alpha_n, float $alpha_o): float {
        if ($old == 0.0) {
            return $new;
        }
        return round($alpha_o * $old + $alpha_n * $new, 4);
    }

    private static function relative_deviation(float $baseline, float $current, float $threshold): float {
        if ($baseline <= 0) {
            return -1.0;
        }
        $rel = abs($current - $baseline) / $baseline;
        if ($rel < $threshold) {
            return 0.0;
        }
        return min(1.0, ($rel - $threshold) / (1.0 - $threshold));
    }

    private static function init_from_metrics(int $userid, array $metrics): object {
        return (object)[
            'userid'                      => $userid,
            'samplecount'                 => 1,
            'baseline_wpm'               => (float)($metrics['average_wpm'] ?? 0),
            'baseline_pause_mean'        => (float)($metrics['pause_mean'] ?? 0),
            'baseline_backspace_ratio'   => (float)($metrics['backspace_ratio'] ?? 0),
            'baseline_burst_mean'        => (float)($metrics['burst_mean'] ?? 0),
            'baseline_sentence_variance' => (float)($metrics['sentence_variance'] ?? 0),
            'baseline_vocab_diversity'   => (float)($metrics['vocab_diversity'] ?? 0),
            'baseline_entropy'           => (float)($metrics['entropy_score'] ?? 0),
            'baseline_interkey_mean'     => (float)($metrics['interkey_mean'] ?? 0),
            'baseline_status'            => self::STATUS_NONE,
            'timemodified'               => time(),
        ];
    }

    private static function status_from_count(int $count): string {
        if ($count >= self::STABLE_THRESHOLD) {
            return self::STATUS_STABLE;
        }
        if ($count >= self::PRELIM_THRESHOLD) {
            return self::STATUS_PRELIM;
        }
        return self::STATUS_NONE;
    }
}
