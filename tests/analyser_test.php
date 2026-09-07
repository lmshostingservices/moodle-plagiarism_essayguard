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

use plagiarism_essayguard\local\service\analyser;

/**
 * Unit tests for the behavioural score engine.
 *
 * Two groups of tests live here.
 *
 * The pure helpers -- risk_level(), entropy_from_sd() and std_dev() -- touch nothing
 * and are called directly at their exact threshold values.
 *
 * score_attempt() reads its events from the database and its thresholds from plugin
 * configuration, so those tests insert a real event stream and call set_config().
 * Each stream is built so that exactly the signals under test can fire; the expected
 * numbers are the ones the real method returned for that exact stream, recorded from
 * a proof run rather than derived from the code comments.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_essayguard\local\service\analyser
 */
final class analyser_test extends \advanced_testcase {
    /** @var int The student the fixtures belong to. */
    const USERID = 501;

    /** @var int The course module the fixtures belong to. */
    const CMID = 601;

    /** @var int The module context the fixtures belong to. */
    const CONTEXTID = 701;

    /** @var string The typing-session key the fixtures share. */
    const ATTEMPTKEY = 'egtestattemptkey';

    /**
     * Linguistic metrics chosen so that no linguistic signal can fire.
     *
     * Passing a non-empty array also stops score_attempt() recomputing them from the
     * text, which keeps every test's expectations about behavioural signals isolated
     * from the text it happens to use.
     *
     * @var array
     */
    const QUIET_LINGUISTIC = [
        'sentence_variance' => 20.0,
        'vocab_diversity'   => 0.8,
        'rare_word_ratio'   => 0.1,
        'sentence_count'    => 4,
    ];

    /**
     * Store one telemetry event for the shared fixture session.
     *
     * @param string $eventname The tracker event name.
     * @param int    $eventtime The event timestamp in milliseconds.
     * @param array  $payload   The event payload, JSON encoded on the way in.
     * @return void
     */
    private function add_event(string $eventname, int $eventtime, array $payload = []): void {
        global $DB;
        $DB->insert_record(
            'plagiarism_essayguard_ev',
            (object)[
                'userid'      => self::USERID,
                'cmid'        => self::CMID,
                'contextid'   => self::CONTEXTID,
                'attemptkey'  => self::ATTEMPTKEY,
                'eventname'   => $eventname,
                'eventtime'   => $eventtime,
                'payloadjson' => json_encode($payload),
                'timecreated' => (int)($eventtime / 1000),
                ]
        );
    }

    /**
     * Score the shared fixture session.
     *
     * @param string $finaltext The submitted text.
     * @param int    $qslot     Question slot; 0 scores the attempt as a whole.
     * @param int    $timestart Attempt start time, for the server-timing signal.
     * @param int    $timefinish Attempt finish time, for the same signal.
     * @return array The result array returned by score_attempt().
     */
    private function score(
        string $finaltext,
        int $qslot = 0,
        int $timestart = 0,
        int $timefinish = 0
    ): array {
        $result = analyser::score_attempt(
            self::USERID,
            self::CMID,
            self::CONTEXTID,
            self::ATTEMPTKEY,
            self::QUIET_LINGUISTIC,
            $finaltext,
            $qslot,
            $timestart,
            $timefinish
        );
        /* The score_attempt() method traces several of its fallback paths through
         * debugging(..., DEBUG_DEVELOPER). Which of them fire is an implementation
         * detail of the path under test, not the behaviour being asserted, so the
         * developer channel is drained here rather than being asserted on.
         */
        $this->resetDebugging();
        return $result;
    }

    /**
     * A 150-character answer of which 20 characters were pasted.
     *
     * 122 keydowns and 8 backspaces (backspace ratio 0.0615, above the Signal 5
     * thresholds), one gap longer than two seconds (so Signal 4 cannot fire), a
     * keystroke-to-character ratio of 0.867 (so Signal 12 cannot fire) and a 20-char
     * paste that is 13 percent of the answer (the proportional branch of Signal 1).
     * The only other signal that fires is Signal 10, at a constant 10 points.
     *
     * @return string The 150-character submitted text that goes with this stream.
     */
    private function build_mixed_session(): string {
        $time = 1700000000000;
        $ikds = [80, 400, 130, 900, 210, 60, 700, 320, 150, 1100];
        for ($i = 0; $i < 122; $i++) {
            $time += 120;
            $this->add_event('keydown', $time, ['ikd' => $ikds[$i % 10]]);
        }
        for ($i = 0; $i < 8; $i++) {
            $time += 120;
            $this->add_event('backspace', $time, ['ikd' => 250]);
        }
        $time += 4000;
        $this->add_event('paste', $time, ['insertlen' => 20]);
        return str_repeat('ab cd ef gh ij ', 10);
    }

