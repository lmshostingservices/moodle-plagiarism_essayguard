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

namespace plagiarism_essayguard\hook;

/**
 * FIX-EG-QUIZ-FOOTER-BUTTON (v1.2.52)
 *
 * Hook callback for core\hook\output\before_footer_html_generation.
 *
 * Problem: Teachers on the quiz report/overview page had no direct, visible
 * link to the Essay Guard class report. The only Essay Guard entry point was
 * a tiny 0.8rem grey "Essay Guard class report" link injected via get_links()
 * inside individual attempt-review cells — pages teachers rarely visit just
 * to find plagiarism data. The quiz overview table does NOT call
 * plagiarism_get_links(), so there was NO Essay Guard indicator at all on
 * the page where teachers spend most of their time reviewing submissions.
 *
 * Fix: On mod-quiz-view, mod-quiz-report, and mod-quiz-review pages, inject
 * a prominent pill button in the page footer area (top-right, fixed position)
 * linking to the Essay Guard class report for the current quiz. Badge shows
 * the count of students who have Essay Guard scores for this quiz.
 *
 * Only shown to users with plagiarism/essayguard:viewreport capability.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_footer {
    /**
     * Load the reporter AMD module on the quiz grading overview page.
     *
     * Moodle never calls plagiarism_get_links() for that table, so the badges are
     * injected from JavaScript instead. Loaded only for users holding
     * plagiarism/essayguard:viewreport in the activity.
     *
     * @param \core\hook\output\before_footer_html_generation $hook The footer hook.
     * @return void
     */
    public static function callback(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $PAGE, $DB, $OUTPUT, $CFG;

        if (isguestuser() || !isloggedin()) {
            return;
        }
        require_once($CFG->dirroot . '/plagiarism/essayguard/lib.php');
        // Site switch: off until an administrator enables Essay Guard.
        if (!\plagiarism_essayguard_is_enabled()) {
            return;
        }
        if (during_initial_install()) {
            return;
        }

        $cm = $PAGE->cm ?? null;
        if (!$cm || $cm->modname !== 'quiz') {
            return;
        }

        // Only show on quiz view, report, and review pages.
        $pagetype = $PAGE->pagetype ?? '';
        $allowed  = ['mod-quiz-view', 'mod-quiz-report', 'mod-quiz-review'];
        if (!in_array($pagetype, $allowed, true)) {
            return;
        }

        $context = \context_module::instance($cm->id);
        if (!has_capability('plagiarism/essayguard:viewreport', $context)) {
            return;
        }

        // FIX-EG-NO-BADGE-OVERVIEW (v1.2.60): On the quiz grading overview page,
        // load reporter.js which calls the get_badges web service and injects risk
        // badges into each student row. Moodle's plagiarism_get_links() is never
        // called on this page, so the AMD injection is the only viable path.
        if ($pagetype === 'mod-quiz-report') {
            $PAGE->requires->js_call_amd(
                'plagiarism_essayguard/reporter',
                'init',
                [['cmid' => $cm->id]]
            );
        }

        // Count students who have Essay Guard scores for this quiz.
        //
        // SEC-EG-FOOTER-GROUPS (v1.3.0): honour separate groups. The v1.2.219/221/224
        // sweep added group restrictions to report.php, student.php, rescore.php and
        // get_badges.php and missed this count, so a tutor restricted to one group saw
        // the figure for the whole cohort on the button. A count rather than names, but
        // it is the same leak and it is one query away from being right.
        $groupmode = groups_get_activity_groupmode($cm);
        $context   = \context_module::instance($cm->id);
        $restrict  = ($groupmode == SEPARATEGROUPS)
            && !has_capability('moodle/site:accessallgroups', $context);

        if ($restrict) {
            $mygroups = groups_get_activity_allowed_groups($cm);
            if (empty($mygroups)) {
                $count = 0;
            } else {
                [$ginsql, $gparams] = $DB->get_in_or_equal(
                    array_keys($mygroups),
                    SQL_PARAMS_NAMED,
                    'eggrp'
                );
                $count = (int)$DB->count_records_sql(
                    "SELECT COUNT(sc.id)
                       FROM {plagiarism_essayguard_sc} sc
                      WHERE sc.cmid = :cmid AND sc.qslot = 0
                        AND EXISTS (SELECT 1
                                      FROM {groups_members} gm
                                     WHERE gm.userid = sc.userid AND gm.groupid {$ginsql})",
                    array_merge(['cmid' => $cm->id], $gparams)
                );
            }
        } else {
            $count = (int)$DB->count_records(
                'plagiarism_essayguard_sc',
                [
                    'cmid'  => $cm->id,
                    'qslot' => 0,
                    ]
            );
        }

        $reporturl = new \moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cm->id]);

        $html = $OUTPUT->render_from_template('plagiarism_essayguard/footer_report_button', [
            'reporturl' => $reporturl->out(false),
            'count' => $count,
        ]);
        $hook->add_html($html);
    }
}
