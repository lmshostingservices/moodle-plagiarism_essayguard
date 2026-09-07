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
 * Tests for the student writing-baseline engine.
 *
 * The baseline is the longest-lived record this plugin keeps and it feeds a scoring
 * signal, so what does and does not move it is the whole of its behaviour.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_essayguard\local\service\fingerprint
 */
final class fingerprint_test extends \advanced_testcase {
    /** @var int The student the fixtures belong to. */
    const USERID = 4242;

    /**
     * A plausible set of behavioural metrics.
     *
     * @param float $wpm             Words per minute for this sample.
     * @param int   $totalkeystrokes Keystrokes captured for this sample.
     * @return array
     */
    private function metrics(float $wpm = 40.0, int $totalkeystrokes = 500): array {
        return [
            'total_keystrokes'  => $totalkeystrokes,
            'average_wpm'       => $wpm,
            'pause_mean'        => 800.0,
            'backspace_ratio'   => 0.08,
            'burst_mean'        => 40.0,
            'sentence_variance' => 20.0,
            'vocab_diversity'   => 0.6,
            'entropy_score'     => 0.4,
            'interkey_mean'     => 200.0,
        ];
    }

    /**
     * The first sample creates the record, and a single sample is explicitly NOT a
     * baseline anyone may be judged against.
     *
     * @return void
     */
    public function test_first_sample_creates_an_unusable_baseline(): void {
        global $DB;
        $this->resetAfterTest();

        fingerprint::update(self::USERID, $this->metrics(45.0));

        $record = $DB->get_record('plagiarism_essayguard_fp', ['userid' => self::USERID]);
        $this->assertNotFalse($record);
        $this->assertEquals(1, $record->samplecount);
        $this->assertEquals(45.0, $record->baseline_wpm);
        $this->assertSame(fingerprint::STATUS_NONE, $record->baseline_status);
        $this->assertNull(fingerprint::get(self::USERID));
    }

    /**
     * Confidence is promoted at three samples and again at five, and never before.
     *
     * @return void
     */
    public function test_confidence_is_promoted_at_the_documented_thresholds(): void {
        global $DB;
        $this->resetAfterTest();

        $expected = [
            1 => fingerprint::STATUS_NONE,
            2 => fingerprint::STATUS_NONE,
            3 => fingerprint::STATUS_PRELIM,
            4 => fingerprint::STATUS_PRELIM,
            5 => fingerprint::STATUS_STABLE,
            6 => fingerprint::STATUS_STABLE,
        ];
        foreach ($expected as $count => $status) {
            fingerprint::update(self::USERID, $this->metrics());
            $record = $DB->get_record('plagiarism_essayguard_fp', ['userid' => self::USERID]);
            $this->assertEquals($count, $record->samplecount);
            $this->assertSame($status, $record->baseline_status, "After {$count} sample(s).");
        }
    }

    /**
     * Later samples are blended in, not substituted: the baseline keeps three quarters of
     * its history each time.
     *
     * @return void
     */
    public function test_later_samples_are_blended_into_the_baseline(): void {
        global $DB;
        $this->resetAfterTest();

        fingerprint::update(self::USERID, $this->metrics(40.0));
        fingerprint::update(self::USERID, $this->metrics(80.0));

        $record = $DB->get_record('plagiarism_essayguard_fp', ['userid' => self::USERID]);
        // 0.75 * 40 + 0.25 * 80.
        $this->assertEquals(50.0, (float)$record->baseline_wpm);
        $this->assertEquals(2, $record->samplecount);
    }

