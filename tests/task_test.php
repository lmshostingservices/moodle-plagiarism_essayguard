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

use plagiarism_essayguard\task\cleanup;
use plagiarism_essayguard\task\refresh_licence;
use plagiarism_essayguard\task\rescore_pending;

/**
 * Tests for the three Essay Guard scheduled tasks.
 *
 * Each task is run through its real execute() against real rows, with its cron output
 * captured, so the retention rule, the re-score backstop and the licence refresh are all
 * exercised the way cron exercises them.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_essayguard\task\cleanup
 * @covers     \plagiarism_essayguard\task\rescore_pending
 * @covers     \plagiarism_essayguard\task\refresh_licence
 */
final class task_test extends \advanced_testcase {
    use \essayguard_test_helper;

    /** @var int The student the fixtures belong to. */
    const USERID = 9001;

    /** @var int The course module the fixtures belong to. */
    const CMID = 9002;

    /** @var int The module context the fixtures belong to. */
    const CONTEXTID = 9003;

    /**
     * Run a task and return everything it printed, so mtrace output is asserted on
     * rather than leaking into the test runner.
     *
     * @param \core\task\scheduled_task $task The task to run.
     * @return string Everything the task printed.
     */
    private function run_task(\core\task\scheduled_task $task): string {
        ob_start();
        $task->execute();
        return (string)ob_get_clean();
    }

    /**
     * Write one telemetry event with a chosen server receipt time.
     *
     * @param int    $timecreated The server time the row was written.
     * @param string $attemptkey  The typing session key.
     * @return int The id of the inserted row.
     */
    private function event_received_at(int $timecreated, string $attemptkey = 'aktask'): int {
        global $DB;
        $id = $this->add_event(
            self::USERID,
            self::CMID,
            self::CONTEXTID,
            $attemptkey,
            'keydown',
            1767322845000
        );
        $DB->set_field('plagiarism_essayguard_ev', 'timecreated', $timecreated, ['id' => $id]);
        return $id;
    }

    /**
     * The retention window prunes the raw keystroke stream and keeps the derived scores,
     * which is what the setting promises the administrator.
     *
     * @return void
     */
    public function test_cleanup_prunes_old_telemetry_and_keeps_scores(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('retentiondays', 90, 'plagiarism_essayguard');

        $old    = $this->event_received_at(time() - (91 * DAYSECS));
        $recent = $this->event_received_at(time() - (89 * DAYSECS));
        $this->create_score(['userid' => self::USERID, 'cmid' => self::CMID, 'contextid' => self::CONTEXTID]);

        $output = $this->run_task(new cleanup());

        $this->assertStringContainsString('deleted 1 telemetry event(s)', $output);
        $this->assertFalse($DB->record_exists('plagiarism_essayguard_ev', ['id' => $old]));
        $this->assertTrue($DB->record_exists('plagiarism_essayguard_ev', ['id' => $recent]));
        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_sc'));
    }

    /**
     * Retention 0 means "keep everything", and the task says so rather than deleting the
     * lot on a cutoff of "now".
     *
     * @return void
     */
    public function test_cleanup_retention_zero_disables_pruning(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('retentiondays', 0, 'plagiarism_essayguard');

        $this->event_received_at(time() - (10 * YEARSECS));

        $output = $this->run_task(new cleanup());

        $this->assertStringContainsString('pruning disabled', $output);
        $this->assertSame(1, $DB->count_records('plagiarism_essayguard_ev'));
    }

    /**
     * With the setting never saved, the documented 90-day default applies — get_config()
     * returns nothing for the key, and the task must not read that as zero.
     *
     * @return void
     */
    public function test_cleanup_defaults_to_ninety_days_when_never_saved(): void {
        global $DB;
        $this->resetAfterTest();
        $this->assertFalse(get_config('plagiarism_essayguard', 'retentiondays'));

        $old    = $this->event_received_at(time() - (91 * DAYSECS));
        $recent = $this->event_received_at(time() - (10 * DAYSECS));

        $output = $this->run_task(new cleanup());

        $this->assertStringContainsString('90 days', $output);
        $this->assertFalse($DB->record_exists('plagiarism_essayguard_ev', ['id' => $old]));
        $this->assertTrue($DB->record_exists('plagiarism_essayguard_ev', ['id' => $recent]));
    }

    /**
     * Insert a zero-scored aggregate record with telemetry behind it, as the observer
     * leaves one when the browser's flush lost the race with the submit.
     *
     * @param int    $events     How many telemetry events to write for the session.
     * @param int    $agesecs    How long ago the score record was written.
     * @param string $attemptkey The typing session key.
     * @return int The score record id.
     */
    private function stale_zero_score(int $events, int $agesecs, string $attemptkey = 'akstale'): int {
        for ($i = 0; $i < $events; $i++) {
            $this->add_event(
                self::USERID,
                self::CMID,
                self::CONTEXTID,
                $attemptkey,
                'keydown',
                1767322845000 + ($i * 200),
                ['ikd' => 200]
            );
        }
        return $this->create_score([
            'userid'       => self::USERID,
            'cmid'         => self::CMID,
            'contextid'    => self::CONTEXTID,
            'attemptkey'   => $attemptkey,
            'qslot'        => 0,
            'riskscore'    => 0,
            'timemodified' => time() - $agesecs,
        ]);
    }

    /**
     * REGRESSION (v1.2.224 FIX-EG-RESCOREPENDING-NEVERRUNS): a never-saved "enabled"
     * setting reads as bool false, and every other component in this plugin treats that
     * as ENABLED because it is the state of every fresh install. This task once read it
     * as disabled and returned immediately on every run, forever — so the one component
     * that exists to repair attempts whose browser never reached finalize_attempt was the
     * one component the default configuration switched off.
     *
     * @return void
     */
    public function test_rescore_pending_runs_on_a_fresh_install(): void {
        $this->resetAfterTest();
        $this->assertFalse(get_config('plagiarism_essayguard', 'enabled'));

        $output = $this->run_task(new rescore_pending());

        $this->assertStringNotContainsString('plugin disabled', $output);
    }

