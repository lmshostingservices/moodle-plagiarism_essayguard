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
 * Essay Guard class behaviour analysis report for one activity.
 *
 * Access checks and data retrieval only; presentation lives in
 * templates/report*.mustache.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use plagiarism_essayguard\local\service\analyser;

$cmid = required_param('cmid', PARAM_INT);

$cm      = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cmid);

require_login($course, false, $cm);
require_capability('plagiarism/essayguard:viewreport', $context);

$PAGE->set_url(new moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title(get_string('classreport', 'plagiarism_essayguard'));
$PAGE->set_heading($course->fullname);

// Link back to the activity's grading page.
if ($cm->modname === 'assign') {
    $gradeurl = new moodle_url('/mod/assign/view.php', ['id' => $cmid, 'action' => 'grading']);
} else if ($cm->modname === 'quiz') {
    $gradeurl = new moodle_url('/mod/quiz/report.php', ['id' => $cmid, 'mode' => 'grading']);
} else {
    $gradeurl = new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cmid]);
}

// Honour separate groups: without accessallgroups a viewer only sees members of their own groups,
// and a viewer in no group sees nobody (matching Moodle's grader report).
$groupmode   = groups_get_activity_groupmode($cm, $course);
$groupwhere  = '';
$groupparams = [];

if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
    $allowedgroupids = array_keys(groups_get_activity_allowed_groups($cm));

    if (empty($allowedgroupids)) {
        $groupwhere = ' AND 1 = 0';
    } else {
        [$ginsql, $gparams] = $DB->get_in_or_equal($allowedgroupids, SQL_PARAMS_NAMED, 'grp');
        $groupwhere  = " AND EXISTS (SELECT 1 FROM {groups_members} gm
                                      WHERE gm.userid = sc.userid AND gm.groupid {$ginsql})";
        $groupparams = $gparams;
    }
}

// Email is an identity field: only shown when configured in showuseridentity and the viewer may see identity.
$identityfields = explode(',', (string)($CFG->showuseridentity ?? ''));
$showemail = in_array('email', $identityfields, true)
    && has_capability('moodle/site:viewuseridentity', $context);

// Load aggregate (qslot 0) and per-question rows. The large explanations blob is deliberately not selected.
$scores = $DB->get_records_sql(
    "SELECT sc.id, sc.userid, sc.cmid, sc.qslot, sc.attemptkey, sc.riskscore, sc.risklevel,
            sc.metricsjson, sc.timemodified, sc.typing_time, sc.total_keystrokes, sc.paste_events,
            u.firstname, u.lastname, u.email, u.username, u.picture, u.imagealt,
            u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
       FROM {plagiarism_essayguard_sc} sc
       JOIN {user} u ON u.id = sc.userid
      WHERE sc.cmid = :cmid {$groupwhere}
   ORDER BY sc.userid ASC, sc.qslot ASC, sc.timemodified DESC",
    array_merge(['cmid' => $cmid], $groupparams)
);

// Scope each student to a single attempt (shared rule in lib.php), keyed by qslot.
$rowsbyuser = [];
foreach ($scores as $sc) {
    $rowsbyuser[(int)$sc->userid][] = $sc;
}

$display = [];
foreach ($rowsbyuser as $userrows) {
    $records = plagiarism_essayguard_scope_to_current_attempt($userrows);
    $main = $records[0] ?? null;
    if (!$main) {
        $main = reset($records);
    }
    $main->_perq    = $records;
    $main->_overall = plagiarism_essayguard_overall_score($records);
    $display[] = $main;
}

// Sort: highest risk level first, then overall score descending, then by name.
usort($display, function ($a, $b) {
    $order = ['high' => 0, 'medium' => 1, 'low' => 2];
    $ao = $order[analyser::risk_level((int)round($a->_overall * 100))] ?? 2;
    $bo = $order[analyser::risk_level((int)round($b->_overall * 100))] ?? 2;
    if ($ao !== $bo) {
        return $ao - $bo;
    }
    $sccmp = $b->_overall <=> $a->_overall;
    if ($sccmp !== 0) {
        return $sccmp;
    }
    return strcmp($a->lastname . $a->firstname, $b->lastname . $b->firstname);
});

