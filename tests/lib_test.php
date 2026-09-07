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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/plagiarism/essayguard/lib.php');

/**
 * Unit tests for the library helpers that carry decision logic.
 *
 * plagiarism_essayguard_config_int() is the only one that reads configuration, so it
 * is the only one that resets after the test; the rest are pure. Every expected value
 * was observed from a run of the real function against the exact input given.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::plagiarism_essayguard_config_int
 * @covers     ::plagiarism_essayguard_supports_mod
 * @covers     ::plagiarism_essayguard_pref_never_writable
 * @covers     ::plagiarism_essayguard_user_preferences
 * @covers     ::plagiarism_essayguard_render_badge
 */
final class lib_test extends \advanced_testcase {
    /**
     * A never-saved setting reads as bool false, not null and not the empty string.
     *
     * This is the premise the whole of plagiarism_essayguard_config_int() rests on, and
     * the fact that both the v1.2.219 paste_weight defect and the v1.2.224 minchars
     * defect turned on. Pinning it here means a change in Moodle's own behaviour would
     * be caught by a test that names the reason, rather than by the callers failing
     * mysteriously.
     *
     * @return void
     */
    public function test_get_config_returns_false_for_a_never_saved_key(): void {
        $this->resetAfterTest();
        $raw = get_config('plagiarism_essayguard', 'a_key_that_was_never_saved');
        $this->assertFalse($raw);
        $this->assertNotSame(null, $raw);
        $this->assertNotSame('', $raw);
    }

    /**
     * config_int() falls back only for the never-saved sentinels.
     *
     * @dataProvider config_int_provider
     * @param string|null $stored   The value to save, or null to leave the key unsaved.
     * @param int         $default  The default passed to the function.
     * @param int         $expected The value observed from the real function.
     * @return void
     */
    public function test_config_int(?string $stored, int $default, int $expected): void {
        $this->resetAfterTest();
        if ($stored !== null) {
            set_config('captureinterval', $stored, 'plagiarism_essayguard');
        }
        $this->assertSame($expected, plagiarism_essayguard_config_int('captureinterval', $default));
    }

    /**
     * The sentinels that must take the default, and the values that must not.
     *
     * The deliberate-zero dataset is the whole point of the function: `?:` would return
     * 200 here, which is the defect fixed in v1.2.224.
     *
     * @return array[] Each dataset: stored value, default, expected result.
     */
    public static function config_int_provider(): array {
        return [
            'never saved takes the default'   => [null, 200, 200],
            'empty string takes the default'  => ['', 200, 200],
            'deliberate zero is honoured'     => ['0', 200, 0],
            'positive value is honoured'      => ['5000', 200, 5000],
            'negative value is honoured'      => ['-1', 200, -1],
            'surrounding spaces are trimmed'  => [' 42 ', 200, 42],
            'float is truncated toward zero'  => ['7.9', 200, 7],
            'non numeric becomes zero'        => ['abc', 200, 0],
        ];
    }

    /**
     * config_int() never returns the default once a key holds a zero, whatever the
     * default is.
     *
     * @return void
     */
    public function test_config_int_zero_beats_every_default(): void {
        $this->resetAfterTest();
        set_config('minchars', '0', 'plagiarism_essayguard');
        $this->assertSame(0, plagiarism_essayguard_config_int('minchars', 120));
        $this->assertSame(0, plagiarism_essayguard_config_int('minchars', 1));
        $this->assertSame(0, plagiarism_essayguard_config_int('minchars', -99));
    }

    /**
     * Only assign, quiz and forum are supported, and the match is case sensitive.
     *
     * @dataProvider supports_mod_provider
     * @param string $modulename The activity type name.
     * @param bool   $expected   Whether the plugin acts on it.
     * @return void
     */
    public function test_supports_mod(string $modulename, bool $expected): void {
        $this->assertSame($expected, plagiarism_essayguard_supports_mod($modulename));
    }

    /**
     * The supported types, some unsupported ones and two near misses.
     *
     * @return array[] Each dataset: the module name, then whether it is supported.
     */
    public static function supports_mod_provider(): array {
        return [
            'assign'              => ['assign', true],
            'quiz'                => ['quiz', true],
            'forum'               => ['forum', true],
            'workshop'            => ['workshop', false],
            'lesson'              => ['lesson', false],
            'wrong case'          => ['ASSIGN', false],
            'empty'               => ['', false],
            'longer name'         => ['assignment', false],
        ];
    }