    /**
     * A 496-character answer that arrived as a single paste and nothing else.
     *
     * @return string The 496-character submitted text that goes with this stream.
     */
    private function build_pure_paste_session(): string {
        $this->add_event('paste', 1700000000000, ['insertlen' => 500]);
        $this->add_event('input', 1700000001500, ['addedchars' => 500, 'insertlen' => 500]);
        return str_repeat('abcde fghij ', 41) . 'abcd';
    }

    /**
     * risk_level() bands a score, inclusive at 30 and at 66.
     *
     * @dataProvider risk_level_provider
     * @param int    $score100 The score to band.
     * @param string $expected The band observed for it.
     * @return void
     */
    public function test_risk_level(int $score100, string $expected): void {
        $this->assertSame($expected, analyser::risk_level($score100));
    }

    /**
     * Every band boundary, one below it and one above it.
     *
     * @return array[] Each dataset: the score, then the expected band.
     */
    public static function risk_level_provider(): array {
        return [
            'negative is low'         => [-5, 'low'],
            'zero is low'             => [0, 'low'],
            'low ceiling'             => [29, 'low'],
            'medium floor'            => [30, 'medium'],
            'one above medium floor'  => [31, 'medium'],
            'medium ceiling'          => [65, 'medium'],
            'high floor'              => [66, 'high'],
            'one above high floor'    => [67, 'high'],
            'full marks'              => [100, 'high'],
            'above full marks'        => [150, 'high'],
        ];
    }

    /**
     * entropy_from_sd() is a step function over inter-key standard deviation.
     *
     * @dataProvider entropy_from_sd_provider
     * @param float $sd       The standard deviation in milliseconds.
     * @param float $expected The entropy score observed for it.
     * @return void
     */
    public function test_entropy_from_sd(float $sd, float $expected): void {
        $this->assertSame($expected, analyser::entropy_from_sd($sd));
    }

    /**
     * Every step boundary, and the value immediately below it.
     *
     * @return array[] Each dataset: the standard deviation, then the expected entropy.
     */
    public static function entropy_from_sd_provider(): array {
        return [
            'negative'          => [-1.0, 0.0],
            'zero'              => [0.0, 0.0],
            'just above zero'   => [0.001, 0.15],
            'just below 60'     => [59.999, 0.15],
            'exactly 60'        => [60.0, 0.25],
            'just below 100'    => [99.999, 0.25],
            'exactly 100'       => [100.0, 0.4],
            'just below 150'    => [149.999, 0.4],
            'exactly 150'       => [150.0, 0.6],
            'just below 220'    => [219.999, 0.6],
            'exactly 220'       => [220.0, 0.8],
            'just below 300'    => [299.999, 0.8],
            'exactly 300'       => [300.0, 1.0],
            'far above 300'     => [1000.0, 1.0],
        ];
    }

    /**
     * std_dev() is the population standard deviation and needs at least two values.
     *
     * @dataProvider std_dev_provider
     * @param float[] $values   The values to measure.
     * @param float   $expected The observed standard deviation.
     * @return void
     */
    public function test_std_dev(array $values, float $expected): void {
        $this->assertSame($expected, analyser::std_dev($values));
    }

    /**
     * Standard deviation cases including both degenerate inputs.
     *
     * @return array[] Each dataset: the values, then the expected standard deviation.
     */
    public static function std_dev_provider(): array {
        return [
            'no values'     => [[], 0.0],
            'single value'  => [[7], 0.0],
            'all identical' => [[4, 4, 4], 0.0],
            'two values'    => [[10, 20], 5.0],
            'textbook set'  => [[2, 4, 4, 4, 5, 5, 7, 9], 2.0],
        ];
    }

