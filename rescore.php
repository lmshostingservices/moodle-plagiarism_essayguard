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
 * Requires: plagiarism/essayguard:rescore capability (this page writes score rows).
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

global $DB, $CFG, $OUTPUT, $PAGE;

require_once($CFG->libdir . '/formslib.php');
// V1.2.222: lib.php was never loaded here, so every plagiarism_essayguard_*() call in
// this file was a fatal. Caught live: pressing "Score N unscored attempts" produced
// "Call to undefined function plagiarism_essayguard_check_unlock()". The GET path never
// reaches those calls, which is why the page looked fine until the button was pressed.
require_once(__DIR__ . '/lib.php');

$cmid  = required_param('cmid', PARAM_INT);
$force = optional_param('force', 0, PARAM_INT);

$cm      = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cmid);

require_login($course, false, $cm);
// V1.2.224 FIX-EG-RESCORE-READCAP: this page writes score rows and spends vendor API
// calls, so it is gated on the write capability, not on the read one. See db/access.php.
require_capability('plagiarism/essayguard:rescore', $context);

if ($cm->modname !== 'quiz') {
    throw new \moodle_exception(
        'generalexceptionmessage',
        'error',
        '',
        get_string('rescore_onlyquiz', 'plagiarism_essayguard')
    );
}

$PAGE->set_url(new moodle_url('/plagiarism/essayguard/rescore.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title(get_string('rescore_pagetitle', 'plagiarism_essayguard'));
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/plagiarism/essayguard/styles.css');

/* ── Helpers (mirrors observer.php private methods) ──────────────────────────── */

/**
 * Extract plain text from HTML (strip tags, decode entities, collapse whitespace).
 *
 * @param string $html The stored answer HTML.
 * @return string The plain text of that answer, with whitespace collapsed.
 */
function plagiarism_essayguard_rescore_extract_text(string $html): string {
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xc2\xa0", ' ', $text); // Non-breaking space.
    return trim(preg_replace('/\s+/', ' ', $text));
}

/**
 * Fetch essay-question answers keyed by question slot, for a quiz attempt.
 * Returns [slot => plain_text]. Mirrors observer::get_quiz_essay_texts_by_slot().
 *
 * @param int $quizattemptid The quiz_attempts.id of the attempt to read answers from.
 * @return array<int,string>
 */
function plagiarism_essayguard_rescore_slot_texts(int $quizattemptid): array {
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
            $text = plagiarism_essayguard_rescore_extract_text($row->value ?? '');
            if ($text !== '') {
                $result[$slot] = $text;
            }
        }
    }

    // Fallback: responsesummary (Moodle's own plain-text summary — always populated
    // for essay questions, independent of editor type or step_data format).
    if (empty($result)) {
        $summaryrows = $DB->get_records_sql(
            "SELECT id, slot, responsesummary
               FROM {question_attempts}
              WHERE questionusageid = :qubaid
                AND responsesummary IS NOT NULL
                AND " . $DB->sql_isnotempty('question_attempts', 'responsesummary', true, true) . "
           ORDER BY slot ASC",
            ['qubaid' => $attempt->uniqueid]
        );
        foreach ($summaryrows as $sr) {
            $slot = (int)$sr->slot;
            if (!isset($result[$slot])) {
                $text = plagiarism_essayguard_rescore_extract_text($sr->responsesummary ?? '');
                if ($text !== '') {
                    $result[$slot] = $text;
                }
            }
        }
    }

    return $result;
}

/* ── Fetch quiz attempt list ─────────────────────────────────────────────────── */

$quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'id', MUST_EXIST);

// V1.2.224 FIX-EG-RESCORE-GROUPLEAK: report.php and student.php were both hardened for
// SEPARATEGROUPS in v1.2.219/v1.2.221; this page was missed. It selects every finished
// attempt in the quiz with no group filter and renders u.firstname/u.lastname, the risk
// level and the score back to the viewer, so a tutor restricted to one group could read
// every other group's students and results from here. Same restriction, same reasoning,
// same escape hatch (moodle/site:accessallgroups, or switch the activity to visible
// groups) as the other two entry points.
$groupmode   = groups_get_activity_groupmode($cm, $course);
$groupwhere  = '';
$groupparams = [];

