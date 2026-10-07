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
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * The per-activity setting survives duplicate, backup and restore.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_plagiarism_essayguard_plugin
 * @covers     \restore_plagiarism_essayguard_plugin
 */
final class backup_restore_test extends \advanced_testcase {
    use \essayguard_test_helper;

    /**
     * Turn on core plagiarism and Essay Guard so core includes the plugin in backups.
     *
     * @return void
     */
    private function setup_site(): void {
        set_config('enableplagiarism', 1);
        $this->enable_essayguard();
        $this->set_platform_settings(false, false);
    }

    /**
     * Duplicating an activity copies its setting to the new course module.
     *
     * @dataProvider states_provider
     * @param bool $enabled The setting on the original activity.
     * @return void
     */
    public function test_duplicate_keeps_the_setting(bool $enabled): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setup_site();

        $act = $this->create_essayguard_assign($enabled);
        $course = $DB->get_record('course', ['id' => $act['course']->id]);
        $cm = get_fast_modinfo($course)->get_cm($act['cm']->id);

        $newcm = duplicate_module($course, $cm);

        $this->assertNotEquals($act['cm']->id, $newcm->id);
        $this->assertSame($enabled, plagiarism_essayguard_is_cm_active((int)$newcm->id));
        $this->assertSame($enabled ? '1' : '0', get_config('plagiarism_essayguard', 'enabled_cm_' . $newcm->id));
    }

    /**
     * Backing up a course and restoring it as a new course maps the setting to the new
     * course module ids.
     *
     * @dataProvider states_provider
     * @param bool $enabled The setting on the original activity.
     * @return void
     */
    public function test_course_restore_keeps_the_setting(bool $enabled): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setup_site();

        $act = $this->create_essayguard_assign($enabled);

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $act['course']->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course('Restored', 'RESTORED', $act['course']->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $newcms = get_fast_modinfo($newcourseid)->get_instances_of('assign');
        $this->assertCount(1, $newcms);
        $newcm = reset($newcms);
        $this->assertSame($enabled, plagiarism_essayguard_is_cm_active((int)$newcm->id));
    }

    /**
     * Both values of the setting.
     *
     * @return array
     */
    public static function states_provider(): array {
        return ['enabled' => [true], 'disabled' => [false]];
    }
}