    /**
     * Regression test for PASTE-DETECTION-DISABLED-ON-FRESH-INSTALL (v1.2.219).
     *
     * get_config() returns bool false, not null, for a key an administrator has never
     * saved. The v1.2.212 code read (int)(get_config(...) ?? 100), which evaluated to
     * (int)false = 0 on every site that had never opened the Essay Guard settings page,
     * so Signals 1 and 2 were multiplied to zero and a pasted answer scored LOW.
     *
     * This test writes no configuration at all, which is exactly that state, and pins
     * Signal 1 at its full 60 points.
     *
     * @return void
     */
    public function test_paste_weight_defaults_to_full_when_never_saved(): void {
        $this->resetAfterTest();
        $text = $this->build_pure_paste_session();
        $result = $this->score($text);
        $this->assertSame(60, $result['metrics']['signal_breakdown'][1]);
        $this->assertSame(100, $result['score100']);
        $this->assertSame('high', $result['risklevel']);
    }

    /**
     * The same regression, on the mixed session where the multiplier decides the band.
     *
     * A never-saved paste_weight must leave Signal 1 at the full proportional award of
     * 30 points, which is what carries this session over the MEDIUM boundary. Under the
     * v1.2.212 defect Signal 1 was 0 and the session read LOW.
     *
     * @return void
     */
    public function test_mixed_session_reaches_medium_when_paste_weight_never_saved(): void {
        $this->resetAfterTest();
        $text = $this->build_mixed_session();
        $result = $this->score($text);
        $this->assertSame([1 => 30, 10 => 10], $result['metrics']['signal_breakdown']);
        $this->assertSame(40, $result['score100']);
        $this->assertSame('medium', $result['risklevel']);
    }

    /**
     * A configured paste_weight scales Signals 1 and 2, and a deliberate 0 is honoured.
     *
     * The 0 dataset is the other half of the v1.2.219 fix: the never-saved sentinel must
     * take the default, but an administrator who typed 0 must get 0.
     *
     * @dataProvider paste_weight_provider
     * @param string $configured The value saved into plugin configuration.
     * @param int    $signal1    The Signal 1 points observed for it.
     * @param int    $score100   The final score observed for it.
     * @param string $risklevel  The band observed for it.
     * @return void
     */
    public function test_paste_weight_scales_signal_one(
        string $configured,
        int $signal1,
        int $score100,
        string $risklevel
    ): void {
        $this->resetAfterTest();
        set_config('paste_weight', $configured, 'plagiarism_essayguard');
        $text = $this->build_mixed_session();
        $result = $this->score($text);
        $this->assertSame([1 => $signal1, 10 => 10], $result['metrics']['signal_breakdown']);
        $this->assertSame($score100, $result['score100']);
        $this->assertSame($risklevel, $result['risklevel']);
    }

    /**
     * Paste weights across the configurable range, on the mixed session.
     *
     * @return array[] Each dataset: configured value, Signal 1 points, score, band.
     */
    public static function paste_weight_provider(): array {
        return [
            'deliberate zero disables signal 1' => ['0', 0, 10, 'low'],
            'quarter weight'                    => ['25', 8, 18, 'low'],
            'half weight'                       => ['50', 15, 25, 'low'],
            'full weight'                       => ['100', 30, 40, 'medium'],
        ];
    }

    /**
     * Regression test for FIX-EG-PASTEWEIGHT-UNREACHABLE (v1.2.225): the setting reaches
     * every signal that is describing the paste.
     *
     * Before v1.2.225 the multiplier scaled Signals 1 and 2 only. In a pure-paste session
     * Signals 3, 4, 5, 6 and 7 are five further descriptions of the same single paste -
     * each says so in its own comment - and unweighted they total 100 points on their own.
     * `paste_weight = 0` therefore produced exactly the same verdict as `100`: HIGH. The
     * documented purpose ("set to 25 % so paste-only sessions score MEDIUM rather than
     * HIGH") was unreachable at every value the setting accepts.
     *
     * The 100 dataset is the backward-compatibility guarantee: at the default, and on a
     * site that has never saved the setting, nothing about the score changes.
     *
     * @dataProvider pure_paste_weight_provider
     * @param string|null $configured The value saved, or null to leave the key unsaved.
     * @param array       $breakdown  The signal breakdown observed for it.
     * @param int         $score100   The score observed for it.
     * @param string      $risklevel  The band observed for it.
     * @return void
     */
    public function test_paste_weight_scales_the_whole_pure_paste_session(
        ?string $configured,
        array $breakdown,
        int $score100,
        string $risklevel
    ): void {
        $this->resetAfterTest();
        if ($configured !== null) {
            set_config('paste_weight', $configured, 'plagiarism_essayguard');
        }
        $text = $this->build_pure_paste_session();
        $result = $this->score($text);
        $this->assertSame($breakdown, $result['metrics']['signal_breakdown']);
        $this->assertSame($score100, $result['score100']);
        $this->assertSame($risklevel, $result['risklevel']);
    }