if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
    $allowedgroupids = array_keys(groups_get_activity_allowed_groups($cm));

    if (empty($allowedgroupids)) {
        // Viewer is in no group in a separate-groups activity: no attempts are visible.
        $groupwhere = ' AND 1 = 0';
    } else {
        [$ginsql, $gparams] = $DB->get_in_or_equal($allowedgroupids, SQL_PARAMS_NAMED, 'grp');
        $groupwhere  = " AND EXISTS (SELECT 1 FROM {groups_members} gm
                                      WHERE gm.userid = qa.userid AND gm.groupid {$ginsql})";
        $groupparams = $gparams;
    }
}

// All finished quiz attempts for this quiz (state = finished), within the viewer's groups.
$quizattempts = $DB->get_records_sql(
    "SELECT qa.id, qa.userid, qa.timestart, qa.timefinish, qa.attempt,
            u.firstname, u.lastname
       FROM {quiz_attempts} qa
       JOIN {user} u ON u.id = qa.userid
      WHERE qa.quiz = :quiz
        AND qa.state = 'finished'
        {$groupwhere}
   ORDER BY qa.timefinish DESC, qa.id DESC",
    array_merge(['quiz' => $quiz->id], $groupparams)
);

// Existing EssayGuard aggregate records for this cmid.
$existingkeys = [];
if (!empty($quizattempts)) {
    $existingrecs = $DB->get_records_sql(
        "SELECT DISTINCT attemptkey FROM {plagiarism_essayguard_sc}
          WHERE cmid = :cmid AND qslot = 0",
        ['cmid' => $cmid]
    );
    foreach ($existingrecs as $r) {
        // V1.2.221: key on the ATTEMPT, not the user. plagiarism_essayguard_sc is unique
        // on (userid, cmid, attemptkey, qslot) and the key is 'qa_<attemptid>', so a student
        // with three permitted attempts needs three rows. Keying the skip set on userid meant
        // that once ANY one attempt was scored the other two were skipped forever - on a
        // resit-enabled quiz two thirds of attempts were never touched, and the summary
        // counted them as already done.
        $existingkeys[(string)$r->attemptkey] = true;
    }
}

/* ── Handle POST (rescore action) ────────────────────────────────────────────── */

