<?php
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
 * @copyright  2026 EssayGraderAI
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
$PAGE->set_title('Essay Guard — Class Report');
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/plagiarism/essayguard/styles.css');

// ── Grading URL ───────────────────────────────────────────────────────────────
if ($cm->modname === 'assign') {
    $gradeurl = new moodle_url('/mod/assign/view.php', ['id' => $cmid, 'action' => 'grading']);
} elseif ($cm->modname === 'quiz') {
    $gradeurl = new moodle_url('/mod/quiz/report.php', ['id' => $cmid, 'mode' => 'grading']);
} else {
    $gradeurl = new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cmid]);
}

// ── Load scores ───────────────────────────────────────────────────────────────
// Load ALL records (aggregate + per-question), group by user, keep most recent per (user, qslot).
// FIX-EG-REPORT-PERQ (v1.2.69): Per-question rows are kept so teachers see per-Q breakdown.
$scores = $DB->get_records_sql(
    "SELECT sc.*,
            u.firstname, u.lastname, u.email, u.username, u.picture, u.imagealt,
            u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
       FROM {plagiarism_essayguard_sc} sc
       JOIN {user} u ON u.id = sc.userid
      WHERE sc.cmid = :cmid
   ORDER BY sc.userid ASC, sc.qslot ASC, sc.timemodified DESC",
    ['cmid' => $cmid]
);

$by_user = [];
foreach ($scores as $sc) {
    $uid  = (int)$sc->userid;
    $slot = (int)($sc->qslot ?? 0);
    if (!isset($by_user[$uid][$slot])) {
        $by_user[$uid][$slot] = $sc;
    }
}

$display = [];
foreach ($by_user as $uid => $records) {
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
    $pq_scores = [];
    if (!empty($sc->_perq)) {
        foreach ($sc->_perq as $slot => $r) {
            if ((int)$slot > 0 && isset($r->riskscore)) {
                // Apply the same aggregate-fallback rule used in lib.php and in the
                // per-question breakdown below, so the overall average is consistent
                // with what is shown in the individual question rows.
                $pq_score = (float)$r->riskscore;
                if ($pq_score <= 0.0 && isset($sc->_perq[0])) {
                    $agg_r   = $sc->_perq[0];
                    $agg_met = !empty($agg_r->metricsjson) ? (json_decode($agg_r->metricsjson, true) ?: []) : [];
                    $agg_s1  = (int)(($agg_met['signal_breakdown'][1] ?? 0));
                    if ($agg_s1 >= 30 || (float)$agg_r->riskscore >= 0.70) {
                        $pq_score = (float)$agg_r->riskscore;
                    }
                }
                $pq_scores[] = $pq_score;
            }
        }
    }
    if (!empty($pq_scores)) {
        $sc->_overall = array_sum($pq_scores) / count($pq_scores);
    } else {
        $sc->_overall = isset($sc->riskscore) ? (float)$sc->riskscore : 0.0;
    }
}

// Sort: highest risk first, then score desc, then alphabetical.
// FIX-EG-BADGE-LEVEL (v1.2.71): Re-derive level from score, not stale DB column.
// FIX-EG-REPORT-AVG (v1.2.208): Sort on computed average, not raw aggregate riskscore.
usort($display, function ($a, $b) {
    $score_a = (int)round($a->_overall * 100);
    $score_b = (int)round($b->_overall * 100);
    $lv_a    = \plagiarism_essayguard\local\service\analyser::risk_level($score_a);
    $lv_b    = \plagiarism_essayguard\local\service\analyser::risk_level($score_b);
    $order   = ['high' => 0, 'medium' => 1, 'low' => 2];
    $ao = $order[$lv_a] ?? 2;
    $bo = $order[$lv_b] ?? 2;
    if ($ao !== $bo) { return $ao - $bo; }
    $sc_cmp = $b->_overall <=> $a->_overall;
    if ($sc_cmp !== 0) { return $sc_cmp; }
    return strcmp($a->lastname . $a->firstname, $b->lastname . $b->firstname);
});