    /**
     * The whole configurable range against one 500-character paste.
     *
     * The 25 % row is the one the setting's own documentation promises and the one that
     * was impossible before v1.2.225.
     *
     * @return array[] Each dataset: configured value, breakdown, score, band.
     */
    public static function pure_paste_weight_provider(): array {
        return [
            'never saved behaves as full weight' =>
                [null, [1 => 60, 3 => 30, 4 => 20, 5 => 15, 6 => 25, 7 => 10], 100, 'high'],
            'full weight is unchanged' =>
                ['100', [1 => 60, 3 => 30, 4 => 20, 5 => 15, 6 => 25, 7 => 10], 100, 'high'],
            'three quarters still high' =>
                ['75', [1 => 45, 3 => 23, 4 => 15, 5 => 11, 6 => 19, 7 => 8], 100, 'high'],
            'half is still high' =>
                ['50', [1 => 30, 3 => 15, 4 => 10, 5 => 8, 6 => 13, 7 => 5], 81, 'high'],
            'the documented quarter gives medium' =>
                ['25', [1 => 15, 3 => 8, 4 => 5, 5 => 4, 6 => 6, 7 => 3], 41, 'medium'],
            'deliberate zero disables paste scoring' =>
                ['0', [1 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0], 0, 'low'],
        ];
    }

    /**
     * paste_weight must not touch a session the student genuinely typed.
     *
     * The risk of FIX-EG-PASTEWEIGHT-UNREACHABLE is that it becomes a general "score
     * everything lower" dial. It must not: a student who typed fast, without pauses and
     * without corrections is not describing a paste, and turning the paste weight down
     * must not hide them. The breakdown and the score are identical at every value.
     *
     * @return void
     */
    public function test_paste_weight_does_not_affect_a_typed_session(): void {
        $expected = null;
        foreach ([null, '0', '25', '100'] as $configured) {
            $this->resetAfterTest();
            if ($configured !== null) {
                set_config('paste_weight', $configured, 'plagiarism_essayguard');
            }
            $time = 1700000000000;
            for ($i = 0; $i < 400; $i++) {
                $time += 60;
                $this->add_event('keydown', $time, ['ikd' => 60]);
            }
            $result = $this->score(str_repeat('ab ', 140));
            $actual = [$result['metrics']['signal_breakdown'], $result['score100'], $result['risklevel']];
            if ($expected === null) {
                $expected = $actual;
                $this->assertSame([4 => 20, 5 => 15, 7 => 10], $result['metrics']['signal_breakdown']);
                $this->assertSame(45, $result['score100']);
                $this->assertSame('medium', $result['risklevel']);
            }
            $this->assertSame(
                $expected,
                $actual,
                'paste_weight=' . var_export($configured, true) . ' changed a typed session'
            );
        }
    }

    /**
     * The minchars gate decides whether the signal engine runs at all.
     *
     * The fixture is 60 keydowns with no inter-key delays and a 50-character answer, so
     * the only signal that can fire is Signal 5 at 15 points. The gate compares the
     * answer length against minchars with >=, so 50 opens it and 51 closes it, and a
     * never-saved key takes the documented default of 120 -- which closes it.
     *
     * The 0 dataset is the v1.2.224 FIX-EG-CONFIG-ZERO half of this: `?:` would have
     * substituted 120 for a deliberate 0 and left the gate shut.
     *
     * @dataProvider minchars_provider
     * @param string|null $configured The configured minchars, or null to leave it unsaved.
     * @param int         $score100   The score observed for it.
     * @param array       $breakdown  The signal breakdown observed for it.
     * @return void
     */
    public function test_minchars_gates_the_signal_engine(
        ?string $configured,
        int $score100,
        array $breakdown
    ): void {
        $this->resetAfterTest();
        if ($configured !== null) {
            set_config('minchars', $configured, 'plagiarism_essayguard');
        }
        $time = 1700000000000;
        for ($i = 0; $i < 60; $i++) {
            $time += 100;
            $this->add_event('keydown', $time);
        }
        $result = $this->score(str_repeat('ab ', 16) . 'cd');
        $this->assertSame(50, $result['metrics']['text_chars']);
        $this->assertSame($breakdown, $result['metrics']['signal_breakdown']);
        $this->assertSame($score100, $result['score100']);
    }

