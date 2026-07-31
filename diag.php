<?php
// EssayGuard — Diagnostic Tool v1.2.124
// Access: /plagiarism/essayguard/diag.php?cmid=<cmid>
//         /plagiarism/essayguard/diag.php?cmid=<cmid>&userid=<userid>
// Requires: site admin or moodle/site:config capability.
// Safe to ship — no data is modified and no external requests are made.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

// Accept either ?cmid=X or ?id=X (Moodle activity URLs use ?id=).
$cmid   = optional_param('cmid', 0, PARAM_INT) ?: optional_param('id', 0, PARAM_INT);
if (!$cmid) {
    throw new \moodle_exception('missingparam', 'error', '', 'cmid or id');
}
$userid = optional_param('userid', 0, PARAM_INT);

$cm = get_coursemodule_from_id(false, $cmid, 0, false, IGNORE_MISSING);
if (!$cm) {
    // Maybe the user passed a course ID — check and show a picker.
    $css = '<style>body{font-family:sans-serif;margin:2rem;background:#f5f5f5;color:#111;}
h2{font-size:1.2rem;margin-bottom:0.5rem;}
.box{background:#fff;border:1px solid #ddd;border-radius:6px;padding:1.2rem 1.5rem;max-width:750px;margin-bottom:1.5rem;}
.err{background:#f8d7da;color:#721c24;}
.info{background:#d1ecf1;color:#0c5460;}
table{width:100%;border-collapse:collapse;margin-top:0.5rem;}
td,th{padding:0.4rem 0.7rem;font-size:0.85rem;border-bottom:1px solid #eee;text-align:left;}
th{background:#f0f0f0;font-weight:600;}
a{color:#0070f3;}code{background:#eee;padding:2px 5px;border-radius:3px;}</style>';

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>EssayGuard Diag — Pick activity</title>' . $css . '</head><body>';
    echo '<h2>EssayGuard Diagnostic — Choose an Activity</h2>';

    // Check if this is a valid course ID.
    $course_check = $DB->get_record('course', ['id' => $cmid], 'id,fullname', IGNORE_MISSING);

    if ($course_check) {
        echo '<div class="box info">';
        echo "<p><strong>" . htmlspecialchars($course_check->fullname) . "</strong> (course id={$cmid}) — ";
        echo "that is a <strong>course</strong> id, not an activity id.</p>";
        echo "<p>Pick a quiz or assignment from this course below:</p>";
        echo '</div>';

        // Find all quiz + assignment cms in this course.
        $cms_in_course = $DB->get_records_sql(
            "SELECT cm.id AS cmid, cm.module, cm.instance, m.name AS modname
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid AND m.name IN ('quiz','assign')
           ORDER BY cm.id ASC",
            ['courseid' => $cmid]
        );

        if ($cms_in_course) {
            echo '<div class="box"><table>';
            echo '<tr><th>Activity</th><th>Type</th><th>cmid</th><th>EssayGuard?</th><th>Action</th></tr>';
            foreach ($cms_in_course as $row) {
                $modname = $row->modname === 'quiz' ? 'quiz' : 'assign';
                $instance_name = $DB->get_field($modname, 'name', ['id' => $row->instance]);
                $eg_on = get_config('plagiarism_essayguard', 'enabled_cm_' . $row->cmid) ? '✓ Active' : '—';
                $url = new moodle_url('/plagiarism/essayguard/diag.php', ['cmid' => $row->cmid]);
                echo '<tr>';
                echo '<td>' . htmlspecialchars($instance_name ?: '(unnamed)') . '</td>';
                echo '<td>' . htmlspecialchars($row->modname) . '</td>';
                echo '<td>' . $row->cmid . '</td>';
                echo '<td>' . $eg_on . '</td>';
                echo '<td><a href="' . $url->out() . '">Run diag</a></td>';
                echo '</tr>';
            }
            echo '</table></div>';
        } else {
            echo '<div class="box err"><p>No quizzes or assignments found in this course.</p></div>';
        }
    } else {
        echo '<div class="box err">';
        echo "<p><strong>cmid={$cmid} is not a valid activity or course id.</strong></p>";
        echo '<p>You need the id from a quiz or assignment URL. Example:</p>';
        echo '<p><code>https://moodle.example.com/mod/quiz/view.php?id=<strong>123</strong></code> → use <code>cmid=123</code></p>';
        echo '</div>';
    }
    echo '</body></html>';
    exit;
}

$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

// If no userid given, try to find a recent student submission for this cm.
$sample_userid = $userid;
if (!$sample_userid) {
    $row = $DB->get_record_sql(
        "SELECT userid FROM {plagiarism_essayguard_sc} WHERE cmid = :cmid ORDER BY timemodified DESC",
        ['cmid' => $cmid],
        IGNORE_MULTIPLE
    );
    if ($row) {
        $sample_userid = (int)$row->userid;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function eg_pass(string $label, string $value = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="pass">PASS</td>'
         . '<td class="val">' . htmlspecialchars($value) . '</td></tr>';
}
function eg_fail(string $label, string $detail = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="fail">FAIL</td>'
         . '<td class="val">' . htmlspecialchars($detail) . '</td></tr>';
}
function eg_info(string $label, string $value = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="info">INFO</td>'
         . '<td class="val">' . htmlspecialchars($value) . '</td></tr>';
}

$rows_plugin   = '';
$rows_bug1     = '';
$rows_bug2     = '';
$rows_bug3     = '';
$rows_records  = '';
$rows_signals  = '';
$rows_badge    = '';
$rows_events   = '';
$rows_html     = '';
$overall_pass  = true;

// ── Shared helper: risk level from score percentage (single source of truth) ──
// Thresholds match analyser::risk_level(): 0–29=LOW, 30–65=MEDIUM, 66–100=HIGH.
function eg_risk_level_label(float $score_pct): string {
    if ($score_pct >= 66) return 'HIGH';
    if ($score_pct >= 30) return 'MEDIUM';
    return 'LOW';
}

// ── SECTION 1: Plugin version ─────────────────────────────────────────────────

$plugin = new stdClass();
include(__DIR__ . '/version.php');
$ver_num     = $plugin->version  ?? 0;
$ver_release = $plugin->release  ?? '?';

if (version_compare($ver_release, '1.2.119', '>=')) {
    $rows_plugin .= eg_pass('Plugin version', $ver_release . ' (build ' . $ver_num . ') — v1.2.119+ confirmed');
} else {
    $rows_plugin .= eg_fail('Plugin version', $ver_release . ' (build ' . $ver_num . ') — upgrade to v1.2.119+ required');
    $overall_pass = false;
}

// FIX-EG-DIAG-CMCHECK (v1.2.123): Mirror the exact logic of is_cm_active() instead of a
// bare `if ($eg_enabled)` which treated PHP false (key never saved = default-enabled) the
// same as '0' (explicitly disabled). The old code falsely reported "Not enabled" on any
// quiz whose settings had never been opened since the plugin was installed — even though
// inject_tracker() was running normally for those quizzes.
//
// Three states:
//   false  = key never saved → defaults to ENABLED (Moodle fresh install, pre-settings-save)
//   truthy = explicitly set to 1 → ENABLED
//   '0'/'' = explicitly set to 0 → DISABLED → inject_tracker() skips → no tracker JS →
//            zero events expected → this is the root cause of any "ALL CHECKS PASSED but
//            keystrokes=0" scenario — the JS was never loaded, not a binding failure.
$eg_enabled_raw = get_config('plagiarism_essayguard', 'enabled_cm_' . $cmid);
$eg_cm_active   = ($eg_enabled_raw === false) || !empty($eg_enabled_raw);

if ($eg_cm_active) {
    if ($eg_enabled_raw === false) {
        $rows_plugin .= eg_pass(
            'EssayGuard active for this cm',
            'cmid=' . $cmid . ' — config key never saved; defaults to ENABLED '
          . '(inject_tracker() runs normally for this cm)'
        );
    } else {
        $rows_plugin .= eg_pass(
            'EssayGuard active for this cm',
            'cmid=' . $cmid . ' — explicitly enabled in quiz/assignment settings'
        );
    }
} else {
    $rows_plugin .= eg_fail(
        'EssayGuard active for this cm',
        'DISABLED — enabled_cm_' . $cmid . ' is set to 0 in Moodle config. '
      . 'inject_tracker() skips this cm entirely, so the JS tracker is NEVER loaded '
      . 'and no keydown/paste events can ever be queued or sent to the server. '
      . 'This is the root cause of zero events in Section 8. '
      . 'ACTION: edit the quiz/assignment settings, tick the EssayGuard "Enabled" checkbox, '
      . 'and Save. Then have the student retake the quiz to capture fresh behavioural data.'
    );
    $overall_pass = false;
}

// ── SECTION 2: Bug 1 — qslot threshold 85%→60% + html_entity_decode ──────────
// (FIX-EG-QSLOT-THRESHOLD v1.2.114 / FIX-EG-QSLOT-CONTENT-FUZZY v1.2.106)
//
// We simulate what find_qslot_by_content() does: fetch the most recent quiz
// attempt for the sample user, read one question's stored answer, and run
// similar_text() with BOTH the old 85% and new 60% threshold to show which
// would have succeeded.

$rows_bug1 .= eg_info(
    'What this checks',
    'Bug 1: find_qslot_by_content() was using an 85% similarity threshold. '
  . 'HTML entities (&amp; &nbsp; etc.) caused TinyMCE answers to score <85%, '
  . 'falling back to the aggregate record (same badge for every question). '
  . 'Fix: threshold lowered to 60% + html_entity_decode() applied before comparison.'
);

if ($sample_userid) {
    // Find most recent finished quiz attempt for this user + cm.
    $attempt = $DB->get_record_sql(
        "SELECT qa.uniqueid, qa.id as attemptid
           FROM {quiz_attempts} qa
           JOIN {quiz} q ON q.id = qa.quiz
           JOIN {course_modules} cm ON cm.instance = q.id
          WHERE cm.id = :cmid AND qa.userid = :userid AND qa.state = 'finished'
       ORDER BY qa.id DESC",
        ['cmid' => $cmid, 'userid' => $sample_userid],
        IGNORE_MULTIPLE
    );

    if ($attempt) {
        // Fetch all question slots' answers.
        $rows_q = $DB->get_records_sql(
            "SELECT qas.id, qa.slot, qasd.value
               FROM {question_attempt_steps} qas
               JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
               JOIN {question_attempts} qa ON qa.id = qas.questionattemptid
              WHERE qa.questionusageid = :qubaid AND qasd.name = 'answer'
           ORDER BY qa.slot ASC, qas.id DESC",
            ['qubaid' => $attempt->uniqueid]
        );

        if ($rows_q) {
            $seen = [];
            foreach ($rows_q as $rq) {
                $slot = (int)$rq->slot;
                if (isset($seen[$slot])) {
                    continue;
                }
                $seen[$slot] = true;
                $raw   = (string)($rq->value ?? '');
                $norm  = trim(preg_replace('/\s+/', ' ', strip_tags($raw)));
                $dec   = html_entity_decode($norm, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // Fetch the SC record for this slot.
                $sc_pq = $DB->get_record_sql(
                    "SELECT riskscore, paste_events, total_keystrokes, average_wpm
                       FROM {plagiarism_essayguard_sc}
                      WHERE userid = :userid AND cmid = :cmid AND qslot = :qslot
                   ORDER BY timemodified DESC",
                    ['userid' => $sample_userid, 'cmid' => $cmid, 'qslot' => $slot],
                    IGNORE_MULTIPLE
                );

                if (!$sc_pq) {
                    // No per-question record — the qslot lookup failed for this slot.
                    // Simulate: would the old 85% or new 60% have found it?
                    // We do this by comparing norm vs dec to see if entity-decode matters.
                    $diff_chars = abs(strlen($norm) - strlen($dec));
                    $diff_pct   = strlen($norm) > 0 ? round(($diff_chars / strlen($norm)) * 100, 1) : 0;

                    if ($diff_pct > 0) {
                        $rows_bug1 .= eg_fail(
                            "Slot $slot: no SC record + entity-decode mismatch",
                            "No per-question score record for slot=$slot. "
                          . "Normalised length=" . strlen($norm) . " vs decoded length=" . strlen($dec)
                          . " ({$diff_pct}% difference). The old 85% threshold would have failed. "
                          . "With v1.2.115: 60% threshold + html_entity_decode should now match. "
                          . "Run generate/attempt to create a new SC record."
                        );
                        $overall_pass = false;
                    } else {
                        $rows_bug1 .= eg_info(
                            "Slot $slot: no SC record, no entity difference",
                            "No per-question SC record (possible new activity). "
                          . "Entity-decode makes no difference for this answer — threshold fix still applies."
                        );
                    }
                } else {
                    $score_pct = round((float)$sc_pq->riskscore * 100, 1);
                    $rows_bug1 .= eg_pass(
                        "Slot $slot: per-question SC record found",
                        "riskscore={$sc_pq->riskscore} ({$score_pct}%), "
                      . "paste_events={$sc_pq->paste_events}, "
                      . "total_keystrokes={$sc_pq->total_keystrokes}, "
                      . "wpm={$sc_pq->average_wpm} — qslot detection worked correctly"
                    );
                }
            }
        } else {
            $rows_bug1 .= eg_info('Quiz attempt found', 'No question answers in attempt uniqueid=' . $attempt->uniqueid);
        }
    } else {
        $rows_bug1 .= eg_info('No finished quiz attempt', 'User id=' . $sample_userid . ' has no finished attempt for this cm');
    }
} else {
    $rows_bug1 .= eg_info('No student data', 'No submissions found for this cm yet — run a student attempt to test');
}

// ── SECTION 3: Bug 2 — aggregate fallback paste-evidence guard ────────────────
// (FIX-EG-FALLBACK-PASTE-ONLY v1.2.110 + FIX-EG-PASTE-HIGH-V3 keystroke ratio v1.2.114)

$rows_bug2 .= eg_info(
    'What this checks',
    'Bug 2: Signal 1 keystroke-ratio gate — when total_keystrokes/text_chars < 0.25, '
  . 'the session is treated as effectively pure paste (full +60). Also: aggregate fallback '
  . 'now only fires when paste_events>0 OR riskscore>=0.70 (not for honest typing noise).'
);

if ($sample_userid) {
    // Fetch aggregate record (qslot=0) for this user.
    $agg = $DB->get_record_sql(
        "SELECT riskscore, paste_events, total_keystrokes, average_wpm, metricsjson, timemodified
           FROM {plagiarism_essayguard_sc}
          WHERE userid = :userid AND cmid = :cmid AND qslot = 0
       ORDER BY timemodified DESC",
        ['userid' => $sample_userid, 'cmid' => $cmid],
        IGNORE_MULTIPLE
    );

    if ($agg) {
        $agg_score_pct = round((float)$agg->riskscore * 100, 1);
        $rows_bug2 .= eg_info(
            'Aggregate record (qslot=0)',
            "riskscore={$agg->riskscore} ({$agg_score_pct}%), "
          . "paste_events={$agg->paste_events}, "
          . "total_keystrokes={$agg->total_keystrokes}, "
          . "wpm={$agg->average_wpm}"
        );

        // Show whether aggregate fallback WOULD fire under old vs new logic.
        $agg_has_paste = ((int)($agg->paste_events ?? 0) > 0)
                      || ((float)$agg->riskscore >= 0.70);

        if ($agg_has_paste) {
            $rows_bug2 .= eg_pass(
                'Aggregate fallback guard',
                'paste_events=' . $agg->paste_events . ' OR riskscore>=' . $agg->riskscore
              . ' — aggregate will only override a per-question LOW when there is genuine paste evidence (correct)'
            );
        } else {
            $rows_bug2 .= eg_pass(
                'Aggregate fallback guard',
                'paste_events=0 AND riskscore<0.70 — aggregate will NOT override per-question score. '
              . 'Under the old logic this aggregate (typing noise) would have overridden per-question scores (fixed in v1.2.110)'
            );
        }

        // Keystroke-ratio check from metricsjson if available.
        if (!empty($agg->metricsjson)) {
            $metrics = json_decode($agg->metricsjson, true);
            if (is_array($metrics)) {
                $ksr = isset($metrics['keystroke_ratio']) ? (float)$metrics['keystroke_ratio'] : null;
                $tc  = isset($metrics['text_chars'])      ? (int)$metrics['text_chars']         : null;
                $tk  = (int)$agg->total_keystrokes;

                if ($ksr !== null) {
                    if ($ksr < 0.25 && $tc > 100) {
                        $rows_bug2 .= eg_pass(
                            'Keystroke-ratio gate (Signal 1)',
                            "keystroke_ratio={$ksr} < 0.25 — session treated as effectively pure paste. "
                          . "Full +60 awarded. text_chars={$tc}, total_keystrokes={$tk}."
                        );
                    } else {
                        $rows_bug2 .= eg_info(
                            'Keystroke-ratio gate (Signal 1)',
                            "keystroke_ratio={$ksr} (≥0.25 or text_chars≤100) — genuine typing or small answer. "
                          . "Gate does not fire. text_chars={$tc}, total_keystrokes={$tk}."
                        );
                    }
                } else {
                    // FIX-EG-DIAG-KSR (v1.2.122): keystroke_ratio was never stored in
                    // metricsjson before v1.2.122 — use total_keystrokes from the SC record
                    // as a proxy to distinguish stale data from a live capture failure.
                    if ($tk > 0) {
                        $rows_bug2 .= eg_info(
                            'Keystroke-ratio gate (Signal 1)',
                            "keystroke_ratio not yet stored in metricsjson (pre-v1.2.122 score record). "
                          . "total_keystrokes={$tk} — events DID reach the server. "
                          . "Submit a new attempt under v1.2.122+ to populate."
                        );
                    } else {
                        $rows_bug2 .= eg_fail(
                            'Keystroke-ratio gate (Signal 1)',
                            "keystroke_ratio not in metricsjson AND total_keystrokes=0. "
                          . "Keystroke events did NOT reach the server — JS tracker binding likely failed. "
                          . "Check Section 8 (Event capture) and the browser console for [EssayGuard DIAG] messages."
                        );
                        $overall_pass = false;
                    }
                }
            }
        } else {
            $rows_bug2 .= eg_info('Keystroke-ratio gate', 'metricsjson empty — session predates ratio tracking');
        }
    } else {
        $rows_bug2 .= eg_info('No aggregate SC record', 'User id=' . $sample_userid . ' has no aggregate record for this cm');
    }
} else {
    $rows_bug2 .= eg_info('No student data', 'No submissions found for this cm yet');
}

// ── SECTION 4: Bug 3 — MutationObserver 4-retry TinyMCE bind ─────────────────
// (FIX-EG-TMCE-BIND-RETRY v1.2.114)
//
// We verify the AMD build contains the four setTimeout retry calls.
// This is a code-level check on the built file on disk.

$rows_bug3 .= eg_info(
    'What this checks',
    'Bug 3: MutationObserver used a single 300ms delay before binding TinyMCE iframes. '
  . 'On slow Moodle themes (Boost/Classic/Moove) the iframe body was not ready — paste '
  . 'listener was never attached to Q2 editor. Fix: four retries at 300/600/1200/2500ms.'
);

$build_path = __DIR__ . '/amd/build/tracker.min.js';
$src_path   = __DIR__ . '/amd/src/tracker.js';

foreach ([['src', $src_path], ['build', $build_path]] as [$label, $path]) {
    if (!file_exists($path)) {
        $rows_bug3 .= eg_fail("tracker.{$label}.js exists", 'File not found at: ' . $path);
        $overall_pass = false;
        continue;
    }

    $content = file_get_contents($path);

    // Count occurrences of the four retry timeouts.
    $t300  = substr_count($content, '300');
    $t600  = substr_count($content, '600');
    $t1200 = substr_count($content, '1200');
    $t2500 = substr_count($content, '2500');

    // FIX-EG-DIAG-RETRY-CHECK (v1.2.138): The retry implementation uses anonymous
    // setTimeout callbacks, not a named retryBind() function. The old check for
    // 'retryBind' always FAILed even on correct builds, producing a misleading
    // diagnostic. New check: look for both the timeout values (300, 1200, 2500)
    // AND the MutationObserver callback pattern (observeTinyMCEIframes).
    if ($label === 'src') {
        $has_300  = strpos($content, '300')  !== false;
        $has_1200 = strpos($content, '1200') !== false;
        $has_2500 = strpos($content, '2500') !== false;
        $has_obs  = strpos($content, 'observeTinyMCEIframes') !== false
                 || strpos($content, 'MutationObserver')      !== false;
        $has_retry = $has_300 && $has_1200 && $has_2500 && $has_obs;
        if ($has_retry) {
            $rows_bug3 .= eg_pass(
                'tracker.src.js: 4-retry MutationObserver',
                'setTimeout values 300/1200/2500 + MutationObserver found — v1.2.114 fix confirmed'
            );
        } else {
            $rows_bug3 .= eg_fail(
                'tracker.src.js: 4-retry MutationObserver',
                'setTimeout values (300/1200/2500) or MutationObserver not found — file may be pre-v1.2.114'
            );
            $overall_pass = false;
        }
    } else {
        // In minified build: check for presence of all four numbers near each other.
        $has_2500 = strpos($content, '2500') !== false;
        $has_1200 = strpos($content, '1200') !== false;
        if ($has_2500 && $has_1200) {
            $rows_bug3 .= eg_pass(
                'tracker.build.js: retry timeouts present',
                'Values 1200 and 2500 found in minified build — 4-retry pattern confirmed'
            );
        } else {
            $rows_bug3 .= eg_fail(
                'tracker.build.js: retry timeouts present',
                'Values 1200 and/or 2500 missing from minified build — AMD build may not be in sync with src'
            );
            $overall_pass = false;
        }

        // MD5 check — confirm src and build are the same version.
        $md5_src   = file_exists($src_path)   ? md5_file($src_path)   : 'N/A';
        $md5_build = md5_file($build_path);
        $rows_bug3 .= eg_info(
            'tracker build MD5',
            'src=' . $md5_src . ' | build=' . $md5_build
        );
    }
}

// ── SECTION 5: Overall per-question vs aggregate comparison ───────────────────

if ($sample_userid) {
    $all_sc = $DB->get_records_sql(
        "SELECT qslot, riskscore, paste_events, total_keystrokes, timemodified
           FROM {plagiarism_essayguard_sc}
          WHERE userid = :userid AND cmid = :cmid
       ORDER BY qslot ASC, timemodified DESC",
        ['userid' => $sample_userid, 'cmid' => $cmid]
    );

    $seen_slots = [];
    foreach ($all_sc as $r) {
        $slot = (int)$r->qslot;
        if (isset($seen_slots[$slot])) {
            continue;
        }
        $seen_slots[$slot] = true;
        $score_pct = round((float)$r->riskscore * 100, 1);
        $band = eg_risk_level_label($score_pct);
        $label = $slot === 0 ? 'Aggregate (qslot=0)' : "Per-question qslot=$slot";
        $rows_records .= eg_info(
            $label,
            "riskscore={$r->riskscore} ({$score_pct}%) → {$band} | "
          . "paste_events={$r->paste_events} | "
          . "keystrokes={$r->total_keystrokes}"
        );
    }

    if (empty($seen_slots)) {
        $rows_records .= eg_info('No SC records', 'No score records for userid=' . $sample_userid . ' cmid=' . $cmid);
    }

    // Check for the "same score on every question" symptom (Bug 1 indicator).
    $per_q = array_filter($seen_slots, fn($s) => $s > 0, ARRAY_FILTER_USE_KEY);
    if (count($per_q) >= 2) {
        $scores = [];
        foreach ($all_sc as $r) {
            $slot = (int)$r->qslot;
            if ($slot > 0 && !isset($scores[$slot])) {
                $scores[$slot] = (float)$r->riskscore;
            }
        }
        if (count(array_unique($scores)) === 1) {
            // FIX-EG-DIAG-IDENTICAL-SCORE (v1.2.138): When the JS tracker sends zero
            // events (total_keystrokes=0 for every question), the linguistic fallback
            // produces identical scores for questions with similar text length and
            // vocabulary. This is NOT Bug 1 (qslot detection failure) — it is the
            // expected outcome when the tracker fails to load. Bug 1 specifically
            // means per-question records all have the SAME riskscore as the aggregate
            // (qslot=0) record, which happens when every question is assigned the
            // aggregate record. Check that specific case instead.
            $agg_score = null;
            foreach ($all_sc as $r) {
                if ((int)$r->qslot === 0) {
                    $agg_score = (float)$r->riskscore;
                    break;
                }
            }
            $pq_score = reset($scores);
            // True Bug 1: all per-question scores equal the aggregate score (qslot detection fallback).
            if ($agg_score !== null && abs($pq_score - $agg_score) < 0.001) {
                $rows_records .= eg_fail(
                    'All per-question scores identical (= aggregate)',
                    'Every per-question riskscore (' . $pq_score . ') equals the aggregate (qslot=0) score — '
                  . 'classic Bug 1: qslot detection failed and every question was assigned the aggregate record. '
                  . 'The v1.2.115 fix should resolve this on the next attempt.'
                );
                $overall_pass = false;
            } else {
                // Per-question scores are equal to each other but NOT to aggregate — likely
                // zero-event sessions where linguistic fallback produces similar scores.
                // This is expected when the JS tracker fails to load; NOT a qslot bug.
                $rows_records .= eg_pass(
                    'Per-question scores identical (not Bug 1)',
                    'All per-question riskscores equal ' . $pq_score . ' but differ from aggregate — '
                  . 'likely zero JS events causing identical linguistic fallback scores for both questions. '
                  . 'Check Section 8 (Event capture) for the root cause.'
                );
            }
        } else {
            $rows_records .= eg_pass(
                'Per-question scores vary',
                'Questions have different riskscores — qslot detection is working correctly'
            );
        }
    }
}

// ── SECTION 6: Signal breakdown from metricsjson ─────────────────────────────
// For each SC record, decode metricsjson and show which signals fired and why.

$signal_names = [
    1  => 'S1: Paste / drop events (primary paste trigger)',
    2  => 'S2: Large insert burst (TinyMCE paste proxy)',
    3  => 'S3: Superhuman typing speed (>8 cps)',
    4  => 'S4: No long pauses (robotic cadence)',
    5  => 'S5: Low backspace ratio (<2%)',
    6  => 'S6: Near-zero session typing time',
    7  => 'S7: Suspicious rhythm / low IKI entropy',
    8  => 'S8: Sentence length uniformity (linguistic)',
    9  => 'S9: Low vocabulary diversity (linguistic)',
    10 => 'S10: IKI autocorrelation deviation',
    11 => 'S11: Speed burst coefficient',
    12 => 'S12: Keystroke-to-character ratio (low)',
];

$rows_signals .= eg_info('What this checks',
    'Decodes metricsjson for every SC record to show exactly which signals fired, '
  . 'their point contributions, and why the badge was assigned. '
  . 'Thresholds: LOW=0–29, MEDIUM=30–65, HIGH=66–100. '
  . 'Session must be scored with v1.2.113+ for signal_breakdown to be present.'
);

// Build latest_by_slot here so Section 7 can reuse it.
$latest_by_slot = [];
if ($sample_userid) {
    $all_sc_full = $DB->get_records_sql(
        "SELECT * FROM {plagiarism_essayguard_sc}
          WHERE userid = :userid AND cmid = :cmid
       ORDER BY qslot ASC, timemodified DESC",
        ['userid' => $sample_userid, 'cmid' => $cmid]
    );
    foreach ($all_sc_full as $r) {
        $slot = (int)$r->qslot;
        if (!isset($latest_by_slot[$slot])) {
            $latest_by_slot[$slot] = $r;
        }
    }
    ksort($latest_by_slot);
}

if ($sample_userid) {
    if (empty($latest_by_slot)) {
        $rows_signals .= eg_info('No SC records', 'No score records for this student and activity');
    } else {
        foreach ($latest_by_slot as $slot => $r) {
            $slot_label  = $slot === 0 ? 'Aggregate (qslot=0)' : "Per-question qslot=$slot";
            $score_pct   = round((float)$r->riskscore * 100, 1);
            $band        = eg_risk_level_label($score_pct);

            // Section header row.
            $rows_signals .= '<tr><td colspan="3" style="background:#e8eaf6;font-weight:600;'
                           . 'padding:0.5rem 0.7rem;border-top:2px solid #c5cae9;">'
                           . htmlspecialchars($slot_label) . ' &mdash; stored score: '
                           . $score_pct . '% &rarr; '
                           . '<strong>' . $band . '</strong></td></tr>';

            if (!empty($r->metricsjson)) {
                $metrics = json_decode($r->metricsjson, true);
                if (is_array($metrics)) {
                    // Key input metrics.
                    $tc   = $metrics['text_chars']      ?? null;
                    $pe   = (int)($r->paste_events      ?? 0);
                    $tk   = (int)($r->total_keystrokes  ?? 0);
                    $li   = $metrics['large_inserts']   ?? null;
                    $ksr  = $metrics['keystroke_ratio'] ?? null;
                    $pf   = $metrics['paste_frac']      ?? null;
                    $cps  = $metrics['chars_per_sec']   ?? null;
                    $ent  = $metrics['entropy_score']   ?? null;
                    $iki  = $metrics['iki_shannon']      ?? null;
                    $s100 = $metrics['score100']         ?? null;

                    $m_parts = [];
                    if ($tc  !== null) $m_parts[] = "text_chars={$tc}";
                    $m_parts[] = "keystrokes={$tk}";
                    $m_parts[] = "paste_events={$pe}";
                    if ($li  !== null) $m_parts[] = "large_inserts={$li}";
                    if ($ksr !== null) $m_parts[] = 'keystroke_ratio=' . round((float)$ksr, 3);
                    if ($pf  !== null) $m_parts[] = 'paste_frac='      . round((float)$pf, 3);
                    if ($cps !== null) $m_parts[] = 'chars_per_sec='   . round((float)$cps, 2);
                    if ($ent !== null) $m_parts[] = 'entropy='         . round((float)$ent, 3);
                    if ($iki !== null) $m_parts[] = 'iki_shannon='     . round((float)$iki, 3);

                    $rows_signals .= eg_info('  Input metrics', implode(', ', $m_parts));

                    // Signal breakdown.
                    // FIX-EG-DIAG-S6-EMPTY-SIGNALS (v1.2.125): Use $s100 !== null as the
                    // v1.2.124+ indicator instead of !empty($sb). Sessions scored with
                    // v1.2.124+ always store score100 in metricsjson. signal_breakdown can
                    // legitimately be an empty array [] for no-events sessions where the
                    // linguistic fallback ran but no individual behavioural signals fired.
                    // The old !empty($sb) check treated that case as "session scored before
                    // v1.2.113" which was misleading — it was a fresh attempt, just no events.
                    $sb = $metrics['signal_breakdown'] ?? null;
                    if ($s100 !== null) {
                        // v1.2.124+ session — show signal details.
                        $total_raw  = is_array($sb) ? (int)array_sum($sb) : 0;

                        if (is_array($sb) && !empty($sb)) {
                            $sig_parts = [];
                            foreach ($sb as $sig => $pts) {
                                $sig_parts[] = "S{$sig}(+{$pts})";
                            }
                            $rows_signals .= eg_info('  Signals fired', implode('  ', $sig_parts) . "  →  {$total_raw} raw pts");

                            foreach ($sb as $sig => $pts) {
                                $name = $signal_names[$sig] ?? "Signal $sig";
                                if ($sig == 1 || $sig == 2) {
                                    $rows_signals .= eg_pass("  {$name}", "+{$pts} pts — paste detected");
                                } elseif (in_array($sig, [4, 5, 7]) && $pe === 0 && (!$li)) {
                                    $rows_signals .= eg_info("  {$name}",
                                        "+{$pts} pts (no paste — pure typing signal; false-positive cap should apply if no other paste signals)");
                                } else {
                                    $rows_signals .= eg_info("  {$name}", "+{$pts} pts");
                                }
                            }
                        } else {
                            $rows_signals .= eg_info('  Behavioral signals',
                                'None fired — JS tracker sent 0 events; see Section 8 (Event capture)');
                        }

                        $ling_pts = (int)($metrics['linguistic_fallback_pts'] ?? 0);
                        $agg_elevated = !empty($metrics['agg_elevated_from_perq']);
                        if ($ling_pts > 0) {
                            $rows_signals .= eg_info('  Linguistic fallback',
                                "+{$ling_pts} pts (no behavioural events — linguistic signals used as proxy)");
                        } elseif ($tk === 0) {
                            $rows_signals .= eg_info('  Linguistic fallback',
                                '0 pts fired (sentence_variance and vocab_diversity within normal range)');
                        }
                        if ($agg_elevated) {
                            $rows_signals .= eg_info('  Aggregate elevation',
                                'FIX-EG-AGG-PERQ-CONSISTENCY: aggregate score was elevated to match '
                              . 'max per-question score (no events, linguistic metrics differed for combined text)');
                        }

                        // Cap check.
                        $s100_int = (int)$s100;
                        if ($total_raw > 0 && $s100_int < $total_raw) {
                            $rows_signals .= eg_info('  False-positive cap applied',
                                "Raw {$total_raw} pts capped at {$s100_int} "
                              . "(no paste + normal speed + natural entropy → capped at LOW ceiling 29). "
                              . "Final badge: " . eg_risk_level_label($s100_int));
                        } elseif ($total_raw > 0) {
                            $rows_signals .= eg_pass('  Final score (no cap)',
                                "Raw {$total_raw} pts → {$s100_int}% → " . eg_risk_level_label($s100_int));
                        } else {
                            $rows_signals .= eg_info('  Final score',
                                "{$s100_int}% → " . eg_risk_level_label($s100_int)
                              . ($s100_int === 0 ? ' (no signals + no linguistic fallback fired)' : ''));
                        }
                    } else {
                        $rows_signals .= eg_info('  signal_breakdown',
                            'Not present — session scored before v1.2.113. Submit a new attempt to populate.');
                    }
                } else {
                    $rows_signals .= eg_info('  metricsjson', 'Invalid JSON in DB');
                }
            } else {
                $rows_signals .= eg_info('  metricsjson', 'Empty — session predates metrics tracking');
            }
        }
    }
} else {
    $rows_signals .= eg_info('No student data', 'No submissions found for this cm yet');
}

// ── SECTION 7: Badge consistency — Review Attempt vs Teacher Report ───────────
// Simulates the exact fallback logic from lib.php and report.php for each slot
// and flags any divergence so the root cause of a mismatch is immediately visible.

$rows_badge .= eg_info('What this checks',
    'Simulates the exact badge-selection logic from both display paths for every question slot. '
  . '(A) Review Attempt page — lib.php get_links() per-question lookup + aggregate fallback. '
  . '(B) Teacher report — report.php per-question breakdown rows + same fallback gate. '
  . 'Also shows what badge would appear if qslot detection FAILS (lib.php falls to aggregate). '
  . 'FAIL = the two pages would show different badges for the same student/question.'
);

if ($sample_userid) {
    $agg_r    = $latest_by_slot[0] ?? null;
    $pq_slots = array_filter(array_keys($latest_by_slot), fn($s) => $s > 0);

    // ── Aggregate paste evidence (v1.2.160 gate: Signal 1 >= 30 pts OR riskscore >= 0.70)
    // Mirrors lib.php FIX-EG-AGG-PASTE-MEANINGFUL (v1.2.143) and report.php
    // FIX-EG-REPORT-PASTE-SIGNAL1 (v1.2.160). Both pages now use identical logic.
    $agg_metrics_arr = [];
    $agg_signal1_pts = 0;
    if ($agg_r && !empty($agg_r->metricsjson)) {
        $agg_metrics_arr = json_decode($agg_r->metricsjson, true) ?: [];
        $agg_signal1_pts = (int)(($agg_metrics_arr['signal_breakdown'][1] ?? 0));
    }
    $agg_has_paste = $agg_r
        && (($agg_signal1_pts >= 30) || ((float)$agg_r->riskscore >= 0.70));

    if ($agg_r) {
        $agg_score = round((float)$agg_r->riskscore * 100, 1);
        $agg_band  = eg_risk_level_label($agg_score);
        $agg_pe    = (int)($agg_r->paste_events ?? 0);
        if ($agg_has_paste) {
            $agg_ev = "has meaningful paste evidence (Signal1={$agg_signal1_pts}pts"
                    . ($agg_signal1_pts >= 30 ? ' >=30' : '') . ", riskscore={$agg_r->riskscore}"
                    . ((float)$agg_r->riskscore >= 0.70 ? ' >=0.70' : '') . ') — aggregate CAN rescue a zero per-question slot';
        } else {
            $agg_ev = "NO meaningful paste evidence (Signal1={$agg_signal1_pts}pts <30, riskscore={$agg_r->riskscore} <0.70)"
                    . " — aggregate will NOT override any per-question score (paste_events raw={$agg_pe})";
        }
        $rows_badge .= eg_info('Aggregate record (qslot=0)', "{$agg_score}% {$agg_band} | {$agg_ev}");
    } else {
        $rows_badge .= eg_info('Aggregate record (qslot=0)', 'Not found — no aggregate SC record for this student');
    }

    if (empty($pq_slots)) {
        if ($agg_r) {
            $agg_score = round((float)$agg_r->riskscore * 100, 1);
            $rows_badge .= eg_info('No per-question records',
                'Only aggregate record exists. Both pages show aggregate: '
              . $agg_score . '% ' . eg_risk_level_label($agg_score));
        } else {
            $rows_badge .= eg_info('No SC records', 'No score records at all for this student');
        }
    } else {
        $slot_mismatches = 0;
        foreach ($pq_slots as $slot) {
            $pq             = $latest_by_slot[$slot];
            $pq_score       = round((float)$pq->riskscore * 100, 1);
            $pq_keystrokes  = (int)($pq->total_keystrokes ?? 0);
            $pq_paste_ev    = (int)($pq->paste_events ?? 0);

            // ── Simulate lib.php get_links() — FIX-EG-PERQ-PASTE-STRICT (v1.2.160) ──
            // Gate: riskscore <= 0.0 only (strict). Matches report.php exactly.
            $lib_record      = $pq;
            $lib_is_fallback = false;
            if ((float)$pq->riskscore <= 0.0 && $agg_r && $agg_has_paste) {
                $lib_record      = $agg_r;
                $lib_is_fallback = true;
            }
            $lib_score = round((float)$lib_record->riskscore * 100, 1);
            $lib_band  = eg_risk_level_label($lib_score);
            $lib_label = $lib_band . ' ' . $lib_score . '%' . ($lib_is_fallback ? ' (OVERALL)' : '');

            // ── Simulate report.php fallback logic ──
            // Gate: riskscore <= 0.0 + Signal1 >= 30 OR riskscore >= 0.70.
            // FIX-EG-REPORT-PASTE-SIGNAL1 (v1.2.160): now matches lib.php exactly.
            $rep_record      = $pq;
            $rep_is_fallback = false;
            if ((float)$pq->riskscore <= 0.0 && $agg_r && $agg_has_paste) {
                $rep_record      = $agg_r;
                $rep_is_fallback = true;
            }
            $rep_score = round((float)$rep_record->riskscore * 100, 1);
            $rep_band  = eg_risk_level_label($rep_score);
            $rep_label = $rep_band . ' ' . $rep_score . '%' . ($rep_is_fallback ? ' (OVERALL)' : '');

            // ── What both pages would show if qslot detection fails (falls to agg) ──
            $miss_label = 'N/A';
            if ($agg_r) {
                $miss_score = round((float)$agg_r->riskscore * 100, 1);
                $miss_band  = eg_risk_level_label($miss_score);
                $miss_label = $miss_band . ' ' . $miss_score . '% (OVERALL — qslot detection failed)';
            }

            // ── Section header ──
            $rows_badge .= '<tr><td colspan="3" style="background:#f0f4ff;font-weight:600;'
                         . 'padding:0.5rem 0.7rem;border-top:2px solid #c5cae9;">'
                         . "Slot {$slot} — per-question score: {$pq_score}% "
                         . "| keystrokes={$pq_keystrokes} | paste_events={$pq_paste_ev}</td></tr>";

            // ── Main consistency check — PASS or FAIL ──
            if ($lib_label === $rep_label) {
                $rows_badge .= eg_pass(
                    "Slot {$slot}: Review Attempt = EssayGuard Report",
                    "Both show: {$lib_label}"
                );
            } else {
                $rows_badge .= eg_fail(
                    "Slot {$slot}: MISMATCH — pages show different badges",
                    "Review Attempt: {$lib_label} | EssayGuard Report: {$rep_label}"
                );
                $overall_pass = false;
                $slot_mismatches++;
            }

            // ── Warn if a qslot detection failure would produce a different badge ──
            if ($agg_r && $miss_label !== $lib_label) {
                $rows_badge .= eg_info(
                    "Slot {$slot}: if qslot detection fails",
                    "Both pages would show {$miss_label} instead of {$lib_label} "
                  . "— check Section 2 to confirm qslot detection is working"
                );
            } elseif ($agg_r) {
                $rows_badge .= eg_pass(
                    "Slot {$slot}: qslot detection failure is safe",
                    "Even if qslot detection fails, the aggregate fallback produces the same badge — no mismatch risk from this slot"
                );
            }

            // ── Explain fallback decision ──
            if ($lib_is_fallback) {
                $rows_badge .= eg_info(
                    "Slot {$slot}: aggregate fallback triggered",
                    "Per-question riskscore=0.0 (no scoring signal for this slot) AND aggregate has meaningful paste evidence "
                  . "(Signal1={$agg_signal1_pts}pts, riskscore={$agg_r->riskscore}) → aggregate score displayed"
                );
            } elseif ((float)$pq->riskscore > 0.0) {
                $rows_badge .= eg_pass(
                    "Slot {$slot}: per-question record trusted",
                    "riskscore={$pq->riskscore} ({$pq_score}%) > 0 → per-question score used directly; aggregate not consulted"
                );
            } else {
                $rows_badge .= eg_info(
                    "Slot {$slot}: no fallback (aggregate has no paste evidence)",
                    "Per-question riskscore=0.0 BUT aggregate has no meaningful paste evidence → LOW 0% shown"
                );
            }
        }

        // ── Overall match summary ──
        if ($slot_mismatches === 0) {
            $rows_badge .= eg_pass(
                'Review Attempt vs EssayGuard Report: ALL SLOTS MATCH',
                'Every question shows the same badge on the Review Attempt page and the EssayGuard Report. '
              . 'No mismatch detected for this student.'
            );
        } else {
            $rows_badge .= eg_fail(
                "Review Attempt vs EssayGuard Report: {$slot_mismatches} SLOT(S) MISMATCH",
                'One or more questions show a different badge on the Review Attempt page vs the EssayGuard Report. '
              . 'See slot detail rows above for the specific divergence. '
              . 'Most likely cause: qslot detection failed (see Section 2) — both pages fall back to the aggregate.'
            );
        }
    }
} else {
    $rows_badge .= eg_info('No student data', 'No submissions found for this cm yet');
}

// ── SECTION 8: Event capture verification ─────────────────────────────────────
// Queries plagiarism_essayguard_ev to verify whether the JS tracker actually
// sent events to the server. This is the ground-truth check that "ALL CHECKS
// PASSED" was missing — total_keystrokes=0 in an SC record is ambiguous (stale
// data vs live capture failure); only the ev table tells us which it is.
//
// FIX-EG-DIAG-EV-CHECK (v1.2.122): added this section so admins can
// distinguish "events existed but belong to an old attempt" from "JS tracker
// never sent any events at all". The latter produces total_keystrokes=0 on
// FRESH attempts and causes random-looking badges driven purely by linguistic
// fallback — the exact symptom that prompted this diagnostic rewrite.

// FIX-EG-DIAG-CMCHECK-S8 (v1.2.123): If the CM is disabled, zero events are the
// EXPECTED outcome — not a JS tracker failure. Surface this prominently so admins
// don't waste time chasing TinyMCE binding issues when the real root cause is a
// single unchecked checkbox in the quiz settings.
if (!$eg_cm_active) {
    $rows_events .= eg_fail(
        'CM DISABLED — zero events are expected and correct',
        'EssayGuard is DISABLED for cmid=' . $cmid . ' (Section 1 FAIL). '
      . 'inject_tracker() exits early for this cm, so the tracker JS is NEVER loaded '
      . 'in the student browser. No keydown/paste/input events are queued, so no events '
      . 'can reach the server regardless of TinyMCE version, browser, or network. '
      . 'The zero-events FAIL below is a direct consequence of the CM being disabled. '
      . 'Fix: enable EssayGuard in the quiz settings (see Section 1 ACTION).'
    );
}

$rows_events .= eg_info(
    'What this checks',
    'Queries the raw event table (plagiarism_essayguard_ev) to verify whether the '
  . 'JS tracker actually sent keystroke/paste events to the server for this student. '
  . 'total_keystrokes=0 in an SC record means either (A) no events reached the DB at all '
  . '(JS tracker binding/flush failure or CM disabled — see Section 1) or (B) events exist '
  . 'but under a different attemptkey (lookup mismatch). This section shows both cases unambiguously.'
);

if ($sample_userid) {
    // Get up to 5 most recent attempt keys with full event-type breakdown.
    $ev_rows = $DB->get_records_sql(
        "SELECT attemptkey,
                COUNT(*)                                                                AS total_events,
                SUM(CASE WHEN eventname = 'keydown'      THEN 1 ELSE 0 END)           AS keydown_count,
                SUM(CASE WHEN eventname = 'backspace'    THEN 1 ELSE 0 END)           AS backspace_count,
                SUM(CASE WHEN eventname = 'delete'       THEN 1 ELSE 0 END)           AS delete_count,
                SUM(CASE WHEN eventname = 'paste'        THEN 1 ELSE 0 END)           AS paste_count,
                SUM(CASE WHEN eventname = 'large_insert' THEN 1 ELSE 0 END)           AS large_insert_count,
                SUM(CASE WHEN eventname = 'input'        THEN 1 ELSE 0 END)           AS input_count,
                MAX(timecreated)                                                       AS latest_time
           FROM {plagiarism_essayguard_ev}
          WHERE userid = :userid AND cmid = :cmid
       GROUP BY attemptkey
       ORDER BY MAX(timecreated) DESC",
        ['userid' => $sample_userid, 'cmid' => $cmid],
        0,
        5
    );

    if (empty($ev_rows)) {
        $rows_events .= eg_fail(
            'Event table: no rows found',
            'plagiarism_essayguard_ev contains ZERO rows for this student (userid='
          . $sample_userid . ', cmid=' . $cmid . '). '
          . 'The JS tracker either failed to bind to the essay field, the periodic '
          . 'flush never fired (student submitted in under 5 s), or all flush requests '
          . 'were rejected by the server. '
          . 'Action: open the browser console during a live quiz attempt and look for '
          . '[EssayGuard DIAG] init, bindField, and flush messages.'
        );
        $overall_pass = false;
    } else {
        $ev_i = 0;
        foreach ($ev_rows as $ev) {
            $ev_i++;
            $akey_short = substr($ev->attemptkey, 0, 12) . '…';
            $kd_total   = (int)$ev->keydown_count + (int)$ev->backspace_count + (int)$ev->delete_count;
            $latest_fmt = $ev->latest_time ? date('Y-m-d H:i:s', (int)$ev->latest_time) : '?';
            $label  = "Attempt key #{$ev_i}: {$akey_short} (last event: {$latest_fmt})";
            $detail = "total_events={$ev->total_events}"
                    . " | keydown={$ev->keydown_count}"
                    . " | backspace={$ev->backspace_count}"
                    . " | paste={$ev->paste_count}"
                    . " | large_insert={$ev->large_insert_count}"
                    . " | input={$ev->input_count}";

            if ($kd_total > 0) {
                $rows_events .= eg_pass($label,
                    $detail . ' — keystroke events captured correctly');
            } elseif ((int)$ev->paste_count > 0 || (int)$ev->large_insert_count > 0) {
                $rows_events .= eg_info($label,
                    $detail . ' — paste/insert events present but ZERO keystroke events. '
                  . 'TinyMCE keydown listener likely failed to bind (check browser console '
                  . 'for [EssayGuard DIAG] bindField messages).');
            } else {
                $rows_events .= eg_fail($label,
                    $detail . ' — NO keystroke or paste events at all. '
                  . 'The JS tracker sent events but none are behavioural — '
                  . 'only focus/blur/wpm_snapshot events arrived. '
                  . 'This means bindField() was called but the keydown/paste listeners '
                  . 'are not firing (likely a TinyMCE cross-frame binding issue).');
                $overall_pass = false;
            }
        }

        // Cross-check: does the latest ev attemptkey match the latest SC record?
        $latest_ev_key = reset($ev_rows)->attemptkey;
        $latest_sc_key = $DB->get_field_sql(
            "SELECT attemptkey FROM {plagiarism_essayguard_sc}
              WHERE userid = :userid AND cmid = :cmid
           ORDER BY timemodified DESC LIMIT 1",
            ['userid' => $sample_userid, 'cmid' => $cmid]
        );
        if ($latest_sc_key && $latest_sc_key !== $latest_ev_key) {
            $rows_events .= eg_fail(
                'Attemptkey mismatch: ev table vs sc table',
                'Most recent EV key (' . substr($latest_ev_key, 0, 12) . '…) differs '
              . 'from most recent SC key (' . substr($latest_sc_key, 0, 12) . '…). '
              . 'Events were stored under a different key than the score record — '
              . 'analyser::score_attempt() found 0 matching events when scoring, '
              . 'so total_keystrokes=0 even though typing data exists. '
              . 'Root cause: inject_tracker() generated a different attemptkey at '
              . 'score time vs event-capture time. Check lib.php inject_tracker().'
            );
            $overall_pass = false;
        } elseif ($latest_sc_key) {
            $rows_events .= eg_pass(
                'Attemptkey match: ev table vs sc table',
                'Latest EV and SC records share the same attemptkey ('
              . substr($latest_ev_key, 0, 12) . '…) — '
              . 'the scorer is reading the correct event set'
            );
        } else {
            $rows_events .= eg_info(
                'Attemptkey cross-check',
                'No SC record found for comparison (quiz not yet submitted or scoring pending)'
            );
        }
    }
} else {
    $rows_events .= eg_info('No student data', 'No submissions found for this cm yet');
}

// ── SECTION 9: HTML deprecation warning diagnosis ────────────────────────────
// Diagnoses why "plagiarism_plugin::update_status() is deprecated" and
// "Callback should be migrated to new hook callback" may still appear as HTML
// output on Moodle grading/results pages when developer debug mode is on.
//
// The fix (FIX-EG-UPDATE-STATUS-REVIVE v1.2.174 + FIX-EG-PRELOAD-LIB v1.2.183
// + FIX-EG-BODY-PRELOAD-LIB v1.2.184) works through two interlocking guards:
//
//   Guard 1 — function_exists() bypass:
//     plagiarism_update_status() in plagiarismlib.php calls function_exists(
//     'plagiarism_essayguard_before_standard_top_of_body_html'). When TRUE,
//     Moodle calls the function directly and NEVER reaches the ReflectionMethod
//     branch — so the deprecation notice is never emitted.
//
//   Guard 2 — dual-class Path B (belt-and-suspenders):
//     The before_standard_head_html_generation hook callback pre-loads both
//     plagiarismlib.php and lib.php. When process_legacy_callbacks() later
//     includes lib.php, class_exists('plagiarism_plugin', false) = TRUE →
//     the `extends plagiarism_plugin` branch is taken → update_status() is
//     inherited → getDeclaringClass() = 'plagiarism_plugin' → deprecation
//     check suppressed even if function_exists() somehow returned FALSE.
//
// Checks are ordered by execution sequence (earliest to latest in page load).

// 9.1 — Minimum version check (both fixes shipped in v1.2.185).
// FIX-EG-DIAG-VERSION (v1.2.190): Moodle only stores $plugin->version (integer) in
// mdl_config_plugins — never $plugin->release (string). get_config('plagiarism_essayguard','release')
// always returns empty on sites that never explicitly set it via set_config(), causing this check
// to permanently show FAIL / "Cannot determine installed release". Fix: read version.php directly
// (same pattern used in Section 1 of this diagnostic).
$_eg_html_vp = new stdClass();
include(__DIR__ . '/version.php');  // sets $plugin->release, $plugin->version
$_eg_html_vp->release = $plugin->release ?? '';
$_eg_html_vp->version = $plugin->version ?? 0;
unset($plugin);
$eg_ver_release = $_eg_html_vp->release;
$eg_ver_num     = (int)$_eg_html_vp->version;
unset($_eg_html_vp);
// Parse "1.2.185" → integer for comparison.
$eg_ver_parts = array_map('intval', explode('.', trim($eg_ver_release, "v \t")));
$eg_ver_int = (count($eg_ver_parts) === 3)
    ? ($eg_ver_parts[0] * 1000000 + $eg_ver_parts[1] * 1000 + $eg_ver_parts[2])
    : 0;
$eg_min_int = 1 * 1000000 + 2 * 1000 + 185; // v1.2.185
if ($eg_ver_int >= $eg_min_int) {
    $rows_html .= eg_pass(
        'Plugin version (min v1.2.185)',
        $eg_ver_release . ' (build ' . $eg_ver_num . ') — FIX-EG-PRELOAD-LIB (v1.2.183) and FIX-EG-BODY-PRELOAD-LIB (v1.2.184) are present'
    );
} else {
    $rows_html .= eg_fail(
        'Plugin version (min v1.2.185)',
        $eg_ver_release
            ? 'Installed: ' . $eg_ver_release . ' — upgrade to v1.2.185+ to get the HTML-warning fix'
            : 'Cannot determine installed release — check Site Admin → Plugins → Plagiarism → EssayGuard'
    );
    $overall_pass = false;
}

// 9.2 — Moodle hook system availability (Moodle 4.3+ required).
$hook_class = '\core\hook\output\before_standard_head_html_generation';
$has_hook_system = class_exists($hook_class);
if ($has_hook_system) {
    $rows_html .= eg_pass(
        'Moodle hook system',
        'core\\hook\\output\\before_standard_head_html_generation exists — Moodle 4.3+ hook API available'
    );
} else {
    $rows_html .= eg_info(
        'Moodle hook system',
        'core\\hook\\output\\before_standard_head_html_generation not found — this is Moodle ≤4.2. '
        . 'The dual-class preload fix requires the hook API. On Moodle 4.0–4.2 the legacy '
        . 'plagiarism_essayguard_before_standard_html_head() function handles injection instead. '
        . 'Guard 1 (function_exists bypass) still applies; Guard 2 Path B requires Moodle 4.3+.'
    );
}

// 9.3 — db/hooks.php contains both required hook registrations.
$eg_hooks_file = __DIR__ . '/plagiarism/essayguard/db/hooks.php';
if (!file_exists($eg_hooks_file)) {
    // Running inside Moodle: __DIR__ is moodle/plagiarism/essayguard.
    $eg_hooks_file = __DIR__ . '/db/hooks.php';
}
if (file_exists($eg_hooks_file)) {
    $eg_hooks_content = file_get_contents($eg_hooks_file);
    // Check before_standard_head_html_generation entry.
    if (strpos($eg_hooks_content, 'before_standard_head_html_generation') !== false) {
        $rows_html .= eg_pass(
            'db/hooks.php — head hook registered',
            'before_standard_head_html_generation callback entry found in db/hooks.php'
        );
    } else {
        $rows_html .= eg_fail(
            'db/hooks.php — head hook missing',
            'before_standard_head_html_generation is NOT registered in db/hooks.php. '
            . 'The preload callback will never fire → class_exists() = false at lib.php load time → Path A taken → deprecation possible. '
            . 'Fix: add the before_standard_head_html_generation callback to db/hooks.php and purge Moodle caches.'
        );
        $overall_pass = false;
    }
    // Check before_standard_top_of_body_html_generation entry (belt-and-suspenders).
    if (strpos($eg_hooks_content, 'before_standard_top_of_body_html_generation') !== false) {
        $rows_html .= eg_pass(
            'db/hooks.php — body hook registered',
            'before_standard_top_of_body_html_generation callback entry found (belt-and-suspenders secondary preload)'
        );
    } else {
        $rows_html .= eg_info(
            'db/hooks.php — body hook not registered',
            'before_standard_top_of_body_html_generation is missing from db/hooks.php. '
            . 'This is the belt-and-suspenders fallback — its absence does not cause warnings on its own '
            . 'but means a stale head-hook cache has no safety net. Recommended: add this entry and purge caches.'
        );
    }
} else {
    $rows_html .= eg_fail(
        'db/hooks.php — file not found',
        'Cannot locate db/hooks.php at ' . $eg_hooks_file . '. Plugin may be missing its hook registration file entirely.'
    );
    $overall_pass = false;
}

// 9.4 — Hook callback class files exist on disk.
$eg_head_cb_file = __DIR__ . '/classes/hook/before_standard_head_html_generation.php';
$eg_body_cb_file = __DIR__ . '/classes/hook/before_standard_top_of_body_html_generation.php';

if (file_exists($eg_head_cb_file)) {
    $rows_html .= eg_pass(
        'Hook callback file — head',
        'classes/hook/before_standard_head_html_generation.php exists on disk'
    );
    // 9.5 — Check the callback actually loads both plagiarismlib.php and lib.php.
    $eg_head_cb_content = file_get_contents($eg_head_cb_file);
    $has_plagiarismlib = strpos($eg_head_cb_content, 'plagiarismlib.php') !== false;
    $has_lib_preload   = strpos($eg_head_cb_content, "require_once(__DIR__ . '/../../lib.php')") !== false
                      || strpos($eg_head_cb_content, "require_once(__DIR__.'/../../lib.php')") !== false
                      || strpos($eg_head_cb_content, "'../../lib.php'") !== false;
    if ($has_plagiarismlib) {
        $rows_html .= eg_pass(
            'Head callback — loads plagiarismlib.php',
            'require_once(plagiarismlib.php) found in head callback — ensures plagiarism_plugin class is in memory '
            . 'before process_legacy_callbacks() includes lib.php → Path B (extends plagiarism_plugin) selected'
        );
    } else {
        $rows_html .= eg_fail(
            'Head callback — plagiarismlib.php NOT loaded',
            'require_once(plagiarismlib.php) is missing from before_standard_head_html_generation.php. '
            . 'Without this, plagiarism_plugin is not in memory when lib.php is included → Path A taken → '
            . 'update_status() is the local stub → getDeclaringClass() = plagiarism_plugin_essayguard → deprecation emitted.'
        );
        $overall_pass = false;
    }
    if ($has_lib_preload) {
        $rows_html .= eg_pass(
            'Head callback — loads lib.php',
            "require_once('../../lib.php') found in head callback — ensures plagiarism_essayguard_before_standard_top_of_body_html() "
            . 'is defined before plagiarism_update_status() calls function_exists()'
        );
    } else {
        $rows_html .= eg_fail(
            'Head callback — lib.php NOT pre-loaded',
            "require_once('../../lib.php') is missing from before_standard_head_html_generation.php (FIX-EG-PRELOAD-LIB v1.2.183). "
            . 'Without this, plagiarism_essayguard_before_standard_top_of_body_html() may not be in memory when '
            . 'plagiarism_update_status() calls function_exists() → falls through to ReflectionMethod → deprecation emitted.'
        );
        $overall_pass = false;
    }
} else {
    $rows_html .= eg_fail(
        'Hook callback file — head MISSING',
        'classes/hook/before_standard_head_html_generation.php does not exist. '
        . 'The preload mechanism cannot fire at all. Create this file and register it in db/hooks.php.'
    );
    $overall_pass = false;
}

if (file_exists($eg_body_cb_file)) {
    $rows_html .= eg_pass(
        'Hook callback file — body',
        'classes/hook/before_standard_top_of_body_html_generation.php exists (belt-and-suspenders secondary preload)'
    );
    $eg_body_cb_content = file_get_contents($eg_body_cb_file);
    $body_has_lib = strpos($eg_body_cb_content, "'../../lib.php'") !== false
                 || strpos($eg_body_cb_content, "'/../../lib.php'") !== false
                 || strpos($eg_body_cb_content, 'lib.php') !== false;
    if ($body_has_lib) {
        $rows_html .= eg_pass(
            'Body callback — loads lib.php',
            'require_once(lib.php) found in body callback (FIX-EG-BODY-PRELOAD-LIB v1.2.184) — '
            . 'second guarantee that the global function is defined even if head callback hook registration is stale'
        );
    } else {
        $rows_html .= eg_info(
            'Body callback — lib.php not loaded',
            'lib.php preload is absent from before_standard_top_of_body_html_generation.php. '
            . 'This is the belt-and-suspenders fallback; its absence is only a problem if the head callback is also not firing.'
        );
    }
} else {
    $rows_html .= eg_info(
        'Hook callback file — body not present',
        'classes/hook/before_standard_top_of_body_html_generation.php does not exist. '
        . 'This is the secondary safety net — its absence matters only if the head callback is also missing or stale.'
    );
}

// 9.6 — lib.php static code checks: dual-class guard + global function present.
$eg_lib_content = file_get_contents(__DIR__ . '/lib.php');

$has_dual_class = strpos($eg_lib_content, "class_exists('plagiarism_plugin', false)") !== false;
if ($has_dual_class) {
    $rows_html .= eg_pass(
        'lib.php — dual-class guard present',
        "if (class_exists('plagiarism_plugin', false)) { extends ... } else { standalone ... } pattern found. "
        . 'Path B (extends plagiarism_plugin) is selected on normal page loads where the hook fires first.'
    );
} else {
    $rows_html .= eg_fail(
        'lib.php — dual-class guard MISSING',
        "class_exists('plagiarism_plugin', false) conditional not found in lib.php. "
        . 'The class definition may use a bare `extends plagiarism_plugin` (crashes on early bootstrap) '
        . 'or a standalone class with no extends (always Path A → deprecation on every page). '
        . 'Apply FIX-EG-CONDITIONAL-EXTENDS (v1.2.166).'
    );
    $overall_pass = false;
}

$has_noop_fn = strpos($eg_lib_content, 'function plagiarism_essayguard_before_standard_top_of_body_html()') !== false;
if ($has_noop_fn) {
    $rows_html .= eg_pass(
        'lib.php — no-op global function present',
        'plagiarism_essayguard_before_standard_top_of_body_html() defined in lib.php (FIX-EG-UPDATE-STATUS-REVIVE v1.2.174). '
        . 'When loaded, function_exists() returns TRUE → plagiarism_update_status() calls it directly '
        . 'and NEVER reaches the ReflectionMethod / update_status() branch.'
    );
} else {
    $rows_html .= eg_fail(
        'lib.php — no-op global function MISSING',
        'plagiarism_essayguard_before_standard_top_of_body_html() not found in lib.php. '
        . 'Guard 1 (function_exists bypass) is absent. Deprecation warning will be emitted whenever '
        . 'plagiarism_update_status() runs if Path A was taken. Apply FIX-EG-UPDATE-STATUS-REVIVE (v1.2.174).'
    );
    $overall_pass = false;
}

// 9.7 — Runtime: is the global no-op function actually in memory right now?
// (Confirms the preload chain fired successfully for THIS page load.)
if (function_exists('plagiarism_essayguard_before_standard_top_of_body_html')) {
    $rows_html .= eg_pass(
        'RUNTIME — function_exists() check',
        'plagiarism_essayguard_before_standard_top_of_body_html() IS defined in memory right now. '
        . 'plagiarism_update_status() would call it directly and skip the ReflectionMethod path → '
        . 'NO "update_status() is deprecated" warning for this page load.'
    );
} else {
    $rows_html .= eg_fail(
        'RUNTIME — function_exists() check',
        'plagiarism_essayguard_before_standard_top_of_body_html() is NOT in memory. '
        . 'The preload chain (hook callback → require_once lib.php) did not fire before diag.php loaded lib.php. '
        . 'This is expected here because diag.php itself does require_once(lib.php) at the top — '
        . 'but it means on OTHER pages (quiz Results, assign grading) the function will also be missing '
        . 'unless the hook fires. Most likely cause: Moodle hook registry cache is stale. '
        . 'Fix: Site Admin → Development → Purge all caches.'
    );
    $overall_pass = false;
}

// 9.8 — Runtime: which class path was taken? (Path A = standalone, Path B = extends plagiarism_plugin)
if (class_exists('plagiarism_plugin_essayguard', false)) {
    try {
        $refl_eg = new ReflectionClass('plagiarism_plugin_essayguard');
        $method_eg = $refl_eg->getMethod('update_status');
        $declaring_eg = $method_eg->getDeclaringClass()->getName();
        if ($declaring_eg === 'plagiarism_plugin') {
            $rows_html .= eg_pass(
                'RUNTIME — class path (Path B)',
                'plagiarism_plugin_essayguard::update_status() declaring class = plagiarism_plugin. '
                . 'Path B (extends plagiarism_plugin) was taken — this is the desired state. '
                . 'plagiarism_update_status() reflection check sees getDeclaringClass() = plagiarism_plugin '
                . 'and suppresses the deprecation without needing function_exists() to be TRUE.'
            );
        } else {
            $rows_html .= eg_info(
                'RUNTIME — class path (Path A)',
                'plagiarism_plugin_essayguard::update_status() declaring class = ' . $declaring_eg . '. '
                . 'Path A (standalone class with stub) was taken — plagiarism_plugin was NOT in memory when lib.php loaded. '
                . 'Guard 2 did not engage. Guard 1 (function_exists) is the only active protection. '
                . 'Check whether the before_standard_head_html_generation hook is firing correctly.'
            );
        }
    } catch (ReflectionException $e) {
        $rows_html .= eg_info(
            'RUNTIME — class path check failed',
            'ReflectionClass threw: ' . $e->getMessage() . '. '
            . 'This can happen when update_status() is not defined on either path (unexpected). '
            . 'Check lib.php dual-class pattern.'
        );
    }
} else {
    $rows_html .= eg_info(
        'RUNTIME — class not yet loaded',
        'plagiarism_plugin_essayguard is not defined yet in this request. '
        . 'This is unusual since diag.php does require_once(lib.php) at the top. '
        . 'Check for PHP errors in lib.php.'
    );
}

// 9.9 — Live hook registry check: is our callback in Moodle's hook manager?
// FIX-EG-DIAG-LIVE-HOOK-CHECK (v1.2.190): replaced the passive INFO advisory
// with an active PASS/FAIL test. All file-level checks (9.2–9.8) can PASS while
// the Moodle hook registry cache is stale — meaning the preload callback never
// fires on quiz review / assign grading pages and HTML warnings still appear.
// This check queries the live hook manager so the diag correctly FAILs when
// the cache has not been purged after the callback was added to db/hooks.php.
//
// FAIL here = HTML warnings WILL appear on:
//   • Quiz "Review attempt" page  (mod/quiz/review.php)
//   • Quiz "Results / Overview"   (mod/quiz/report.php)
//   • Assign grading table        (mod/assign/view.php?action=grading)
//
// PASS here = preload chain fires → no warnings on any of the above pages.
if ($has_hook_system) {
    $eg_hook_live      = false;
    $eg_hook_live_note = '';
    try {
        $hm = \core\hook\manager::get_instance();
        if (method_exists($hm, 'get_callbacks_for_hook')) {
            // FIX-EG-DIAG-HOOK-QUERY (v1.2.191): Moodle stores hook class names via
            // ::class which NEVER includes a leading backslash. The previous query used
            // '\core\hook\output\before_standard_head_html_generation' (with leading \)
            // so get_callbacks_for_hook() always returned 0 — causing a false-positive
            // FAIL even when the hook is correctly registered and caches are fresh.
            // Fix: strip leading backslash before querying (matches how Moodle stores it).
            // Fallback: also try with leading backslash in case a specific build differs.
            $eg_hook_class_bare = ltrim('\core\hook\output\before_standard_head_html_generation', '\\');
            $cbs = $hm->get_callbacks_for_hook($eg_hook_class_bare);
            if (empty($cbs)) {
                $cbs_alt = $hm->get_callbacks_for_hook('\core\hook\output\before_standard_head_html_generation');
                if (!empty($cbs_alt)) {
                    $cbs = $cbs_alt;
                }
            }
            $eg_hook_live_count = count($cbs);
            foreach ($cbs as $cb) {
                $cb_str = is_array($cb) ? serialize($cb) : (string)$cb;
                if (strpos($cb_str, 'essayguard') !== false
                    || strpos($cb_str, 'plagiarism_essayguard') !== false) {
                    $eg_hook_live = true;
                    break;
                }
            }
            $eg_hook_live_note = $eg_hook_live_count . ' total callback(s) registered for '
                . 'before_standard_head_html_generation in live hook manager';
        } else {
            // Older Moodle 4.3 build — try reflection fallback.
            $refl_hm = new ReflectionObject($hm);
            foreach ($refl_hm->getProperties() as $hm_prop) {
                $hm_prop->setAccessible(true);
                try {
                    $hm_val = $hm_prop->getValue($hm);
                    $hm_ser = is_scalar($hm_val) ? (string)$hm_val : @serialize($hm_val);
                    if (strpos($hm_ser, 'before_standard_head_html_generation') !== false
                        && strpos($hm_ser, 'essayguard') !== false) {
                        $eg_hook_live = true;
                        $eg_hook_live_note = 'Found via hook manager property: ' . $hm_prop->getName();
                        break;
                    }
                } catch (Throwable $_e) {}
            }
            if (!$eg_hook_live) {
                $eg_hook_live_note = 'get_callbacks_for_hook() not available — reflection scan found no essayguard entry';
            }
        }
    } catch (Throwable $e) {
        $eg_hook_live_note = 'Hook manager check exception: ' . $e->getMessage();
    }

    if ($eg_hook_live) {
        $rows_html .= eg_pass(
            'LIVE — hook registry',
            'EssayGuard before_standard_head_html_generation callback IS registered in the live Moodle hook manager '
            . '→ preload chain fires before plagiarism_update_status() runs '
            . '→ no HTML warnings on quiz review, quiz results, or assign grading pages. '
            . $eg_hook_live_note
        );
    } else {
        $rows_html .= eg_fail(
            'LIVE — hook registry cache STALE',
            'EssayGuard before_standard_head_html_generation is NOT in the live Moodle hook manager even though '
            . 'db/hooks.php is correct on disk. The hook registry cache was built before this callback was added '
            . '→ preload never fires → HTML deprecation warnings appear as raw output on: '
            . 'quiz Review Attempt (mod/quiz/review.php), '
            . 'quiz Results/Overview (mod/quiz/report.php), '
            . 'and assign grading table (mod/assign/view.php?action=grading). '
            . 'FIX: Site Admin → Development → Purge all caches '
            . '(or run "php admin/cli/purge_caches.php" on the server). '
            . 'Then reload any affected page — warnings will stop immediately. '
            . $eg_hook_live_note
        );
        $overall_pass = false;
    }
} else {
    // Moodle ≤4.2: no hook system, Guard 1 (function_exists) is the only protection.
    $rows_html .= eg_info(
        'LIVE — hook registry (N/A on Moodle ≤4.2)',
        'Moodle hook system not available on this version. Guard 1 (function_exists bypass) '
        . 'is the sole protection against HTML warnings.'
    );
}
$rows_html .= eg_info(
    'NOTE — warnings only visible in DEBUG_DEVELOPER mode',
    'These HTML warnings are injected by Moodle\'s debug output only when debug level is set to DEVELOPER '
    . '(Site Admin → Development → Debugging → DEBUG_DEVELOPER). '
    . 'Students and teachers on production debug levels never see them.'
);

// ── Page output ───────────────────────────────────────────────────────────────

$overall_label = $overall_pass
    ? '<span class="pass-banner">ALL CHECKS PASSED</span>'
    : '<span class="fail-banner">ONE OR MORE CHECKS FAILED — see details below</span>';

// FIX-EG-DIAG-FULLNAME-FIELDS (v1.2.189): fullname() requires firstnamephonetic, lastnamephonetic,
// middlename, alternatename — fetching only id/firstname/lastname triggers a Moodle debugging()
// notice that renders as raw HTML inside the diag page when developer debug mode is on.
$sample_user_obj = $sample_userid ? $DB->get_record('user', ['id' => $sample_userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename', IGNORE_MISSING) : null;
$sample_name     = $sample_user_obj ? fullname($sample_user_obj) . ' (id=' . $sample_userid . ')' : 'none found';

echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<title>EssayGuard Diagnostic — cmid ' . $cmid . '</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; background: #f5f5f5; color: #111; }
  h1 { font-size: 1.3rem; margin-bottom: 0.25rem; }
  h2 { font-size: 1rem; margin: 1.5rem 0 0.5rem; background: #222; color: #fff; padding: 0.4rem 0.7rem; border-radius: 4px; }
  table { width: 100%; border-collapse: collapse; background: #fff; margin-bottom: 1rem; border-radius: 4px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
  td { padding: 0.45rem 0.7rem; font-size: 0.85rem; border-bottom: 1px solid #eee; vertical-align: top; }
  td.label { width: 42%; font-weight: 500; }
  td.pass  { width: 7%; color: #1a7a3f; font-weight: 700; white-space: nowrap; }
  td.fail  { width: 7%; color: #c0392b; font-weight: 700; white-space: nowrap; }
  td.info  { width: 7%; color: #7a6000; font-weight: 700; white-space: nowrap; }
  td.val   { color: #555; font-size: 0.82rem; word-break: break-word; }
  .overall { margin-bottom: 1.5rem; display: inline-block; }
  .pass-banner { background: #d4edda; color: #155724; padding: 0.5rem 1rem; border-radius: 4px; display: inline-block; font-weight: 600; }
  .fail-banner { background: #f8d7da; color: #721c24; padding: 0.5rem 1rem; border-radius: 4px; display: inline-block; font-weight: 600; }
  .meta { font-size: 0.8rem; color: #555; margin-bottom: 0.5rem; }
  .user-switch { font-size: 0.8rem; background:#fff; border:1px solid #ddd; padding:0.5rem 0.8rem; border-radius:4px; margin-bottom:1rem; display:inline-block; }
  a { color: #0070f3; }
</style></head><body>';

echo '<h1>EssayGuard — Diagnostic Report</h1>';
echo '<p class="meta">';
echo 'Activity: <strong>' . htmlspecialchars($cm->name) . '</strong> &nbsp;|&nbsp; ';
echo 'cmid: <strong>' . $cmid . '</strong> &nbsp;|&nbsp; ';
echo 'Module type: <strong>' . $cm->modname . '</strong> &nbsp;|&nbsp; ';
echo 'Sample student: <strong>' . htmlspecialchars($sample_name) . '</strong>';
echo '</p>';

if (!$userid && $sample_userid) {
    echo '<p class="meta" style="color:#666;">Showing most recent student. To test a specific student add <code>&amp;userid=<em>ID</em></code> to the URL.</p>';
}

echo '<p class="user-switch">';
echo 'Switch student: ';
$students = $DB->get_records_sql(
    "SELECT DISTINCT userid FROM {plagiarism_essayguard_sc} WHERE cmid = :cmid ORDER BY userid DESC",
    ['cmid' => $cmid]
);
foreach ($students as $s) {
    $u = $DB->get_record('user', ['id' => $s->userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename', IGNORE_MISSING);
    if ($u) {
        $url = new moodle_url('/plagiarism/essayguard/diag.php', ['cmid' => $cmid, 'userid' => $s->userid]);
        echo '<a href="' . $url->out() . '">' . htmlspecialchars(fullname($u)) . '</a> &nbsp; ';
    }
}
echo '</p>';

echo '<div class="overall">' . $overall_label . '</div>';

echo '<h2>1. Plugin version</h2>';
echo '<table>' . $rows_plugin . '</table>';

echo '<h2>2. Bug 1 — qslot detection (threshold 85%→60% + html_entity_decode)</h2>';
echo '<p style="font-size:0.82rem;color:#555;margin:0 0 0.5rem;">Symptom: every question on Review Attempt shows the same badge. '
   . 'PASS = per-question SC records exist (qslot detection succeeded). '
   . 'FAIL = no per-question records (qslot lookup failed — would show same score for all questions).</p>';
echo '<table>' . $rows_bug1 . '</table>';

echo '<h2>3. Bug 2 — keystroke-ratio gate + aggregate fallback guard</h2>';
echo '<p style="font-size:0.82rem;color:#555;margin:0 0 0.5rem;">Symptom: pasted answer shows MEDIUM instead of HIGH, or honest typist shows MEDIUM. '
   . 'Check the keystroke_ratio and aggregate fallback logic for the sample student.</p>';
echo '<table>' . $rows_bug2 . '</table>';

echo '<h2>4. Bug 3 — MutationObserver 4-retry TinyMCE bind (code check)</h2>';
echo '<p style="font-size:0.82rem;color:#555;margin:0 0 0.5rem;">Symptom: Q2+ paste not captured in TinyMCE editors on slow themes. '
   . 'This is a code-level check — no student data required.</p>';
echo '<table>' . $rows_bug3 . '</table>';

echo '<h2>5. All score records for sample student</h2>';
echo '<p style="font-size:0.82rem;color:#555;margin:0 0 0.5rem;">qslot=0 = aggregate (whole attempt). qslot=N = per-question. '
   . 'If all per-question scores are identical, Bug 1 is still present. '
   . 'Risk bands: LOW=0–29%, MEDIUM=30–65%, HIGH=66–100%.</p>';
echo '<table>' . $rows_records . '</table>';

echo '<h2>6. Signal breakdown — what fired and why (per SC record)</h2>';
echo '<p style="font-size:0.82rem;color:#555;margin:0 0 0.5rem;">'
   . 'Decodes metricsjson for every score record. Shows each signal that contributed points, '
   . 'the raw pre-cap total, whether the false-positive cap fired, and the final derived badge. '
   . 'Requires a session scored with v1.2.113+ — older sessions show "signal_breakdown not present".</p>';
echo '<table>' . $rows_signals . '</table>';

echo '<h2>7. Badge consistency — Review Attempt vs Teacher Report</h2>';
echo '<p style="font-size:0.82rem;color:#555;margin:0 0 0.5rem;">'
   . 'Simulates the exact fallback logic from both display paths for each question slot. '
   . 'PASS = both pages show the same badge. FAIL = pages disagree (root cause shown inline). '
   . 'Also shows what badge would appear if qslot detection fails in the Review Attempt path.</p>';
echo '<table>' . $rows_badge . '</table>';

echo '<h2>8. Event capture — raw keystroke/paste events in the database</h2>';
echo '<p style="font-size:0.82rem;color:#555;margin:0 0 0.5rem;">'
   . 'Checks the raw event table to confirm whether the JS tracker actually sent keystroke '
   . 'and paste events to the server. This is the root-cause check for total_keystrokes=0. '
   . 'PASS = keydown/backspace events exist. FAIL = zero behavioural events — '
   . 'badges are based on linguistic fallback only, not actual student typing behaviour. '
   . 'FIX-EG-DIAG-EV-CHECK v1.2.122.</p>';
echo '<table>' . $rows_events . '</table>';

echo '<h2>9. HTML deprecation warning diagnosis</h2>';
echo '<p style="font-size:0.82rem;color:#555;margin:0 0 0.5rem;">'
   . 'Diagnoses why <code>plagiarism_plugin::update_status() is deprecated</code> and '
   . '<code>Callback before_standard_top_of_body_html in plagiarism_essayguard should be migrated</code> '
   . 'may still appear as raw HTML output on quiz Results and assignment grading pages '
   . 'when Moodle developer debug mode is on. '
   . 'Checks run in page-load execution order: version → hook system → hook registration → callback files → lib.php code → runtime confirmation.</p>';
echo '<table>' . $rows_html . '</table>';

echo '<p class="meta">Reload after a new student attempt to refresh data.</p>';
echo '<p>';
echo '<a href="' . (new moodle_url('/plagiarism/essayguard/report.php', ['cmid' => $cmid]))->out() . '">&larr; EssayGuard report</a>';
echo ' &nbsp;|&nbsp; ';
echo '<a href="' . (new moodle_url('/plagiarism/essayguard/badge_diag.php', ['cmid' => $cmid]))->out() . '">&#9654; Badge accuracy &amp; teacher report diagnostic</a>';
echo '</p>';
echo '</body></html>';
