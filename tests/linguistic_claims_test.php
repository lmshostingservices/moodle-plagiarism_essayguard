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
use plagiarism_essayguard\local\signals;

/**
 * Tests for the paragraph-structure and transition-density measures (v1.4.0).
 *
 * Both were advertised in the README from the first release with nothing behind them.
 * These tests pin the properties that make them safe to use rather than the exact numbers
 * they currently return, so a later tuning pass cannot silently turn them into something
 * that fires on ordinary writing.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_essayguard\local\service\linguistic
 */
final class linguistic_claims_test extends \basic_testcase {
    /**
     * Heavily signposted prose scores higher than unstructured writing.
     *
     * @return void
     */
    public function test_transition_density_separates_signposted_prose(): void {
        $signposted = 'Artificial intelligence is changing assessment. However, it also '
            . 'introduces risk. Furthermore, institutions must adapt. In addition, staff '
            . 'need training. Consequently, policy must evolve. Moreover, students need '
            . 'guidance. In conclusion, planning is essential. For example, assessment '
            . 'design must change. Therefore, leaders should act. Similarly, funding '
            . 'must follow the decision that has been taken by the executive team.';

        $plain = 'The biggest issue with site safety is that nobody reads the method '
            . 'statement. We print them and they sit in the folder. Last job a bloke '
            . 'nearly got hit by a reversing truck because the spotter wandered off. '
            . 'The paperwork said we had a spotter. We did, on paper. What works is the '
            . 'toolbox talk where someone tells a story about something that went wrong.';

        $this->assertGreaterThan(
            linguistic::transition_density($plain),
            linguistic::transition_density($signposted)
        );
        $this->assertGreaterThan(6.0, linguistic::transition_density($signposted));
    }

    /**
     * Short text reports nothing, because the ratio means nothing there.
     *
     * Below about forty words the figure is dominated by whether a single marker happens
     * to appear, which would make a one-line answer look heavily signposted.
     *
     * @return void
     */
    public function test_transition_density_is_not_reported_for_short_text(): void {
        $this->assertEquals(0.0, linguistic::transition_density('However, this is short.'));
    }

    /**
     * Paragraphs are recognised through the markup a Moodle editor produces.
     *
     * @return void
     */
    public function test_paragraphs_are_counted_through_editor_markup(): void {
        $html = '<p>The first paragraph has enough words in it to count properly here.</p>'
            . '<p>The second paragraph is noticeably longer than the first one and keeps '
            . 'going for a while with a good deal more detail than was strictly needed.</p>'
            . '<p>A third paragraph of fairly moderate length sits here at the end.</p>';

        $lengths = linguistic::paragraph_lengths($html);

        $this->assertCount(3, $lengths);
        $this->assertGreaterThan(0.0, linguistic::variance($lengths));
    }

    /**
     * Short lines are not paragraphs.
     *
     * A heading or a stray line break would otherwise make every answer look uneven.
     *
     * @return void
     */
    public function test_short_lines_are_not_counted_as_paragraphs(): void {
        $html = '<h3>Task 1</h3><p>Yes.</p><p>This paragraph has enough words in it that '
            . 'it should be counted as a real paragraph rather than discarded.</p>';

        $this->assertCount(1, linguistic::paragraph_lengths($html));
    }

    /**
     * Evenly-sized paragraphs vary less than naturally written ones.
     *
     * @return void
     */
    public function test_uniform_paragraphs_vary_less_than_natural_ones(): void {
        $uniform = str_repeat(
            '<p>This paragraph contains exactly the same number of words as all the '
            . 'others in this particular submission do.</p>',
            4
        );
        $natural = '<p>Short opening line to set the scene.</p>'
            . '<p>' . str_repeat('A much longer middle section with a great deal more '
            . 'detail in it. ', 6) . '</p>'
            . '<p>Then a brief closing thought to finish on.</p>';

        $this->assertLessThan(
            linguistic::variance(linguistic::paragraph_lengths($natural)),
            linguistic::variance(linguistic::paragraph_lengths($uniform))
        );
    }

    /**
     * Style signals cannot reach the medium threshold on their own.
     *
     * This is the property that keeps the plugin defensible for second-language writers
     * and for the templated answers VET training packages ask students to produce. The
     * cap must stay below the medium boundary; if a later change raises it above, a
     * submission could be badged on prose style alone.
     *
     * @return void
     */
    public function test_style_signals_cannot_reach_the_medium_band_alone(): void {
        $textual = signals::in_class(signals::EVIDENCE_TEXTUAL);
        $this->assertNotEmpty($textual);

        $uncapped = 0;
        foreach ($textual as $number) {
            $uncapped += signals::max_points($number, 1.0);
        }

        // The signals really can add up past the threshold, which is why the cap exists.
        $this->assertGreaterThan(signals::TEXTUAL_CAP, $uncapped);

        // And the cap keeps them below the medium band (30).
        $this->assertLessThan(30, signals::TEXTUAL_CAP);
    }

    /**
     * The registry reports the maximum this site could actually award.
     *
     * The old hardcoded table printed 60 for the paste signal on a site configured to
     * award at most 15, so a teacher checking the breakdown against the documentation
     * found numbers that could never occur.
     *
     * @return void
     */
    public function test_registry_maxima_follow_the_paste_weight_setting(): void {
        $full = signals::max_points(1, 1.0);
        $quarter = signals::max_points(1, 0.25);

        $this->assertEquals(60, $full);
        $this->assertEquals(15, $quarter);

        // Signals that do not describe a paste are unaffected by the setting.
        $this->assertEquals(
            signals::max_points(10, 1.0),
            signals::max_points(10, 0.25)
        );
    }
}