    /**
     * minchars at, either side of, and far from the 50-character answer length.
     *
     * @return array[] Each dataset: configured minchars, score, signal breakdown.
     */
    public static function minchars_provider(): array {
        return [
            'never saved takes the 120 default' => [null, 0, []],
            'deliberate zero opens the gate'    => ['0', 15, [5 => 15]],
            'below the answer length'           => ['40', 15, [5 => 15]],
            'exactly the answer length'         => ['50', 15, [5 => 15]],
            'one above the answer length'       => ['51', 0, []],
        ];
    }

    /**
     * An insertion counts as a suspicious burst when it reaches maxburstchars.
     *
     * The comparison is >=, so an insertion of exactly maxburstchars counts. A
     * never-saved key takes the documented default of 150. A deliberate 0 makes every
     * input event a burst, since every length is >= 0 -- recorded behaviour, and the
     * v1.2.224 counterpart to the minchars case above.
     *
     * @dataProvider maxburstchars_provider
     * @param string|null $configured The configured maxburstchars, or null to leave unsaved.
     * @param int         $insertlen  The length of the single input event.
     * @param int         $expected   The burstsuspicious count observed for it.
     * @return void
     */
    public function test_maxburstchars_boundary(
        ?string $configured,
        int $insertlen,
        int $expected
    ): void {
        $this->resetAfterTest();
        if ($configured !== null) {
            set_config('maxburstchars', $configured, 'plagiarism_essayguard');
        }
        $this->add_event(
            'input',
            1700000000000,
            ['addedchars' => $insertlen, 'insertlen' => $insertlen]
        );
        $result = $this->score(str_repeat('abcde fghij ', 41) . 'abcd');
        $this->assertSame($expected, $result['metrics']['burstsuspicious']);
    }

    /**
     * Insertion lengths at, below and above both the default and a configured threshold.
     *
     * @return array[] Each dataset: configured maxburstchars, insert length, expected count.
     */
    public static function maxburstchars_provider(): array {
        return [
            'default, one below 150'      => [null, 149, 0],
            'default, exactly 150'        => [null, 150, 1],
            'default, one above 150'      => [null, 151, 1],
            'configured 200, at old 150'  => ['200', 150, 0],
            'configured 200, exactly 200' => ['200', 200, 1],
            'deliberate zero, one char'   => ['0', 1, 1],
            // V1.2.224 FIX-EG-PASTE-DOUBLE-COUNTED: with maxburstchars set to 0 this
            // used to count an input event carrying NO inserted characters as a
            // suspicious burst, because 0 >= 0. A burst of nothing is not a burst.
            'deliberate zero, no chars'   => ['0', 0, 0],
        ];
    }

    /**
     * A paste is counted twice when the editor also reports the insertion.
     *
     * The pure-paste fixture is one paste event and the input event the browser fires
     * for the same clipboard insertion. Both branches of the event loop compare their
     * own length against maxburstchars, so one paste produces burstsuspicious = 2.
     * Recorded behaviour: the metric is reported to teachers as a count of suspicious
     * insertions, and this session had one.
     *
     * @return void
     */
    public function test_one_paste_is_counted_as_two_suspicious_bursts(): void {
        $this->resetAfterTest();
        $text = $this->build_pure_paste_session();
        $result = $this->score($text);
        $this->assertSame(1, $result['metrics']['pastecount']);
        $this->assertSame(2, $result['metrics']['burstsuspicious']);
    }

