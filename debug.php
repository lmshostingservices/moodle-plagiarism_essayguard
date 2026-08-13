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

/**
 * Essay Guard — In-Moodle Diagnostic / Debug Panel.
 *
 * Shows the full scoring pipeline state for a given course module without
 * needing access to PHP server error logs. Accessible to admins and teachers
 * who hold plagiarism/essayguard:viewreport in the module context.
 *
 * URL: /plagiarism/essayguard/debug.php?cmid=X
 *
 * What it shows:
 *  1. Plugin status — enabled, unlocked, version.
 *  2. DB records in plagiarism_essayguard_sc (per user, per slot).
 *  3. Most recent quiz attempt step_data extraction preview.
 *  4. responsesummary fallback data from question_attempts.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

global $DB, $OUTPUT, $PAGE, $USER;

$cmid = required_param('cmid', PARAM_INT);

$cm      = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cmid);

require_login($course, false, $cm);
require_capability('plagiarism/essayguard:viewreport', $context);

$PAGE->set_url(new moodle_url('/plagiarism/essayguard/debug.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title('Essay Guard Debug — ' . $cm->name);
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/plagiarism/essayguard/styles.css');

// ── Helpers ───────────────────────────────────────────────────────────────────

function eg_debug_badge(string $ok_label, bool $ok, string $fail_label = ''): string {
    if ($ok) {
        return '<span style="background:#f0fdf4;color:#166534;border:1px solid #86efac;padding:2px 10px;border-radius:4px;font-weight:600;">'
            . htmlspecialchars($ok_label) . '</span>';
    }
    return '<span style="background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;padding:2px 10px;border-radius:4px;font-weight:600;">'
        . htmlspecialchars($fail_label ?: $ok_label) . '</span>';
}

function eg_debug_extract_plain(string $html): string {
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xc2\xa0", ' ', $text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

// ── Gather data ───────────────────────────────────────────────────────────────

$plugin_enabled  = (bool)get_config('plagiarism_essayguard', 'enabled');
$plugin_version  = get_config('plagiarism_essayguard', 'version') ?: 'unknown';
$plugin_unlocked = plagiarism_essayguard_check_unlock();

// DB records for this cmid.
$score_records = $DB->get_records_sql(
    "SELECT sc.*, u.firstname, u.lastname
       FROM {plagiarism_essayguard_sc} sc
       JOIN {user} u ON u.id = sc.userid
      WHERE sc.cmid = :cmid
   ORDER BY sc.userid ASC, sc.qslot ASC, sc.timemodified DESC",
    ['cmid' => $cmid]
);

// Recent quiz attempts for this cmid (last 10 unique quiz attempt IDs).
$recent_attempts = [];
if ($cm->modname === 'quiz') {
    $recent_attempts = $DB->get_records_sql(
        "SELECT qa.id, qa.userid, qa.timefinish, qa.uniqueid, u.firstname, u.lastname
           FROM {quiz_attempts} qa
           JOIN {quiz} q ON q.id = qa.quiz
           JOIN {course_modules} cm ON cm.instance = q.id
           JOIN {user} u ON u.id = qa.userid
          WHERE cm.id = :cmid AND qa.state = 'finished'
       ORDER BY qa.timefinish DESC",
        ['cmid' => $cmid],
        0, 10
    );
}

// ── Render ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();

$reporturl = new moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]);

echo '<div class="essayguard-report" style="font-family:system-ui,sans-serif;max-width:1100px;">';

// Header.
echo '<div style="margin-bottom:1.5rem;">';
echo html_writer::tag('a', '&#8592; Back to Essay Guard report',
    ['href' => $reporturl->out(false), 'style' => 'color:#2563eb;font-size:0.9rem;']);
echo '<h2 style="margin:0.5rem 0 0.25rem;font-size:1.3rem;font-weight:700;">Essay Guard — Debug Panel</h2>';
echo '<p style="margin:0;color:#6b7280;font-size:0.9rem;">'
    . htmlspecialchars($cm->name) . ' (cmid=' . $cmid . ')</p>';
echo '</div>';

// ── Section 1: Plugin status ──────────────────────────────────────────────────
echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:1.25rem;margin-bottom:1.25rem;">';
echo '<h3 style="margin:0 0 1rem;font-size:1rem;font-weight:700;">1. Plugin Status</h3>';
echo '<table style="border-collapse:collapse;width:100%;">';
$rows = [
    ['Plugin enabled',  eg_debug_badge('Enabled', $plugin_enabled, 'DISABLED — scoring will not run')],
    ['Site unlocked',   eg_debug_badge('Unlocked', $plugin_unlocked, 'NOT UNLOCKED — visit Essay Guard Settings')],
    ['Version',         '<code>' . htmlspecialchars(get_config('plagiarism_essayguard', 'version') ?: 'not set') . '</code>'],
    ['Module type',     '<code>' . htmlspecialchars($cm->modname) . '</code>'],
    ['CM enabled',      eg_debug_badge('Enabled for this activity', (bool)get_config('plagiarism_essayguard', 'enabled_cm_' . $cmid), 'Not enabled for this activity')],
];
foreach ($rows as [$label, $val]) {
    echo '<tr>';
    echo '<td style="padding:6px 12px 6px 0;color:#374151;font-size:0.9rem;width:220px;">' . htmlspecialchars($label) . '</td>';
    echo '<td style="padding:6px 0;">' . $val . '</td>';
    echo '</tr>';
}
echo '</table>';
echo '</div>';

// ── Section 2: DB score records ───────────────────────────────────────────────
echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:1.25rem;margin-bottom:1.25rem;">';
echo '<h3 style="margin:0 0 1rem;font-size:1rem;font-weight:700;">2. Score Records in DB ('
    . count($score_records) . ' rows)</h3>';

if (empty($score_records)) {
    echo '<p style="color:#9ca3af;font-size:0.9rem;">No score records found for this activity. '
        . 'This means either no students have submitted yet, the observer did not fire, '
        . 'or the analyser threw an error. Check the PHP error log for [EssayGuard] entries.</p>';
} else {
    echo '<div style="overflow-x:auto;">';
    echo '<table style="border-collapse:collapse;width:100%;font-size:0.85rem;">';
    echo '<thead><tr style="background:#f9fafb;">';
    foreach (['User', 'Slot', 'Risk %', 'Level', 'AttemptKey', 'Modified'] as $h) {
        echo '<th style="padding:6px 10px;text-align:left;border-bottom:1px solid #e5e7eb;white-space:nowrap;">'
            . htmlspecialchars($h) . '</th>';
    }
    echo '</tr></thead><tbody>';

    $seen = [];
    foreach ($score_records as $sc) {
        $key = $sc->userid . '_' . ($sc->qslot ?? 0);
        if (isset($seen[$key])) {
            continue; // show only most recent per (user, slot)
        }
        $seen[$key] = true;

        $pct   = (int)round((float)($sc->riskscore ?? 0) * 100);
        $level = \plagiarism_essayguard\local\service\analyser::risk_level($pct);
        $lcfg  = [
            'high'    => '#fef2f2;color:#991b1b',
            'medium'  => '#fff7ed;color:#7c2d12',
            'partial' => '#fff7ed;color:#7c2d12',   // legacy alias
            'low'     => '#f0fdf4;color:#166534',
        ][$level] ?? '#f9fafb;color:#374151';

        echo '<tr style="border-bottom:1px solid #f3f4f6;">';
        echo '<td style="padding:6px 10px;">' . htmlspecialchars($sc->firstname . ' ' . $sc->lastname) . '</td>';
        echo '<td style="padding:6px 10px;">' . ($sc->qslot == 0 ? '<em style="color:#9ca3af;">aggregate</em>' : 'Q' . (int)$sc->qslot) . '</td>';
        echo '<td style="padding:6px 10px;font-weight:600;">' . $pct . '%</td>';
        echo '<td style="padding:6px 10px;"><span style="background:' . $lcfg . ';padding:1px 8px;border-radius:4px;font-size:0.8rem;font-weight:600;">' . strtoupper($level) . '</span></td>';
        echo '<td style="padding:6px 10px;font-size:0.8rem;color:#6b7280;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' . htmlspecialchars($sc->attemptkey ?? '') . '">'
            . htmlspecialchars($sc->attemptkey ?? '—') . '</td>';
        echo '<td style="padding:6px 10px;white-space:nowrap;">' . userdate((int)($sc->timemodified ?? 0), get_string('strftimedatetimeshort', 'langconfig')) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div>';

// ── Section 3: Recent quiz attempt extraction preview ─────────────────────────
if ($cm->modname === 'quiz') {
    echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:1.25rem;margin-bottom:1.25rem;">';
    echo '<h3 style="margin:0 0 0.5rem;font-size:1rem;font-weight:700;">3. Recent Quiz Attempt Extraction Preview</h3>';
    echo '<p style="margin:0 0 1rem;color:#6b7280;font-size:0.85rem;">Shows what Essay Guard would extract from each attempt. '
        . '"step_data" is the primary source; "responsesummary" is the fallback used when step_data yields nothing. '
        . 'An attempt showing "EMPTY" in both columns is why that student has no score.</p>';

    if (empty($recent_attempts)) {
        echo '<p style="color:#9ca3af;font-size:0.9rem;">No finished quiz attempts found for this activity.</p>';
    } else {
        foreach ($recent_attempts as $att) {
            $qubaid = $att->uniqueid;

            // Primary: step_data 'answer' field.
            $step_rows = $DB->get_records_sql(
                "SELECT qas.id, qa.slot, qasd.value
                   FROM {question_attempt_steps} qas
                   JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
                   JOIN {question_attempts} qa ON qa.id = qas.questionattemptid
                  WHERE qa.questionusageid = :qubaid AND qasd.name = 'answer'
               ORDER BY qa.slot ASC, qas.id DESC",
                ['qubaid' => $qubaid]
            );

            $step_slots = [];
            foreach ($step_rows as $sr) {
                $slot = (int)$sr->slot;
                if (!isset($step_slots[$slot])) {
                    $text = eg_debug_extract_plain($sr->value ?? '');
                    $step_slots[$slot] = ['raw' => (string)($sr->value ?? ''), 'text' => $text];
                }
            }

            // Fallback: responsesummary from question_attempts.
            $summary_rows = $DB->get_records_sql(
                "SELECT id, slot, responsesummary
                   FROM {question_attempts}
                  WHERE questionusageid = :qubaid
                    AND responsesummary IS NOT NULL
               ORDER BY slot ASC",
                ['qubaid' => $qubaid]
            );

            echo '<details style="margin-bottom:1rem;border:1px solid #e5e7eb;border-radius:4px;">';
            echo '<summary style="padding:0.6rem 1rem;cursor:pointer;font-size:0.9rem;font-weight:600;background:#f9fafb;">';
            echo htmlspecialchars($att->firstname . ' ' . $att->lastname);
            echo ' <span style="color:#6b7280;font-weight:400;">'
                . '(attemptid=' . (int)$att->id . ', '
                . userdate((int)$att->timefinish, '%d %b %Y %H:%M') . ')'
                . '</span>';
            $has_text = !empty(array_filter(array_column($step_slots, 'text'), 'strlen'));
            echo ' ' . eg_debug_badge(count($step_slots) . ' slot(s) extracted', $has_text, 'EMPTY — will use fallback or no score');
            echo '</summary>';

            echo '<div style="padding:1rem;font-size:0.85rem;">';

            // Step-data table.
            echo '<strong>Primary source (question_attempt_step_data, name=\'answer\'):</strong>';
            if (empty($step_slots)) {
                echo '<p style="color:#dc2626;margin:0.25rem 0 0.75rem;">No \'answer\' step_data rows found for this attempt. Check that the quiz uses essay questions.</p>';
            } else {
                echo '<table style="border-collapse:collapse;width:100%;margin:0.5rem 0 1rem;font-size:0.82rem;">';
                echo '<thead><tr style="background:#f9fafb;">';
                foreach (['Slot', 'Extracted text (trimmed)', 'Raw value (first 200 chars)'] as $h) {
                    echo '<th style="padding:4px 8px;text-align:left;border-bottom:1px solid #e5e7eb;">' . $h . '</th>';
                }
                echo '</tr></thead><tbody>';
                foreach ($step_slots as $slot => $info) {
                    $extracted = $info['text'];
                    $raw       = mb_substr($info['raw'], 0, 200);
                    $ok        = $extracted !== '';
                    echo '<tr style="border-bottom:1px solid #f3f4f6;">';
                    echo '<td style="padding:4px 8px;white-space:nowrap;">Q' . (int)$slot . '</td>';
                    echo '<td style="padding:4px 8px;">';
                    if ($ok) {
                        echo '<span style="color:#166534;">' . htmlspecialchars(mb_substr($extracted, 0, 120)) . (mb_strlen($extracted) > 120 ? '…' : '') . '</span>';
                    } else {
                        echo '<span style="color:#dc2626;font-weight:600;">EMPTY after strip_tags + entity decode</span>';
                    }
                    echo '</td>';
                    echo '<td style="padding:4px 8px;color:#6b7280;font-family:monospace;">' . htmlspecialchars($raw) . (mb_strlen($info['raw']) > 200 ? '…' : '') . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }

            // Responsesummary fallback.
            echo '<strong>Fallback source (question_attempts.responsesummary):</strong>';
            $has_summary = false;
            foreach ($summary_rows as $sr) {
                if (!empty(trim($sr->responsesummary ?? ''))) {
                    $has_summary = true;
                    break;
                }
            }
            if (empty($summary_rows)) {
                echo '<p style="color:#9ca3af;margin:0.25rem 0 0;">No question_attempt rows found.</p>';
            } else {
                echo '<table style="border-collapse:collapse;width:100%;margin:0.5rem 0;font-size:0.82rem;">';
                echo '<thead><tr style="background:#f9fafb;">';
                foreach (['Slot', 'responsesummary'] as $h) {
                    echo '<th style="padding:4px 8px;text-align:left;border-bottom:1px solid #e5e7eb;">' . $h . '</th>';
                }
                echo '</tr></thead><tbody>';
                foreach ($summary_rows as $sr) {
                    $text = eg_debug_extract_plain($sr->responsesummary ?? '');
                    echo '<tr style="border-bottom:1px solid #f3f4f6;">';
                    echo '<td style="padding:4px 8px;white-space:nowrap;">Q' . (int)$sr->slot . '</td>';
                    echo '<td style="padding:4px 8px;">';
                    if ($text !== '') {
                        echo '<span style="color:#166534;">' . htmlspecialchars(mb_substr($text, 0, 150)) . (mb_strlen($text) > 150 ? '…' : '') . '</span>';
                    } else {
                        echo '<span style="color:#9ca3af;">empty</span>';
                    }
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }

            echo '</div>'; // padding
            echo '</details>';
        }
    }

    echo '</div>';
}

// ── Section 4: Quick diagnosis ────────────────────────────────────────────────
echo '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:1.25rem;margin-bottom:1.25rem;">';
echo '<h3 style="margin:0 0 0.75rem;font-size:1rem;font-weight:700;">4. Quick Diagnosis</h3>';
echo '<ul style="margin:0;padding-left:1.25rem;font-size:0.9rem;line-height:1.8;">';

if (!$plugin_enabled) {
    echo '<li style="color:#dc2626;"><strong>Plugin not enabled</strong> — go to Site Administration → Plugins → Plagiarism → Essay Guard and enable it.</li>';
}
if (!$plugin_unlocked) {
    echo '<li style="color:#dc2626;"><strong>Site not unlocked</strong> — the EssayGraderAI licence check failed. Visit Essay Guard Settings and check the licence key.</li>';
}
if (empty($score_records)) {
    echo '<li style="color:#b45309;"><strong>No score records</strong> — Essay Guard has never written a score for this activity. '
        . 'Either no student has submitted, the observer did not fire, or the analyser threw an exception. '
        . 'Search the PHP error log for <code>[EssayGuard]</code>.</li>';
} else {
    $has_perq = false;
    foreach ($score_records as $sc) {
        if ((int)($sc->qslot ?? 0) > 0) {
            $has_perq = true;
            break;
        }
    }
    if (!$has_perq && $cm->modname === 'quiz') {
        echo '<li style="color:#b45309;"><strong>Only aggregate records (qslot=0) — no per-question records</strong> — '
            . 'this means get_quiz_essay_texts_by_slot() returned empty every time. '
            . 'The extraction preview in section 3 above will show you why. '
            . 'v1.2.84 now uses the responsesummary fallback automatically.</li>';
    } else {
        echo '<li style="color:#166534;"><strong>Score records present</strong> — '
            . count(array_unique(array_column((array)$score_records, 'userid')))
            . ' unique student(s) scored.</li>';
    }
}

if ($cm->modname !== 'quiz') {
    echo '<li style="color:#374151;">This is a <strong>' . htmlspecialchars($cm->modname) . '</strong> — per-question scoring only applies to quizzes. '
        . 'Aggregate (qslot=0) records are expected.</li>';
}

echo '</ul>';
echo '</div>';

echo '</div>'; // essayguard-report

echo $OUTPUT->footer();