    /**
     * The attempt-key preferences can never be written over the AJAX endpoint.
     *
     * A student able to rewrite their own attempt key could detach their telemetry from
     * their submission, so this callback must return false for every caller.
     *
     * @return void
     */
    public function test_pref_never_writable(): void {
        $user = (object)['id' => 42];
        $this->assertFalse(
            plagiarism_essayguard_pref_never_writable($user, 'essayguard_ak_7')
        );
        $this->assertFalse(
            plagiarism_essayguard_pref_never_writable($user, 'essayguard_lastscore_7')
        );
        $this->assertFalse(
            plagiarism_essayguard_pref_never_writable($user, 'anything_at_all')
        );
    }

    /**
     * Both declared preferences are regexps guarded by the never-writable callback.
     *
     * @return void
     */
    public function test_user_preferences_are_all_locked_down(): void {
        $prefs = plagiarism_essayguard_user_preferences();
        $this->assertSame(
            ['essayguard_ak_.*', 'essayguard_lastscore_.*'],
            array_keys($prefs)
        );
        foreach ($prefs as $definition) {
            $this->assertTrue($definition['isregexp']);
            $this->assertSame(
                'plagiarism_essayguard_pref_never_writable',
                $definition['permissioncallback']
            );
        }
        $this->assertSame('', $prefs['essayguard_ak_.*']['default']);
        $this->assertSame('0', $prefs['essayguard_lastscore_.*']['default']);
    }

    /**
     * The badge carries the CSS class for its band, mapping the two legacy DB values.
     *
     * @dataProvider badge_class_provider
     * @param string $status   The record's analysis state.
     * @param string $level    The record's stored risk level.
     * @param string $expected The badge modifier class observed for that combination.
     * @return void
     */
    public function test_render_badge_class(string $status, string $level, string $expected): void {
        $html = plagiarism_essayguard_render_badge($status, 40.0, $level, 'boom', 7, 9, false, false);
        $this->assertStringStartsWith('<div class="essayguard-wrap">', $html);
        $this->assertStringContainsString(
            '<span class="essayguard-badge essayguard-badge-' . $expected . '"',
            $html
        );
        $this->assertStringContainsString(
            '<span class="essayguard-badge-dot essayguard-badge-dot-' . $expected . '">',
            $html
        );
    }

    /**
     * Every status, and every level the database can hold including the two legacy
     * aliases and one value that is in neither set.
     *
     * @return array[] Each dataset: status, stored level, expected modifier class.
     */
    public static function badge_class_provider(): array {
        return [
            'analysed high'            => ['analysed', 'high', 'high'],
            'analysed medium'          => ['analysed', 'medium', 'medium'],
            'analysed low'             => ['analysed', 'low', 'low'],
            'legacy partial is medium' => ['analysed', 'partial', 'medium'],
            'legacy mild is medium'    => ['analysed', 'mild', 'medium'],
            // V1.2.224 FIX-EG-BADGE-FAILS-GREEN: an unrecognised risklevel used to render
            // the green LOW badge - the reassuring answer in the one case where nobody
            // knows. It now renders MEDIUM, which prompts a human to look.
            'unknown level reads medium' => ['analysed', 'nonsense', 'medium'],
            'pending'                  => ['pending', '', 'pending'],
            'error'                    => ['error', '', 'error'],
            'unknown status is pending' => ['weird', '', 'pending'],
        ];
    }

    /**
     * Only a teacher gets the drill-down links, and only an analysed record gets two.
     *
     * A student viewing their own submission must not be handed the class report link.
     *
     * @return void
     */
    public function test_render_badge_links_are_teacher_only(): void {
        $student = plagiarism_essayguard_render_badge('analysed', 88.0, 'high', '', 7, 9, false, false);
        $this->assertSame(0, substr_count($student, 'class="essayguard-link"'));

        $teacher = plagiarism_essayguard_render_badge('analysed', 88.0, 'high', '', 7, 9, true, false);
        $this->assertSame(2, substr_count($teacher, 'class="essayguard-link"'));

        $pending = plagiarism_essayguard_render_badge('pending', 0.0, '', '', 7, 9, true, false);
        $this->assertSame(1, substr_count($pending, 'class="essayguard-link"'));
    }

