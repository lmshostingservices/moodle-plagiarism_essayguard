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
 * Essay Guard — Class Behaviour Analysis Report.
 *
 * DocGuard-quality layout: version badge header, 6 stat cards, clean Submissions
 * table (Score/Risk/Questions/Analysed/Actions), Cross-Student Behaviour Summary.
 *
 * URL: /plagiarism/essayguard/report.php?cmid=X
 * Requires: plagiarism/essayguard:viewreport capability (editingteacher, manager).
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');
require_once(__DIR__ . '/lib.php');

global $DB, $CFG, $OUTPUT, $PAGE;

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
$PAGE->requires->css('/plagiarism/essayguard/styles.css');

/* ── Grading URL ─────────────────────────────────────────────────────────────── */
if ($cm->modname === 'assign') {
    $gradeurl = new moodle_url('/mod/assign/view.php', ['id' => $cmid, 'action' => 'grading']);
} else if ($cm->modname === 'quiz') {
    $gradeurl = new moodle_url('/mod/quiz/report.php', ['id' => $cmid, 'mode' => 'grading']);
} else {
    $gradeurl = new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cmid]);
}

/* ── Group mode restriction ──────────────────────────────────────────────────── */
// V1.2.219: THIS REPORT IGNORED GROUP MODE ENTIRELY.
//
// It listed every student in the activity, by full name and email address, to anyone
// holding plagiarism/essayguard:viewreport in the module context. On a site using
// separate groups — every multi-cohort RTO, every faculty sharing one course shell —
// a tutor assigned to one group could read the names, email addresses and behavioural
// integrity profiles of every other tutor's students. Moodle's own grading pages honour
// the group restriction; this report simply did not implement it, so it was a way around
// the restriction rather than a view of it.
//
// groups_get_activity_allowed_groups() returns the groups this user may see, honouring
// both SEPARATEGROUPS and the moodle/site:accessallgroups override. When the activity is
// in separate-groups mode and the viewer lacks that capability, the score query is
// restricted to members of those groups. A viewer in separate-groups mode who belongs to
// no group sees nothing, which is the same thing the grader report shows them.
//
// MIGRATION CONSEQUENCE: on courses using separate groups, teachers who previously saw
// the whole cohort in this report will now see only their own groups. That is the
// correction, not a regression; a site that genuinely wants the wider view grants
// moodle/site:accessallgroups or switches the activity to visible groups.
$groupmode    = groups_get_activity_groupmode($cm, $course);
$groupwhere   = '';
$groupparams  = [];
$grouprestricted = false;

if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
    $grouprestricted = true;
    $allowedgroups   = groups_get_activity_allowed_groups($cm);
    $allowedgroupids = array_keys($allowedgroups);

    if (empty($allowedgroupids)) {
        // Viewer is in no group in a separate-groups activity: no students are visible.
        $groupwhere = ' AND 1 = 0';
    } else {
        [$ginsql, $gparams] = $DB->get_in_or_equal($allowedgroupids, SQL_PARAMS_NAMED, 'grp');
        $groupwhere  = " AND EXISTS (SELECT 1 FROM {groups_members} gm
                                      WHERE gm.userid = sc.userid AND gm.groupid {$ginsql})";
        $groupparams = $gparams;
    }
}

/* ── Load scores ─────────────────────────────────────────────────────────────── */
// Load ALL records (aggregate + per-question), group by user, keep most recent per (user, qslot).
// FIX-EG-REPORT-PERQ (v1.2.69): Per-question rows are kept so teachers see per-Q breakdown.
$scores = $DB->get_records_sql(
    "SELECT sc.*,
            u.firstname, u.lastname, u.email, u.username, u.picture, u.imagealt,
            u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
       FROM {plagiarism_essayguard_sc} sc
       JOIN {user} u ON u.id = sc.userid
      WHERE sc.cmid = :cmid {$groupwhere}
   ORDER BY sc.userid ASC, sc.qslot ASC, sc.timemodified DESC",
    array_merge(['cmid' => $cmid], $groupparams)
);

$byuser = [];
foreach ($scores as $sc) {
    $uid  = (int)$sc->userid;
    $slot = (int)($sc->qslot ?? 0);
    if (!isset($byuser[$uid][$slot])) {
        $byuser[$uid][$slot] = $sc;
    }
}

