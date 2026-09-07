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

use plagiarism_essayguard\local\service\explainer;

/**
 * Unit tests for the explanation engine.
 *
 * explainer::explain() is pure apart from get_string(), so the tests assert how many
 * explanations a metric set produces rather than their wording, which is a language
 * pack concern. Every case starts from a baseline metric set deliberately built so
 * that no rule fires, and changes exactly one metric, so a count of one proves that
 * one rule and only that rule fired. Every count was observed from a run of the real
 * method against the exact metric set given.
 *
 * Since v1.2.225 the method never returns an empty array, so a count alone no longer
 * separates "a rule fired" from "nothing fired and the guaranteed fallback filled in".
 * The two are told apart without reading any English: a rule hit does not depend on the
 * risk band, so the low and high results are identical, while a fallback does depend on
 * it — low gets the reassuring line and every other band gets the no-specific-indicator
 * line, so the two results differ.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_essayguard\local\service\explainer
 */
final class explainer_test extends \advanced_testcase {
    /**
     * A metric set on which no explanation rule fires.
     *
     * @return array The quiet baseline.
     */
    private static function baseline(): array {
        return [
            'paste_events'         => 0,
            'burstsuspicious'      => 0,
            'backspace_ratio'      => 0.10,
            'total_keystrokes'     => 200,
            'entropy_score'        => 0.9,
            'charsadded'           => 100,
            'pausecount'           => 5,
            'sentence_variance'    => 30.0,
            'vocab_diversity'      => 0.8,
            'thinking_pause_score' => 0.5,
            'interkey_mean'        => 250.0,
            'iki_autocorr'         => 0.1,
            'speed_burst_cv'       => 0.9,
            'typing_time'          => 40000,
            'text_chars'           => 200,
            'average_wpm'          => 40.0,
        ];
    }

    /**
     * Changing one metric away from the quiet baseline fires exactly one rule.
     *
     * @dataProvider single_rule_provider
     * @param array $overrides The metrics to change from the baseline.
     * @param int   $expected  The number of explanations observed for the result.
     * @return void
     */
    public function test_one_metric_fires_one_rule(array $overrides, int $expected): void {
        $metrics = $overrides + self::baseline();
        $explanations = explainer::explain($metrics, 'high');
        $this->assertCount($expected, $explanations);
        // A rule states an observation and is the same whatever band the score fell in.
        // Equality with the low-band result therefore proves these came from rules and
        // not from the band-dependent fallback added in v1.2.225.
        $this->assertSame(explainer::explain($metrics, 'low'), $explanations);
    }

    /**
     * One dataset per rule, each at a value that fires it and, where the rule has two
     * tiers, one value in each tier.
     *
     * @return array[] Each dataset: metric overrides, then the expected explanation count.
     */
    public static function single_rule_provider(): array {
        return [
            'one paste'                           => [['paste_events' => 1], 1],
            'three pastes'                        => [['paste_events' => 3], 1],
            'suspicious bursts'                   => [['burstsuspicious' => 2], 1],
            'no corrections at all'               => [['backspace_ratio' => 0.0], 1],
            'slightly few corrections'            => [['backspace_ratio' => 0.03], 1],
            'robotic rhythm'                      => [['entropy_score' => 0.2], 1],
            'smooth rhythm'                       => [['entropy_score' => 0.45], 1],
            'no pauses over 300 chars'            => [['pausecount' => 0,
                                                       'charsadded' => 400], 1],
            'uniform sentences'                   => [['sentence_variance' => 5.0], 1],
            'fairly uniform sentences'            => [['sentence_variance' => 11.0], 1],
            'narrow vocabulary'                   => [['vocab_diversity' => 0.25], 1],
            'fairly narrow vocabulary'            => [['vocab_diversity' => 0.35], 1],
            'no thinking pauses'                  => [['thinking_pause_score' => 0.05,
                                                       'charsadded' => 400], 1],
            'very fast keys'                      => [['interkey_mean' => 50.0], 1],
            'strongly repetitive timing'          => [['iki_autocorr' => 0.8], 1],
            'mildly repetitive timing'            => [['iki_autocorr' => 0.5], 1],
            'constant speed over 30 s'            => [['speed_burst_cv' => 0.2], 1],
            'keystroke ratio well below one'      => [['total_keystrokes' => 80], 1],
            'keystroke ratio a little below one'  => [['total_keystrokes' => 140], 1],
            // V1.2.225 FIX-EG-EXPLAINER-GAPS: the six signals that could score points
            // with nothing said about them. Each of these metric sets produced an empty
            // array before the fix.
            'editor insert with no paste event'   => [['large_inserts' => 2], 1],
            'editor insert alongside a paste'     => [['large_inserts' => 2,
                                                       'paste_events' => 1], 1],
            'impossible characters per second'    => [['chars_per_sec' => 30.0,
                                                       'charsadded' => 600], 1],
            'fast characters per second'          => [['chars_per_sec' => 10.4,
                                                       'charsadded' => 600], 1],
            'answer finished in four seconds'     => [['typing_time' => 4200,
                                                       'charsadded' => 600], 1],
            'near-identical keystroke gaps'       => [['iki_shannon' => 0.2], 1],
            'fairly regular keystroke gaps'       => [['iki_shannon' => 0.45], 1],
            'server timing beyond typing speed'   => [['server_cps' => 20.5], 1],
            'server timing merely fast'           => [['server_cps' => 6.25], 1],
        ];
    }