$risklabels = [
    'low'    => get_string('risklow', 'plagiarism_essayguard'),
    'medium' => get_string('riskmedium', 'plagiarism_essayguard'),
    'high'   => get_string('riskhigh', 'plagiarism_essayguard'),
];

$siglabels = [
    1  => get_string('siglabel1', 'plagiarism_essayguard'),
    2  => get_string('siglabel2', 'plagiarism_essayguard'),
    3  => get_string('siglabel3', 'plagiarism_essayguard'),
    4  => get_string('siglabel4', 'plagiarism_essayguard'),
    5  => get_string('siglabel5', 'plagiarism_essayguard'),
    12 => get_string('siglabel12', 'plagiarism_essayguard'),
    13 => get_string('siglabel13', 'plagiarism_essayguard'),
];

// Display name for a score row joined to its user.
$namefor = function (stdClass $sc): string {
    return fullname((object)[
        'firstname'         => $sc->firstname,
        'lastname'          => $sc->lastname,
        'firstnamephonetic' => $sc->firstnamephonetic ?? '',
        'lastnamephonetic'  => $sc->lastnamephonetic ?? '',
        'middlename'        => $sc->middlename ?? '',
        'alternatename'     => $sc->alternatename ?? '',
    ]);
};

// Status warnings: why the plugin may not be scoring this activity.
$pluginenabled = plagiarism_essayguard_is_enabled();
$cmenabled     = plagiarism_essayguard_is_cm_active($cmid);
$unlockok      = plagiarism_essayguard_check_unlock();
$status = [
    'show'           => !$pluginenabled || !$cmenabled || !$unlockok,
    'globaldisabled' => !$pluginenabled,
    'cmdisabled'     => $pluginenabled && !$cmenabled,
    'notunlocked'    => $pluginenabled && !$unlockok,
    'settingsurl'    => (new moodle_url('/admin/settings.php', ['section' => 'plagiarismsettingessayguard']))->out(false),
];

// Stat cards. A student is "not assessed" when none of their records captured any typing activity.
$counts = ['low' => 0, 'medium' => 0, 'high' => 0];
$notassessed = 0;
foreach ($display as $sc) {
    $counts[analyser::risk_level((int)round($sc->_overall * 100))]++;

    $hasmeasured = false;
    foreach ($sc->_perq as $r) {
        if (!plagiarism_essayguard_is_unmeasured($r)) {
            $hasmeasured = true;
            break;
        }
    }
    if (!$hasmeasured) {
        $notassessed++;
    }
}

$statcards = [
    ['variant' => 'total', 'label' => get_string('stattotal', 'plagiarism_essayguard'), 'value' => count($display)],
    ['variant' => 'high', 'label' => get_string('stathigh', 'plagiarism_essayguard'), 'value' => $counts['high']],
    ['variant' => 'medium', 'label' => get_string('statmedium', 'plagiarism_essayguard'), 'value' => $counts['medium']],
    ['variant' => 'low', 'label' => get_string('statlow', 'plagiarism_essayguard'), 'value' => $counts['low']],
    ['variant' => 'notassessed', 'label' => get_string('statnotassessed', 'plagiarism_essayguard'), 'value' => $notassessed],
];

// Submissions table rows, each with its per-question breakdown.
$rows = [];
$highrisk = [];
$analysedformat = get_string('report_strftimeanalysed', 'plagiarism_essayguard');

