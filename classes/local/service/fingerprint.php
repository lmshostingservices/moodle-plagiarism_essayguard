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
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fingerprint {
    /** @var float Weight given to the newest submission when blending it into the running baseline. */
    const WEIGHT_NEW       = 0.25;

    /** @var float Weight retained by the existing baseline when a new submission is blended in. */
    const WEIGHT_OLD       = 0.75;

    /** @var string Confidence label for a baseline built from too few samples to be used. */
    const STATUS_NONE      = 'none';

    /** @var string Confidence label for an early baseline: usable, but still an estimate. */
    const STATUS_PRELIM    = 'preliminary';

    /** @var string Confidence label for a baseline with enough samples to be treated as reliable. */
    const STATUS_STABLE    = 'stable';

    /** @var int Sample count at which the baseline is promoted from 'none' to 'preliminary'. */
    const PRELIM_THRESHOLD = 3;

    /** @var int Sample count at which the baseline is promoted from 'preliminary' to 'stable'. */
    const STABLE_THRESHOLD = 5;

    /**
     * Samples required before a metric's own variance is trusted for comparison.
     *
     * BASELINE-EG-WELFORD (v1.4.0). Five samples give a usable mean but a poor variance
     * estimate, and the whole point of this rework is to judge a deviation against the
     * student's own spread rather than a fixed percentage. Eight is the point at which
     * the standard deviation stops swinging wildly with each new submission. Below it the
     * metric contributes nothing rather than contributing noise.
     *
     * @var int
     */
    const MIN_SAMPLES_FOR_Z = 8;

    /**
     * Metrics with enough samples required before any comparative score is reported.
     *
     * One metric crossing a threshold is an anecdote. The comparison is only meaningful
     * when several independent measurements of the same student agree.
     *
     * @var int
     */
    const MIN_METRICS_FOR_SCORE = 3;

    /** @var float Deviations below this many standard deviations score nothing. */
    const Z_FLOOR = 2.0;

    /** @var float Deviation at which a metric contributes its full weight. */
    const Z_CEILING = 4.0;

    /**
     * The metrics tracked per student, mapped to their key in the metrics array.
     *
     * Each is a property of HOW the student writes rather than what they wrote, and each
     * is measured the same way on every submission. Values that are structurally absent
     * (a zero because nothing was captured, rather than a measured zero) are rejected by
     * sample_is_usable() before they reach the running statistics.
     *
     * @var string[]
     */
    const TRACKED_METRICS = [
        'wpm'                => 'average_wpm',
        'pause_mean'         => 'pause_mean',
        'backspace_ratio'    => 'backspace_ratio',
        'burst_mean'         => 'burst_mean',
        'sentence_variance'  => 'sentence_variance',
        'vocab_diversity'    => 'vocab_diversity',
        'entropy'            => 'entropy_score',
        'interkey_mean'      => 'interkey_mean',
        'transition_density' => 'transition_density',
        'paragraph_variance' => 'paragraph_variance',
    ];

    /**
     * Update the student fingerprint with metrics from the latest submission.
     * Metrics array must contain all behavioural keys (wpm, pause_mean, etc.).
     *
     * @param int   $userid  The student whose baseline is being updated.
     * @param array $metrics The behavioural metrics measured for this submission.
     * @return void
     */
    public static function update(int $userid, array $metrics): void {
        global $DB;

        /*
         * V1.2.229 FIX-EG-FINGERPRINT-ZERO-SAMPLE: a scoring pass that captured no
         * keystrokes must not be folded into the baseline.
         *
         * The observer calls update() with whatever score_attempt() produced. When the
         * tracker recorded nothing usable for that submission - a mobile-app submission, a
         * theme that suppresses the footer hook, JS disabled, a flush that never landed -
         * every behavioural metric in that array is 0. ewma() then multiplied the
         * student's real baseline by 0.75 and added nothing, so each such submission cut
         * their recorded typing speed by a quarter, while samplecount still ticked up
         * towards 'stable'.
         *
         * Three no-telemetry submissions in a row leave a student with a CONFIDENT
         * baseline of roughly 42 % of their true speed. The next time they type normally,
         * fingerprint::deviation_score() reports a large deviation from "their own"
         * baseline and analyser.php's Signal 11 adds up to 15 points to their risk score
         * for the crime of typing at their usual speed. The students this hits hardest
         * are the ones whose devices the tracker works worst on.
         *
         * A sample with no keystrokes is not evidence that the student types slowly; it is
         * an absence of evidence, and the baseline must simply not move.
         */
        if ((int)($metrics['total_keystrokes'] ?? 0) <= 0) {
            \debugging(
                'Essay Guard: no keystrokes captured for user ' . $userid
                    . ' - the writing baseline is left unchanged rather than being pulled'
                    . ' towards zero by a sample that measured nothing.',
                DEBUG_DEVELOPER
            );
            return;
        }

        $existing = $DB->get_record('plagiarism_essayguard_fp', ['userid' => $userid]);

        if (!$existing) {
            // V1.2.219: CHECK-THEN-INSERT RACE against the userid_ix UNIQUE index.
            // get_record() and insert_record() are two separate statements. Two
            // concurrent scoring passes for the same student (a quiz submit racing a
            // still-in-flight log_event flush — log_event closes the session lock, so
            // nothing serialises them) both read "no fingerprint" and both insert; the
            // loser gets an uncaught dml_write_exception and the whole submission errors.
            // Insert-and-recover: if the index rejects our row, the other writer already
            // created the fingerprint, so fall through to the normal EWMA update path
            // using the row they wrote. Same final state, no exception.
            $record = self::init_from_metrics($userid, $metrics);
            try {
                $DB->insert_record('plagiarism_essayguard_fp', $record);
                return;
            } catch (\dml_write_exception $e) {
                $existing = $DB->get_record('plagiarism_essayguard_fp', ['userid' => $userid]);
                if (!$existing) {
                    // Not a duplicate-key collision — a real write failure. Re-raise.
                    throw $e;
                }
            }
        }

        $count   = (int)$existing->samplecount + 1;
        $alphan = self::WEIGHT_NEW;
        $alphao = self::WEIGHT_OLD;

        $record = (object)[
            'id'                          => $existing->id,
            'userid'                      => $userid,
            'samplecount'                 => $count,
            'baseline_wpm'               => self::ewma(
                (float)$existing->baseline_wpm,
                (float)($metrics['average_wpm'] ?? 0),
                $alphan,
                $alphao
            ),
            'baseline_pause_mean'        => self::ewma(
                (float)$existing->baseline_pause_mean,
                (float)($metrics['pause_mean'] ?? 0),
                $alphan,
                $alphao
            ),
            'baseline_backspace_ratio'   => self::ewma(
                (float)$existing->baseline_backspace_ratio,
                (float)($metrics['backspace_ratio'] ?? 0),
                $alphan,
                $alphao
            ),
            'baseline_burst_mean'        => self::ewma(
                (float)$existing->baseline_burst_mean,
                (float)($metrics['burst_mean'] ?? 0),
                $alphan,
                $alphao
            ),
            'baseline_sentence_variance' => self::ewma(
                (float)$existing->baseline_sentence_variance,
                (float)($metrics['sentence_variance'] ?? 0),
                $alphan,
                $alphao
            ),
            'baseline_vocab_diversity'   => self::ewma(
                (float)$existing->baseline_vocab_diversity,
                (float)($metrics['vocab_diversity'] ?? 0),
                $alphan,
                $alphao
            ),
            'baseline_entropy'           => self::ewma(
                (float)$existing->baseline_entropy,
                (float)($metrics['entropy_score'] ?? 0),
                $alphan,
                $alphao
            ),
            'baseline_interkey_mean'     => self::ewma(
                (float)$existing->baseline_interkey_mean,
                (float)($metrics['interkey_mean'] ?? 0),
                $alphan,
                $alphao
            ),
            'baseline_status'            => self::status_from_count($count),
            'timemodified'               => time(),
        ];

        $DB->update_record('plagiarism_essayguard_fp', $record);
    }


    /**
     * Fold one submission's metrics into the student's running per-metric statistics.
     *
     * BASELINE-EG-WELFORD (v1.4.0). Welford's online algorithm keeps a running mean and
     * sum of squared deviations (M2) in constant space and without the catastrophic
     * cancellation of the naive sum-of-squares form, so the standard deviation is
     * available at any time without storing the samples.
     *
     * Statistics are partitioned by activity type. A quiz essay is written under time
     * pressure in short bursts; an assignment is drafted at leisure. Blending the two
     * into one distribution inflates the variance until nothing can ever deviate from it,
     * which is the quiet way a comparative signal stops working.
     *
     * @param int    $userid      The student.
     * @param string $contexttype Activity type: quiz, assign, forum or other.
     * @param array  $metrics     The metrics measured for this submission.
     * @return void
     */
    public static function update_statistics(int $userid, string $contexttype, array $metrics): void {
        global $DB;

        $contexttype = self::normalise_context_type($contexttype);
        $now         = time();

        foreach (self::TRACKED_METRICS as $name => $metrickey) {
            if (!self::sample_is_usable($metrickey, $metrics)) {
                continue;
            }
            $value = (float)$metrics[$metrickey];

            $row = $DB->get_record('plagiarism_essayguard_fpm', [
                'userid'      => $userid,
                'contexttype' => $contexttype,
                'metricname'  => $name,
                ]);

            if (!$row) {
                // Insert-and-recover against the unique index: two scoring passes for the
                // same student can race here exactly as they can on the fingerprint row.
                try {
                    $DB->insert_record('plagiarism_essayguard_fpm', (object)[
                        'userid'       => $userid,
                        'contexttype'  => $contexttype,
                        'metricname'   => $name,
                        'samplen'      => 1,
                        'runmean'      => $value,
                        'runm2'        => 0,
                        'timemodified' => $now,
                        ]);
                    continue;
                } catch (\dml_write_exception $e) {
                    $row = $DB->get_record('plagiarism_essayguard_fpm', [
                        'userid'      => $userid,
                        'contexttype' => $contexttype,
                        'metricname'  => $name,
                        ]);
                    if (!$row) {
                        throw $e;
                    }
                }
            }

            // Welford update.
            $n     = (int)$row->samplen + 1;
            $mean  = (float)$row->runmean;
            $delta = $value - $mean;
            $mean += $delta / $n;
            $m2    = (float)$row->runm2 + $delta * ($value - $mean);

            $DB->update_record('plagiarism_essayguard_fpm', (object)[
                'id'           => $row->id,
                'samplen'      => $n,
                'runmean'      => round($mean, 6),
                'runm2'        => round($m2, 6),
                'timemodified' => $now,
                ]);
        }
    }

    /**
     * How far this submission sits from the student's own established pattern.
     *
     * BASELINE-EG-WELFORD (v1.4.0). This replaces a fixed proportional tolerance — 50 %
     * for most metrics, identical for every student — with a z-score measured in that
     * student's own standard deviations for that metric in that kind of activity.
     *
     * A metric contributes nothing until it has MIN_SAMPLES_FOR_Z samples behind it, and
     * nothing is reported at all until MIN_METRICS_FOR_SCORE metrics qualify, so a new
     * student is never compared against a distribution that does not exist yet.
     *
     * @param int    $userid      The student.
     * @param string $contexttype Activity type the current submission belongs to.
     * @param array  $metrics     The metrics measured for this submission.
     * @return array {
     * @var float    deviation  0.0-1.0 combined departure from the student's pattern.
     * @var int      metrics    How many metrics were comparable.
     * @var string[] drivers    Metric names that exceeded the floor, worst first.
     * @var array    detail     Per-metric z-scores, for the teacher-facing breakdown.
     * }
     */
    public static function comparative_deviation(int $userid, string $contexttype, array $metrics): array {
        global $DB;

        $empty = ['deviation' => 0.0, 'metrics' => 0, 'drivers' => [], 'detail' => []];

        $contexttype = self::normalise_context_type($contexttype);
        $rows = $DB->get_records('plagiarism_essayguard_fpm', [
            'userid'      => $userid,
            'contexttype' => $contexttype,
            ]);
        if (empty($rows)) {
            return $empty;
        }

        $scores  = [];
        $detail  = [];
        foreach ($rows as $row) {
            $name = $row->metricname;
            if (!isset(self::TRACKED_METRICS[$name])) {
                continue;
            }
            $metrickey = self::TRACKED_METRICS[$name];
            if (!self::sample_is_usable($metrickey, $metrics)) {
                continue;
            }
            $n = (int)$row->samplen;
            if ($n < self::MIN_SAMPLES_FOR_Z) {
                continue;
            }

            $sd = sqrt(max(0.0, (float)$row->runm2) / max(1, $n - 1));
            $mean = (float)$row->runmean;

            // A student who is genuinely metronomic produces a near-zero standard
            // deviation, and dividing by it would turn any trivial change into an
            // enormous z. Floor the spread at 5 % of the mean (or a small absolute
            // value when the mean itself is near zero) so consistency cannot
            // manufacture a deviation.
            $floor = max(abs($mean) * 0.05, 1e-6);
            $sd    = max($sd, $floor);

            $z = abs((float)$metrics[$metrickey] - $mean) / $sd;

            $contribution = 0.0;
            if ($z > self::Z_FLOOR) {
                $contribution = min(1.0, ($z - self::Z_FLOOR) / (self::Z_CEILING - self::Z_FLOOR));
            }

            $scores[$name] = $contribution;
            $detail[$name] = [
                'z'        => round($z, 2),
                'mean'     => round($mean, 4),
                'sd'       => round($sd, 4),
                'observed' => round((float)$metrics[$metrickey], 4),
                'n'        => $n,
                ];
        }

        if (count($scores) < self::MIN_METRICS_FOR_SCORE) {
            return $empty;
        }

        arsort($scores);
        $drivers = [];
        foreach ($scores as $name => $value) {
            if ($value > 0.0) {
                $drivers[] = $name;
            }
        }

        return [
            'deviation' => min(1.0, array_sum($scores) / count($scores)),
            'metrics'   => count($scores),
            'drivers'   => $drivers,
            'detail'    => $detail,
            ];
    }

    /**
     * Whether this submission's value for a metric is a measurement or an absence.
     *
     * A zero that means "nothing was captured" must never enter the statistics: three
     * such submissions used to leave a student with a confident baseline at roughly 42 %
     * of their true speed, after which typing normally read as a large deviation. The
     * students that hits hardest are the ones whose devices the tracker works worst on.
     *
     * @param string $metrickey Key in the metrics array.
     * @param array  $metrics   The metrics measured for this submission.
     * @return bool True when the value is a real measurement.
     */
    private static function sample_is_usable(string $metrickey, array $metrics): bool {
        if (!array_key_exists($metrickey, $metrics)) {
            return false;
        }
        if ((int)($metrics['total_keystrokes'] ?? 0) <= 0) {
            return false;
        }
        $value = (float)$metrics[$metrickey];
        if (!is_finite($value)) {
            return false;
        }
        // Every tracked metric is strictly positive when it was genuinely measured.
        return $value > 0.0;
    }

    /**
     * Collapse a Moodle module name onto the activity types the baseline partitions by.
     *
     * @param string $modname The activity type name, e.g. "quiz".
     * @return string One of quiz, assign, forum or other.
     */
    public static function normalise_context_type(string $modname): string {
        $known = ['quiz', 'assign', 'forum'];
        return in_array($modname, $known, true) ? $modname : 'other';
    }

    /**
     * Baseline confidence for this student in this kind of activity.
     *
     * Reported from the per-metric sample counts rather than a single counter, so the
     * label describes the statistics actually available for comparison.
     *
     * @param int    $userid      The student.
     * @param string $contexttype Activity type.
     * @return array {
     * @var string status  none, preliminary or stable.
     * @var int    metrics  How many metrics have enough samples to compare.
     * @var int    samples  Samples behind the best-established metric.
     * }
     */
    public static function confidence(int $userid, string $contexttype): array {
        global $DB;

        $contexttype = self::normalise_context_type($contexttype);
        $rows = $DB->get_records('plagiarism_essayguard_fpm', [
            'userid'      => $userid,
            'contexttype' => $contexttype,
            ]);

        $qualifying = 0;
        $best       = 0;
        foreach ($rows as $row) {
            $n = (int)$row->samplen;
            $best = max($best, $n);
            if ($n >= self::MIN_SAMPLES_FOR_Z) {
                $qualifying++;
            }
        }

        $status = self::STATUS_NONE;
        if ($qualifying >= self::MIN_METRICS_FOR_SCORE) {
            $status = self::STATUS_STABLE;
        } else if ($best >= self::PRELIM_THRESHOLD) {
            $status = self::STATUS_PRELIM;
        }

        return ['status' => $status, 'metrics' => $qualifying, 'samples' => $best];
    }

    /**
     * Retrieve the student's fingerprint record, or null if not enough samples.
     *
     * @param int $userid The student to look up.
     * @return object|null The fingerprint record, or null when the student has no usable
     *                     baseline yet.
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
     *
     * @param int   $userid  The student to compare against their own baseline.
     * @param array $metrics The behavioural metrics measured for this submission.
     * @return float 0.0 to 1.0; 0.0 when the student has no baseline to compare with.
     */
    public static function deviation_score(int $userid, array $metrics): float {
        $fp = self::get($userid);
        if (!$fp) {
            return 0.0;
        }

        $deviations = [];

        $deviations[] = self::relative_deviation(
            (float)$fp->baseline_wpm,
            (float)($metrics['average_wpm'] ?? 0),
            0.5
        );
        $deviations[] = self::relative_deviation(
            (float)$fp->baseline_backspace_ratio,
            (float)($metrics['backspace_ratio'] ?? 0),
            0.5
        );
        $deviations[] = self::relative_deviation(
            (float)$fp->baseline_sentence_variance,
            (float)($metrics['sentence_variance'] ?? 0),
            0.5
        );
        $deviations[] = self::relative_deviation(
            (float)$fp->baseline_entropy,
            (float)($metrics['entropy_score'] ?? 0),
            0.3
        );
        $deviations[] = self::relative_deviation(
            (float)$fp->baseline_pause_mean,
            (float)($metrics['pause_mean'] ?? 0),
            0.5
        );

        $deviations = array_filter($deviations, fn($d) => $d >= 0);

        if (empty($deviations)) {
            return 0.0;
        }

        return min(1.0, array_sum($deviations) / count($deviations));
    }

    /**
     * Blend a new measurement into a running baseline value.
     *
     * An exponentially weighted moving average, so recent submissions matter more
     * than old ones without discarding history. A baseline of zero is treated as
     * "not yet set" and adopts the new value outright.
     *
     * @param float $old     The current baseline value.
     * @param float $new     The value measured for this submission.
     * @param float $alphan Weight given to the new value.
     * @param float $alphao Weight given to the existing baseline.
     * @return float The updated baseline value, rounded to four decimal places.
     */
    private static function ewma(float $old, float $new, float $alphan, float $alphao): float {
        if ($old == 0.0) {
            return $new;
        }
        return round($alphao * $old + $alphan * $new, 4);
    }

    /**
     * How far one measurement departs from the student's own baseline.
     *
     * Deviations within $threshold are treated as normal variation and score 0.0.
     * Beyond it the excess is scaled linearly to 1.0.
     *
     * @param float $baseline  The student's baseline value for this measurement.
     * @param float $current   The value measured for this submission.
     * @param float $threshold Proportional change tolerated before scoring anything.
     * @return float 0.0 to 1.0, or -1.0 when there is no usable baseline to compare with.
     */
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

    /**
     * Build a first fingerprint record from one submission's metrics.
     *
     * @param int   $userid  The student the fingerprint belongs to.
     * @param array $metrics The metrics measured for this submission.
     * @return object An unsaved plagiarism_essayguard_fp record with samplecount 1.
     */
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

    /**
     * Map the number of contributing submissions onto a baseline confidence level.
     *
     * @param int $count How many submissions the baseline has been built from.
     * @return string One of STATUS_NONE, STATUS_PRELIM or STATUS_STABLE.
     */
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
