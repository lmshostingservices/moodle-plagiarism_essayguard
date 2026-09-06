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
 * EssayGuard — Per-Attempt Diagnostic Tool v1.2.127
 *
 * Shows the complete scoring pipeline for a single attempt:
 *   - Every raw event stored in plagiarism_essayguard_ev, with its resolved qslot
 *   - Event counts and key metrics per question slot
 *   - Which signals fired and why, drawn from stored metricsjson signal_breakdown
 *   - Qslot routing audit explaining which events went to which slot
 *   - Comparison of computed vs stored score for each slot
 *
 * Access:
 *   /plagiarism/essayguard/attempt_diag.php?cmid=X
 *   /plagiarism/essayguard/attempt_diag.php?cmid=X&userid=Y
 *   /plagiarism/essayguard/attempt_diag.php?cmid=X&userid=Y&attemptkey=Z
 *
 * Requires: moodle/site:config (site admin) or plagiarism/essayguard:viewreport.
 * Safe to ship — read-only, no external requests.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_login();

$cmid       = required_param('cmid', PARAM_INT);
$userid     = optional_param('userid', 0, PARAM_INT);
$attemptkey = optional_param('attemptkey', '', PARAM_ALPHANUMEXT);

$cm      = get_coursemodule_from_id(false, $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cmid);

require_login($course, false, $cm);

// Allow site admins OR teachers with viewreport.
$is_admin = has_capability('moodle/site:config', context_system::instance());
if (!$is_admin) {
    require_capability('plagiarism/essayguard:viewreport', $context);
}

// ── CSS ──────────────────────────────────────────────────────────────────────

