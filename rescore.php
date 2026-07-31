<?php
/**
 * Essay Guard — Retroactive Rescore Admin Tool.
 *
 * FIX-EG-RESCORE-TOOL (v1.2.137): Retroactively score quiz attempts that were
 * submitted before a working version of Essay Guard was installed. The PHP
 * observer (observer.php) scores each attempt at submission time; if the plugin
 * had a bug at that point (e.g. v1.2.134 missing global $DB crashed every quiz
 * submit silently), those submissions were never scored and report.php shows
 * "0 submissions". This page re-runs the exact same scoring logic for every
 * finished quiz attempt in the activity, writing records to
 * plagiarism_essayguard_sc for any attempt that is not yet scored.
 *
 * URL: /plagiarism/essayguard/rescore.php?cmid=X
 *      POST action=rescore  — run scoring pass
 *      POST action=rescore&force=1 — overwrite existing records too
 *
 * Requires: plagiarism/essayguard:viewreport capability.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

global $DB, $CFG, $OUTPUT, $PAGE;

require_once($CFG->libdir . '/formslib.php');

$cmid  = required_param('cmid', PARAM_INT);
$force = optional_param('force', 0, PARAM_INT);

$cm      = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cmid);

require_login($course, false, $cm);
require_capability('plagiarism/essayguard:viewreport', $context);

if ($cm->modname !== 'quiz') {
    throw new \moodle_exception('generalexceptionmessage', 'error', '', 'Essay Guard rescore is only available for quiz activities.');
}

$PAGE->set_url(new moodle_url('/plagiarism/essayguard/rescore.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title('Essay Guard — Rescore past attempts');
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/plagiarism/essayguard/styles.css');

// ── Helpers (mirrors observer.php private methods) ────────────────────────────

/**
 * Extract plain text from HTML (strip tags, decode entities, collapse whitespace).
 */
function essayguard_rescore_extract_text(string $html): string {
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xc2\xa0", ' ', $text); // non-breaking space
    return trim(preg_replace('/\s+/', ' ', $text));
}

/**
 * Fetch essay-question answers keyed by question slot, for a quiz attempt.
 * Returns [slot => plain_text]. Mirrors observer::get_quiz_essay_texts_by_slot().
 *
 * @param int $quizattemptid
 * @return array<int,string>
 */
function essayguard_rescore_slot_texts(int $quizattemptid): array {
    global $DB;

    $attempt = $DB->get_record('quiz_attempts', ['id' => $quizattemptid], 'uniqueid');
    if (!$attempt) {
        return [];
    }

    // Most-recent step_data answer per slot (ORDER BY qas.id DESC + !isset guard).
    $sql = "SELECT qas.id, qa.slot, qasd.value
              FROM {question_attempt_steps} qas
              JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
              JOIN {question_attempts} qa             ON qa.id = qas.questionattemptid
             WHERE qa.questionusageid = :qubaid
               AND qasd.name = 'answer'
          ORDER BY qa.slot ASC, qas.id DESC";

    $rows   = $DB->get_records_sql($sql, ['qubaid' => $attempt->uniqueid]);
    $result = [];
    foreach ($rows as $row) {
        $slot = (int)$row->slot;
        if (!isset($result[$slot])) {
            $text = essayguard_rescore_extract_text($row->value ?? '');
            if ($text !== '') {
                $result[$slot] = $text;
            }
        }
    }

    // Fallback: responsesummary (Moodle's own plain-text summary — always populated
    // for essay questions, independent of editor type or step_data format).
    if (empty($result)) {
        $summary_rows = $DB->get_records_sql(
            "SELECT id, slot, responsesummary
               FROM {question_attempts}
              WHERE questionusageid = :qubaid
                AND responsesummary IS NOT NULL
                AND " . $DB->sql_isnotempty('question_attempts', 'responsesummary', true, true) . "
           ORDER BY slot ASC",
            ['qubaid' => $attempt->uniqueid]
        );
        foreach ($summary_rows as $sr) {
            $slot = (int)$sr->slot;
            if (!isset($result[$slot])) {
                $text = essayguard_rescore_extract_text($sr->responsesummary ?? '');
                if ($text !== '') {
                    $result[$slot] = $text;
                }
            }
        }
    }

    return $result;
}

// ── Fetch quiz attempt list ───────────────────────────────────────────────────

$quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'id', MUST_EXIST);

// All finished quiz attempts for this quiz (state = finished).
$quiz_attempts = $DB->get_records_sql(
    "SELECT qa.id, qa.userid, qa.timestart, qa.timefinish, qa.attempt,
            u.firstname, u.lastname
       FROM {quiz_attempts} qa
       JOIN {user} u ON u.id = qa.userid
      WHERE qa.quiz = :quiz
        AND qa.state = 'finished'
   ORDER BY qa.timefinish DESC",
    ['quiz' => $quiz->id]
);