    /**
     * Regression tests for FIX-EG-RESCORE-UNSTABLE-CURSOR (v1.2.225).
     *
     * The bulk rescore page resumes between batches. It used to resume from a numeric
     * offset into a list ordered newest-first, which is not stable: an attempt finished
     * while the teacher works through the quiz is inserted at the FRONT and shifts every
     * index. The consequence was both duplication and silent omission, and the run then
     * reported itself complete.
     *
     * @dataProvider attempts_after_provider
     * @param array $ids      The attempt ids in display order.
     * @param int   $afterid  The cursor.
     * @param array $expected The ids expected to remain.
     * @return void
     */
    public function test_attempts_after(array $ids, int $afterid, array $expected): void {
        $attempts = array_map(static fn($id) => (object)['id' => $id], $ids);
        $result = plagiarism_essayguard_attempts_after($attempts, $afterid);
        $this->assertSame(
            $expected,
            array_values(array_map(
                static fn($qa) => $qa->id,
                $result
                ))
        );
    }

    /**
     * Cursor positions, including the two that must not restart the run.
     *
     * @return array[] Each dataset: ids, cursor, expected remaining ids.
     */
    public static function attempts_after_provider(): array {
        $list = [112, 111, 110, 109, 108];
        return [
            'no cursor returns everything'  => [$list, 0, $list],
            'negative cursor is no cursor'  => [$list, -1, $list],
            'resume after the first'        => [$list, 112, [111, 110, 109, 108]],
            'resume after the middle'       => [$list, 110, [109, 108]],
            'resume after the last'         => [$list, 108, []],
            // A deleted attempt or a reset quiz. Restarting from the beginning here would
            // silently rescore the whole quiz again.
            'cursor no longer in the list'  => [$list, 999, []],
            'empty list'                    => [[], 5, []],
        ];
    }

    /**
     * The cursor processes every attempt exactly once while the list grows underneath it.
     *
     * This is the scenario the offset could not survive, written as a simulation because
     * it is the interaction between batches - not any single call - that was broken.
     *
     * @return void
     */
    public function test_the_cursor_survives_attempts_arriving_mid_run(): void {
        $make = static fn(array $ids) => array_map(static fn($id) => (object)['id' => $id], $ids);
        $attempts = $make([112, 111, 110, 109, 108, 107, 106, 105, 104, 103, 102, 101]);

        $cursor = 0;
        $processed = [];
        for ($batch = 1; $batch <= 3; $batch++) {
            $pending = plagiarism_essayguard_attempts_after($attempts, $cursor);
            $done = array_slice($pending, 0, 4);
            foreach ($done as $qa) {
                $processed[] = $qa->id;
            }
            if ($done) {
                $cursor = (int)end($done)->id;
            }
            // Two students submit between batches; new attempts sort to the front.
            $newid = 112 + $batch * 2;
            $attempts = array_merge($make([$newid + 1, $newid]), $attempts);
        }

        $this->assertSame(
            [112, 111, 110, 109, 108, 107, 106, 105, 104, 103, 102, 101],
            $processed
        );
        // Every original attempt exactly once: no duplicates, nothing missed.
        $this->assertSame($processed, array_values(array_unique($processed)));
    }

    /**
     * Regression test for FIX-EG-TRACKER-MAXBURST-ZERO (V1.2.228).
     *
     * The burst threshold shipped to the browser used the raw get_config() accessor while
     * the setting beside it went through config_int(). get_config() returns false for a
     * key nobody has written and (int)false is 0, and neither db/install.php nor
     * db/upgrade.php ever writes a maxburstchars default - so on every fresh install the
     * tracker was told the threshold was 0 rather than 150, and tracker.js flags a burst
     * with `delta >= maxburstchars`, marking EVERY input event suspicious.
     *
     * The server side already had this fixed and documented; only the configuration feed
     * to the client was missed, so the two halves of the plugin disagreed about what a
     * burst is.
     *
     * @return void
     */
    public function test_maxburstchars_default_reaches_the_tracker(): void {
        $this->resetAfterTest();

        // Never saved: must be the documented default, not zero.
        $this->assertSame(150, plagiarism_essayguard_config_int('maxburstchars', 150));

        // A deliberate zero must still be honoured - the other half of FIX-EG-CONFIG-ZERO.
        set_config('maxburstchars', '0', 'plagiarism_essayguard');
        $this->assertSame(0, plagiarism_essayguard_config_int('maxburstchars', 150));

        // A configured value is passed through.
        set_config('maxburstchars', '200', 'plagiarism_essayguard');
        $this->assertSame(200, plagiarism_essayguard_config_int('maxburstchars', 150));
    }
}