    /**
     * Per-question scoring sees only the events tagged with that question slot.
     *
     * The fixture is one attempt with a 496-character answer pasted into slot 1 and a
     * 150-character answer typed into slot 2. Scoring slot 1 must find the paste;
     * scoring slot 2 must not; scoring a slot that has no events must score nothing.
     *
     * @dataProvider qslot_provider
     * @param int    $qslot     The slot to score.
     * @param string $which     Which fixture answer to submit: 'pasted', 'typed' or 'both'.
     * @param int    $score100  The score observed for it.
     * @param string $risklevel The band observed for it.
     * @param array  $breakdown The signal breakdown observed for it.
     * @return void
     */
    public function test_per_question_slots_are_scored_in_isolation(
        int $qslot,
        string $which,
        int $score100,
        string $risklevel,
        array $breakdown
    ): void {
        $this->resetAfterTest();
        $pasted = str_repeat('abcde fghij ', 41) . 'abcd';
        $typed  = str_repeat('mn op ', 25);
        $time   = 1700000000000;
        $this->add_event('paste', $time, ['insertlen' => 500, 'qslot' => 1]);
        $this->add_event(
            'input',
            $time + 100,
            ['addedchars' => 500, 'insertlen' => 500, 'qslot' => 1]
        );
        $time += 5000;
        $ikds = [90, 300, 170, 640, 220];
        for ($i = 0; $i < 130; $i++) {
            $time += 150;
            $this->add_event('keydown', $time, ['ikd' => $ikds[$i % 5], 'qslot' => 2]);
        }
        $texts  = ['pasted' => $pasted, 'typed' => $typed, 'both' => $pasted . $typed];
        $result = $this->score($texts[$which], $qslot);
        $this->assertSame($breakdown, $result['metrics']['signal_breakdown']);
        $this->assertSame($score100, $result['score100']);
        $this->assertSame($risklevel, $result['risklevel']);
    }

    /**
     * The pasted slot, the typed slot, the aggregate and a slot with no events.
     *
     * @return array[] Each dataset: slot, answer, score, band, signal breakdown.
     */
    public static function qslot_provider(): array {
        return [
            'slot 1 was pasted' => [
                1, 'pasted', 100, 'high',
                [1 => 60, 3 => 30, 4 => 20, 5 => 15, 6 => 25, 7 => 10],
            ],
            'slot 2 was typed' => [
                2, 'typed', 29, 'low', [4 => 20, 5 => 15, 10 => 5],
            ],
            'aggregate sees both' => [
                0, 'both', 100, 'high',
                [1 => 60, 3 => 30, 5 => 15, 7 => 10, 10 => 5, 12 => 10],
            ],
            'slot with no events scores nothing' => [
                3, 'typed', 0, 'low', [],
            ],
        ];
    }

    /**
     * The false-positive cap holds an honest typist at 29, the top of the LOW band.
     *
     * The typed slot of the per-question fixture accumulates 40 points from Signals 4,
     * 5 and 10, which would read MEDIUM. Because there is no paste evidence, the speed
     * is human and the rhythm is not robotic, FIX-EG-TYPING-FALSE-POSITIVE caps the
     * score at 29. The stored breakdown still shows the uncapped 40, by design.
     *
     * @return void
     */
    public function test_typing_false_positive_cap_holds_an_honest_typist_at_low(): void {
        $this->resetAfterTest();
        $time = 1700000000000;
        $this->add_event('paste', $time, ['insertlen' => 500, 'qslot' => 1]);
        $this->add_event(
            'input',
            $time + 100,
            ['addedchars' => 500, 'insertlen' => 500, 'qslot' => 1]
        );
        $time += 5000;
        $ikds = [90, 300, 170, 640, 220];
        for ($i = 0; $i < 130; $i++) {
            $time += 150;
            $this->add_event('keydown', $time, ['ikd' => $ikds[$i % 5], 'qslot' => 2]);
        }
        $result = $this->score(str_repeat('mn op ', 25), 2);
        $this->assertSame(40, array_sum($result['metrics']['signal_breakdown']));
        $this->assertSame(29, $result['score100']);
        $this->assertSame('low', $result['risklevel']);
    }

    /**
     * Regression test for FIX-EG-CONSTANT-RHYTHM-READS-LOW (v1.2.224).
     *
     * Two hundred keystrokes exactly 100 ms apart is the textbook signature of automated
     * input. The inter-key standard deviation is 0, so entropy_from_sd() returns 0.0 --
     * and before v1.2.224 every consumer read that 0.0 as "no rhythm data" rather than
     * "no rhythm variation". Signal 7 was gated on entropy_score > 0 and did not fire,
     * and the FIX-EG-TYPING-FALSE-POSITIVE cap treated entropy_score === 0.0 as
     * unavailable and applied the LOW ceiling. The one input pattern a human cannot
     * produce scored 29 / LOW, the same as a session with no timing data at all.
     *
     * v1.2.224 separates the two meanings with a sample floor: with at least 30 delays,
     * an entropy of 0.0 is evidence, not absence. Signal 7 now fires at its maximum and
     * the cap does not apply.
     *
     * This test fails if either half of that fix is reverted.
     *
     * @return void
     */
    public function test_a_perfectly_constant_rhythm_is_scored_as_robotic(): void {
        $this->resetAfterTest();
        $time = 1700000000000;
        for ($i = 0; $i < 200; $i++) {
            $time += 100;
            $this->add_event('keydown', $time, ['ikd' => 100]);
        }
        $result = $this->score(str_repeat('ab ', 60));
        $this->assertSame(0.0, $result['metrics']['interkey_std_dev']);
        $this->assertSame(0.0, $result['metrics']['entropy_score']);
        $this->assertSame(0.0, $result['metrics']['iki_shannon']);
        // Signal 7 (rhythm entropy) fires at its maximum: this is the point of the fix.
        $this->assertSame(10, $result['metrics']['signal_breakdown'][7]);
        // Signal 10's autocorrelation of a constant series is 0.0, one tenth away from
        // the human baseline, so it still does not fire. Unchanged by v1.2.224.
        $this->assertArrayNotHasKey(10, $result['metrics']['signal_breakdown']);
        // 29 was the capped value before the fix; the cap no longer applies.
        $this->assertSame(45, $result['score100']);
        $this->assertSame('medium', $result['risklevel']);
    }