    /**
     * A metric set on which no rule fires is given exactly one explanation, and which one
     * depends on the band: the reassuring line for LOW, the no-specific-indicator line
     * otherwise. Before v1.2.225 every one of these returned [] for a non-low record.
     *
     * @dataProvider no_rule_provider
     * @param array $overrides The metrics to change from the baseline.
     * @return void
     */
    public function test_a_metric_outside_every_gate_falls_back(array $overrides): void {
        $metrics = $overrides + self::baseline();
        $high = explainer::explain($metrics, 'high');
        $low  = explainer::explain($metrics, 'low');
        $this->assertCount(1, $high);
        $this->assertCount(1, $low);
        // Two different lines: nothing was observed, so what is said depends on the band.
        $this->assertNotSame($low, $high);
    }

    /**
     * Values sitting just outside a rule's gate, including the gates on the rules added
     * in v1.2.225. None of these may be described as an observation.
     *
     * @return array[] Each dataset: the metric overrides.
     */
    public static function no_rule_provider(): array {
        return [
            'quiet baseline'                      => [[]],
            'no corrections, no keystrokes'       => [['backspace_ratio' => 0.0,
                                                       'total_keystrokes' => 0]],
            'no pauses at exactly 300 chars'      => [['pausecount' => 0,
                                                       'charsadded' => 300]],
            'constant speed under 30 s'           => [['speed_burst_cv' => 0.2,
                                                       'typing_time' => 20000]],
            'natural keystroke gaps'              => [['iki_shannon' => 0.9]],
            // The analyser prefers Shannon entropy over the older entropy score whenever
            // it has enough samples, so the explainer does too: a natural Shannon reading
            // suppresses the older rule rather than adding a second rhythm sentence.
            'natural shannon over low entropy'    => [['iki_shannon' => 0.9,
                                                       'entropy_score' => 0.2]],
            'server timing below the gate'        => [['server_cps' => 3.0]],
            'fast but only twenty characters'     => [['chars_per_sec' => 30.0,
                                                       'charsadded' => 10,
                                                       'text_chars' => 20]],
        ];
    }

    /**
     * A clean low-risk record is given the reassuring explanation.
     *
     * @return void
     */
    public function test_a_clean_low_record_is_explained_as_clean(): void {
        $this->assertCount(1, explainer::explain(self::baseline(), 'low'));
        $this->assertCount(1, explainer::explain([], 'low'));
    }

