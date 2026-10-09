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

namespace plagiarism_essayguard\local;

/**
 * The signal registry: one definition of what Essay Guard measures.
 *
 * REGISTRY-EG-ONE-DEFINITION (v1.4.0). Before this file the signal set was described in
 * four places that had drifted apart: the scoring branches in analyser.php, a hardcoded
 * "Max" column in student.php that ignored the paste-weight setting, the language strings,
 * and a table in README.md that listed nine signals with the wrong weights while the
 * engine scored thirteen. A teacher cross-checking the breakdown against the vendor's own
 * documentation found different numbers, which is the kind of discrepancy that ends badly
 * in an appeal.
 *
 * Everything that describes a signal now reads from here.
 *
 * Each definition carries:
 *   - max      the most points the signal can contribute
 *   - evidence what KIND of claim the signal supports (see the constants below)
 *   - scaled   whether the admin paste-weight setting scales it
 *
 * The evidence class matters more than the points. A behavioural signal observes what the
 * student did. A textual signal observes what the prose looks like, which is a property of
 * the writing and not of the person — those are corroboration only, because the honest
 * explanations for them (an ESL writer, a templated VET answer, a formal house style) are
 * at least as common as the dishonest one.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signals {
    /** @var string Directly observed: the clipboard or a bulk insertion was used. */
    public const EVIDENCE_OBSERVED = 'observed';

    /** @var string Behavioural: describes how the typing happened. */
    public const EVIDENCE_BEHAVIOURAL = 'behavioural';

    /** @var string Textual: describes the prose, not the person. Corroboration only. */
    public const EVIDENCE_TEXTUAL = 'textual';

    /** @var string Comparative: measured against this student's own established pattern. */
    public const EVIDENCE_COMPARATIVE = 'comparative';

    /**
     * The most points the textual signals may contribute between them.
     *
     * Style measurements are the ones that fall hardest on ESL students and on the
     * formulaic answers that VET assessment actively asks for, so they are bounded well
     * below the medium threshold. They can corroborate a behavioural finding; they can
     * never produce one.
     *
     * @var int
     */
    public const TEXTUAL_CAP = 20;

    /**
     * Definitions for every signal the engine can award.
     *
     * @return array[] Keyed by signal number.
     */
    public static function definitions(): array {
        return [
            1 => [
                'key'      => 'paste',
                'max'      => 60,
                'evidence' => self::EVIDENCE_OBSERVED,
                'scaled'   => true,
            ],
            2 => [
                'key'      => 'largeinsert',
                'max'      => 20,
                'evidence' => self::EVIDENCE_OBSERVED,
                'scaled'   => true,
            ],
            3 => [
                'key'      => 'charspersec',
                'max'      => 30,
                'evidence' => self::EVIDENCE_BEHAVIOURAL,
                'scaled'   => true,
            ],
            4 => [
                'key'      => 'nopauses',
                'max'      => 20,
                'evidence' => self::EVIDENCE_BEHAVIOURAL,
                'scaled'   => true,
            ],
            5 => [
                'key'      => 'backspaceratio',
                'max'      => 15,
                'evidence' => self::EVIDENCE_BEHAVIOURAL,
                'scaled'   => true,
            ],
            6 => [
                'key'      => 'sessiontime',
                'max'      => 25,
                'evidence' => self::EVIDENCE_BEHAVIOURAL,
                'scaled'   => true,
            ],
            7 => [
                'key'      => 'entropy',
                'max'      => 10,
                'evidence' => self::EVIDENCE_BEHAVIOURAL,
                'scaled'   => true,
            ],
            8 => [
                'key'      => 'sentencevariance',
                'max'      => 10,
                'evidence' => self::EVIDENCE_TEXTUAL,
                'scaled'   => false,
            ],
            9 => [
                'key'      => 'vocabdiversity',
                'max'      => 5,
                'evidence' => self::EVIDENCE_TEXTUAL,
                'scaled'   => false,
            ],
            10 => [
                'key'      => 'ikiautocorr',
                'max'      => 10,
                'evidence' => self::EVIDENCE_BEHAVIOURAL,
                'scaled'   => false,
            ],
            11 => [
                'key'      => 'speedcv',
                'max'      => 10,
                'evidence' => self::EVIDENCE_BEHAVIOURAL,
                'scaled'   => false,
            ],
            12 => [
                'key'      => 'keystrokeratio',
                'max'      => 10,
                'evidence' => self::EVIDENCE_BEHAVIOURAL,
                'scaled'   => false,
            ],
            13 => [
                'key'      => 'servercps',
                'max'      => 50,
                'evidence' => self::EVIDENCE_BEHAVIOURAL,
                'scaled'   => false,
            ],
            14 => [
                'key'      => 'transitiondensity',
                'max'      => 10,
                'evidence' => self::EVIDENCE_TEXTUAL,
                'scaled'   => false,
            ],
            15 => [
                'key'      => 'paragraphuniformity',
                'max'      => 10,
                'evidence' => self::EVIDENCE_TEXTUAL,
                'scaled'   => false,
            ],
            16 => [
                'key'      => 'transcription',
                'max'      => 15,
                'evidence' => self::EVIDENCE_BEHAVIOURAL,
                'scaled'   => false,
            ],
        ];
    }

    /**
     * The maximum points a signal can award on this site.
     *
     * Applies the admin paste-weight setting where it is relevant, so the "Max" column a
     * teacher reads matches what the engine could actually have given. The old hardcoded
     * table showed 60 for Signal 1 on a site configured to award at most 15.
     *
     * @param int        $number      Signal number.
     * @param float|null $pasteweight Paste-weight multiplier, or null to read it from config.
     * @return int Maximum points for this signal on this site.
     */
    public static function max_points(int $number, ?float $pasteweight = null): int {
        $definitions = self::definitions();
        if (!isset($definitions[$number])) {
            return 0;
        }

        $definition = $definitions[$number];
        if (empty($definition['scaled'])) {
            return (int)$definition['max'];
        }

        if ($pasteweight === null) {
            $configured  = get_config('plagiarism_essayguard', 'paste_weight');
            $pasteweight = ($configured === false || $configured === '') ? 1.0 : ((int)$configured / 100.0);
        }

        return (int)round($definition['max'] * $pasteweight);
    }

    /**
     * The evidence class for a signal.
     *
     * @param int $number Signal number.
     * @return string One of the EVIDENCE_* constants.
     */
    public static function evidence_class(int $number): string {
        $definitions = self::definitions();
        return $definitions[$number]['evidence'] ?? self::EVIDENCE_BEHAVIOURAL;
    }

    /**
     * Signal numbers belonging to one evidence class.
     *
     * @param string $evidence One of the EVIDENCE_* constants.
     * @return int[] Signal numbers.
     */
    public static function in_class(string $evidence): array {
        $numbers = [];
        foreach (self::definitions() as $number => $definition) {
            if ($definition['evidence'] === $evidence) {
                $numbers[] = $number;
            }
        }
        return $numbers;
    }
}