// ── Risk config ───────────────────────────────────────────────────────────────
// FIX-EG-BADGE-MEDIUM (v1.2.113): 'medium' key matches analyser::risk_level() output.
$risk_cfg = [
    'low'    => ['dot' => '#22c55e', 'bg' => '#f0fdf4', 'text' => '#166534', 'border' => '#86efac', 'label' => 'Low'],
    'medium' => ['dot' => '#f97316', 'bg' => '#fff7ed', 'text' => '#7c2d12', 'border' => '#fdba74', 'label' => 'Medium'],
    'high'   => ['dot' => '#ef4444', 'bg' => '#fef2f2', 'text' => '#991b1b', 'border' => '#fca5a5', 'label' => 'High'],
];

// Summary counts — use computed average, not raw aggregate riskscore.
// FIX-EG-REPORT-AVG (v1.2.208): _overall is the mean of per-question scores.
$counts = ['low' => 0, 'medium' => 0, 'high' => 0];
foreach ($display as $sc) {
    $sc_pct = (int)round($sc->_overall * 100);
    $lv     = \plagiarism_essayguard\local\service\analyser::risk_level($sc_pct);
    $counts[$lv]++;
}

// ── Signal labels (used in Cross-Student section) ─────────────────────────────
$sig_labels = [
    1  => 'Large paste detected',
    2  => 'Low keystroke ratio',
    3  => 'Superhuman typing speed',
    4  => 'No natural pauses',
    5  => 'High backspace ratio',
    12 => 'Robotic keystroke entropy',
    13 => 'Server-side speed anomaly',
];

// ── Render ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();

// ── Plugin status banner ──────────────────────────────────────────────────────
$eg_global_enabled = get_config('plagiarism_essayguard', 'enabled');
$eg_plugin_enabled = ($eg_global_enabled === false) || !empty($eg_global_enabled);
$eg_cm_enabled     = plagiarism_essayguard_is_cm_active($cmid);
$eg_unlock_ok      = plagiarism_essayguard_check_unlock();

if (!$eg_plugin_enabled || !$eg_cm_enabled || !$eg_unlock_ok) {
    $warn_parts = [];
    if (!$eg_plugin_enabled) {
        $settings_url = new moodle_url('/admin/settings.php', ['section' => 'plagiarismsettingessayguard']);
        $warn_parts[] = 'Essay Guard is globally <strong>disabled</strong>. '
            . html_writer::link($settings_url, 'Enable it in Site Admin → Plugins → Plagiarism → Essay Guard', []);
    }
    if ($eg_plugin_enabled && !$eg_cm_enabled) {
        $warn_parts[] = 'Essay Guard is <strong>disabled for this activity</strong>. '
            . 'Edit the quiz settings and enable "Essay Guard" under the plagiarism section.';
    }
    if ($eg_plugin_enabled && !$eg_unlock_ok) {
        $settings_url = new moodle_url('/admin/settings.php', ['section' => 'plagiarismsettingessayguard']);
        $warn_parts[] = 'This site is <strong>not unlocked</strong> for Essay Guard. '
            . 'Check your Site ID and API Key in '
            . html_writer::link($settings_url, 'Essay Guard Settings', [])
            . ', or verify your credit balance on the EssayGraderAI portal.';
    }
    echo '<div style="background:#fff7ed;border:1px solid #fdba74;border-radius:8px;'
        . 'padding:0.85rem 1.1rem;margin-bottom:1rem;font-size:0.88rem;color:#7c2d12;">'
        . '&#x26A0; <strong>Essay Guard is not scoring submissions.</strong><br>'
        . '<ul style="margin:0.4rem 0 0 1.2rem;padding:0;">'
        . '<li>' . implode('</li><li>', $warn_parts) . '</li>'
        . '</ul></div>';
}

// ── Page header banner ────────────────────────────────────────────────────────
echo '<div class="eg-class-header">';
echo '<div class="eg-class-header-inner">';
echo '<h2 class="eg-class-title">Essay Guard &#8212; Class Behaviour Analysis Report</h2>';
echo '<p class="eg-class-meta">';
echo '<span class="eg-version-badge">1.2.179</span>';
echo '&nbsp;&nbsp;Essay Guard';
echo '</p>';
echo '</div>';
echo '<a href="' . $gradeurl->out(false) . '" class="eg-back-btn">&#8592; Back to grading</a>';
echo '</div>';