    /**
     * No record that is not LOW can ever be rendered with an empty reason list.
     *
     * Until v1.2.225 the reassuring line was the only fallback and was gated on the low
     * band, so a MEDIUM or HIGH record whose points came entirely from a signal with no
     * rule -- Signal 3 typing speed, Signal 6 near-instant insertion and Signal 13 server
     * timing had none -- showed the teacher a coloured badge and nothing beside it. Those
     * rules now exist; this asserts the structural guarantee behind them, including for
     * the degenerate case of a metric set carrying no recognised key at all.
     *
     * @return void
     */
    public function test_a_non_low_record_always_gets_an_explanation(): void {
        $this->assertCount(1, explainer::explain(self::baseline(), 'medium'));
        $this->assertCount(1, explainer::explain(self::baseline(), 'high'));
        $this->assertCount(1, explainer::explain([], 'high'));
        $this->assertCount(1, explainer::explain([], 'medium'));
        // The fallback quotes the score when the metrics carry one and stays silent about
        // it when they do not; either way it is exactly one line.
        $this->assertCount(1, explainer::explain(['score100' => 72] + self::baseline(), 'high'));
    }

    /**
     * Baseline comparisons add their own explanations on top of the rule hits.
     *
     * @return void
     */
    public function test_baseline_comparisons(): void {
        $fasterthanbaseline = (object)[
            'baseline_wpm'                => 20.0,
            'baseline_backspace_ratio'    => 0.10,
            'baseline_sentence_variance'  => 20.0,
        ];
        $this->assertCount(
            1,
            explainer::explain(
                ['average_wpm' => 45.0] + self::baseline(),
                'high',
                $fasterthanbaseline
                )
        );

        $fewercorrections = (object)[
            'baseline_wpm'                => 0.0,
            'baseline_backspace_ratio'    => 0.10,
            'baseline_sentence_variance'  => 20.0,
        ];
        // Two: the absolute low-backspace rule and the below-baseline comparison.
        $this->assertCount(
            2,
            explainer::explain(
                ['backspace_ratio' => 0.01] + self::baseline(),
                'high',
                $fewercorrections
                )
        );

        $moreuniform = (object)[
            'baseline_wpm'                => 0.0,
            'baseline_backspace_ratio'    => 0.0,
            'baseline_sentence_variance'  => 20.0,
        ];
        // Two: the absolute medium-uniformity rule and the below-baseline comparison.
        $this->assertCount(
            2,
            explainer::explain(
                ['sentence_variance' => 7.0] + self::baseline(),
                'high',
                $moreuniform
                )
        );

        // The same metrics with no baseline record produce only the absolute rules.
        $this->assertCount(
            1,
            explainer::explain(
                ['sentence_variance' => 7.0] + self::baseline(),
                'high',
                null
                )
        );
    }

    /**
     * Regression tests for FIX-EG-EXPLAINER-GATE-MISMATCH (v1.2.225).
     *
     * The explainer decides from the metrics array alone whether a signal fired. Four of
     * the analyser's gates turned on values that were never stored, so this class
     * approximated them - and approximated them differently. Each dataset below is one of
     * those four, in three states: the analyser's gate satisfied (must explain), the gate
     * not satisfied (must stay silent), and a record written before v1.2.225 that carries
     * none of the new keys (must behave exactly as it always did, so an old report does
     * not change meaning retroactively).
     *
     * The count is asserted rather than the English, because the strings come from
     * get_string() and asserting them would fail on a language-pack edit rather than on a
     * behaviour change.
     *
     * @dataProvider gate_alignment_provider
     * @param array $metrics  The metrics to explain.
     * @param bool  $explains Whether an explanation is expected.
     * @return void
     */
    public function test_explainer_gates_match_the_analyser(array $metrics, bool $explains): void {
        // Since v1.2.225 explain() can never return an empty array, so a count of one
        // does not distinguish "a rule fired" from "the fallback filled the gap". The
        // risk band does: a real rule describes an observation and is the same whatever
        // the band, while the fallback is the band-dependent line (explain_clean for low,
        // explain_noreason for anything else). Comparing the two bands separates them
        // without asserting any English, which would break on a language-pack edit rather
        // than on a behaviour change.
        $low  = explainer::explain($metrics, 'low');
        $high = explainer::explain($metrics, 'high');
        if ($explains) {
            $this->assertSame($low, $high, 'expected a rule to fire, got the band fallback');
        } else {
            $this->assertNotSame($low, $high, 'expected the band fallback, got a rule');
            $this->assertCount(1, $high);
        }
    }

