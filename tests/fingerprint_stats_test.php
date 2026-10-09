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

use plagiarism_essayguard\local\service\fingerprint;

/**
 * Tests for the comparative baseline (BASELINE-EG-WELFORD, v1.4.0).
 *
 * These are written as specifications of what the comparison must and must not do, not as
 * snapshots of what it currently returns. The distinction matters: the existing analyser
 * tests record observed output, which is how a false positive came to be asserted as
 * correct behaviour in analyser_test.php.
 *
 * The four properties that make comparative scoring safe to put in front of an appeal:
 *   - a student with no history is never compared,
 *   - a typical submission from an established student scores nothing,
 *   - consistency is not punished,
 *   - one kind of activity never answers for another.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_essayguard\local\service\fingerprint
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\plagiarism_essayguard\local\service\fingerprint::class)]
final class fingerprint_stats_test extends \advanced_testcase {
    /**
     * Build a metrics array for one submission.
     *
     * @param float $wpm     Words per minute.
     * @param float $pause   Mean pause in milliseconds.
     * @param float $bsratio Backspace ratio.
     * @param float $iki     Mean inter-key delay in milliseconds.
     * @return array Metrics as score_attempt() would produce them.
     */
    private function metrics(float $wpm, float $pause, float $bsratio, float $iki): array {
        return [
            'total_keystrokes'   => 500,
            'average_wpm'        => $wpm,
            'pause_mean'         => $pause,
            'backspace_ratio'    => $bsratio,
            'interkey_mean'      => $iki,
            'burst_mean'         => 14.0,
            'sentence_variance'  => 30.0,
            'vocab_diversity'    => 0.62,
            'entropy_score'      => 0.55,
            'transition_density' => 3.0,
            'paragraph_variance' => 25.0,
        ];
    }

    /**
     * Record a run of ordinary submissions for one student.
     *
     * @param int    $userid      The student.
     * @param string $contexttype Activity type.
     * @param int    $count       How many submissions to record.
     * @return void
     */
    private function build_history(int $userid, string $contexttype, int $count): void {
        for ($i = 0; $i < $count; $i++) {
            // Small, ordinary week-to-week variation.
            $drift = 1.0 + (($i % 3) - 1) * 0.06;
            fingerprint::update_statistics(
                $userid,
                $contexttype,
                $this->metrics(42.0 * $drift, 3000.0 * $drift, 0.06 * $drift, 210.0 * $drift)
            );
        }
    }

    /**
     * A student with no history must never be compared against one.
     *
     * This is the defect the rework exists to fix: the old engine awarded deviation points
     * from the student's second submission onward, so the report could print "No baseline
     * yet" above a signal table scoring a departure from that baseline.
     *
     * @return void
     */
    public function test_a_student_with_no_history_is_never_compared(): void {
        $this->resetAfterTest();

        $result = fingerprint::comparative_deviation(
            1001,
            'quiz',
            $this->metrics(140.0, 400.0, 0.001, 60.0)
        );

        $this->assertEquals(0.0, $result['deviation']);
        $this->assertEquals(0, $result['metrics']);
    }

    /**
     * Below the sample threshold nothing is reported, however extreme the submission.
     *
     * @return void
     */
    public function test_history_below_the_threshold_reports_nothing(): void {
        $this->resetAfterTest();

        $this->build_history(1002, 'quiz', fingerprint::MIN_SAMPLES_FOR_Z - 1);

        $result = fingerprint::comparative_deviation(
            1002,
            'quiz',
            $this->metrics(140.0, 400.0, 0.001, 60.0)
        );

        $this->assertEquals(0.0, $result['deviation']);
    }

    /**
     * An established student submitting typical work scores nothing.
     *
     * @return void
     */
    public function test_a_typical_submission_scores_nothing(): void {
        $this->resetAfterTest();

        $this->build_history(1003, 'quiz', 12);

        $result = fingerprint::comparative_deviation(
            1003,
            'quiz',
            $this->metrics(43.0, 3050.0, 0.059, 208.0)
        );

        $this->assertEquals(0.0, $result['deviation']);
        $this->assertSame([], $result['drivers']);
    }

    /**
     * A submission far outside the student's own pattern is reported, with its drivers.
     *
     * @return void
     */
    public function test_a_sharply_different_submission_is_reported(): void {
        $this->resetAfterTest();

        $this->build_history(1004, 'quiz', 12);

        $result = fingerprint::comparative_deviation(
            1004,
            'quiz',
            $this->metrics(130.0, 500.0, 0.001, 65.0)
        );

        $this->assertGreaterThan(0.3, $result['deviation']);
        $this->assertContains('wpm', $result['drivers']);
        $this->assertArrayHasKey('wpm', $result['detail']);
        $this->assertGreaterThan(2.0, $result['detail']['wpm']['z']);
    }

    /**
     * A perfectly consistent student is not punished for being consistent.
     *
     * Without a floor on the standard deviation, a student whose measurements barely move
     * would have every trivial change register as an enormous number of standard
     * deviations. The most regular writers would be the most suspected.
     *
     * @return void
     */
    public function test_consistency_is_not_treated_as_deviation(): void {
        $this->resetAfterTest();

        for ($i = 0; $i < 12; $i++) {
            fingerprint::update_statistics(1005, 'quiz', $this->metrics(40.0, 3000.0, 0.05, 200.0));
        }

        $result = fingerprint::comparative_deviation(
            1005,
            'quiz',
            $this->metrics(41.0, 3050.0, 0.051, 203.0)
        );

        $this->assertEquals(0.0, $result['deviation']);
    }

    /**
     * Quiz history does not answer for an assignment.
     *
     * Quiz writing is short and time-pressured; assignment writing is drafted at leisure.
     * Pooling them inflates the variance until nothing can deviate from it.
     *
     * @return void
     */
    public function test_activity_types_are_partitioned(): void {
        $this->resetAfterTest();

        $this->build_history(1006, 'quiz', 12);

        $result = fingerprint::comparative_deviation(
            1006,
            'assign',
            $this->metrics(130.0, 500.0, 0.001, 65.0)
        );

        $this->assertEquals(0.0, $result['deviation']);
        $this->assertEquals(0, $result['metrics']);
    }

    /**
     * A submission that captured nothing must not move the statistics.
     *
     * Folding zeroes in used to drag a student's recorded speed down a quarter at a time
     * while the sample count still advanced towards "stable", after which typing normally
     * read as a large deviation. It hit hardest the students whose devices the tracker
     * works worst on.
     *
     * @return void
     */
    public function test_a_submission_with_no_keystrokes_does_not_move_the_baseline(): void {
        global $DB;
        $this->resetAfterTest();

        $this->build_history(1007, 'quiz', 10);
        $before = $DB->get_record('plagiarism_essayguard_fpm', [
            'userid'      => 1007,
            'contexttype' => 'quiz',
            'metricname'  => 'wpm',
        ]);

        $empty = $this->metrics(0.0, 0.0, 0.0, 0.0);
        $empty['total_keystrokes'] = 0;
        fingerprint::update_statistics(1007, 'quiz', $empty);

        $after = $DB->get_record('plagiarism_essayguard_fpm', [
            'userid'      => 1007,
            'contexttype' => 'quiz',
            'metricname'  => 'wpm',
        ]);

        $this->assertEquals((int)$before->samplen, (int)$after->samplen);
        $this->assertEquals((float)$before->runmean, (float)$after->runmean);
    }

    /**
     * Welford's running mean and variance match the textbook values.
     *
     * @return void
     */
    public function test_running_statistics_are_correct(): void {
        global $DB;
        $this->resetAfterTest();

        $values = [10.0, 12.0, 23.0, 23.0, 16.0, 23.0, 21.0, 16.0];
        foreach ($values as $value) {
            fingerprint::update_statistics(1008, 'quiz', $this->metrics($value, 3000.0, 0.06, 210.0));
        }

        $row = $DB->get_record('plagiarism_essayguard_fpm', [
            'userid'      => 1008,
            'contexttype' => 'quiz',
            'metricname'  => 'wpm',
        ]);

        $n    = count($values);
        $mean = array_sum($values) / $n;
        $m2   = 0.0;
        foreach ($values as $value) {
            $m2 += ($value - $mean) ** 2;
        }

        $this->assertEquals($n, (int)$row->samplen);
        $this->assertEqualsWithDelta($mean, (float)$row->runmean, 0.0001);
        $this->assertEqualsWithDelta($m2, (float)$row->runm2, 0.0001);
    }

    /**
     * Confidence is reported from the statistics that exist, per activity type.
     *
     * @return void
     */
    public function test_confidence_reflects_the_available_statistics(): void {
        $this->resetAfterTest();

        $this->assertEquals(
            fingerprint::STATUS_NONE,
            fingerprint::confidence(1009, 'quiz')['status']
        );

        $this->build_history(1009, 'quiz', 3);
        $this->assertEquals(
            fingerprint::STATUS_PRELIM,
            fingerprint::confidence(1009, 'quiz')['status']
        );

        $this->build_history(1009, 'quiz', 9);
        $confidence = fingerprint::confidence(1009, 'quiz');
        $this->assertEquals(fingerprint::STATUS_STABLE, $confidence['status']);
        $this->assertGreaterThanOrEqual(fingerprint::MIN_METRICS_FOR_SCORE, $confidence['metrics']);

        // A different activity type has its own, separate confidence.
        $this->assertEquals(
            fingerprint::STATUS_NONE,
            fingerprint::confidence(1009, 'assign')['status']
        );
    }
}