// Existing EssayGuard aggregate records for this cmid.
$existing_uids = [];
if (!empty($quiz_attempts)) {
    $existing_recs = $DB->get_records_sql(
        "SELECT DISTINCT userid FROM {plagiarism_essayguard_sc}
          WHERE cmid = :cmid AND qslot = 0",
        ['cmid' => $cmid]
    );
    foreach ($existing_recs as $r) {
        $existing_uids[(int)$r->userid] = true;
    }
}

// ── Handle POST (rescore action) ──────────────────────────────────────────────

$results = [];
$run_done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('action', '', PARAM_ALPHA) === 'rescore') {
    require_sesskey();

    // Release session lock before long-running scoring loop.
    \core\session\manager::write_close();

    $global_enabled = get_config('plagiarism_essayguard', 'enabled');
    $plugin_enabled = ($global_enabled === false) || !empty($global_enabled);

    $unlock_ok = plagiarism_essayguard_check_unlock();

    if (!$plugin_enabled) {
        $results['error'] = 'Essay Guard is globally disabled. Enable it via Site Administration → Plugins → Plagiarism → Essay Guard.';
    } elseif (!$unlock_ok) {
        $results['error'] = 'Essay Guard is not unlocked for this site. Check your Site ID and API Key in the Essay Guard settings, or verify your credit balance on the EssayGraderAI portal.';
    } else {
        $scored      = 0;
        $skipped     = 0;
        $no_text     = 0;
        $errors      = 0;
        $detail_rows = [];

        foreach ($quiz_attempts as $qa) {
            $uid   = (int)$qa->userid;
            $qaid  = (int)$qa->id;
            $name  = fullname((object)['firstname' => $qa->firstname, 'lastname' => $qa->lastname]);

            // Skip already-scored attempts unless force=1.
            if (!$force && isset($existing_uids[$uid])) {
                $skipped++;
                $detail_rows[] = ['name' => $name, 'qaid' => $qaid, 'status' => 'skipped', 'detail' => 'Already has Essay Guard data (use "Overwrite" to re-score)'];
                continue;
            }

            // Reconstruct attemptkey exactly as observer does (FIX-EG-ATTEMPTKEY v1.2.81).
            $attemptkey = 'qa_' . $qaid;

            // Extract text from DB.
            $slot_texts = essayguard_rescore_slot_texts($qaid);
            $finaltext  = implode("\n\n", $slot_texts);

            if (empty(trim($finaltext))) {
                $no_text++;
                $detail_rows[] = ['name' => $name, 'qaid' => $qaid, 'status' => 'no_text', 'detail' => 'No essay text found (quiz may not have essay questions, or attempt was not fully submitted)'];
                continue;
            }

            try {
                // Score per-question first (FIX-EG-PERQ-FIRST v1.2.125).
                $atrow = $DB->get_record('quiz_attempts', ['id' => $qaid], 'timestart,timefinish');
                $attempt_timestart  = $atrow ? (int)$atrow->timestart  : 0;
                $attempt_timefinish = $atrow ? (int)$atrow->timefinish : 0;

                $scored_slots = [];
                foreach ($slot_texts as $slot => $slottext) {
                    \plagiarism_essayguard\local\service\analyser::score_attempt(
                        $uid, $cmid, $context->id, $attemptkey,
                        [], $slottext, (int)$slot,
                        $attempt_timestart, $attempt_timefinish
                    );
                    $scored_slots[] = (int)$slot;
                }

                // Aggregate (qslot=0).
                $result = \plagiarism_essayguard\local\service\analyser::score_attempt(
                    $uid, $cmid, $context->id, $attemptkey,
                    [], $finaltext, 0,
                    $attempt_timestart, $attempt_timefinish
                );

                $existing_uids[$uid] = true;
                $scored++;

                $pct   = (int)round((float)($result['riskscore'] ?? 0) * 100);
                $level = $result['risklevel'] ?? 'low';
                $qlabel = !empty($scored_slots)
                    ? ' (Q' . implode(', Q', $scored_slots) . ' + aggregate)'
                    : ' (aggregate only)';

                $detail_rows[] = ['name' => $name, 'qaid' => $qaid, 'status' => $level,
                    'detail' => strtoupper($level) . ' ' . $pct . '%' . $qlabel];
            } catch (\Throwable $e) {
                $errors++;
                $detail_rows[] = ['name' => $name, 'qaid' => $qaid, 'status' => 'error',
                    'detail' => 'Error: ' . $e->getMessage()];
            }
        }

        $results = [
            'scored'  => $scored,
            'skipped' => $skipped,
            'no_text' => $no_text,
            'errors'  => $errors,
            'rows'    => $detail_rows,
        ];
        $run_done = true;
    }
}