    /**
     * The constant-rhythm judgement requires a real sample.
     *
     * Below the 30-delay floor a run of identical intervals is chance rather than a
     * signature, so entropy 0.0 is still read as "no rhythm data": Signal 7 does not
     * fire and the paste-gate cap still applies. This is the conservative half of
     * FIX-EG-CONSTANT-RHYTHM-READS-LOW and it is what stops the fix turning a handful of
     * evenly-spaced keystrokes into a MEDIUM.
     *
     * @return void
     */
    public function test_a_constant_rhythm_below_the_sample_floor_is_not_treated_as_evidence(): void {
        $this->resetAfterTest();
        $time = 1700000000000;
        for ($i = 0; $i < 29; $i++) {
            $time += 100;
            $this->add_event('keydown', $time, ['ikd' => 100]);
        }
        $result = $this->score(str_repeat('ab ', 60));
        $this->assertSame(0.0, $result['metrics']['entropy_score']);
        $this->assertArrayNotHasKey(7, $result['metrics']['signal_breakdown']);
        $this->assertSame(29, $result['score100']);
        $this->assertSame('low', $result['risklevel']);
    }

    /**
     * With no events at all, the server-side chars-per-second signal is the only
     * evidence, and it bands on attempt duration.
     *
     * @dataProvider server_cps_provider
     * @param int    $durationsec The attempt duration in seconds.
     * @param float  $servercps   The chars-per-second observed for it.
     * @param array  $breakdown   The signal breakdown observed for it.
     * @param int    $score100    The score observed for it.
     * @param string $risklevel   The band observed for it.
     * @return void
     */
    public function test_server_timing_signal(
        int $durationsec,
        float $servercps,
        array $breakdown,
        int $score100,
        string $risklevel
    ): void {
        $this->resetAfterTest();
        $result = $this->score(
            str_repeat('abcde fghij ', 41) . 'abcd',
            0,
            1000,
            1000 + $durationsec
        );
        $this->assertSame(496, $result['metrics']['text_chars']);
        $this->assertSame($servercps, $result['metrics']['server_cps']);
        $this->assertSame($breakdown, $result['metrics']['signal_breakdown']);
        $this->assertSame($score100, $result['score100']);
        $this->assertSame($risklevel, $result['risklevel']);
    }

    /**
     * A 496-character answer submitted over three attempt durations.
     *
     * @return array[] Each dataset: duration, server cps, breakdown, score, band.
     */
    public static function server_cps_provider(): array {
        return [
            'twenty seconds is impossible' => [20, 24.8, [13 => 50], 50, 'medium'],
            'one minute is suspicious'     => [60, 8.2667, [13 => 25], 25, 'low'],
            // V1.2.227 FIX-EG-NOTHING-MEASURED-READS-LOW: this used to assert 'low'. No
            // event was captured and no signal fired, so nothing about this attempt was
            // measured - reporting it as low risk is a claim the data does not support,
            // and it is exactly what a broken tracker produces. The two rows above still
            // band normally because the server-timing signal did fire for them.
            'eight minutes is plausible'   => [500, 0.992, [], 0, 'unmeasured'],
        ];
    }