// Activity + course subtitle.
echo '<p class="eg-class-subtitle">' . s($cm->name) . ' &nbsp;|&nbsp; ' . s($course->fullname) . '</p>';

// ── Stat cards ────────────────────────────────────────────────────────────────
$stat_cards = [
    ['Total Submissions', count($display), '#1e3a5f', '#fff'],
    ['High Risk',         $counts['high'],   '#b71c1c', '#fff'],
    ['Medium Risk',       $counts['medium'], '#e65100', '#fff'],
    ['Low Risk',          $counts['low'],    '#2e7d32', '#fff'],
    ['Pending',           0,                 '#555555', '#fff'],
    ['Errors',            0,                 '#6b21a8', '#fff'],
];
echo '<div class="eg-stat-cards">';
foreach ($stat_cards as [$label, $val, $bg, $fg]) {
    echo '<div class="eg-stat-card" style="background:' . $bg . ';color:' . $fg . ';">'
       . '<div class="eg-stat-num">' . (int)$val . '</div>'
       . '<div class="eg-stat-label">' . s($label) . '</div>'
       . '</div>';
}
echo '</div>';

// ── Empty state ───────────────────────────────────────────────────────────────
if (empty($display)) {
    echo '<div class="essayguard-empty">';
    echo '<p style="margin:0 0 0.5rem;">No Essay Guard data found for this activity. '
       . 'Data is captured while students type — if no records appear here, either no students have '
       . 'submitted yet, Essay Guard was not active when they completed the activity, or an earlier '
       . 'plugin bug prevented scoring.</p>';
    if ($cm->modname === 'quiz') {
        $rescore_url = new moodle_url('/plagiarism/essayguard/rescore.php', ['cmid' => $cmid]);
        echo '<div style="margin-top:0.75rem;padding:0.75rem 1rem;background:#f5f3ff;'
           . 'border:1px solid #c4b5fd;border-radius:6px;font-size:0.88rem;color:#4c1d95;">'
           . '<strong>Students submitted before Essay Guard was working?</strong> '
           . 'Use the ' . html_writer::link($rescore_url, 'Retroactive Rescore Tool',
               ['style' => 'color:#6c3483;font-weight:600;'])
           . ' to score past quiz attempts using the answers already stored in Moodle.'
           . '</div>';
    }
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

// ── Submissions table ─────────────────────────────────────────────────────────
echo '<h4 class="eg-section-heading">Submissions</h4>';

echo '<table class="generaltable eg-class-table">';
echo '<thead><tr>'
   . '<th>Student</th>'
   . '<th>Score</th>'
   . '<th>Risk</th>'
   . '<th>Questions</th>'
   . '<th>Analysed</th>'
   . '<th>Actions</th>'
   . '</tr></thead>';
echo '<tbody>';

foreach ($display as $sc) {
    // FIX-EG-BADGE-LEVEL (v1.2.71): Derive level from score, not stale DB column.
    // FIX-EG-REPORT-AVG (v1.2.208): Use computed mean of per-question scores.
    $score = (int)round($sc->_overall * 100);
    $level = \plagiarism_essayguard\local\service\analyser::risk_level($score);
    $c     = $risk_cfg[$level] ?? $risk_cfg['low'];

    // Count distinct per-question slots (slot > 0).
    $perq_count = 0;
    if (!empty($sc->_perq)) {
        foreach ($sc->_perq as $slot => $r) {
            if ((int)$slot > 0) { $perq_count++; }
        }
    }
    $qcount_display = $perq_count > 0 ? $perq_count : 1;

    // URLs.
    $profileurl = new moodle_url('/user/view.php', ['id' => $sc->userid, 'course' => $course->id]);
    $detailurl  = new moodle_url('/plagiarism/essayguard/student.php', ['cmid' => $cmid, 'userid' => $sc->userid]);

    // Full name.
    $fn = fullname((object)[
        'firstname'         => $sc->firstname,
        'lastname'          => $sc->lastname,
        'firstnamephonetic' => $sc->firstnamephonetic ?? '',
        'lastnamephonetic'  => $sc->lastnamephonetic  ?? '',
        'middlename'        => $sc->middlename        ?? '',
        'alternatename'     => $sc->alternatename     ?? '',
    ]);

    // Score badge: "65/100  HIGH" with colored background.
    $score_html = '<span class="eg-score-badge eg-score-badge-' . s($level) . '">'
                . $score . '/100 &nbsp;&nbsp;' . strtoupper($c['label'])
                . '</span>';

    // Risk badge (separate column).
    $risk_html = '<span class="essayguard-badge essayguard-badge-' . s($level) . '">'
               . strtoupper($c['label'])
               . '</span>';

    // Analysed timestamp.
    $analysed = $sc->timemodified ? userdate((int)$sc->timemodified, '%d %b %Y %H:%M') : '—';

    // Action button.
    $action_html = '<a href="' . $detailurl->out(false) . '" class="eg-action-btn">View Report</a>';

    $row_class = ($level === 'high') ? 'essayguard-row-high' : '';
    echo '<tr' . ($row_class ? ' class="' . $row_class . '"' : '') . '>';
    echo '<td>'
       . '<a href="' . $profileurl->out(false) . '" class="eg-student-name">' . s($fn) . '</a>'
       . '<br><small class="eg-student-email">' . s($sc->email ?? '') . '</small>'
       . '</td>';
    echo '<td>' . $score_html . '</td>';
    echo '<td>' . $risk_html . '</td>';
    echo '<td class="eg-center">' . $qcount_display . '</td>';
    echo '<td class="eg-ts">' . $analysed . '</td>';
    echo '<td>' . $action_html . '</td>';
    echo '</tr>';

    // ── Per-question breakdown rows ───────────────────────────────────────────
    // FIX-EG-REPORT-PERQ (v1.2.69): Indented breakdown rows for quiz per-question slots.
    if (!empty($sc->_perq)) {
        $perq = $sc->_perq;
        ksort($perq);

        // FIX-EG-REPORT-FALLBACK (v1.2.108): Mirror lib.php fallback rule for consistency.
        $agg = $perq[0] ?? null;
        foreach ($perq as $slot => $r) {
            if ((int)$slot === 0) { continue; }

            // FIX-EG-FALLBACK-PASTE-ONLY (v1.2.110) + FIX-EG-REPORT-PASTE-SIGNAL1 (v1.2.160).
            $is_aggregate_fallback = false;
            if ((float)$r->riskscore <= 0.0 && $agg) {
                $agg_metrics_arr   = !empty($agg->metricsjson) ? (json_decode($agg->metricsjson, true) ?: []) : [];
                $agg_signal1_pts   = (int)(($agg_metrics_arr['signal_breakdown'][1] ?? 0));
                $agg_has_paste_ev  = ($agg_signal1_pts >= 30) || ((float)$agg->riskscore >= 0.70);
                if ($agg_has_paste_ev) {
                    $r = $agg;
                    $is_aggregate_fallback = true;
                }
            }

            // FIX-EG-BADGE-LEVEL (v1.2.71): Derive from score, not DB column.
            $qscore  = isset($r->riskscore) ? (int)round((float)$r->riskscore * 100) : 0;
            $qlevel  = \plagiarism_essayguard\local\service\analyser::risk_level($qscore);
            $qc      = $risk_cfg[$qlevel] ?? $risk_cfg['low'];
            $qlabel  = strtoupper($qc['label']) . ($is_aggregate_fallback ? ' (OVERALL)' : '');

            $qscore_html = '<span class="eg-score-badge eg-score-badge-' . s($qlevel) . '" style="font-size:0.8rem;">'
                         . $qscore . '/100 &nbsp;&nbsp;' . $qlabel
                         . '</span>';
            $qrisk_html  = '<span class="essayguard-badge essayguard-badge-' . s($qlevel) . '">'
                         . strtoupper($qc['label'])
                         . '</span>';

            echo '<tr class="eg-perq-row">';
            echo '<td class="eg-perq-label">&#x21B3; Question ' . (int)$slot . '</td>';
            echo '<td>' . $qscore_html . '</td>';
            echo '<td>' . $qrisk_html . '</td>';
            echo '<td class="eg-center eg-muted">—</td>';
            echo '<td class="eg-ts eg-muted">—</td>';
            echo '<td></td>';
            echo '</tr>';
        }
    }
}

echo '</tbody></table>';

// ── Cross-Student Behaviour Summary ───────────────────────────────────────────
echo '<h4 class="eg-section-heading" style="margin-top:2rem;">Cross-Student Behaviour Summary</h4>';
echo '<p class="eg-similarity-note">'
   . 'Essay Guard analyses individual typing behaviour — keystroke dynamics, paste events, and timing patterns. '
   . 'Students listed below had HIGH risk flags detected in their submission, indicating significant '
   . 'copy-paste or non-human typing signals. Review each report for full signal evidence.'
   . '</p>';

$high_risk = array_filter($display, function ($sc) {
    $s = (int)round($sc->_overall * 100);
    return \plagiarism_essayguard\local\service\analyser::risk_level($s) === 'high';
});

if (empty($high_risk)) {
    echo '<p class="eg-no-similarity">No high-risk submissions detected in this activity.</p>';
} else {
    echo '<table class="generaltable eg-similarity-table">';
    echo '<thead><tr>'
       . '<th>Student</th>'
       . '<th>Score</th>'
       . '<th>Primary Signal</th>'
       . '<th>Actions</th>'
       . '</tr></thead><tbody>';

    foreach ($high_risk as $sc) {
        $s  = (int)round($sc->_overall * 100);
        $fn = fullname((object)[
            'firstname'         => $sc->firstname,
            'lastname'          => $sc->lastname,
            'firstnamephonetic' => $sc->firstnamephonetic ?? '',
            'lastnamephonetic'  => $sc->lastnamephonetic  ?? '',
            'middlename'        => $sc->middlename        ?? '',
            'alternatename'     => $sc->alternatename     ?? '',
        ]);

        // Identify the top-scoring signal from metricsjson.
        $metrics = !empty($sc->metricsjson) ? (json_decode($sc->metricsjson, true) ?: []) : [];
        $sig_bd  = $metrics['signal_breakdown'] ?? [];
        $top_sig = '—';
        if (!empty($sig_bd)) {
            arsort($sig_bd);
            $top_k   = array_key_first($sig_bd);
            $top_sig = $sig_labels[(int)$top_k] ?? 'Signal ' . $top_k;
        }

        $durl = new moodle_url('/plagiarism/essayguard/student.php', ['cmid' => $cmid, 'userid' => $sc->userid]);
        echo '<tr>';
        echo '<td class="eg-student-name-plain">' . s($fn) . '</td>';
        echo '<td><span class="eg-score-badge eg-score-badge-high">' . $s . '/100 &nbsp;&nbsp;HIGH</span></td>';
        echo '<td class="eg-signal-label">' . s($top_sig) . '</td>';
        echo '<td><a href="' . $durl->out(false) . '" class="eg-action-btn">View Report</a></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

// ── Debug / diagnostic links ──────────────────────────────────────────────────
$debugurl      = new moodle_url('/plagiarism/essayguard/debug.php',      ['cmid' => $cmid]);
$diagurl       = new moodle_url('/plagiarism/essayguard/diag.php',       ['cmid' => $cmid]);
$badge_diagurl = new moodle_url('/plagiarism/essayguard/badge_diag.php', ['cmid' => $cmid]);

echo '<div class="eg-debug-bar">';
echo html_writer::tag('a', 'Debug Panel',
    ['href' => $debugurl->out(false), 'class' => 'eg-debug-link',
     'title' => 'Open the Essay Guard debug panel — shows extraction preview, DB records, and quick diagnosis']);
echo ' &nbsp;|&nbsp; ';
echo html_writer::tag('a', 'Full Diagnostic',
    ['href' => $diagurl->out(false), 'class' => 'eg-debug-link',
     'title' => 'Open the main diagnostic page — plugin version, qslot detection, signal breakdown, badge consistency']);
echo ' &nbsp;|&nbsp; ';
echo html_writer::tag('a', 'Badge Accuracy Diagnostic',
    ['href' => $badge_diagurl->out(false), 'class' => 'eg-debug-link',
     'title' => 'Explains why each badge was assigned — raw event trace, signal thresholds, teacher report view, session timeline']);
echo '</div>';

echo $OUTPUT->footer();