$results = [];
$rundone = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('action', '', PARAM_ALPHA) === 'rescore') {
    require_sesskey();

    // Release session lock before long-running scoring loop.
    \core\session\manager::write_close();

    $globalenabled = get_config('plagiarism_essayguard', 'enabled');
    $pluginenabled = ($globalenabled === false) || !empty($globalenabled);

    $unlockok = plagiarism_essayguard_check_unlock();

    if (!$pluginenabled) {
        $results['error'] = get_string('rescore_errordisabled', 'plagiarism_essayguard');
    } else if (!$unlockok) {
        $results['error'] = get_string('rescore_errorlocked', 'plagiarism_essayguard');
    } else {
        $scored      = 0;
        $skipped     = 0;
        $notext     = 0;
        $errors      = 0;
        $remaining   = 0;
        $detailrows = [];

        /* ── v1.2.219: BOUNDED BATCH ──────────────────────────────────────────────── */
        // This loop used to run over EVERY finished attempt in the quiz, synchronously,
        // inside one web request, with no limit. Each iteration is several DB queries
        // plus a full analyser::score_attempt() per question slot plus one aggregate
        // pass. On a 900-attempt exam that is tens of thousands of queries in a single
        // POST: it exceeded max_execution_time, the PHP process was killed part-way, and
        // there was no record of how far it got — so the admin's only option was to press
        // the button again and have it redo everything from the start.
        //
        // The run is now bounded to RESCORE_BATCH attempts. Already-scored attempts are
        // skipped without being counted against the batch (they cost one array lookup),
        // so consecutive runs make real forward progress rather than re-walking the same
        // prefix. When attempts remain, the page says so and the admin presses the button
        // again; because scored attempts are skipped on the next pass, the operation is
        // naturally resumable and idempotent.
        //
        // MIGRATION CONSEQUENCE: rescoring a large quiz now takes several clicks instead
        // of one. It also now finishes, which the previous version did not.
        $batchlimit = 25;

        // V1.2.220: resume from an explicit offset.
        //
        // Progress used to rely entirely on the "skip already-scored" test below, which
        // meant the batch could never advance in two cases. With force=1 that test is
        // disabled, so every run restarted at attempt 1 and rescored the same 25 forever -
        // the button could be pressed indefinitely and attempts 26+ were never touched.
        // And without force, a no_text or errors outcome consumed the batch budget without
        // ever being recorded as done, so a quiz whose newest 25 attempts answered no
        // essay questions could never progress past them.
        //
        // v1.2.225 FIX-EG-RESCORE-UNSTABLE-CURSOR: the resume position was a numeric
        // OFFSET into a list ordered `qa.timefinish DESC, qa.id DESC`. That list is not
        // stable between batches. Any attempt finished while the teacher works through a
        // large quiz - and a teacher rescores a quiz precisely when students are still
        // submitting to it - is inserted at the FRONT of the ordering, so every index
        // shifts by one and array_slice() skips an attempt that was never processed. The
        // skipped attempt is not reported as skipped; it simply never appears, and the
        // run finishes claiming to be complete.
        //
        // Cursor on the attempt id instead. Ids are immutable and monotonic, so "continue
        // after id N" identifies the same position however the list has grown. New
        // attempts arriving mid-run are still picked up, because they sort at the front
        // and are therefore before the cursor, and are caught by the ordinary
        // already-scored test on a later pass.
        //
        // `startfrom` is still accepted so a bookmarked or in-flight URL from v1.2.220
        // does not break; it is honoured as an offset exactly as before when no cursor is
        // present. New pages always emit the cursor.
        $aftergroup = optional_param('afterid', 0, PARAM_INT);
        $startfrom  = optional_param('startfrom', 0, PARAM_INT);

        if ($aftergroup > 0) {
            // Drop everything up to and including the last attempt the previous batch
            // consumed. Comparing by id makes this independent of the list's length.
            // The logic lives in lib.php so it can be tested; see
            // plagiarism_essayguard_attempts_after().
            $quizattempts = plagiarism_essayguard_attempts_after($quizattempts, $aftergroup);
        } else if ($startfrom > 0) {
            $quizattempts = array_slice($quizattempts, $startfrom);
        }

        $consumed = 0;
        $lastconsumedid = $aftergroup;

        foreach ($quizattempts as $qa) {
            $qaid = (int)$qa->id;

            if (($scored + $notext + $errors) >= $batchlimit) {
                // V1.2.225 FIX-EG-RESCORE-REMAINING-OVERCOUNT: the batch-limit test used
                // to run BEFORE the already-scored test, so every attempt beyond the
                // limit was counted as outstanding whether or not it needed any work. On
                // a quiz where most attempts are already scored, the page told the
                // teacher there were hundreds left to do and they pressed the button
                // again and again watching the number barely move. Count only attempts
                // that would actually be processed on a later pass.
                if ($force || !isset($existingkeys['qa_' . $qaid])) {
                    $remaining++;
                }
                continue;
            }
            $consumed++;
            $lastconsumedid = $qaid;

            $uid   = (int)$qa->userid;
            $name  = fullname((object)['firstname' => $qa->firstname, 'lastname' => $qa->lastname]);

            // Skip already-scored attempts unless force=1.
            if (!$force && isset($existingkeys['qa_' . $qaid])) {
                $skipped++;
                $detailrows[] = ['name' => $name, 'qaid' => $qaid, 'status' => 'skipped',
                    'detail' => get_string('rescore_detailskipped', 'plagiarism_essayguard')];
                continue;
            }

            // Reconstruct attemptkey exactly as observer does (FIX-EG-ATTEMPTKEY v1.2.81).
            $attemptkey = 'qa_' . $qaid;

            // Extract text from DB.
            $slottexts = plagiarism_essayguard_rescore_slot_texts($qaid);
            $finaltext  = implode("\n\n", $slottexts);

            if (empty(trim($finaltext))) {
                $notext++;
                $detailrows[] = ['name' => $name, 'qaid' => $qaid, 'status' => 'no_text',
                    'detail' => get_string('rescore_detailnotext', 'plagiarism_essayguard')];
                continue;
            }

            try {
                // Score per-question first (FIX-EG-PERQ-FIRST v1.2.125).
                $atrow = $DB->get_record('quiz_attempts', ['id' => $qaid], 'timestart,timefinish');
                $attempttimestart  = $atrow ? (int)$atrow->timestart : 0;
                $attempttimefinish = $atrow ? (int)$atrow->timefinish : 0;

                $scoredslots = [];
                foreach ($slottexts as $slot => $slottext) {
                    \plagiarism_essayguard\local\service\analyser::score_attempt(
                        $uid,
                        $cmid,
                        $context->id,
                        $attemptkey,
                        [],
                        $slottext,
                        (int)$slot,
                        $attempttimestart,
                        $attempttimefinish
                    );
                    $scoredslots[] = (int)$slot;
                }

                // Prose, not code: Squiz.PHP.CommentedOutCode reads "qslot=0" as an assignment.
                // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
                // Aggregate (qslot=0).
                $result = \plagiarism_essayguard\local\service\analyser::score_attempt(
                    $uid,
                    $cmid,
                    $context->id,
                    $attemptkey,
                    [],
                    $finaltext,
                    0,
                    $attempttimestart,
                    $attempttimefinish
                );

                $existingkeys['qa_' . $qaid] = true;
                $scored++;

                $pct   = (int)round((float)($result['riskscore'] ?? 0) * 100);
                $level = $result['risklevel'] ?? 'low';
                $qlabel = !empty($scoredslots)
                    ? get_string(
                        'rescore_detailslots',
                        'plagiarism_essayguard',
                        implode(', Q', $scoredslots)
                    )
                    : get_string('rescore_detailaggregateonly', 'plagiarism_essayguard');

                $detailrows[] = ['name' => $name, 'qaid' => $qaid, 'status' => $level,
                    'detail' => strtoupper($level) . ' ' . $pct . '%' . $qlabel];
            } catch (\Throwable $e) {
                $errors++;
                $detailrows[] = ['name' => $name, 'qaid' => $qaid, 'status' => 'error',
                    'detail' => get_string(
                        'rescore_detailerror',
                        'plagiarism_essayguard',
                        $e->getMessage()
                    )];
            }
        }

        $results = [
            'scored'    => $scored,
            'skipped'   => $skipped,
            'no_text'   => $notext,
            'errors'    => $errors,
            // V1.2.219: attempts left untouched because the batch limit was reached.
            'remaining' => $remaining,
            // V1.2.220: where the NEXT run must resume from. Without this the form posts
            // startfrom=0 every time and the offset added above achieves nothing.
            // v1.2.225: the id of the last attempt this batch consumed, which is the
            // cursor the next batch resumes after. `nextfrom` is retained only so an
            // in-flight v1.2.220 page keeps working; new pages post `afterid`.
            'nextfrom'  => $startfrom + $consumed,
            'nextafter' => $lastconsumedid,
            'rows'      => $detailrows,
        ];
        $rundone = true;
    }
}