    /**
     * An administrator who has explicitly switched the plugin off is obeyed.
     *
     * @return void
     */
    public function test_rescore_pending_skips_when_explicitly_disabled(): void {
        $this->resetAfterTest();
        set_config('enabled', 0, 'plagiarism_essayguard');

        $this->stale_zero_score(10, 600);
        $output = $this->run_task(new rescore_pending());

        $this->assertStringContainsString('plugin disabled', $output);
    }

    /**
     * An attempt with real telemetry behind a zero score is re-scored, so a badge that
     * only reads LOW because the events arrived a moment too late is corrected.
     *
     * @return void
     */
    public function test_rescore_pending_rescores_a_stale_zero_score(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_essayguard();

        $id = $this->stale_zero_score(10, 600);

        $output = $this->run_task(new rescore_pending());
        $this->resetDebugging();

        $this->assertStringContainsString('rescoring 1 attempt(s)', $output);
        $record = $DB->get_record('plagiarism_essayguard_sc', ['id' => $id]);
        $this->assertGreaterThan(time() - 60, (int)$record->timemodified);
    }

    /**
     * A session with fewer than five events is not enough to re-score on, and is left
     * alone rather than churned through every five minutes forever.
     *
     * @return void
     */
    public function test_rescore_pending_ignores_a_session_with_too_few_events(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        $this->stale_zero_score(4, 600);

        $output = $this->run_task(new rescore_pending());
        $this->assertStringContainsString('no stale zero-score records', $output);
    }

    /**
     * A score written moments ago is left alone: the three-minute grace window is what
     * stops the task racing a submission whose events are still arriving.
     *
     * @return void
     */
    public function test_rescore_pending_respects_the_grace_window(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        $this->stale_zero_score(10, 10);

        $output = $this->run_task(new rescore_pending());
        $this->assertStringContainsString('no stale zero-score records', $output);
    }

    /**
     * Only the aggregate slot is a candidate: per-question rows are re-written by the
     * aggregate pass and must not be queued separately.
     *
     * @return void
     */
    public function test_rescore_pending_only_considers_the_aggregate_slot(): void {
        $this->resetAfterTest();
        $this->enable_essayguard();

        for ($i = 0; $i < 10; $i++) {
            $this->add_event(self::USERID, self::CMID, self::CONTEXTID, 'akperq', 'keydown', 1767322845000 + $i * 200);
        }
        $this->create_score([
            'userid'       => self::USERID,
            'cmid'         => self::CMID,
            'contextid'    => self::CONTEXTID,
            'attemptkey'   => 'akperq',
            'qslot'        => 3,
            'riskscore'    => 0,
            'timemodified' => time() - 600,
        ]);

        $output = $this->run_task(new rescore_pending());
        $this->assertStringContainsString('no stale zero-score records', $output);
    }

    /**
     * The licence refresh obeys the site-wide switch, so a site that has turned the
     * plugin off makes no outbound call at all.
     *
     * @return void
     */
    public function test_refresh_licence_skips_when_disabled(): void {
        $this->resetAfterTest();
        set_config('enabled', 0, 'plagiarism_essayguard');

        $output = $this->run_task(new refresh_licence());

        $this->assertStringContainsString('plugin disabled', $output);
    }

    /**
     * With no credentials configured the task completes without contacting anyone, and
     * reports both refreshes — a site in "open mode" must still get a clean cron run.
     *
     * @return void
     */
    public function test_refresh_licence_completes_without_credentials(): void {
        $this->resetAfterTest();
        set_config('enabled', 1, 'plagiarism_essayguard');
        $this->assertEmpty(plagiarism_essayguard_get_siteid());

        $output = $this->run_task(new refresh_licence());
        $this->resetDebugging();

        $this->assertStringContainsString('unlock status refreshed', $output);
        $this->assertStringContainsString('platform settings refreshed', $output);
    }

    /**
     * Every task declares a translated name, because an untranslatable task name is a
     * fatal on the scheduled-tasks admin page.
     *
     * @return void
     */
    public function test_every_task_has_a_translated_name(): void {
        $this->resetAfterTest();

        foreach ([new cleanup(), new rescore_pending(), new refresh_licence()] as $task) {
            $name = $task->get_name();
            $this->assertNotEmpty($name);
            $this->assertStringNotContainsString('[[', $name);
        }
    }

    /**
     * db/tasks.php must name classes that exist and schedules Moodle can parse. A cron
     * field Moodle cannot evaluate stops the task silently.
     *
     * @return void
     */
    public function test_declared_schedules_are_valid(): void {
        global $CFG;
        $this->resetAfterTest();

        $tasks = null;
        include($CFG->dirroot . '/plagiarism/essayguard/db/tasks.php');
        $this->assertIsArray($tasks);
        $this->assertCount(3, $tasks);

        foreach ($tasks as $definition) {
            $this->assertTrue(class_exists($definition['classname']), $definition['classname'] . ' does not exist.');
            $task = new $definition['classname']();
            $task->set_minute($definition['minute']);
            $task->set_hour($definition['hour']);
            $task->set_day($definition['day']);
            $task->set_day_of_week($definition['dayofweek']);
            $task->set_month($definition['month']);
            // A schedule Moodle cannot evaluate returns no next run time at all.
            $this->assertGreaterThan(time(), $task->get_next_scheduled_time());
        }
    }
}