foreach ($display as $sc) {
    $score     = (int)round($sc->_overall * 100);
    $level     = analyser::risk_level($score);
    $risklabel = core_text::strtoupper($risklabels[$level] ?? $risklabels['low']);
    $fullname  = $namefor($sc);
    $detailurl = (new moodle_url('/plagiarism/essayguard/student.php', ['cmid' => $cmid, 'userid' => $sc->userid]))->out(false);

    $perqcount = 0;
    foreach (array_keys($sc->_perq) as $slot) {
        if ((int)$slot > 0) {
            $perqcount++;
        }
    }

    $questions = [];
    $perq = $sc->_perq;
    ksort($perq);
    $agg = $perq[0] ?? null;
    foreach ($perq as $slot => $r) {
        if ((int)$slot === 0) {
            continue;
        }

        $isaggregatefallback = false;
        $r = plagiarism_essayguard_resolve_question_record($r, $agg, $isaggregatefallback);

        $qscore  = isset($r->riskscore) ? (int)round((float)$r->riskscore * 100) : 0;
        $qlevel  = analyser::risk_level($qscore);
        $qrisk   = core_text::strtoupper($risklabels[$qlevel] ?? $risklabels['low']);
        $qlabel  = $qrisk . ($isaggregatefallback ? get_string('overallsuffix', 'plagiarism_essayguard') : '');

        // Never badge an unassessed question as LOW.
        $qunmeasured = plagiarism_essayguard_is_unmeasured($r);
        if ($qunmeasured) {
            $qlevel = 'nodata';
            $qlabel = core_text::strtoupper(get_string('nodatabadge', 'plagiarism_essayguard'));
            $qrisk  = core_text::strtoupper($qlabel);
        }

        $questions[] = [
            'label'      => get_string('questionlabel', 'plagiarism_essayguard', (int)$slot),
            'level'      => $qlevel,
            'score'      => $qscore,
            'unmeasured' => $qunmeasured,
            'scorelabel' => $qlabel,
            'risklabel'  => $qrisk,
        ];
    }

    $rows[] = [
        'fullname'   => $fullname,
        'profileurl' => (new moodle_url('/user/view.php', ['id' => $sc->userid, 'course' => $course->id]))->out(false),
        'showemail'  => $showemail,
        'email'      => $sc->email ?? '',
        'level'      => $level,
        'ishigh'     => $level === 'high',
        'score'      => $score,
        'risklabel'  => $risklabel,
        'qcount'     => $perqcount > 0 ? $perqcount : 1,
        'analysed'   => $sc->timemodified ? userdate((int)$sc->timemodified, $analysedformat) : '—',
        'detailurl'  => $detailurl,
        'questions'  => $questions,
    ];

    if ($level === 'high') {
        // Primary signal: the highest-scoring entry in the aggregate signal breakdown.
        $metrics = !empty($sc->metricsjson) ? (json_decode($sc->metricsjson, true) ?: []) : [];
        $sigbd   = $metrics['signal_breakdown'] ?? [];
        $topsig  = '—';
        if (!empty($sigbd)) {
            arsort($sigbd);
            $topk   = array_key_first($sigbd);
            $topsig = $siglabels[(int)$topk] ?? get_string('signalnumber', 'plagiarism_essayguard', $topk);
        }

        $highrisk[] = [
            'fullname'  => $fullname,
            'score'     => $score,
            'risklabel' => core_text::strtoupper($risklabels['high']),
            'signal'    => $topsig,
            'detailurl' => $detailurl,
        ];
    }
}

$plugininfo = \core_plugin_manager::instance()->get_plugin_info('plagiarism_essayguard');

$templatedata = [
    'gradeurl'     => $gradeurl->out(false),
    'release'      => ($plugininfo && !empty($plugininfo->release)) ? $plugininfo->release : '',
    'activityname' => $cm->name,
    'coursename'   => $course->fullname,
    'status'       => $status,
    'statcards'    => $statcards,
    'hasrows'      => !empty($rows),
    'rows'         => $rows,
    'isquiz'       => $cm->modname === 'quiz',
    'rescoreurl'   => (new moodle_url('/plagiarism/essayguard/rescore.php', ['cmid' => $cmid]))->out(false),
    'hashighrisk'  => !empty($highrisk),
    'highrisk'     => $highrisk,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('plagiarism_essayguard/report', $templatedata);
echo $OUTPUT->footer();