/* ── Render ──────────────────────────────────────────────────────────────────── */

echo $OUTPUT->header();

// Back / breadcrumb.
$reporturl = new moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]);
echo html_writer::tag(
    'a',
    '&#8592; ' . get_string('rescore_backtoreport', 'plagiarism_essayguard'),
    ['href' => $reporturl->out(false), 'style' => 'color:#6c3483;font-size:0.9rem;text-decoration:none;']
);

echo html_writer::tag(
    'h1',
    get_string('rescore_heading', 'plagiarism_essayguard'),
    ['style' => 'font-size:1.4rem;font-weight:700;margin:1rem 0 0.25rem;']
);
echo html_writer::tag(
    'p',
    format_string($cm->name) . ' &nbsp;|&nbsp; '
    . (count(
        $quizattempts) === 1
            ? get_string(
                'rescore_attemptsfoundone',
                'plagiarism_essayguard',
                count($quizattempts)
            )
            : get_string(
                'rescore_attemptsfound',
                'plagiarism_essayguard',
                count($quizattempts)
            )
    ),
    ['style' => 'color:#6b7280;font-size:0.88rem;margin:0 0 1.5rem;']
);

// Explanation box.
echo '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:1rem '
    . '1.25rem;margin-bottom:1.5rem;font-size:0.9rem;">'
    . '<strong style="color:#166534;">'
    . get_string('rescore_introheading', 'plagiarism_essayguard') . '</strong> '
    . get_string('rescore_intro', 'plagiarism_essayguard')
    . '</div>';