$css = '
<style>
*{box-sizing:border-box;}
body{font-family:system-ui,-apple-system,sans-serif;margin:0;padding:1.5rem 2rem;background:#f5f6f8;color:#1f2937;font-size:14px;}
h1{font-size:1.25rem;font-weight:700;margin:0 0 0.25rem;}
h2{font-size:1rem;font-weight:700;margin:0 0 0.75rem;}
.meta{color:#6b7280;font-size:0.85rem;margin-bottom:1.5rem;}
.meta a{color:#2563eb;text-decoration:none;}
.section{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.25rem 1.5rem;margin-bottom:1.25rem;}
.section-title{font-size:0.95rem;font-weight:700;margin:0 0 1rem;display:flex;align-items:center;gap:0.5rem;}
.num{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#e5e7eb;color:#374151;font-size:0.75rem;font-weight:700;flex-shrink:0;}
table{width:100%;border-collapse:collapse;font-size:0.82rem;}
th{text-align:left;padding:6px 10px;background:#f9fafb;border-bottom:2px solid #e5e7eb;font-weight:600;white-space:nowrap;}
td{padding:5px 10px;border-bottom:1px solid #f3f4f6;vertical-align:top;}
tr:last-child td{border-bottom:none;}
tr.ev-paste td{background:#fef2f2;}
tr.ev-large td{background:#fff7ed;}
tr.ev-key td{background:#f0f9ff;}
tr.ev-pause td{background:#fefce8;}
tr.ev-focus td{background:#f9fafb;}
tr.slot-header td{background:#e8eaf6;font-weight:700;font-size:0.88rem;padding:8px 10px;border-top:2px solid #c5cae9;}
.badge{display:inline-block;padding:1px 8px;border-radius:4px;font-size:0.78rem;font-weight:700;white-space:nowrap;}
.badge-high{background:#fef2f2;color:#991b1b;}
.badge-medium{background:#fff7ed;color:#7c2d12;}
.badge-low{background:#f0fdf4;color:#166534;}
.badge-info{background:#e0f2fe;color:#075985;}
.badge-warn{background:#fef9c3;color:#854d0e;}
.badge-none{background:#f3f4f6;color:#6b7280;}
.pill{display:inline-block;padding:1px 6px;border-radius:3px;font-size:0.75rem;font-weight:600;margin:1px;}
.pill-paste{background:#fee2e2;color:#dc2626;}
.pill-large{background:#ffedd5;color:#c2410c;}
.pill-key{background:#dbeafe;color:#1d4ed8;}
.pill-focus{background:#f3f4f6;color:#6b7280;}
.pill-pause{background:#fef9c3;color:#854d0e;}
.pill-wpm{background:#ede9fe;color:#6d28d9;}
.pill-other{background:#f3f4f6;color:#374151;}
.sig-fired{color:#15803d;font-weight:700;}
.sig-silent{color:#9ca3af;}
.sig-na{color:#d1d5db;font-style:italic;}
.metric-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0.5rem;margin-bottom:0.75rem;}
.metric-card{background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:0.5rem 0.75rem;}
.metric-label{font-size:0.75rem;color:#6b7280;margin-bottom:2px;}
.metric-val{font-size:1rem;font-weight:700;color:#111827;}
.picker{margin-bottom:1rem;display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;}
.picker select{padding:4px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.85rem;}
.picker label{font-size:0.85rem;font-weight:600;color:#374151;}
.back{color:#2563eb;font-size:0.85rem;text-decoration:none;display:inline-flex;align-items:center;gap:4px;margin-bottom:0.5rem;}
.timeline{font-family:monospace;font-size:0.8rem;letter-spacing:2px;background:#111;color:#4ade80;padding:0.75rem 1rem;border-radius:6px;word-break:break-all;line-height:1.6;}
.tl-paste{color:#f87171;}
.tl-key{color:#4ade80;}
.tl-pause{color:#facc15;}
.tl-idle{color:#374151;}
details summary{cursor:pointer;font-weight:600;padding:0.4rem 0;}
.note{font-size:0.82rem;color:#6b7280;margin:0.25rem 0 0.75rem;line-height:1.5;}
.warn-box{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:0.75rem 1rem;font-size:0.85rem;color:#92400e;margin-bottom:0.75rem;}
.ok-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:0.75rem 1rem;font-size:0.85rem;color:#166534;margin-bottom:0.75rem;}
</style>';

// ── Helper functions ──────────────────────────────────────────────────────────

function ead_risk_label(int $pct): string {
    if ($pct >= 66) return 'HIGH';
    if ($pct >= 30) return 'MEDIUM';
    return 'LOW';
}

function ead_risk_badge(int $pct): string {
    $label = ead_risk_label($pct);
    $cls   = strtolower($label);
    return '<span class="badge badge-' . $cls . '">' . $label . ' ' . $pct . '%</span>';
}

function ead_ev_pill(string $name): string {
    $cls = match(true) {
        in_array($name, ['paste', 'drop_paste'])  => 'pill-paste',
        $name === 'large_insert'                  => 'pill-large',
        in_array($name, ['keydown', 'backspace', 'delete', 'input']) => 'pill-key',
        in_array($name, ['focus', 'blur'])        => 'pill-focus',
        in_array($name, ['pause', 'burst_end'])   => 'pill-pause',
        $name === 'wpm_snapshot'                  => 'pill-wpm',
        default                                   => 'pill-other',
    };
    return '<span class="pill ' . $cls . '">' . htmlspecialchars($name) . '</span>';
}

function ead_payload_summary(string $json, string $evname): string {
    $p = json_decode($json ?: '{}', true) ?: [];
    $parts = [];
    if (isset($p['qslot']))     $parts[] = 'qslot=' . (int)$p['qslot'];
    if (isset($p['insertlen'])) $parts[] = 'insertlen=' . (int)$p['insertlen'];
    if (isset($p['delta']))     $parts[] = 'delta='     . (int)$p['delta'];
    if (isset($p['ikd']))       $parts[] = 'ikd='       . (int)$p['ikd'] . 'ms';
    if (isset($p['wpm']))       $parts[] = 'wpm='       . (int)$p['wpm'];
    if (isset($p['addedchars']))$parts[] = 'addedchars='. (int)$p['addedchars'];
    if (isset($p['duration']))  $parts[] = 'dur='       . (int)$p['duration'] . 'ms';
    if (isset($p['wordcount'])) $parts[] = 'words='     . (int)$p['wordcount'];
    if (isset($p['cursormoves']))$parts[] = 'cursormoves='.(int)$p['cursormoves'];
    return $parts ? implode(', ', $parts) : '—';
}

// Signal names matching analyser.php
$SIGNAL_NAMES = [
    1  => 'S1: Paste / drop events (primary paste trigger, max 60 pts)',
    2  => 'S2: Large insert burst — TinyMCE paste proxy (max 20 pts)',
    3  => 'S3: Superhuman typing speed >8 cps (max 30 pts)',
    4  => 'S4: No long pauses — robotic cadence (max 20 pts)',
    5  => 'S5: Low backspace ratio <2% (max 15 pts)',
    6  => 'S6: Near-zero session typing time <10s (max 25 pts)',
    7  => 'S7: Suspicious rhythm / low IKI entropy (max 10 pts)',
    8  => 'S8: Sentence length uniformity — linguistic (max 10 pts)',
    9  => 'S9: Low vocabulary diversity — linguistic (max 5 pts)',
    10 => 'S10: IKI autocorrelation deviation (max 10 pts)',
    11 => 'S11: Speed burst coefficient of variation (max 10 pts)',
    12 => 'S12: Keystroke-to-character ratio < 0.5 (max 10 pts)',
    13 => 'S13: Server-side chars-per-second fallback (max 50 pts)',
];

// ── Resolve user and attemptkey ───────────────────────────────────────────────

// Build list of users who have EV records for this cm.
$users_with_ev = $DB->get_records_sql(
    "SELECT DISTINCT e.userid, u.firstname, u.lastname
       FROM {plagiarism_essayguard_ev} e
       JOIN {user} u ON u.id = e.userid
      WHERE e.cmid = :cmid
   ORDER BY u.lastname, u.firstname",
    ['cmid' => $cmid]
);

// Also include users who have only SC records (finalized, ev may be pruned).
$users_with_sc = $DB->get_records_sql(
    "SELECT DISTINCT s.userid, u.firstname, u.lastname
       FROM {plagiarism_essayguard_sc} s
       JOIN {user} u ON u.id = s.userid
      WHERE s.cmid = :cmid
   ORDER BY u.lastname, u.firstname",
    ['cmid' => $cmid]
);

// Merge, keyed by userid.
$all_users = [];
foreach ($users_with_ev as $u) {
    $all_users[$u->userid] = ['id' => $u->userid, 'name' => $u->firstname . ' ' . $u->lastname, 'has_ev' => true, 'has_sc' => false];
}
foreach ($users_with_sc as $u) {
    if (isset($all_users[$u->userid])) {
        $all_users[$u->userid]['has_sc'] = true;
    } else {
        $all_users[$u->userid] = ['id' => $u->userid, 'name' => $u->firstname . ' ' . $u->lastname, 'has_ev' => false, 'has_sc' => true];
    }
}

if (!$userid && !empty($all_users)) {
    // Default to the first user who has EV records.
    foreach ($all_users as $u) {
        if ($u['has_ev']) {
            $userid = $u['id'];
            break;
        }
    }
    if (!$userid) {
        $userid = array_key_first($all_users);
    }
}

// Get all attemptkeys for this user+cm from ev table.
$ev_attemptkeys = [];
if ($userid) {
    $ak_rows = $DB->get_records_sql(
        "SELECT attemptkey, MAX(timecreated) AS latest
           FROM {plagiarism_essayguard_ev}
          WHERE userid = :userid AND cmid = :cmid
       GROUP BY attemptkey
       ORDER BY MAX(timecreated) DESC",
        ['userid' => $userid, 'cmid' => $cmid]
    );
    foreach ($ak_rows as $r) {
        $ev_attemptkeys[] = ['key' => $r->attemptkey, 'latest' => (int)$r->latest];
    }
}

// Also get attemptkeys from SC table (may exist even after EV pruned).
$sc_attemptkeys = [];
if ($userid) {
    $sc_rows = $DB->get_records_sql(
        "SELECT DISTINCT attemptkey, MAX(timemodified) AS latest
           FROM {plagiarism_essayguard_sc}
          WHERE userid = :userid AND cmid = :cmid
       GROUP BY attemptkey
       ORDER BY MAX(timemodified) DESC",
        ['userid' => $userid, 'cmid' => $cmid]
    );
    foreach ($sc_rows as $r) {
        $sc_attemptkeys[$r->attemptkey] = (int)$r->latest;
    }
}

// Default to most recent attemptkey (prefer EV, fall back to SC).
if (!$attemptkey) {
    if (!empty($ev_attemptkeys)) {
        $attemptkey = $ev_attemptkeys[0]['key'];
    } elseif (!empty($sc_attemptkeys)) {
        $attemptkey = array_key_first($sc_attemptkeys);
    }
}

// ── Load raw events for this user+cm+attemptkey ───────────────────────────────

$raw_events = [];
if ($userid && $attemptkey) {
    $raw_events = $DB->get_records_sql(
        "SELECT * FROM {plagiarism_essayguard_ev}
          WHERE userid = :userid AND cmid = :cmid AND attemptkey = :ak
       ORDER BY id ASC",
        ['userid' => $userid, 'cmid' => $cmid, 'ak' => $attemptkey]
    );
}

// ── Load SC records for this user+cm+attemptkey ───────────────────────────────

$sc_records = [];
if ($userid && $attemptkey) {
    $sc_rows = $DB->get_records_sql(
        "SELECT * FROM {plagiarism_essayguard_sc}
          WHERE userid = :userid AND cmid = :cmid AND attemptkey = :ak
       ORDER BY qslot ASC, timemodified DESC",
        ['userid' => $userid, 'cmid' => $cmid, 'ak' => $attemptkey]
    );
    foreach ($sc_rows as $r) {
        $slot = (int)$r->qslot;
        if (!isset($sc_records[$slot])) {
            $sc_records[$slot] = $r;
        }
    }
    ksort($sc_records);
}

// ── Compute per-event metadata ────────────────────────────────────────────────

// Determine session start for time-offset display.
$session_start = PHP_INT_MAX;
foreach ($raw_events as $ev) {
    if ((int)$ev->timecreated < $session_start) {
        $session_start = (int)$ev->timecreated;
    }
}
if ($session_start === PHP_INT_MAX) $session_start = 0;

// Build per-qslot event groups (mirroring analyser.php logic).
// qslot=0 = aggregate (all events). qslot>0 = only events explicitly tagged.
$events_by_slot = [0 => []];
foreach ($raw_events as $ev) {
    $p    = json_decode($ev->payloadjson ?? '{}', true) ?: [];
    $slot = isset($p['qslot']) ? (int)$p['qslot'] : 0;
    $events_by_slot[0][] = $ev;     // aggregate always sees everything
    if ($slot > 0) {
        if (!isset($events_by_slot[$slot])) $events_by_slot[$slot] = [];
        $events_by_slot[$slot][] = $ev;
    }
}
ksort($events_by_slot);

// Build per-slot metric summaries from raw events.
function ead_compute_slot_metrics(array $events): array {
    $m = [
        'keydown' => 0, 'backspace' => 0, 'delete' => 0,
        'paste' => 0, 'large_insert' => 0, 'input' => 0,
        'focus' => 0, 'blur' => 0, 'pause' => 0, 'burst_end' => 0,
        'wpm_snapshot' => 0, 'drop_paste' => 0, 'selection' => 0,
        'other' => 0, 'total' => 0,
        'paste_chars_total' => 0, 'large_insert_max_delta' => 0,
        'charsadded' => 0, 'total_keystrokes' => 0,
        'has_qslot_tag' => false,
    ];
    foreach ($events as $ev) {
        $p    = json_decode($ev->payloadjson ?? '{}', true) ?: [];
        $name = $ev->eventname;
        $m['total']++;
        if (isset($p['qslot']) && (int)$p['qslot'] > 0) {
            $m['has_qslot_tag'] = true;
        }
        switch ($name) {
            case 'keydown':
                $m['keydown']++;
                $m['total_keystrokes']++;
                break;
            case 'backspace':
                $m['backspace']++;
                $m['total_keystrokes']++;
                break;
            case 'delete':
                $m['delete']++;
                $m['total_keystrokes']++;
                break;
            case 'paste':
                $m['paste']++;
                $m['paste_chars_total'] += (int)($p['insertlen'] ?? 0);
                break;
            case 'drop_paste':
                $m['drop_paste']++;
                break;
            case 'large_insert':
                $m['large_insert']++;
                $d = (int)($p['delta'] ?? 0);
                if ($d > $m['large_insert_max_delta']) $m['large_insert_max_delta'] = $d;
                break;
            case 'input':
                $m['input']++;
                $m['charsadded'] += (int)($p['addedchars'] ?? 0);
                break;
            case 'focus':
                $m['focus']++;
                break;
            case 'blur':
                $m['blur']++;
                break;
            case 'pause':
                $m['pause']++;
                break;
            case 'burst_end':
                $m['burst_end']++;
                break;
            case 'wpm_snapshot':
                $m['wpm_snapshot']++;
                break;
            case 'selection':
                $m['selection']++;
                break;
            default:
                $m['other']++;
                break;
        }
    }
    return $m;
}

// ── Render page ───────────────────────────────────────────────────────────────

$diag_url   = new moodle_url('/plagiarism/essayguard/diag.php',   ['cmid' => $cmid]);
$report_url = new moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]);
$self_url   = new moodle_url('/plagiarism/essayguard/attempt_diag.php', ['cmid' => $cmid]);

$user_obj = $userid ? $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename', IGNORE_MISSING) : null;
$user_name = $user_obj ? fullname($user_obj) : 'Unknown';

$page_title = 'EssayGuard — Attempt Diagnostics';
echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
   . '<title>' . htmlspecialchars($page_title) . '</title>'
   . $css . '</head><body>';

// Back links
echo '<a class="back" href="' . $diag_url->out() . '">&larr; Back to Diagnostic</a> &nbsp;'
   . '<a class="back" href="' . $report_url->out() . '">Report</a>';

echo '<h1>' . htmlspecialchars($page_title) . '</h1>';
echo '<p class="meta">';
echo '<strong>' . htmlspecialchars($cm->name) . '</strong> (cmid=' . $cmid . ') &mdash; ';
echo htmlspecialchars($course->fullname);
echo '</p>';

// ── Pickers ───────────────────────────────────────────────────────────────────

echo '<div class="section">';
echo '<div class="section-title"><span class="num">&#9660;</span> Select student &amp; attempt</div>';
echo '<form method="get" action="' . $self_url->out(false) . '">';
echo '<input type="hidden" name="cmid" value="' . (int)$cmid . '">';
echo '<div class="picker">';

// Student picker
echo '<label>Student:</label>';
echo '<select name="userid" onchange="this.form.submit()">';
foreach ($all_users as $u) {
    $sel = $u['id'] == $userid ? ' selected' : '';
    $tag = $u['has_ev'] ? '' : ' [SC only]';
    echo '<option value="' . (int)$u['id'] . '"' . $sel . '>' . htmlspecialchars($u['name'] . $tag) . '</option>';
}
echo '</select>';

// Attempt key picker
if (!empty($ev_attemptkeys) || !empty($sc_attemptkeys)) {
    echo '<label>Attempt:</label>';
    echo '<select name="attemptkey" onchange="this.form.submit()">';
    $shown = [];
    $i = 1;
    foreach ($ev_attemptkeys as $ak) {
        $k   = $ak['key'];
        $shown[$k] = true;
        $sel = ($k === $attemptkey) ? ' selected' : '';
        $dt  = $ak['latest'] ? date('d M Y H:i', $ak['latest']) : '';
        $has_sc = isset($sc_attemptkeys[$k]) ? ' +score' : ' [no score]';
        echo '<option value="' . htmlspecialchars($k) . '"' . $sel . '>'
           . 'Attempt #' . $i . ' — ' . $dt . $has_sc . '</option>';
        $i++;
    }
    foreach ($sc_attemptkeys as $k => $ts) {
        if (isset($shown[$k])) continue;
        $sel = ($k === $attemptkey) ? ' selected' : '';
        $dt  = $ts ? date('d M Y H:i', $ts) : '';
        echo '<option value="' . htmlspecialchars($k) . '"' . $sel . '>'
           . 'Attempt #' . $i . ' — ' . $dt . ' [SC only, EV pruned]</option>';
        $i++;
    }
    echo '</select>';
}

echo '<button type="submit" style="padding:4px 12px;font-size:0.85rem;border:1px solid #d1d5db;border-radius:4px;background:#fff;cursor:pointer;">Load</button>';
echo '</div></form>';
echo '</div>';

if (!$userid) {
    echo '<div class="warn-box">No student data found for this activity. Have students attempt the quiz first.</div>';
    echo '</body></html>';
    exit;
}

// ── SECTION 1: Attempt summary ────────────────────────────────────────────────

echo '<div class="section">';
echo '<div class="section-title"><span class="num">1</span> Attempt summary</div>';

if (!$attemptkey) {
    echo '<p class="note">No attempt data found for this student and activity.</p>';
} else {
    $ak_short = substr($attemptkey, 0, 16) . (strlen($attemptkey) > 16 ? '…' : '');
    echo '<div class="metric-grid">';
    echo '<div class="metric-card"><div class="metric-label">Student</div><div class="metric-val" style="font-size:0.9rem;">' . htmlspecialchars($user_name) . ' (id=' . $userid . ')</div></div>';
    echo '<div class="metric-card"><div class="metric-label">Attempt key</div><div class="metric-val" style="font-size:0.78rem;font-family:monospace;" title="' . htmlspecialchars($attemptkey) . '">' . htmlspecialchars($ak_short) . '</div></div>';
    echo '<div class="metric-card"><div class="metric-label">Total events in EV table</div><div class="metric-val">' . count($raw_events) . '</div></div>';
    echo '<div class="metric-card"><div class="metric-label">Score records (SC table)</div><div class="metric-val">' . count($sc_records) . '</div></div>';

    if (!empty($raw_events)) {
        $first_ts = $session_start;
        $last_ts  = 0;
        foreach ($raw_events as $ev) {
            if ((int)$ev->timecreated > $last_ts) $last_ts = (int)$ev->timecreated;
        }
        echo '<div class="metric-card"><div class="metric-label">Session start</div><div class="metric-val" style="font-size:0.85rem;">' . date('d M Y H:i:s', $first_ts) . '</div></div>';
        echo '<div class="metric-card"><div class="metric-label">Session end</div><div class="metric-val" style="font-size:0.85rem;">' . date('d M Y H:i:s', $last_ts) . '</div></div>';
        $dur = $last_ts - $first_ts;
        echo '<div class="metric-card"><div class="metric-label">Duration (event window)</div><div class="metric-val">' . gmdate('i:s', $dur) . ' (' . $dur . 's)</div></div>';
    }

    // Unique qslots present in events.
    $qslots_in_ev = [];
    foreach ($raw_events as $ev) {
        $p = json_decode($ev->payloadjson ?? '{}', true) ?: [];
        $s = isset($p['qslot']) ? (int)$p['qslot'] : 0;
        $qslots_in_ev[$s] = ($qslots_in_ev[$s] ?? 0) + 1;
    }
    ksort($qslots_in_ev);
    $qslot_summary = [];
    foreach ($qslots_in_ev as $s => $cnt) {
        $qslot_summary[] = ($s === 0 ? 'untagged' : 'Q' . $s) . '×' . $cnt;
    }
    echo '<div class="metric-card"><div class="metric-label">Qslots in events</div><div class="metric-val" style="font-size:0.85rem;">' . (empty($qslot_summary) ? 'none' : implode(', ', $qslot_summary)) . '</div></div>';

    echo '</div>';

    // Stored scores summary.
    if (!empty($sc_records)) {
        echo '<table style="margin-top:0.5rem;">';
        echo '<thead><tr><th>Slot</th><th>Stored score</th><th>Level</th><th>Paste events</th><th>Keystrokes</th><th>Modified</th></tr></thead><tbody>';
        foreach ($sc_records as $slot => $sc) {
            $pct = (int)round((float)$sc->riskscore * 100);
            echo '<tr>';
            echo '<td>' . ($slot === 0 ? '<em style="color:#6b7280">Aggregate</em>' : 'Q' . $slot) . '</td>';
            echo '<td>' . ead_risk_badge($pct) . '</td>';
            echo '<td>' . strtoupper((string)($sc->risklevel ?? ead_risk_label($pct))) . '</td>';
            echo '<td>' . (int)($sc->paste_events ?? 0) . '</td>';
            echo '<td>' . (int)($sc->total_keystrokes ?? 0) . '</td>';
            echo '<td>' . ($sc->timemodified ? date('d M Y H:i', (int)$sc->timemodified) : '—') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<div class="warn-box" style="margin-top:0.75rem;">No score records (SC table) found for this attemptkey. The attempt may not have been finalized, or the observer failed to fire.</div>';
    }
}
echo '</div>';

// ── SECTION 2: Raw event log ──────────────────────────────────────────────────

echo '<div class="section">';
echo '<div class="section-title"><span class="num">2</span> Raw event log <span style="font-weight:400;color:#6b7280;font-size:0.85rem;">' . count($raw_events) . ' events</span></div>';
echo '<p class="note">Every event stored in <code>plagiarism_essayguard_ev</code> for this attempt, in order. '
   . 'Red rows = paste/drop_paste &mdash; these are the primary HIGH-risk triggers. '
   . 'Orange rows = large_insert (TinyMCE paste proxy). Blue rows = keystroke events. '
   . 'Yellow rows = pauses.</p>';

if (empty($raw_events)) {
    echo '<div class="warn-box">Zero events in the EV table for this attempt. The JS tracker either failed to bind, the student submitted before the 5-second flush, or events were pruned by the cleanup task. Check Section 4 (signal breakdown) for the stored score and Section 3 (qslot routing) for context.</div>';
} else {
    echo '<details open><summary>Show event table (' . count($raw_events) . ' rows)</summary>';
    echo '<div style="overflow-x:auto;margin-top:0.5rem;">';
    echo '<table>';
    echo '<thead><tr><th>#</th><th>Event type</th><th>Time offset</th><th>qslot</th><th>Key payload fields</th><th>DB id</th></tr></thead><tbody>';
    $i = 0;
    foreach ($raw_events as $ev) {
        $i++;
        $p      = json_decode($ev->payloadjson ?? '{}', true) ?: [];
        $slot   = isset($p['qslot']) ? (int)$p['qslot'] : 0;
        $offset = $session_start ? ((int)$ev->timecreated - $session_start) : 0;
        $name   = $ev->eventname;

        $row_cls = match(true) {
            in_array($name, ['paste', 'drop_paste']) => 'ev-paste',
            $name === 'large_insert'                 => 'ev-large',
            in_array($name, ['keydown', 'backspace', 'delete', 'input']) => 'ev-key',
            in_array($name, ['pause', 'burst_end'])  => 'ev-pause',
            default => 'ev-focus',
        };

        echo '<tr class="' . $row_cls . '">';
        echo '<td style="color:#9ca3af;">' . $i . '</td>';
        echo '<td>' . ead_ev_pill($name) . '</td>';
        echo '<td style="font-family:monospace;white-space:nowrap;">+' . number_format($offset) . 'ms</td>';
        echo '<td>' . ($slot > 0 ? '<strong>Q' . $slot . '</strong>' : '<span style="color:#9ca3af;">—</span>') . '</td>';
        echo '<td style="font-family:monospace;font-size:0.78rem;">' . htmlspecialchars(ead_payload_summary($ev->payloadjson ?? '{}', $name)) . '</td>';
        echo '<td style="color:#9ca3af;font-size:0.78rem;">' . (int)$ev->id . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></details>';
}
echo '</div>';

// ── SECTION 3: Qslot routing audit ───────────────────────────────────────────

echo '<div class="section">';
echo '<div class="section-title"><span class="num">3</span> Qslot routing audit</div>';
echo '<p class="note">Explains exactly how events were distributed across question slots. '
   . 'The aggregate (slot 0) always sees every event. Per-question slots only see events '
   . 'that tracker.js explicitly tagged with a matching qslot value in the payload.</p>';

if (empty($raw_events)) {
    echo '<div class="warn-box">No events to route — EV table is empty for this attempt.</div>';
} else {
    $untagged = 0;
    $tagged   = [];
    foreach ($raw_events as $ev) {
        $p = json_decode($ev->payloadjson ?? '{}', true) ?: [];
        $s = isset($p['qslot']) ? (int)$p['qslot'] : 0;
        if ($s === 0) {
            $untagged++;
        } else {
            $tagged[$s] = ($tagged[$s] ?? 0) + 1;
        }
    }

    $has_any_tag = !empty($tagged);
    if ($has_any_tag) {
        echo '<div class="ok-box">Qslot tags present. tracker.js successfully tagged events for: '
           . implode(', ', array_map(fn($s) => 'Q' . $s . ' (' . $tagged[$s] . ' events)', array_keys($tagged)))
           . '. Untagged events: ' . $untagged . ' (routed to aggregate only).</div>';
    } else {
        echo '<div class="warn-box"><strong>No qslot tags found in any event.</strong> Every event has qslot absent or =0 in its payload. '
           . 'This means tracker.js could not resolve the question slot at bind time (TinyMCE .que container mismatch, '
           . 'or pre-v1.2.74 tracker). The analyser falls back to paste-length attribution for per-question scoring.</div>';
    }

    // Per-slot event type breakdown.
    echo '<table style="margin-top:0.75rem;">';
    echo '<thead><tr><th>Slot</th><th>Events routed here</th><th>Paste</th><th>Large insert</th><th>Keystrokes</th><th>Paste chars known</th><th>Qslot tags</th></tr></thead><tbody>';

    $slots_to_show = array_unique(array_merge([0], array_keys($tagged), array_keys($sc_records)));
    sort($slots_to_show);

    foreach ($slots_to_show as $slot) {
        $evs = $events_by_slot[$slot] ?? [];
        $m   = ead_compute_slot_metrics($evs);
        echo '<tr>';
        echo '<td>' . ($slot === 0 ? '<em style="color:#6b7280">Aggregate</em>' : '<strong>Q' . $slot . '</strong>') . '</td>';
        echo '<td>' . count($evs) . '</td>';
        echo '<td>' . ($m['paste'] + $m['drop_paste']) . '</td>';
        echo '<td>' . $m['large_insert'] . '</td>';
        echo '<td>' . $m['total_keystrokes'] . '</td>';
        echo '<td>' . ($m['paste_chars_total'] ?: ($m['large_insert_max_delta'] ?: '<span style="color:#9ca3af">unknown</span>')) . '</td>';
        echo '<td>' . ($slot === 0 ? '<span style="color:#6b7280">N/A</span>' : ($m['has_qslot_tag'] ? '<span style="color:#15803d">YES</span>' : '<span style="color:#dc2626">NO — fallback used</span>')) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

// ── SECTION 4: Per-slot signal breakdown ──────────────────────────────────────

echo '<div class="section">';
echo '<div class="section-title"><span class="num">4</span> Per-slot signal breakdown</div>';
echo '<p class="note">For each question slot, shows the stored signal_breakdown from <code>metricsjson</code> '
   . '(written by analyser.php v1.2.113+). Each signal shows the points it contributed and whether the '
   . 'false-positive cap applied. Sessions scored before v1.2.113 will not have a signal_breakdown.</p>';

if (empty($sc_records)) {
    echo '<div class="warn-box">No score records found — cannot show signal breakdown. The attempt was not finalized or the observer failed.</div>';
} else {
    foreach ($sc_records as $slot => $sc) {
        $pct         = (int)round((float)$sc->riskscore * 100);
        $slot_label  = $slot === 0 ? 'Aggregate (qslot=0)' : 'Q' . $slot . ' (qslot=' . $slot . ')';
        $ev_count    = count($events_by_slot[$slot] ?? []);
        $m_slot      = ead_compute_slot_metrics($events_by_slot[$slot] ?? []);

        echo '<details open style="margin-bottom:1rem;border:1px solid #e5e7eb;border-radius:6px;">';
        echo '<summary style="padding:0.75rem 1rem;background:#f9fafb;border-radius:6px;">';
        echo '<strong>' . htmlspecialchars($slot_label) . '</strong> &mdash; ';
        echo ead_risk_badge($pct) . ' &nbsp;';
        echo '<span style="color:#6b7280;font-weight:400;font-size:0.82rem;">' . $ev_count . ' events routed here</span>';
        echo '</summary>';
        echo '<div style="padding:1rem;">';

        // Key input metrics from the SC record.
        echo '<h2 style="font-size:0.88rem;margin-bottom:0.5rem;">Stored metrics (from SC record + metricsjson)</h2>';
        echo '<div class="metric-grid">';
        echo '<div class="metric-card"><div class="metric-label">Paste events</div><div class="metric-val">' . (int)($sc->paste_events ?? 0) . '</div></div>';
        echo '<div class="metric-card"><div class="metric-label">Total keystrokes</div><div class="metric-val">' . (int)($sc->total_keystrokes ?? 0) . '</div></div>';
        echo '<div class="metric-card"><div class="metric-label">Backspaces</div><div class="metric-val">' . (int)($sc->backspace_count ?? 0) . '</div></div>';
        echo '<div class="metric-card"><div class="metric-label">Pause count</div><div class="metric-val">' . (int)($sc->pause_count ?? 0) . '</div></div>';
        echo '<div class="metric-card"><div class="metric-label">Avg WPM</div><div class="metric-val">' . round((float)($sc->average_wpm ?? 0), 1) . '</div></div>';
        echo '<div class="metric-card"><div class="metric-label">Typing time</div><div class="metric-val">' . gmdate('i:s', (int)(($sc->typing_time ?? 0) / 1000)) . '</div></div>';

        $mj = !empty($sc->metricsjson) ? json_decode($sc->metricsjson, true) : [];
        if (is_array($mj)) {
            if (isset($mj['text_chars']))       echo '<div class="metric-card"><div class="metric-label">Text chars</div><div class="metric-val">' . (int)$mj['text_chars'] . '</div></div>';
            if (isset($mj['large_inserts']))    echo '<div class="metric-card"><div class="metric-label">Large inserts</div><div class="metric-val">' . (int)$mj['large_inserts'] . '</div></div>';
            if (isset($mj['keystroke_ratio']))  echo '<div class="metric-card"><div class="metric-label">Keystroke ratio</div><div class="metric-val">' . round((float)$mj['keystroke_ratio'], 3) . ' <span style="font-size:0.7rem;color:#6b7280">(human&ge;1.0)</span></div></div>';
            if (isset($mj['paste_frac']))       echo '<div class="metric-card"><div class="metric-label">Paste fraction</div><div class="metric-val">' . round((float)$mj['paste_frac'] * 100, 1) . '%</div></div>';
            if (isset($mj['chars_per_sec']))    echo '<div class="metric-card"><div class="metric-label">Chars/sec (JS)</div><div class="metric-val">' . round((float)$mj['chars_per_sec'], 2) . ' <span style="font-size:0.7rem;color:#6b7280">(&gt;8=fast,&gt;15=HIGH)</span></div></div>';
            if (isset($mj['server_cps']))       echo '<div class="metric-card"><div class="metric-label">Chars/sec (server)</div><div class="metric-val">' . round((float)$mj['server_cps'], 2) . ' <span style="font-size:0.7rem;color:#6b7280">(&gt;4=med,&gt;10=HIGH)</span></div></div>';
            if (isset($mj['entropy_score']))    echo '<div class="metric-card"><div class="metric-label">Entropy score</div><div class="metric-val">' . round((float)$mj['entropy_score'], 3) . ' <span style="font-size:0.7rem;color:#6b7280">(&lt;0.3=suspicious)</span></div></div>';
            if (isset($mj['iki_shannon']))      echo '<div class="metric-card"><div class="metric-label">IKI Shannon</div><div class="metric-val">' . round((float)$mj['iki_shannon'], 3) . ' <span style="font-size:0.7rem;color:#6b7280">(&lt;0.35=suspicious)</span></div></div>';
            if (isset($mj['score100']))         echo '<div class="metric-card"><div class="metric-label">Stored score100</div><div class="metric-val">' . (int)$mj['score100'] . '</div></div>';
        }
        echo '</div>';

        // Signal breakdown table.
        echo '<h2 style="font-size:0.88rem;margin-top:1rem;margin-bottom:0.5rem;">Signal breakdown</h2>';

        if (!is_array($mj) || !isset($mj['score100'])) {
            echo '<div class="warn-box">No signal_breakdown in metricsjson — session was scored before v1.2.113. Submit a new attempt to populate.</div>';
        } else {
            $sb         = is_array($mj['signal_breakdown'] ?? null) ? $mj['signal_breakdown'] : [];
            $ling_pts   = (int)($mj['linguistic_fallback_pts'] ?? 0);
            $s100       = (int)$mj['score100'];
            $raw_total  = (int)array_sum($sb) + $ling_pts;
            $cap_fired  = ($raw_total > 0 && $s100 < $raw_total);
            $agg_elev   = !empty($mj['agg_elevated_from_perq']);

            echo '<table>';
            echo '<thead><tr><th>Signal</th><th>Points</th><th>Status</th><th>What this means</th></tr></thead><tbody>';

            foreach ($SIGNAL_NAMES as $sig => $desc) {
                $pts = isset($sb[$sig]) ? (int)$sb[$sig] : null;
                if ($pts !== null && $pts > 0) {
                    $status = '<span class="sig-fired">FIRED +' . $pts . ' pts</span>';
                    // Plain-English context
                    $why = match((int)$sig) {
                        1  => 'Paste event detected. paste_frac=' . round((float)($mj['paste_frac'] ?? 0) * 100, 1) . '%, keystroke_ratio=' . round((float)($mj['keystroke_ratio'] ?? 0), 3),
                        2  => 'large_insert event(s) caught by TinyMCE proxy. large_inserts=' . (int)($mj['large_inserts'] ?? 0) . ', max_delta=' . (int)($mj['large_insert_max_delta'] ?? 0),
                        3  => 'Typing speed ' . round((float)($mj['chars_per_sec'] ?? 0), 2) . ' cps (threshold: >8 cps = suspicious, >15 cps = HIGH)',
                        4  => 'Fewer than 2 major pauses (>2s) for substantial content — robotic cadence. pause_count=' . (int)($sc->pause_count ?? 0),
                        5  => 'Backspace ratio ' . round((float)($sc->backspace_count ?? 0) / max(1, (int)($sc->total_keystrokes ?? 1)), 3) . ' (threshold: <0.02 = no corrections = suspicious)',
                        6  => 'Typing time ' . (int)(($sc->typing_time ?? 0) / 1000) . 's — content appeared almost instantly',
                        7  => 'IKI entropy=' . round((float)($mj['iki_shannon'] ?? $mj['entropy_score'] ?? 0), 3) . ' (threshold: <0.35 suspicious for Shannon, <0.30 for SD-based)',
                        8  => 'Sentence length variance=' . round((float)($mj['sentence_variance'] ?? 0), 2) . ' (threshold: <6 = uniform = suspicious)',
                        9  => 'Vocab diversity=' . round((float)($mj['vocab_diversity'] ?? 0), 3) . ' (threshold: <0.30 = low diversity = suspicious)',
                        10 => 'IKI autocorrelation deviation=' . round(abs((float)($mj['iki_autocorr'] ?? 0) - 0.1), 3) . ' from 0.1 baseline (threshold: >0.5 = robotic)',
                        11 => 'Speed burst CV=' . round((float)($mj['speed_burst_cv'] ?? 0), 3) . ' (threshold: <0.30 = constant rate = robotic)',
                        12 => 'Keystroke ratio=' . round((float)($mj['keystroke_ratio'] ?? 0), 3) . ' (threshold: <0.5 = most chars arrived without typing)',
                        13 => 'Server-side cps=' . round((float)($mj['server_cps'] ?? 0), 2) . ' (fallback: fires when JS events absent and >4 cps)',
                        default => '',
                    };
                    $cell_why = $why ? '<span style="font-size:0.78rem;color:#374151;">' . htmlspecialchars($why) . '</span>' : '';
                } elseif ($pts === 0 || isset($sb[$sig])) {
                    $status   = '<span class="sig-silent">silent (0 pts)</span>';
                    $cell_why = '';
                } else {
                    $status   = '<span class="sig-na">not fired</span>';
                    $cell_why = '';
                }
                echo '<tr>';
                echo '<td style="font-size:0.8rem;">' . htmlspecialchars($desc) . '</td>';
                echo '<td style="font-weight:700;white-space:nowrap;">' . ($pts !== null ? ($pts > 0 ? '+' . $pts : '0') : '—') . '</td>';
                echo '<td>' . $status . '</td>';
                echo '<td>' . $cell_why . '</td>';
                echo '</tr>';
            }

            // Linguistic fallback row.
            if ($ling_pts > 0) {
                echo '<tr style="background:#ede9fe;">';
                echo '<td style="font-size:0.8rem;">Linguistic fallback (no behavioural events)</td>';
                echo '<td style="font-weight:700;">+' . $ling_pts . '</td>';
                echo '<td><span class="sig-fired">FIRED</span></td>';
                echo '<td><span style="font-size:0.78rem;color:#374151;">sentence_variance=' . round((float)($mj['sentence_variance'] ?? 0), 2) . ', vocab_diversity=' . round((float)($mj['vocab_diversity'] ?? 0), 3) . '</span></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            // Score summary row.
            echo '<div style="margin-top:0.75rem;padding:0.75rem;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;font-size:0.85rem;">';
            echo '<strong>Raw total:</strong> ' . $raw_total . ' pts';
            if ($cap_fired) {
                echo ' &rarr; <strong style="color:#b45309;">capped at ' . $s100 . '</strong> (false-positive cap: no paste + normal speed + natural entropy &rarr; LOW ceiling 29)';
            } else {
                echo ' &rarr; <strong>final: ' . $s100 . '%</strong>';
            }
            echo ' &rarr; ' . ead_risk_badge($s100);
            if ($agg_elev) {
                echo ' <span style="color:#7c3aed;font-size:0.78rem;">(FIX-EG-AGG-PERQ-CONSISTENCY: aggregate elevated to match max per-question score)</span>';
            }
            echo '</div>';
        }

        echo '</div></details>';
    }
}
echo '</div>';

// ── SECTION 5: Diagnosis and action ──────────────────────────────────────────

echo '<div class="section">';
echo '<div class="section-title"><span class="num">5</span> Diagnosis &amp; recommended action</div>';

$agg_sc = $sc_records[0] ?? null;
$pq_sc  = array_filter($sc_records, fn($s) => $s > 0, ARRAY_FILTER_USE_KEY);

if (empty($raw_events) && empty($sc_records)) {
    echo '<div class="warn-box"><strong>No data at all.</strong> EssayGuard has no events and no score records for this attempt. Check: (1) Is EssayGuard enabled for this activity? (2) Did the student complete the attempt? (3) Check the PHP error log for <code>[EssayGuard]</code> entries around the attempt time.</div>';
} elseif (empty($raw_events) && !empty($sc_records)) {
    echo '<div class="warn-box"><strong>Events pruned — score record exists.</strong> The raw event table (plagiarism_essayguard_ev) has been cleaned by the scheduled cleanup task. The stored score in the SC table is the final record. Check Signal 13 (server_cps) in Section 4 to see if the server-side fallback fired.</div>';
} else {
    $total_paste_ev  = 0;
    $total_ks        = 0;
    $total_li        = 0;
    foreach ($raw_events as $ev) {
        if (in_array($ev->eventname, ['paste', 'drop_paste'])) $total_paste_ev++;
        if (in_array($ev->eventname, ['keydown', 'backspace', 'delete'])) $total_ks++;
        if ($ev->eventname === 'large_insert') $total_li++;
    }
    $any_tagged = !empty(array_filter($events_by_slot, fn($s) => $s > 0, ARRAY_FILTER_USE_KEY));

    $issues = [];
    $ok     = [];

    if ($total_paste_ev === 0 && $total_li === 0) {
        $issues[] = 'Zero paste events and zero large_insert events in the EV table. If the student copy-pasted, either (a) TinyMCE intercepted the native paste event and the large_insert fallback also failed, or (b) the student typed the answer manually. Check browser console for [EssayGuard DIAG] messages during live testing.';
    } else {
        $ok[] = ($total_paste_ev > 0 ? $total_paste_ev . ' paste event(s)' : '') . ($total_li > 0 ? ($total_paste_ev > 0 ? ' + ' : '') . $total_li . ' large_insert(s)' : '') . ' captured. Paste evidence is present.';
    }

    if ($total_ks === 0) {
        $issues[] = 'Zero keystroke events. TinyMCE keydown listener likely failed to bind to the editor. The keystroke-ratio gate (Signal 1 V3) may still detect paste via total_keystrokes/text_chars < 0.25, but the IKI entropy and WPM signals (S7, S10, S11) will not fire.';
    } else {
        $ok[] = $total_ks . ' keystroke events captured.';
    }

    if (!$any_tagged) {
        $issues[] = 'No events carry a qslot tag. Per-question scoring fell back to paste-length attribution. This is the common cause of all questions showing the same badge. Requires tracker.js v1.2.74+ and a Moodle theme where the .que container id attribute is accessible.';
    } else {
        $ok[] = 'Qslot tags found — per-question event isolation is working.';
    }

    if ($agg_sc) {
        $agg_pct = (int)round((float)$agg_sc->riskscore * 100);
        if ($agg_pct === 0 && ($total_paste_ev > 0 || $total_li > 0)) {
            $issues[] = 'Aggregate score is 0% (LOW) but paste events exist in the EV table. This usually means the analyser ran before the flush completed (race condition) and FIX-EG-SCORE-NO-CLOBBER preserved a previous 0 score. Check if the rescore_pending scheduled task has run since the attempt was submitted.';
        }
        if (!empty($pq_sc)) {
            $pq_max = max(array_map(fn($r) => (int)round((float)$r->riskscore * 100), $pq_sc));
            if ($pq_max > $agg_pct + 5) {
                $issues[] = 'Per-question max score (' . $pq_max . '%) is significantly higher than aggregate (' . $agg_pct . '%). The Review Attempt page shows the aggregate badge (lower risk) while the teacher report shows per-question badges (higher risk). This mismatch may confuse teachers.';
            }
        }
    }

    foreach ($ok as $msg) {
        echo '<div class="ok-box">' . htmlspecialchars($msg) . '</div>';
    }
    foreach ($issues as $msg) {
        echo '<div class="warn-box">' . htmlspecialchars($msg) . '</div>';
    }
    if (empty($issues)) {
        echo '<div class="ok-box"><strong>No issues detected.</strong> Events were captured, qslots are tagged, and scores are consistent with the captured data.</div>';
    }
}

// Link to related tools.
echo '<div style="margin-top:1rem;font-size:0.85rem;color:#374151;">';
echo '<strong>Related tools:</strong> ';
echo '<a href="' . $diag_url->out() . '" style="color:#2563eb;">Full diagnostic (diag.php)</a> &nbsp;|&nbsp;';
$badgediag_url = new moodle_url('/plagiarism/essayguard/badge_diag.php', ['cmid' => $cmid, 'userid' => $userid]);
echo '<a href="' . $badgediag_url->out() . '" style="color:#2563eb;">Badge diagnostic (badge_diag.php)</a> &nbsp;|&nbsp;';
echo '<a href="' . $report_url->out() . '" style="color:#2563eb;">Teacher report</a>';
echo '</div>';

echo '</div>';

echo '</body></html>';