    /**
     * REGRESSION (V1.2.229 FIX-EG-FINGERPRINT-ZERO-SAMPLE): a submission that captured no
     * keystrokes must leave the baseline exactly where it was.
     *
     * The observer hands update() whatever score_attempt() produced. When the tracker
     * recorded nothing usable — a mobile submission, a theme that drops the footer hook,
     * JS off, a flush that never landed — every behavioural metric is 0, and ewma()
     * multiplied the student's real baseline by 0.75 and added nothing. Three such
     * submissions leave a CONFIDENT baseline at about 42 % of the student's true speed;
     * the next time they type normally, Signal 11 adds up to 15 risk points for the crime
     * of typing at their usual speed. The students it hits hardest are the ones whose
     * devices the tracker works worst on.
     *
     * @return void
     */
    public function test_a_sample_with_no_keystrokes_does_not_move_the_baseline(): void {
        global $DB;
        $this->resetAfterTest();

        for ($i = 0; $i < 5; $i++) {
            fingerprint::update(self::USERID, $this->metrics(60.0));
        }
        $before = $DB->get_record('plagiarism_essayguard_fp', ['userid' => self::USERID]);
        $this->assertSame(fingerprint::STATUS_STABLE, $before->baseline_status);
        $this->assertEquals(60.0, (float)$before->baseline_wpm);

        // Three submissions where the tracker captured nothing at all.
        for ($i = 0; $i < 3; $i++) {
            fingerprint::update(
                self::USERID,
                [
                    'total_keystrokes' => 0,
                    'average_wpm'      => 0.0,
                    'pause_mean'       => 0.0,
                    'backspace_ratio'  => 0.0,
                    ]
            );
            $this->assertDebuggingCalled();
        }

        $after = $DB->get_record('plagiarism_essayguard_fp', ['userid' => self::USERID]);
        $this->assertEquals(60.0, (float)$after->baseline_wpm);
        $this->assertEquals($before->samplecount, $after->samplecount);

        // And the student is not then judged deviant for typing at their usual speed.
        $this->assertSame(0.0, fingerprint::deviation_score(self::USERID, $this->metrics(60.0)));
    }

    /**
     * A student with no baseline at all scores no deviation, so an absent baseline can
     * never contribute risk.
     *
     * @return void
     */
    public function test_deviation_is_zero_without_a_usable_baseline(): void {
        $this->resetAfterTest();

        $this->assertSame(0.0, fingerprint::deviation_score(self::USERID, $this->metrics()));

        // Still zero at one sample, because the baseline is not yet usable.
        fingerprint::update(self::USERID, $this->metrics(40.0));
        $this->assertSame(0.0, fingerprint::deviation_score(self::USERID, $this->metrics(400.0)));
    }

    /**
     * Once the baseline is usable, a session far outside it scores a deviation, and a
     * session inside the tolerance band scores none.
     *
     * @return void
     */
    public function test_deviation_only_fires_outside_the_tolerance_band(): void {
        $this->resetAfterTest();

        for ($i = 0; $i < 5; $i++) {
            fingerprint::update(self::USERID, $this->metrics(40.0));
        }

        // Within the 50 % tolerance on every measure.
        $this->assertSame(0.0, fingerprint::deviation_score(self::USERID, $this->metrics(45.0)));

        // Far outside it.
        $wild = $this->metrics(400.0);
        $wild['backspace_ratio'] = 0.9;
        $wild['pause_mean']      = 12000.0;
        $this->assertGreaterThan(0.0, fingerprint::deviation_score(self::USERID, $wild));
    }

    /**
     * get() withholds a baseline that is not yet usable, which is what stops a
     * one-submission student being compared against themselves.
     *
     * @return void
     */
    public function test_get_withholds_an_unusable_baseline(): void {
        $this->resetAfterTest();

        fingerprint::update(self::USERID, $this->metrics());
        fingerprint::update(self::USERID, $this->metrics());
        $this->assertNull(fingerprint::get(self::USERID));

        fingerprint::update(self::USERID, $this->metrics());
        $fp = fingerprint::get(self::USERID);
        $this->assertNotNull($fp);
        $this->assertSame(fingerprint::STATUS_PRELIM, $fp->baseline_status);
    }

    /**
     * Two writers racing to create the same student's first baseline must not produce an
     * uncaught dml_write_exception out of the userid unique index: the loser recovers by
     * blending into the row the winner wrote.
     *
     * @return void
     */
    public function test_a_concurrent_first_write_is_recovered_not_thrown(): void {
        global $DB;
        $this->resetAfterTest();

        // Stand in for the other writer having got there first, between the read and the
        // insert this call is about to attempt.
        $DB->insert_record(
            'plagiarism_essayguard_fp',
            (object)[
                'userid'          => self::USERID,
                'samplecount'     => 1,
                'baseline_wpm'    => 40.0,
                'baseline_status' => fingerprint::STATUS_NONE,
                'timemodified'    => time(),
                ]
        );

        fingerprint::update(self::USERID, $this->metrics(80.0));

        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_fp', ['userid' => self::USERID]));
        $record = $DB->get_record('plagiarism_essayguard_fp', ['userid' => self::USERID]);
        $this->assertEquals(2, $record->samplecount);
        $this->assertEquals(50.0, (float)$record->baseline_wpm);
    }
}