/* ── Unlock / plugin status ──────────────────────────────────────────────────── */
$globalenabled = get_config('plagiarism_essayguard', 'enabled');
$pluginenabled = ($globalenabled === false) || !empty($globalenabled);
$unlockok      = plagiarism_essayguard_check_unlock();

$statusbg    = ($pluginenabled && $unlockok) ? '#f0fdf4' : '#fff7ed';
$statusbdr   = ($pluginenabled && $unlockok) ? '#86efac' : '#fdba74';
$statustxt   = ($pluginenabled && $unlockok) ? '#166534' : '#7c2d12';
$statusicon  = ($pluginenabled && $unlockok) ? '&#x2705;' : '&#x26A0;&#xFE0F;';
$statuslabel = '';
if (!$pluginenabled) {
    $statuslabel = get_string('rescore_statusdisabled', 'plagiarism_essayguard');
} else if (!$unlockok) {
    $settingsurl = new moodle_url('/admin/settings.php', ['section' => 'plagiarismsettingessayguard']);
    $statuslabel = get_string('rescore_statuslockedstart', 'plagiarism_essayguard')
        . '<a href="' . $settingsurl->out(false) . '" style="color:' . $statustxt . ';">'
        . get_string('settingslink', 'plagiarism_essayguard') . '</a>'
        . get_string('rescore_statuslockedtail', 'plagiarism_essayguard');
} else {
    $statuslabel = get_string('rescore_statusok', 'plagiarism_essayguard');
}

echo '<div style="background:' . $statusbg . ';border:1px solid ' . $statusbdr . ';border-radius:8px;'
    . 'padding:0.75rem 1rem;margin-bottom:1.5rem;font-size:0.88rem;color:' . $statustxt . ';">'
    . $statusicon . ' ' . $statuslabel
    . '</div>';

