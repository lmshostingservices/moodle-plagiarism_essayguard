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
 * Essay Guard - retroactive rescore admin tool.
 *
 * Scores finished quiz attempts that were never scored at submission time, using the
 * same analyser logic as the submission observer.
 *
 * URL: /plagiarism/essayguard/rescore.php?cmid=X
 *      POST action=rescore          - score unscored attempts (one bounded batch)
 *      POST action=rescore&force=1  - overwrite existing records too
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

global $DB, $CFG, $OUTPUT, $PAGE;

require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/lib.php');

$cmid  = required_param('cmid', PARAM_INT);
$force = optional_param('force', 0, PARAM_INT);

$cm      = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cmid);

require_login($course, false, $cm);
// This page writes score rows and spends vendor API calls, so it needs the write capability.
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

/* ── Helpers (mirror observer.php private methods) ───────────────────────────── */

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

// Respect SEPARATEGROUPS, as report.php and student.php do: a tutor restricted to one
// group must not see other groups' students or results here.
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
            u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
       FROM {quiz_attempts} qa
       JOIN {user} u ON u.id = qa.userid
      WHERE qa.quiz = :quiz
        AND qa.state = 'finished'
        {$groupwhere}
   ORDER BY qa.timefinish DESC, qa.id DESC",
    array_merge(['quiz' => $quiz->id], $groupparams)
);

// Existing EssayGuard aggregate records for this cmid, keyed by attemptkey ('qa_<attemptid>').
// Keyed on the attempt, not the user, so each permitted attempt is scored separately.
$existingkeys = [];
if (!empty($quizattempts)) {
    $existingrecs = $DB->get_records_sql(
        "SELECT DISTINCT attemptkey FROM {plagiarism_essayguard_sc}
          WHERE cmid = :cmid AND qslot = 0",
        ['cmid' => $cmid]
    );
    foreach ($existingrecs as $r) {
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

    $pluginenabled = plagiarism_essayguard_is_enabled();
    $unlockok      = plagiarism_essayguard_check_unlock();

    if (!$pluginenabled) {
        $results['error'] = get_string('rescore_errordisabled', 'plagiarism_essayguard');
    } else if (!$unlockok) {
        $results['error'] = get_string('rescore_errorlocked', 'plagiarism_essayguard');
    } else {
        $scored     = 0;
        $skipped    = 0;
        $notext     = 0;
        $errors     = 0;
        $remaining  = 0;
        $detailrows = [];

        // Each run processes at most $batchlimit attempts so a large quiz cannot exceed
        // max_execution_time. Already-scored attempts are skipped without counting against
        // the batch, so repeated runs are resumable and idempotent.
        $batchlimit = 25;

        // Resume after the last attempt id the previous batch consumed. Ids are stable even
        // when new attempts arrive mid-run, unlike a numeric offset. `startfrom` (legacy
        // offset) is still honoured when no cursor is present.
        $aftergroup = optional_param('afterid', 0, PARAM_INT);
        $startfrom  = optional_param('startfrom', 0, PARAM_INT);

        if ($aftergroup > 0) {
            $quizattempts = plagiarism_essayguard_attempts_after($quizattempts, $aftergroup);
        } else if ($startfrom > 0) {
            $quizattempts = array_slice($quizattempts, $startfrom);
        }

        $consumed = 0;
        $lastconsumedid = $aftergroup;

        foreach ($quizattempts as $qa) {
            $qaid = (int)$qa->id;

            if (($scored + $notext + $errors) >= $batchlimit) {
                // Count only attempts that a later pass would actually process.
                if ($force || !isset($existingkeys['qa_' . $qaid])) {
                    $remaining++;
                }
                continue;
            }
            $consumed++;
            $lastconsumedid = $qaid;

            $uid   = (int)$qa->userid;
            $name  = fullname($qa);

            // Skip already-scored attempts unless force=1.
            if (!$force && isset($existingkeys['qa_' . $qaid])) {
                $skipped++;
                $detailrows[] = ['name' => $name, 'qaid' => $qaid, 'status' => 'skipped',
                    'detail' => get_string('rescore_detailskipped', 'plagiarism_essayguard')];
                continue;
            }

            // Reconstruct attemptkey exactly as the observer does.
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
                // Score per-question first, then the aggregate.
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

                // Aggregate row: question slot zero.
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
                    'detail' => get_string('rescore_detailscored', 'plagiarism_essayguard', (object)[
                        'level' => strtoupper($level),
                        'pct'   => $pct,
                        'slots' => $qlabel,
                    ])];
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
            // Attempts left untouched because the batch limit was reached.
            'remaining' => $remaining,
            // Legacy offset for the next run (kept for in-flight older pages).
            'nextfrom'  => $startfrom + $consumed,
            // Cursor the next batch resumes after.
            'nextafter' => $lastconsumedid,
            'rows'      => $detailrows,
        ];
        $rundone = true;
    }
}