    /**
     * The four realigned gates, each in its satisfied, unsatisfied and legacy state.
     *
     * @return array[] Each dataset: metrics, then whether an explanation is expected.
     */
    public static function gate_alignment_provider(): array {
        // A paste through TinyMCE reports charsadded 0 with a long answer. The analyser
        // fires Signal 4 on max(effective_charsadded, text_chars) > 100; the old rule
        // required charsadded > 300 and never saw it.
        $pastenopauses = ['charsadded' => 0, 'pausecount' => 0, 'text_chars' => 500];

        // V1.2.224 made an entropy of exactly 0.0 the strongest rhythm evidence there is,
        // but only when there were at least 30 delays behind it.
        $constantrhythm = ['entropy_score' => 0.0, 'iki_shannon' => 0.0];

        // The analyser requires 30+ delays before trusting an autocorrelation.
        $autocorr = ['iki_autocorr' => 0.9];

        // The single-sentence linguistic fallback scores at a variance of exactly 0.0.
        $onesentence = ['sentence_variance' => 0.0];

        return [
            'no pauses, gate value stored'        => [$pastenopauses + ['s4_chars' => 500], true],
            'no pauses, gate value below limit'   => [$pastenopauses + ['s4_chars' => 50], false],
            'no pauses, legacy record'            => [$pastenopauses, false],

            'constant rhythm with data behind it' => [$constantrhythm + ['has_rhythm_data' => true], true],
            'constant rhythm with no data'        => [$constantrhythm + ['has_rhythm_data' => false], false],
            'constant rhythm, legacy record'      => [$constantrhythm, false],

            'autocorrelation with 40 delays'      => [$autocorr + ['ikdelay_count' => 40], true],
            'autocorrelation with 3 delays'       => [$autocorr + ['ikdelay_count' => 3], false],
            'autocorrelation, legacy record'      => [$autocorr, true],

            'zero variance, one sentence'         => [$onesentence + ['sentence_count' => 1], true],
            'zero variance, no sentences'         => [$onesentence + ['sentence_count' => 0], false],
            'zero variance, legacy record'        => [$onesentence, false],
        ];
    }

    /**
     * The gate metrics FIX-EG-EXPLAINER-GATE-MISMATCH relies on are actually written.
     *
     * The realignment above is only worth anything if the analyser stores the four values
     * on every scored record. This asserts the contract between the two classes directly,
     * so a future change to the metrics array that drops one of them fails here rather
     * than silently reverting the explainer to approximating again.
     *
     * @return void
     */
    public function test_analyser_stores_the_gate_metrics(): void {
        $this->resetAfterTest();
        $reflection = new \ReflectionClass(\plagiarism_essayguard\local\service\analyser::class);
        $source = file_get_contents($reflection->getFileName());
        foreach (['ikdelay_count', 'has_rhythm_data', 'sentence_count', 's4_chars'] as $key) {
            $this->assertStringContainsString(
                "'" . $key . "'",
                $source,
                'analyser no longer stores ' . $key . ', which explainer.php gates on'
            );
        }
    }