/* ── Results from previous POST ──────────────────────────────────────────────── */
if (isset($results['error'])) {
    echo '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;'
        . 'padding:0.75rem 1rem;margin-bottom:1.5rem;font-size:0.9rem;color:#991b1b;">'
        . '&#x274C; ' . s($results['error'])
        . '</div>';
} else if ($rundone) {
    $r = $results;
    echo '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;'
        . 'padding:1rem 1.25rem;margin-bottom:1.5rem;font-size:0.9rem;color:#166534;">'
        . '<strong>' . get_string('rescore_complete', 'plagiarism_essayguard') . '</strong> '
        . get_string(
            'rescore_summary',
            'plagiarism_essayguard',
            (object) [
                'scored'  => $r['scored'],
                'skipped' => $r['skipped'],
                'notext'  => $r['no_text'],
                'errors'  => $r['errors'],
                ]
        )
        . '</div>';

    // V1.2.219: Tell the admin the run was bounded and that more work is waiting,
    // rather than letting them believe a truncated pass was a complete one.
    if (!empty($r['remaining'])) {
        echo '<div style="background:#fff7ed;border:1px solid #fdba74;border-radius:8px;'
            . 'padding:0.75rem 1rem;margin-bottom:1.5rem;font-size:0.9rem;color:#7c2d12;">'
            . '&#x26A0;&#xFE0F; <strong>'
            . get_string('rescore_remaining', 'plagiarism_essayguard', (int)$r['remaining'])
            . '</strong> '
            . get_string('rescore_batchnote', 'plagiarism_essayguard')
            . '</div>';
    }

    if (!empty($r['rows'])) {
        $levelcolours = [
            'low'     => '#166534',
            'medium'  => '#7c2d12',
            'high'    => '#991b1b',
            'skipped' => '#374151',
            'no_text' => '#6b7280',
            'error'   => '#991b1b',
        ];
        echo html_writer::start_tag(
            'table',
            ['style' => 'width:100%;border-collapse:collapse;font-size:0.87rem;margin-bottom:1.5rem;']
        );
        echo html_writer::start_tag('thead');
        echo '<tr style="background:#f9fafb;">';
        $rescoreheaders = [
            get_string('rescore_colstudent', 'plagiarism_essayguard'),
            get_string('rescore_attemptid', 'plagiarism_essayguard'),
            get_string('rescore_colresult', 'plagiarism_essayguard'),
        ];
        foreach ($rescoreheaders as $h) {
            echo html_writer::tag(
                'th',
                $h,
                ['style' => 'padding:8px 12px;text-align:left;border-bottom:1px solid '
                    . '#e5e7eb;font-weight:600;']
            );
        }
        echo '</tr>';
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        foreach ($r['rows'] as $row) {
            $col = $levelcolours[$row['status']] ?? '#374151';
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

/* ── Action form ─────────────────────────────────────────────────────────────── */
if (!empty($quizattempts)) {
    echo '<form method="post" action="'
        . (new moodle_url('/plagiarism/essayguard/rescore.php', ['cmid' => $cmid]))->out(false) . '">';
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'rescore']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'force', 'value' => '0']);
    // V1.2.220: resume point for the next batch, so repeated presses advance through the
    // quiz instead of reprocessing the same 25 attempts.
    // v1.2.225: the resume point is now the id of the last attempt consumed, not an
    // offset into a list that changes shape whenever a student submits mid-run. See
    // FIX-EG-RESCORE-UNSTABLE-CURSOR.
    echo html_writer::empty_tag(
        'input',
        ['type' => 'hidden', 'name' => 'afterid',
            'value' => (string)(isset($r['nextafter']) ? (int)$r['nextafter'] : 0)]
    );

    $notscoredcount = 0;
    foreach ($quizattempts as $qa) {
        if (!isset($existingkeys['qa_' . (int)$qa->id])) {
            $notscoredcount++;
        }
    }

    if ($notscoredcount > 1) {
        $btnlabel = get_string('rescore_scorebutton', 'plagiarism_essayguard', $notscoredcount);
    } else if ($notscoredcount === 1) {
        $btnlabel = get_string('rescore_scorebuttonone', 'plagiarism_essayguard', $notscoredcount);
    } else {
        $btnlabel = get_string('rescore_alreadyscored', 'plagiarism_essayguard');
    }
    $btndisabled = ($notscoredcount === 0 || !$pluginenabled || !$unlockok) ? 'disabled' : '';

    echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:1rem;">';
    echo '<button type="submit" ' . $btndisabled . ' '
        . 'style="background:#6c3483;color:#fff;border:none;border-radius:6px;padding:9px 20px;'
        . 'font-size:0.9rem;font-weight:600;cursor:pointer;opacity:' . ($btndisabled ? '0.5' : '1') . ';">'
        . s($btnlabel)
        . '</button>';

    // Overwrite button (force=1) — only when there IS existing data.
    // v1.2.221: was `&& !$run_done`, so after a force run the Overwrite button did not
    // render - the only remaining button posted force=0, which skipped everything, and the
    // page reported "Rescore complete" with 875 of 900 attempts still carrying the old
    // scores. It now renders whenever there is anything to overwrite, and the startfrom
    // hidden field above carries the resume offset into it.
    if (count($existingkeys) > 0) {
        echo '<button type="button" onclick="this.form.force.value=\'1\';this.form.submit();" '
            . ($btndisabled ? 'disabled' : '') . ' '
            . 'style="background:#fff;color:#6c3483;border:1px solid #d8b4fe;border-radius:6px;'
            . 'padding:9px 18px;font-size:0.88rem;font-weight:600;cursor:pointer;">'
            . get_string('rescore_overwrite', 'plagiarism_essayguard', count($quizattempts))
            . '</button>';
    }
    echo '</div>';
    echo '</form>';
} else {
    echo '<p style="color:#6b7280;">'
        . get_string('rescore_noattempts', 'plagiarism_essayguard') . '</p>';
}