/* ── Build template context ──────────────────────────────────────────────────── */

$pluginenabled = plagiarism_essayguard_is_enabled();
$unlockok      = plagiarism_essayguard_check_unlock();
$attemptcount  = count($quizattempts);

$data = [
    'reporturl'      => (new moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]))->out(false),
    'cmname'         => format_string($cm->name, true, ['context' => $context]),
    'attemptsfound'  => get_string(
        $attemptcount === 1 ? 'rescore_attemptsfoundone' : 'rescore_attemptsfound',
        'plagiarism_essayguard',
        $attemptcount
    ),
    'statusok'       => $pluginenabled && $unlockok,
    'statusdisabled' => !$pluginenabled,
    'statuslocked'   => $pluginenabled && !$unlockok,
    'settingsurl'    => (new moodle_url('/admin/settings.php', ['section' => 'plagiarismsettingessayguard']))->out(false),
    'error'          => $results['error'] ?? null,
    'rundone'        => $rundone,
    'hasattempts'    => !empty($quizattempts),
];

if ($rundone) {
    // Map known result states to CSS modifiers; anything else falls back to the default colour.
    $statusclasses = [
        'low'     => 'eg-rescore-result-low',
        'medium'  => 'eg-rescore-result-medium',
        'high'    => 'eg-rescore-result-high',
        'skipped' => 'eg-rescore-result-skipped',
        'no_text' => 'eg-rescore-result-notext',
        'error'   => 'eg-rescore-result-error',
    ];
    $rows = [];
    foreach ($results['rows'] as $row) {
        $rows[] = [
            'name'        => $row['name'],
            'qaid'        => (int)$row['qaid'],
            'detail'      => $row['detail'],
            'statusclass' => $statusclasses[$row['status']] ?? '',
        ];
    }
    $data['results'] = [
        'scored'    => (int)$results['scored'],
        'skipped'   => (int)$results['skipped'],
        'notext'    => (int)$results['no_text'],
        'errors'    => (int)$results['errors'],
        'remaining' => (int)$results['remaining'],
        'rows'      => $rows,
        'hasrows'   => !empty($rows),
    ];
}

if (!empty($quizattempts)) {
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

    // Preview of the most recent attempts.
    $previewlimit = 50;
    $preview = [];
    foreach (array_slice($quizattempts, 0, $previewlimit) as $qa) {
        $preview[] = [
            'name'      => fullname($qa),
            'submitted' => userdate((int)$qa->timefinish, get_string('strftimedatetimeshort', 'langconfig')),
            'hasdata'   => isset($existingkeys['qa_' . (int)$qa->id]),
        ];
    }

    $data['form'] = [
        'actionurl'     => (new moodle_url('/plagiarism/essayguard/rescore.php', ['cmid' => $cmid]))->out(false),
        'sesskey'       => sesskey(),
        // Resume cursor: id of the last attempt the previous batch consumed.
        'afterid'       => $rundone ? (int)$results['nextafter'] : 0,
        'btnlabel'      => $btnlabel,
        'disabled'      => ($notscoredcount === 0 || !$pluginenabled || !$unlockok),
        // Overwrite stays available once everything is scored; that is when it is needed.
        'overwritedisabled' => (!$pluginenabled || !$unlockok),
        // Overwrite (force=1) is offered whenever there is existing data to overwrite.
        'showoverwrite' => count($existingkeys) > 0,
        'total'         => $attemptcount,
    ];
    $data['preview'] = $preview;
    $data['previewcount'] = min($attemptcount, $previewlimit);
}

/* ── Render ──────────────────────────────────────────────────────────────────── */

$PAGE->requires->js_call_amd('plagiarism_essayguard/rescore', 'init');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('plagiarism_essayguard/rescore', $data);
echo $OUTPUT->footer();