    /**
     * Regression test for FIX-EG-BASELINE-UNEXPLAINED (v1.2.225).
     *
     * fingerprint::deviation_score() averages five components for up to 15 points -
     * words per minute, backspace ratio, sentence variance, typing-rhythm entropy and
     * mean pause length - and only the first three had an explainer rule. A student whose
     * rhythm or pausing had shifted sharply from their own baseline was scored for it and
     * told nothing about it. That is the worst place for this gap: a comparison against
     * the student's own past work is the most persuasive evidence the plugin produces and
     * the hardest for a teacher to guess at.
     *
     * Both directions of both components are asserted, because a deviation is reportable
     * either way and only one direction is the suspicious one.
     *
     * @dataProvider baseline_component_provider
     * @param array $metrics  The metrics for this attempt.
     * @param array $baseline The student's stored baseline values.
     * @param bool  $explains Whether a deviation sentence is expected.
     * @return void
     */
    public function test_every_baseline_component_can_be_explained(
        array $metrics,
        array $baseline,
        bool $explains
    ): void {
        $result = explainer::explain($metrics + self::quiet_baseline_metrics(), 'high', (object)$baseline);
        // A baseline sentence is a rule, so it is band-independent; the fallback is not.
        $low = explainer::explain($metrics + self::quiet_baseline_metrics(), 'low', (object)$baseline);
        if ($explains) {
            $this->assertSame($low, $result);
        } else {
            $this->assertNotSame($low, $result);
        }
    }

    /**
     * The two components that had no rule, in both directions, plus the no-change control.
     *
     * Thresholds mirror fingerprint::deviation_score()'s own tolerances: 0.3 relative for
     * entropy, 0.5 for pause mean.
     *
     * @return array[] Each dataset: metrics, baseline, whether a sentence is expected.
     */
    public static function baseline_component_provider(): array {
        $bl = ['baseline_entropy' => 0.9, 'baseline_pause_mean' => 1000.0];
        return [
            'rhythm smoother than baseline' => [['entropy_score' => 0.3, 'pause_mean' => 1000.0], $bl, true],
            'rhythm rougher than baseline'  => [['entropy_score' => 0.9, 'pause_mean' => 1000.0],
                                                ['baseline_entropy' => 0.3, 'baseline_pause_mean' => 1000.0], true],
            'rhythm unchanged'              => [['entropy_score' => 0.9, 'pause_mean' => 1000.0], $bl, false],
            'pauses shorter than baseline'  => [['entropy_score' => 0.9, 'pause_mean' => 300.0], $bl, true],
            'pauses longer than baseline'   => [['entropy_score' => 0.9, 'pause_mean' => 4000.0], $bl, true],
            'pauses unchanged'              => [['entropy_score' => 0.9, 'pause_mean' => 1000.0], $bl, false],
            // Values that WOULD deviate against a baseline, with no baseline recorded.
            // Entropy is deliberately 0.9 here: at 0.3 the absolute medium-entropy rule
            // fires on its own, which would make this dataset pass for the wrong reason.
            'no baseline recorded for either'
                => [['entropy_score' => 0.9, 'pause_mean' => 300.0],
                    ['baseline_entropy' => 0.0, 'baseline_pause_mean' => 0.0], false],
        ];
    }

    /**
     * Metrics that fire no absolute rule, so only the baseline comparison can speak.
     *
     * @return array The quiet metric set.
     */
    private static function quiet_baseline_metrics(): array {
        return [
            'sentence_variance'    => 30.0,
            'vocab_diversity'      => 0.8,
            'total_keystrokes'     => 200,
            'backspace_ratio'      => 0.10,
            'charsadded'           => 100,
            'pausecount'           => 5,
            'interkey_mean'        => 250.0,
            'iki_autocorr'         => 0.1,
            'ikdelay_count'        => 200,
            'thinking_pause_score' => 0.5,
            'speed_burst_cv'       => 0.9,
            'paste_events'         => 0,
            'burstsuspicious'      => 0,
            'has_rhythm_data'      => true,
            'sentence_count'       => 3,
        ];
    }

    /**
     * A partial baseline object must not warn.
     *
     * explain() is called with objects assembled outside the fingerprint table, and a
     * component that is simply absent should mean "nothing to compare against" rather
     * than an undefined-property warning rendered into a teacher's report page.
     *
     * @return void
     */
    public function test_a_partial_baseline_object_is_tolerated(): void {
        $result = explainer::explain(
            self::quiet_baseline_metrics() + ['entropy_score' => 0.9, 'pause_mean' => 1000.0],
            'high',
            (object)['baseline_wpm' => 20.0]
        );
        $this->assertCount(1, $result);
    }
}