$display = [];
foreach ($byuser as $uid => $records) {
    $main = $records[0] ?? null;
    if (!$main) {
        $main = reset($records);
    }
    $main->_perq = $records;
    $display[]   = $main;
}

// FIX-EG-REPORT-AVG (v1.2.208): Compute the student's displayed overall score as the
// mean of their per-question scores (qslot > 0) when they exist, rather than using the
// raw qslot=0 aggregate record directly.  The aggregate is scored by pooling ALL keystroke
// events together and can differ from the per-question averages — e.g. two questions at
// 15% and 20% would show as 20% (the aggregate's pooled score) instead of 17.5%.
// Fall back to the aggregate only when no per-question records are present (assignments,
// forums, or single-question quizzes where qslot detection wrote only qslot=0).
foreach ($display as $sc) {
    $pqscores = [];
    if (!empty($sc->_perq)) {
        foreach ($sc->_perq as $slot => $r) {
            if ((int)$slot > 0 && isset($r->riskscore)) {
                // Apply the same aggregate-fallback rule used in lib.php and in the
                // per-question breakdown below, so the overall average is consistent
                // with what is shown in the individual question rows.
                $pqscore = (float)$r->riskscore;
                if ($pqscore <= 0.0 && isset($sc->_perq[0])) {
                    $aggr   = $sc->_perq[0];
                    $aggmet = !empty($aggr->metricsjson) ? (json_decode($aggr->metricsjson, true) ?: []) : [];
                    $aggs1  = (int)(($aggmet['signal_breakdown'][1] ?? 0));
                    if ($aggs1 >= 30 || (float)$aggr->riskscore >= 0.70) {
                        $pqscore = (float)$aggr->riskscore;
                    }
                }
                $pqscores[] = $pqscore;
            }
        }
    }
    if (!empty($pqscores)) {
        $sc->_overall = array_sum($pqscores) / count($pqscores);
    } else {
        $sc->_overall = isset($sc->riskscore) ? (float)$sc->riskscore : 0.0;
    }
}

// Sort: highest risk first, then score desc, then alphabetical.
// FIX-EG-BADGE-LEVEL (v1.2.71): Re-derive level from score, not stale DB column.
// FIX-EG-REPORT-AVG (v1.2.208): Sort on computed average, not raw aggregate riskscore.
usort(
    $display,
    function ($a, $b) {
        $scorea = (int)round($a->_overall * 100);
        $scoreb = (int)round($b->_overall * 100);
        $lva    = \plagiarism_essayguard\local\service\analyser::risk_level($scorea);
        $lvb    = \plagiarism_essayguard\local\service\analyser::risk_level($scoreb);
        $order   = ['high' => 0, 'medium' => 1, 'low' => 2];
        $ao = $order[$lva] ?? 2;
        $bo = $order[$lvb] ?? 2;
        if ($ao !== $bo) {
        return $ao - $bo;
        }
        $sccmp = $b->_overall <=> $a->_overall;
        if ($sccmp !== 0) {
        return $sccmp;
        }
        return strcmp($a->lastname . $a->firstname, $b->lastname . $b->firstname);
        }
);

/* ── Risk config ─────────────────────────────────────────────────────────────── */
// FIX-EG-BADGE-MEDIUM (v1.2.113): 'medium' key matches analyser::risk_level() output.
$riskcfg = [
    'low'    => ['dot' => '#22c55e', 'bg' => '#f0fdf4', 'text' => '#166534', 'border' => '#86efac',
        'label' => get_string('risklow', 'plagiarism_essayguard')],
    'medium' => ['dot' => '#f97316', 'bg' => '#fff7ed', 'text' => '#7c2d12', 'border' => '#fdba74',
        'label' => get_string('riskmedium', 'plagiarism_essayguard')],
    'high'   => ['dot' => '#ef4444', 'bg' => '#fef2f2', 'text' => '#991b1b', 'border' => '#fca5a5',
        'label' => get_string('riskhigh', 'plagiarism_essayguard')],
];

// Summary counts — use computed average, not raw aggregate riskscore.
// FIX-EG-REPORT-AVG (v1.2.208): _overall is the mean of per-question scores.
$counts = ['low' => 0, 'medium' => 0, 'high' => 0];
foreach ($display as $sc) {
    $scpct = (int)round($sc->_overall * 100);
    $lv     = \plagiarism_essayguard\local\service\analyser::risk_level($scpct);
    $counts[$lv]++;
}

