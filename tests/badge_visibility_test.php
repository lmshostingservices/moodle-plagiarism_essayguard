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
require_once(__DIR__ . '/fixtures/essayguard_test_helper.php');
require_once($CFG->dirroot . '/plagiarism/essayguard/lib.php');

/**
 * Who can see a risk badge: the student for their own work, teachers for everyone.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::plagiarism_essayguard_get_links
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('plagiarism_essayguard_get_links')]
final class badge_visibility_test extends \advanced_testcase {
    use \essayguard_test_helper;

    /**
     * Build a quiz with one scored essay answer for a student.
     *
     * @return array [activity, student, other student, teacher]
     */
    private function scored_quiz(): array {
        $this->enable_essayguard();
        $this->set_platform_settings(false, false);
        $act = $this->create_essayguard_quiz();
        $gen = $this->getDataGenerator();
        $student = $gen->create_and_enrol($act['course'], 'student');
        $other = $gen->create_and_enrol($act['course'], 'student');
        $teacher = $gen->create_and_enrol($act['course'], 'editingteacher');
        $this->create_score([
            'userid' => $student->id,
            'cmid' => $act['cm']->id,
            'contextid' => $act['context']->id,
            'qslot' => 1,
            'riskscore' => 0.80,
            'risklevel' => 'high',
            'metricsjson' => json_encode(['event_count' => 40]),
        ]);
        return [$act, $student, $other, $teacher];
    }

    /**
     * The link data the core essay renderer passes on the quiz review page.
     *
     * @param array $act The activity.
     * @param int $userid The attempt owner.
     * @return array
     */
    private function essay_linkarray(array $act, int $userid): array {
        return [
            'context' => $act['context']->id,
            'component' => 'qtype_essay',
            'area' => 1,
            'itemid' => 1,
            'userid' => $userid,
            'content' => '',
        ];
    }

    /**
     * A student sees the badge for their own answer, without teacher links.
     *
     * @return void
     */
    public function test_student_sees_own_badge(): void {
        $this->resetAfterTest();
        [$act, $student] = $this->scored_quiz();

        $this->setUser($student);
        $html = plagiarism_essayguard_get_links($this->essay_linkarray($act, (int)$student->id));

        $this->assertStringContainsString('essayguard-badge-high', $html);
        $this->assertStringNotContainsString('report.php', $html);
        $this->assertStringNotContainsString('student.php', $html);
    }

    /**
     * A student never sees another student's badge.
     *
     * @return void
     */
    public function test_student_cannot_see_another_students_badge(): void {
        $this->resetAfterTest();
        [$act, $student, $other] = $this->scored_quiz();

        $this->setUser($other);
        $html = plagiarism_essayguard_get_links($this->essay_linkarray($act, (int)$student->id));

        $this->assertSame('', $html);
    }

    /**
     * A teacher sees the badge with the detail and class report links.
     *
     * @return void
     */
    public function test_teacher_sees_badge_and_links(): void {
        $this->resetAfterTest();
        [$act, $student, , $teacher] = $this->scored_quiz();

        $this->setUser($teacher);
        $html = plagiarism_essayguard_get_links($this->essay_linkarray($act, (int)$student->id));

        $this->assertStringContainsString('essayguard-badge-high', $html);
        $this->assertStringContainsString('student.php', $html);
        $this->assertStringContainsString('report.php', $html);
    }
}