    /**
     * When the aggregate pass finds no events, it is elevated to the highest
     * per-question score already stored for the same attempt.
     *
     * @return void
     */
    public function test_aggregate_is_elevated_to_the_highest_per_question_score(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->insert_record(
            'plagiarism_essayguard_sc',
            (object)[
                'userid'               => self::USERID,
                'cmid'                 => self::CMID,
                'contextid'            => self::CONTEXTID,
                'attemptkey'           => self::ATTEMPTKEY,
                'qslot'                => 1,
                'riskscore'            => 0.88,
                'risklevel'            => 'high',
                'metricsjson'          => '{}',
                'timemodified'         => 1700000000,
                'typing_time'          => 0,
                'idle_time'            => 0,
                'total_keystrokes'     => 0,
                'paste_events'         => 1,
                'backspace_count'      => 0,
                'delete_count'         => 0,
                'cursor_moves'         => 0,
                'average_wpm'          => 0,
                'wpm_std_dev'          => 0,
                'interkey_mean'        => 0,
                'interkey_std_dev'     => 0,
                'pause_count'          => 0,
                'pause_mean'           => 0,
                'pause_std_dev'        => 0,
                'burst_count'          => 0,
                'burst_mean'           => 0,
                'burst_std_dev'        => 0,
                'sentence_variance'    => 0,
                'vocab_diversity'      => 0,
                'rare_word_ratio'      => 0,
                'thinking_pause_score' => 0,
                'entropy_score'        => 0,
                'explanationsjson'     => '[]',
                'baseline_deviation'   => 0,
                'baseline_status'      => 'none',
                ]
        );
        $result = $this->score(str_repeat('abcde fghij ', 41) . 'abcd');
        $this->assertSame(88, $result['score100']);
        $this->assertSame('high', $result['risklevel']);
        $this->assertTrue($result['metrics']['agg_elevated_from_perq']);
    }

    /**
     * Regression tests for FIX-EG-NOTHING-MEASURED-READS-LOW (v1.2.227).
     *
     * An attempt where the tracker never ran captures no events, fires no signal, scores
     * 0, and risk_level(0) is "low" - a green badge indistinguishable from a genuinely
     * clean attempt, with nothing anywhere saying the measurement did not happen.
     *
     * Found on a live Moodle 5.2 site: two other plugins each declared the same
     * identifier at the top level of an AMD module, Moodle concatenated all 910 modules
     * into one requirejs bundle, the page fetched that bundle under four entry names, and
     * the duplicate top-level declaration threw a SyntaxError that killed the whole
     * bundle - Essay Guard's tracker included. Every attempt on that site captured
     * nothing and every student was badged LOW.
     *
     * "low" is a finding; this is the absence of one. The two must not look the same.
     *
     * @dataProvider unmeasured_provider
     * @param bool   $hasevents Whether any keystroke event was captured.
     * @param string $text      The submitted answer.
     * @param int    $duration  Attempt duration in seconds, for the server-timing signal.
     * @param string $expected  The risk level observed.
     * @return void
     */
    public function test_an_unmeasured_attempt_is_not_reported_as_low(
        bool $hasevents,
        string $text,
        int $duration,
        string $expected
    ): void {
        $this->resetAfterTest();
        if ($hasevents) {
            $time = 1700000000000;
            for ($i = 0; $i < 200; $i++) {
                $time += 120 + ($i % 9) * 17;
                $this->add_event('keydown', $time, ['ikd' => 120 + ($i % 9) * 17]);
            }
        }
        $result = $this->score($text, 0, 1000, 1000 + $duration);
        $this->assertSame($expected, $result['risklevel']);
        $this->assertSame(
            $expected === 'unmeasured',
            (bool)($result['metrics']['nothing_measured'] ?? false)
        );
    }

    /**
     * The cases that must and must not be treated as unmeasured.
     *
     * @return array[] Each dataset: events captured, answer text, duration, risk level.
     */
    public static function unmeasured_provider(): array {
        $answer = str_repeat('word ', 120);
        return [
            // The defect: a real answer, nothing captured, no signal fired.
            'tracker never ran'            => [false, $answer, 1800, 'unmeasured'],
            // Must NOT be unmeasured - there is nothing to measure, and low is honest.
            'student submitted nothing'    => [false, '', 1800, 'low'],
            // Must NOT be unmeasured - the tracker worked.
            'typing was captured'          => [true, $answer, 1800, 'medium'],
            // Must NOT be unmeasured - no events, but the server-timing signal fired, so
            // the attempt WAS assessed and the score stands.
            'no events but timing fired'   => [false, str_repeat('abcde fghij ', 41) . 'abcd', 20, 'medium'],
        ];
    }
}