// ── Render ────────────────────────────────────────────────────────────────────

echo $OUTPUT->header();

// Back / breadcrumb.
$report_url = new moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]);
echo html_writer::tag('a', '&#8592; Back to Essay Guard Report',
    ['href' => $report_url->out(false), 'style' => 'color:#6c3483;font-size:0.9rem;text-decoration:none;']);

echo html_writer::tag('h1', 'Essay Guard — Rescore Past Attempts',
    ['style' => 'font-size:1.4rem;font-weight:700;margin:1rem 0 0.25rem;']);
echo html_writer::tag('p',
    format_string($cm->name) . ' &nbsp;|&nbsp; ' . count($quiz_attempts) . ' finished attempt' .
    (count($quiz_attempts) === 1 ? '' : 's') . ' found',
    ['style' => 'color:#6b7280;font-size:0.88rem;margin:0 0 1.5rem;']);

// Explanation box.
echo '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1.5rem;font-size:0.9rem;">'
    . '<strong style="color:#166534;">What does this do?</strong> '
    . 'Reads each student\'s quiz answers directly from the Moodle database and runs the full Essay Guard '
    . 'risk-scoring algorithm — exactly the same logic that runs when a student submits. '
    . 'Use this when students submitted before a working version of Essay Guard was installed. '
    . 'Behavioural signals (keystrokes, paste events) will not be available for old attempts, '
    . 'so the score is based on linguistic analysis and server-side timing only.'
    . '</div>';

// ── Unlock / plugin status ────────────────────────────────────────────────────
$global_enabled = get_config('plagiarism_essayguard', 'enabled');
$plugin_enabled = ($global_enabled === false) || !empty($global_enabled);
$unlock_ok      = plagiarism_essayguard_check_unlock();

$status_bg    = ($plugin_enabled && $unlock_ok) ? '#f0fdf4' : '#fff7ed';
$status_bdr   = ($plugin_enabled && $unlock_ok) ? '#86efac' : '#fdba74';
$status_txt   = ($plugin_enabled && $unlock_ok) ? '#166534' : '#7c2d12';
$status_icon  = ($plugin_enabled && $unlock_ok) ? '&#x2705;' : '&#x26A0;&#xFE0F;';
$status_label = '';
if (!$plugin_enabled) {
    $status_label = 'Essay Guard is globally disabled — rescoring will not run.';
} elseif (!$unlock_ok) {
    $status_label = 'Essay Guard is not unlocked for this site. '
        . 'Rescoring will fail until your Site ID and API Key are configured in '
        . '<a href="' . (new moodle_url('/admin/settings.php', ['section' => 'plagiarismsettingessayguard']))->out(false) . '" style="color:' . $status_txt . ';">Essay Guard Settings</a>, '
        . 'or until your credit balance is sufficient for automatic unlock.';
} else {
    $status_label = 'Plugin is active and unlocked. Rescoring will work correctly.';
}

echo '<div style="background:' . $status_bg . ';border:1px solid ' . $status_bdr . ';border-radius:8px;'
    . 'padding:0.75rem 1rem;margin-bottom:1.5rem;font-size:0.88rem;color:' . $status_txt . ';">'
    . $status_icon . ' ' . $status_label
    . '</div>';

