<?php

namespace plagiarism_essayguard\hook;

defined('MOODLE_INTERNAL') || die();

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
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_footer {

    public static function callback(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $PAGE, $DB, $OUTPUT;

        if (isguestuser() || !isloggedin()) {
            return;
        }
        // FIX-EG-BEFORE-FOOTER-ENABLED (v1.2.114): get_config() returns PHP false when
        // the key has never been saved (fresh install where admin hasn't submitted the
        // settings page yet). !false = true → the old guard fired on every fresh install,
        // making the floating Essay Guard Report button permanently invisible to teachers.
        // Identical fix to FIX-EG-ENABLED-CHECK-INCONSISTENT (observer.php v1.2.94) and
        // FIX-EG-GLOBAL-ENABLED-MISSING (inject_tracker v1.2.88):
        //   Treat a missing key as enabled; only skip when the key EXISTS and is explicitly
        //   set to a disabled value ('0' or empty string).
        $global_enabled = get_config('plagiarism_essayguard', 'enabled');
        if ($global_enabled !== false && empty($global_enabled)) {
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
        $count = (int)$DB->count_records('plagiarism_essayguard_sc', [
            'cmid'  => $cm->id,
            'qslot' => 0,
        ]);

        $report_url = new \moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cm->id]);

        $label = 'Essay Guard Report';
        $badge = $count > 0
            ? '<span style="background:#fff;color:#6c3483;border-radius:10px;padding:1px 7px;'
              . 'font-size:0.78rem;font-weight:700;margin-left:6px;">' . $count . '</span>'
            : '';

        $html = '<div style="position:fixed;top:80px;right:0;z-index:9999;">'
              . '<a href="' . $report_url->out(false) . '" '
              . 'style="display:inline-flex;align-items:center;background:#6c3483;color:#fff;'
              . 'padding:7px 14px 7px 12px;border-radius:8px 0 0 8px;font-size:0.88rem;'
              . 'font-weight:600;text-decoration:none;box-shadow:-2px 2px 6px rgba(0,0,0,0.18);'
              . 'gap:4px;" title="' . s($label) . '">'
              . '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" '
              . 'viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" '
              . 'style="flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" '
              . 'd="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
              . s($label) . $badge
              . '</a></div>';

        $hook->add_html($html);
    }
}