/* ── Signal labels (used in Cross-Student section) ───────────────────────────── */
$siglabels = [
    1  => get_string('siglabel1', 'plagiarism_essayguard'),
    2  => get_string('siglabel2', 'plagiarism_essayguard'),
    3  => get_string('siglabel3', 'plagiarism_essayguard'),
    4  => get_string('siglabel4', 'plagiarism_essayguard'),
    5  => get_string('siglabel5', 'plagiarism_essayguard'),
    12 => get_string('siglabel12', 'plagiarism_essayguard'),
    13 => get_string('siglabel13', 'plagiarism_essayguard'),
];

/* ── Render ──────────────────────────────────────────────────────────────────── */
echo $OUTPUT->header();

/* ── Plugin status banner ────────────────────────────────────────────────────── */
$egglobalenabled = get_config('plagiarism_essayguard', 'enabled');
$egpluginenabled = ($egglobalenabled === false) || !empty($egglobalenabled);
$egcmenabled     = plagiarism_essayguard_is_cm_active($cmid);
$egunlockok      = plagiarism_essayguard_check_unlock();

if (!$egpluginenabled || !$egcmenabled || !$egunlockok) {
    $warnparts = [];
    if (!$egpluginenabled) {
        $settingsurl = new moodle_url('/admin/settings.php', ['section' => 'plagiarismsettingessayguard']);
        $warnparts[] = get_string('warnglobaldisabled', 'plagiarism_essayguard')
            . html_writer::link(
                $settingsurl,
                get_string('warnglobaldisabledlink', 'plagiarism_essayguard'),
                []
            );
    }
    if ($egpluginenabled && !$egcmenabled) {
        $warnparts[] = get_string('warncmdisabled', 'plagiarism_essayguard');
    }
    if ($egpluginenabled && !$egunlockok) {
        $settingsurl = new moodle_url('/admin/settings.php', ['section' => 'plagiarismsettingessayguard']);
        $warnparts[] = get_string('warnnotunlocked', 'plagiarism_essayguard')
            . html_writer::link($settingsurl, get_string('settingslink', 'plagiarism_essayguard'), [])
            . get_string('warnnotunlockedtail', 'plagiarism_essayguard');
    }
    echo '<div style="background:#fff7ed;border:1px solid #fdba74;border-radius:8px;'
        . 'padding:0.85rem 1.1rem;margin-bottom:1rem;font-size:0.88rem;color:#7c2d12;">'
        . '&#x26A0; <strong>' . get_string('notscoring', 'plagiarism_essayguard') . '</strong><br>'
        . '<ul style="margin:0.4rem 0 0 1.2rem;padding:0;">'
        . '<li>' . implode('</li><li>', $warnparts) . '</li>'
        . '</ul></div>';
}

/* ── Page header banner ──────────────────────────────────────────────────────── */
echo '<div class="eg-class-header">';
echo '<div class="eg-class-header-inner">';
echo '<h2 class="eg-class-title">' . get_string('classreportheading', 'plagiarism_essayguard') . '</h2>';
echo '<p class="eg-class-meta">';
// V1.2.219: This badge was hardcoded to '1.2.179' and had been wrong for forty
// releases — the one place a teacher or a support engineer looks to confirm which build
// is actually installed was lying to them. Read it from version.php instead so it can
// never drift again.
$egplugininfo = \core_plugin_manager::instance()->get_plugin_info('plagiarism_essayguard');
$egrelease    = $egplugininfo && !empty($egplugininfo->release) ? $egplugininfo->release : '';
echo '<span class="eg-version-badge">' . s($egrelease) . '</span>';
echo '&nbsp;&nbsp;' . get_string('essayguard', 'plagiarism_essayguard');
echo '</p>';
echo '</div>';
echo '<a href="' . $gradeurl->out(false) . '" class="eg-back-btn">&#8592; '
    . get_string('backtograding', 'plagiarism_essayguard') . '</a>';
echo '</div>';

// Activity + course subtitle.
echo '<p class="eg-class-subtitle">' . s($cm->name) . ' &nbsp;|&nbsp; ' . s($course->fullname) . '</p>';