/* ── Attempt preview table ───────────────────────────────────────────────────── */
if (!empty($quizattempts)) {
    echo html_writer::tag(
        'h2',
        get_string('rescore_previewheading', 'plagiarism_essayguard'),
        ['style' => 'font-size:1rem;font-weight:600;margin:1.5rem 0 0.5rem;color:#374151;']
    );
    echo html_writer::tag(
        'p',
        get_string('rescore_previewnote', 'plagiarism_essayguard', min(count($quizattempts), 50)),
        ['style' => 'font-size:0.83rem;color:#9ca3af;margin:0 0 0.5rem;']
    );

    echo html_writer::start_tag('table', ['style' => 'width:100%;border-collapse:collapse;font-size:0.85rem;']);
    echo html_writer::start_tag('thead');
    echo '<tr style="background:#f9fafb;">';
    $previewheaders = [
        get_string('rescore_colstudent', 'plagiarism_essayguard'),
        get_string('rescore_colsubmitted', 'plagiarism_essayguard'),
        get_string('rescore_colstatus', 'plagiarism_essayguard'),
    ];
    foreach ($previewheaders as $h) {
        echo html_writer::tag(
            'th',
            $h,
            ['style' => 'padding:8px 12px;text-align:left;border-bottom:1px solid '
                . '#e5e7eb;font-weight:600;']
        );
    }
    echo '</tr>';
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    $i = 0;
    foreach ($quizattempts as $qa) {
        if (++$i > 50) {
            break;
        }
        $uid   = (int)$qa->userid;
        $name  = fullname((object)['firstname' => $qa->firstname, 'lastname' => $qa->lastname]);
        $fin   = userdate((int)$qa->timefinish, get_string('strftimedatetimeshort', 'langconfig'));
        $hasdata = isset($existingkeys['qa_' . (int)$qa->id]);

        $statushtml = $hasdata
            ? '<span style="color:#166534;font-weight:600;">&#x2713; '
                . get_string('rescore_scored', 'plagiarism_essayguard') . '</span>'
            : '<span style="color:#9ca3af;">'
                . get_string('rescore_notscored', 'plagiarism_essayguard') . '</span>';

        echo '<tr style="border-bottom:1px solid #f3f4f6;">';
        echo html_writer::tag('td', s($name), ['style' => 'padding:7px 12px;']);
        echo html_writer::tag('td', $fin, ['style' => 'padding:7px 12px;color:#6b7280;']);
        echo html_writer::tag('td', $statushtml, ['style' => 'padding:7px 12px;']);
        echo '</tr>';
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}

echo $OUTPUT->footer();