// ── Results from previous POST ────────────────────────────────────────────────
if (isset($results['error'])) {
    echo '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;'
        . 'padding:0.75rem 1rem;margin-bottom:1.5rem;font-size:0.9rem;color:#991b1b;">'
        . '&#x274C; ' . s($results['error'])
        . '</div>';
} elseif ($run_done) {
    $r = $results;
    echo '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;'
        . 'padding:1rem 1.25rem;margin-bottom:1.5rem;font-size:0.9rem;color:#166534;">'
        . '<strong>Rescore complete.</strong> '
        . $r['scored'] . ' scored &nbsp;&middot;&nbsp; '
        . $r['skipped'] . ' skipped (already had data) &nbsp;&middot;&nbsp; '
        . $r['no_text'] . ' no essay text found &nbsp;&middot;&nbsp; '
        . $r['errors'] . ' errors'
        . '</div>';

    if (!empty($r['rows'])) {
        $level_colours = [
            'low'     => '#166534',
            'medium'  => '#7c2d12',
            'high'    => '#991b1b',
            'skipped' => '#374151',
            'no_text' => '#6b7280',
            'error'   => '#991b1b',
        ];
        echo html_writer::start_tag('table', ['style' => 'width:100%;border-collapse:collapse;font-size:0.87rem;margin-bottom:1.5rem;']);
        echo html_writer::start_tag('thead');
        echo '<tr style="background:#f9fafb;">';
        foreach (['Student', 'Attempt ID', 'Result'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 12px;text-align:left;border-bottom:1px solid #e5e7eb;font-weight:600;']);
        }
        echo '</tr>';
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        foreach ($r['rows'] as $row) {
            $col = $level_colours[$row['status']] ?? '#374151';
            echo '<tr style="border-bottom:1px solid #f3f4f6;">';
            echo html_writer::tag('td', s($row['name']), ['style' => 'padding:7px 12px;']);
            echo html_writer::tag('td', (int)$row['qaid'], ['style' => 'padding:7px 12px;color:#9ca3af;font-size:0.82rem;']);
            echo html_writer::tag('td', s($row['detail']), ['style' => 'padding:7px 12px;color:' . $col . ';']);
            echo '</tr>';
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }
}

// ── Action form ───────────────────────────────────────────────────────────────
if (!empty($quiz_attempts)) {
    echo '<form method="post" action="' . (new moodle_url('/plagiarism/essayguard/rescore.php', ['cmid' => $cmid]))->out(false) . '">';
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'rescore']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'force', 'value' => '0']);

    $not_scored_count = 0;
    foreach ($quiz_attempts as $qa) {
        if (!isset($existing_uids[(int)$qa->userid])) {
            $not_scored_count++;
        }
    }

    $btn_label = $not_scored_count > 0
        ? 'Score ' . $not_scored_count . ' unscored attempt' . ($not_scored_count === 1 ? '' : 's')
        : 'All attempts already scored';
    $btn_disabled = ($not_scored_count === 0 || !$plugin_enabled || !$unlock_ok) ? 'disabled' : '';

    echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:1rem;">';
    echo '<button type="submit" ' . $btn_disabled . ' '
        . 'style="background:#6c3483;color:#fff;border:none;border-radius:6px;padding:9px 20px;'
        . 'font-size:0.9rem;font-weight:600;cursor:pointer;opacity:' . ($btn_disabled ? '0.5' : '1') . ';">'
        . s($btn_label)
        . '</button>';

    // Overwrite button (force=1) — only when there IS existing data.
    if (count($existing_uids) > 0 && !$run_done) {
        echo '<button type="button" onclick="this.form.force.value=\'1\';this.form.submit();" '
            . ($btn_disabled ? 'disabled' : '') . ' '
            . 'style="background:#fff;color:#6c3483;border:1px solid #d8b4fe;border-radius:6px;'
            . 'padding:9px 18px;font-size:0.88rem;font-weight:600;cursor:pointer;">'
            . 'Overwrite all &amp; re-score (' . count($quiz_attempts) . ' total)'
            . '</button>';
    }
    echo '</div>';
    echo '</form>';
} else {
    echo '<p style="color:#6b7280;">No finished quiz attempts found for this activity.</p>';
}

// ── Attempt preview table ─────────────────────────────────────────────────────
if (!empty($quiz_attempts)) {
    echo html_writer::tag('h2', 'Attempt Preview',
        ['style' => 'font-size:1rem;font-weight:600;margin:1.5rem 0 0.5rem;color:#374151;']);
    echo html_writer::tag('p',
        'Showing the ' . min(count($quiz_attempts), 50) . ' most recent finished attempts.',
        ['style' => 'font-size:0.83rem;color:#9ca3af;margin:0 0 0.5rem;']);

    echo html_writer::start_tag('table', ['style' => 'width:100%;border-collapse:collapse;font-size:0.85rem;']);
    echo html_writer::start_tag('thead');
    echo '<tr style="background:#f9fafb;">';
    foreach (['Student', 'Submitted', 'Essay Guard Status'] as $h) {
        echo html_writer::tag('th', $h, ['style' => 'padding:8px 12px;text-align:left;border-bottom:1px solid #e5e7eb;font-weight:600;']);
    }
    echo '</tr>';
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    $i = 0;
    foreach ($quiz_attempts as $qa) {
        if (++$i > 50) break;
        $uid   = (int)$qa->userid;
        $name  = fullname((object)['firstname' => $qa->firstname, 'lastname' => $qa->lastname]);
        $fin   = userdate((int)$qa->timefinish, get_string('strftimedatetimeshort', 'langconfig'));
        $has_data = isset($existing_uids[$uid]);

        $status_html = $has_data
            ? '<span style="color:#166534;font-weight:600;">&#x2713; Scored</span>'
            : '<span style="color:#9ca3af;">Not yet scored</span>';

        echo '<tr style="border-bottom:1px solid #f3f4f6;">';
        echo html_writer::tag('td', s($name), ['style' => 'padding:7px 12px;']);
        echo html_writer::tag('td', $fin, ['style' => 'padding:7px 12px;color:#6b7280;']);
        echo html_writer::tag('td', $status_html, ['style' => 'padding:7px 12px;']);
        echo '</tr>';
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}

echo $OUTPUT->footer();