/* ── Stat cards ──────────────────────────────────────────────────────────────── */
$statcards = [
    [get_string('stattotal', 'plagiarism_essayguard'), count($display), '#1e3a5f', '#fff'],
    [get_string('stathigh', 'plagiarism_essayguard'), $counts['high'], '#b71c1c', '#fff'],
    [get_string('statmedium', 'plagiarism_essayguard'), $counts['medium'], '#e65100', '#fff'],
    [get_string('statlow', 'plagiarism_essayguard'), $counts['low'], '#2e7d32', '#fff'],
    [get_string('statpending', 'plagiarism_essayguard'), 0, '#555555', '#fff'],
    [get_string('staterrors', 'plagiarism_essayguard'), 0, '#6b21a8', '#fff'],
];
echo '<div class="eg-stat-cards">';
foreach ($statcards as [$label, $val, $bg, $fg]) {
    echo '<div class="eg-stat-card" style="background:' . $bg . ';color:' . $fg . ';">'
       . '<div class="eg-stat-num">' . (int)$val . '</div>'
       . '<div class="eg-stat-label">' . s($label) . '</div>'
       . '</div>';
}
echo '</div>';

/* ── Empty state ─────────────────────────────────────────────────────────────── */
if (empty($display)) {
    echo '<div class="essayguard-empty">';
    echo '<p style="margin:0 0 0.5rem;">' . get_string('emptystate', 'plagiarism_essayguard') . '</p>';
    if ($cm->modname === 'quiz') {
        $rescoreurl = new moodle_url('/plagiarism/essayguard/rescore.php', ['cmid' => $cmid]);
        echo '<div style="margin-top:0.75rem;padding:0.75rem 1rem;background:#f5f3ff;'
           . 'border:1px solid #c4b5fd;border-radius:6px;font-size:0.88rem;color:#4c1d95;">'
           . '<strong>' . get_string('rescoreprompt', 'plagiarism_essayguard') . '</strong> '
           . get_string('useword', 'plagiarism_essayguard')
           . html_writer::link(
               $rescoreurl,
               get_string('rescoretoollink', 'plagiarism_essayguard'),
               ['style' => 'color:#6c3483;font-weight:600;']
           )
           . get_string('rescoreprompttail', 'plagiarism_essayguard')
           . '</div>';
    }
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

/* ── Submissions table ───────────────────────────────────────────────────────── */
echo '<h4 class="eg-section-heading">' . get_string('submissionsheading', 'plagiarism_essayguard') . '</h4>';

echo '<table class="generaltable eg-class-table">';
echo '<thead><tr>'
   . '<th>' . get_string('colstudent', 'plagiarism_essayguard') . '</th>'
   . '<th>' . get_string('colscore', 'plagiarism_essayguard') . '</th>'
   . '<th>' . get_string('colrisk', 'plagiarism_essayguard') . '</th>'
   . '<th>' . get_string('colquestions', 'plagiarism_essayguard') . '</th>'
   . '<th>' . get_string('colanalysed', 'plagiarism_essayguard') . '</th>'
   . '<th>' . get_string('colactions', 'plagiarism_essayguard') . '</th>'
   . '</tr></thead>';
echo '<tbody>';

foreach ($display as $sc) {
    // FIX-EG-BADGE-LEVEL (v1.2.71): Derive level from score, not stale DB column.
    // FIX-EG-REPORT-AVG (v1.2.208): Use computed mean of per-question scores.
    $score = (int)round($sc->_overall * 100);
    $level = \plagiarism_essayguard\local\service\analyser::risk_level($score);
    $c     = $riskcfg[$level] ?? $riskcfg['low'];

    // Count distinct per-question slots (slot > 0).
    $perqcount = 0;
    if (!empty($sc->_perq)) {
        foreach ($sc->_perq as $slot => $r) {
            if ((int)$slot > 0) {
                $perqcount++;
            }
        }
    }
    $qcountdisplay = $perqcount > 0 ? $perqcount : 1;

    // URLs.
    $profileurl = new moodle_url('/user/view.php', ['id' => $sc->userid, 'course' => $course->id]);
    $detailurl  = new moodle_url('/plagiarism/essayguard/student.php', ['cmid' => $cmid, 'userid' => $sc->userid]);

    // Full name.
    $fn = fullname(
        (object)[
            'firstname'         => $sc->firstname,
            'lastname'          => $sc->lastname,
            'firstnamephonetic' => $sc->firstnamephonetic ?? '',
            'lastnamephonetic'  => $sc->lastnamephonetic ?? '',
            'middlename'        => $sc->middlename ?? '',
            'alternatename'     => $sc->alternatename ?? '',
            ]
    );

    // Score badge: "65/100  HIGH" with colored background.
    $scorehtml = '<span class="eg-score-badge eg-score-badge-' . s($level) . '">'
                . $score . '/100 &nbsp;&nbsp;' . strtoupper($c['label'])
                . '</span>';

    // Risk badge (separate column).
    $riskhtml = '<span class="essayguard-badge essayguard-badge-' . s($level) . '">'
               . strtoupper($c['label'])
               . '</span>';

    // Analysed timestamp.
    $analysed = $sc->timemodified ? userdate((int)$sc->timemodified, '%d %b %Y %H:%M') : '—';

    // Action button.
    $actionhtml = '<a href="' . $detailurl->out(false) . '" class="eg-action-btn">'
                 . get_string('viewreport', 'plagiarism_essayguard') . '</a>';

    $rowclass = ($level === 'high') ? 'essayguard-row-high' : '';
    echo '<tr' . ($rowclass ? ' class="' . $rowclass . '"' : '') . '>';
    echo '<td>'
       . '<a href="' . $profileurl->out(false) . '" class="eg-student-name">' . s($fn) . '</a>'
       . '<br><small class="eg-student-email">' . s($sc->email ?? '') . '</small>'
       . '</td>';
    echo '<td>' . $scorehtml . '</td>';
    echo '<td>' . $riskhtml . '</td>';
    echo '<td class="eg-center">' . $qcountdisplay . '</td>';
    echo '<td class="eg-ts">' . $analysed . '</td>';
    echo '<td>' . $actionhtml . '</td>';
    echo '</tr>';

    /* ── Per-question breakdown rows ─────────────────────────────────────────── */
    // FIX-EG-REPORT-PERQ (v1.2.69): Indented breakdown rows for quiz per-question slots.
    if (!empty($sc->_perq)) {
        $perq = $sc->_perq;
        ksort($perq);

        // FIX-EG-REPORT-FALLBACK (v1.2.108): Mirror lib.php fallback rule for consistency.
        $agg = $perq[0] ?? null;
        foreach ($perq as $slot => $r) {
            if ((int)$slot === 0) {
                continue;
            }

            // FIX-EG-FALLBACK-PASTE-ONLY (v1.2.110) + FIX-EG-REPORT-PASTE-SIGNAL1 (v1.2.160).
            $isaggregatefallback = false;
            if ((float)$r->riskscore <= 0.0 && $agg) {
                $aggmetricsarr   = !empty($agg->metricsjson) ? (json_decode($agg->metricsjson, true) ?: []) : [];
                $aggsignal1pts   = (int)(($aggmetricsarr['signal_breakdown'][1] ?? 0));
                $agghaspasteev  = ($aggsignal1pts >= 30) || ((float)$agg->riskscore >= 0.70);
                if ($agghaspasteev) {
                    $r = $agg;
                    $isaggregatefallback = true;
                }
            }

            // FIX-EG-BADGE-LEVEL (v1.2.71): Derive from score, not DB column.
            $qscore  = isset($r->riskscore) ? (int)round((float)$r->riskscore * 100) : 0;
            $qlevel  = \plagiarism_essayguard\local\service\analyser::risk_level($qscore);
            $qc      = $riskcfg[$qlevel] ?? $riskcfg['low'];
            $qlabel  = strtoupper($qc['label'])
                . ($isaggregatefallback ? get_string('overallsuffix', 'plagiarism_essayguard') : '');

            $qscorehtml = '<span class="eg-score-badge eg-score-badge-' . s($qlevel) . '" style="font-size:0.8rem;">'
                         . $qscore . '/100 &nbsp;&nbsp;' . $qlabel
                         . '</span>';
            $qriskhtml  = '<span class="essayguard-badge essayguard-badge-' . s($qlevel) . '">'
                         . strtoupper($qc['label'])
                         . '</span>';

            echo '<tr class="eg-perq-row">';
            echo '<td class="eg-perq-label">&#x21B3; '
                . get_string('questionlabel', 'plagiarism_essayguard', (int)$slot) . '</td>';
            echo '<td>' . $qscorehtml . '</td>';
            echo '<td>' . $qriskhtml . '</td>';
            echo '<td class="eg-center eg-muted">—</td>';
            echo '<td class="eg-ts eg-muted">—</td>';
            echo '<td></td>';
            echo '</tr>';
        }
    }
}

echo '</tbody></table>';

/* ── Cross-Student Behaviour Summary ─────────────────────────────────────────── */
echo '<h4 class="eg-section-heading" style="margin-top:2rem;">'
   . get_string('crossheading', 'plagiarism_essayguard') . '</h4>';
echo '<p class="eg-similarity-note">' . get_string('crossnote', 'plagiarism_essayguard') . '</p>';

$highrisk = array_filter(
    $display,
    function ($sc) {
        $s = (int)round($sc->_overall * 100);
        return \plagiarism_essayguard\local\service\analyser::risk_level($s) === 'high';
        }
);

if (empty($highrisk)) {
    echo '<p class="eg-no-similarity">' . get_string('nohighrisk', 'plagiarism_essayguard') . '</p>';
} else {
    echo '<table class="generaltable eg-similarity-table">';
    echo '<thead><tr>'
       . '<th>' . get_string('colstudent', 'plagiarism_essayguard') . '</th>'
       . '<th>' . get_string('colscore', 'plagiarism_essayguard') . '</th>'
       . '<th>' . get_string('colprimarysignal', 'plagiarism_essayguard') . '</th>'
       . '<th>' . get_string('colactions', 'plagiarism_essayguard') . '</th>'
       . '</tr></thead><tbody>';

    foreach ($highrisk as $sc) {
        $s  = (int)round($sc->_overall * 100);
        $fn = fullname(
            (object)[
                'firstname'         => $sc->firstname,
                'lastname'          => $sc->lastname,
                'firstnamephonetic' => $sc->firstnamephonetic ?? '',
                'lastnamephonetic'  => $sc->lastnamephonetic ?? '',
                'middlename'        => $sc->middlename ?? '',
                'alternatename'     => $sc->alternatename ?? '',
                ]
        );

        // Identify the top-scoring signal from metricsjson.
        $metrics = !empty($sc->metricsjson) ? (json_decode($sc->metricsjson, true) ?: []) : [];
        $sigbd  = $metrics['signal_breakdown'] ?? [];
        $topsig = '—';
        if (!empty($sigbd)) {
            arsort($sigbd);
            $topk   = array_key_first($sigbd);
            $topsig = $siglabels[(int)$topk]
                ?? get_string('signalnumber', 'plagiarism_essayguard', $topk);
        }

        $durl = new moodle_url('/plagiarism/essayguard/student.php', ['cmid' => $cmid, 'userid' => $sc->userid]);
        echo '<tr>';
        echo '<td class="eg-student-name-plain">' . s($fn) . '</td>';
        echo '<td><span class="eg-score-badge eg-score-badge-high">' . $s . '/100 &nbsp;&nbsp;'
            . strtoupper(get_string('riskhigh', 'plagiarism_essayguard')) . '</span></td>';
        echo '<td class="eg-signal-label">' . s($topsig) . '</td>';
        echo '<td><a href="' . $durl->out(false) . '" class="eg-action-btn">'
            . get_string('viewreport', 'plagiarism_essayguard') . '</a></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

// V1.2.219: DIAGNOSTIC PAGES REMOVED FROM THE RELEASE.
//
// This bar linked to debug.php, diag.php and badge_diag.php. Those three, plus
// attempt_diag.php, report_match_diag.php and capture_test.php, were ~5,400 lines —
// about a third of the codebase — of internal troubleshooting scaffolding: raw event
// dumps, signal-by-signal threshold traces, and a description of exactly which
// behaviours trip which detector. Their access control was correct, but a client
// release should not ship a third of its code as developer tooling, and the badge
// diagnostic in particular documented the detection heuristics in plain English on a
// page reachable from the teacher UI. All six files are deleted; this bar goes with them.

echo $OUTPUT->footer();
