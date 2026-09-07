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

namespace plagiarism_essayguard\local\service;

/**
 * Behavioural Metric Scaffold + Score Engine.
 *
 * Processes raw telemetry events into a full set of behavioural metrics
 * and computes an authenticity risk score (0–100).
 *
 * Score bands (3-tier, v1.2.112 — TypeShield-aligned thresholds):
 *   0–29   → low    (Original — normal human typing behaviour)
 *   30–65  → medium (Suspicious — AI retyping, robotic rhythm, or minor paste)
 *   66–100 → high   (High — definitive paste / AI-speed text detected)
 *
 * Behavioural signals are weighted higher than linguistic signals.
 * Paste detection is the primary trigger for HIGH risk.
 * Baseline deviation adds additional risk if a student fingerprint exists.
 *
 * v1.2.219: Every error_log() in this file is now debugging(..., DEBUG_DEVELOPER).
 * error_log() writes to the webserver error log unconditionally on every scoring
 * pass — on a live exam that is one log line per student per 5-second flush, on a
 * file the site admin never asked us to write to. debugging() is the Moodle-native
 * channel: silent in production, visible when a developer turns debugging on.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analyser {
    /**
     * Score a typing session from its raw events.
     * Optionally incorporates linguistic metrics from the final text.
     *
     * v1.2.14: $qslot parameter added for per-question scoring.
     *   0 (default) = aggregate mode — all events for this userid+cmid+attemptkey are used.
     *   N > 0       = per-question mode — only events whose payload contains qslot=N are used.
     *                 This corresponds to Moodle question slot numbers (1-based).
     * Events emitted by tracker.js include qslot in their payloadjson when the textarea
     * name matches Moodle's quiz pattern "q{attemptid}:{slot}_answer". Assignment/forum
     * textareas don't match so their events carry no qslot (treated as 0 = aggregate).
     *
     * @param int    $userid             The student being scored.
     * @param int    $cmid               The course module the attempt belongs to.
     * @param int    $contextid          The module context id.
     * @param string $attemptkey         The typing session key.
     * @param array  $linguistic         Pre-computed linguistic metrics; recomputed from
     *                                   $finaltext when empty.
     * @param string $finaltext          The submitted text, truncated before analysis.
     * @param int    $qslot              Quiz question slot; 0 scores the attempt as a whole.
     * @param int    $attempttimestart  Attempt start time, for the server-side speed signal.
     * @param int    $attempttimefinish Attempt finish time, for the same signal.
     * @return array riskscore (0.0-1.0), risklevel, score100, metrics and explanations.
     */
    public static function score_attempt(
        int $userid,
        int $cmid,
        int $contextid,
        string $attemptkey,
        array $linguistic = [],
        string $finaltext = '',
        int $qslot = 0,
        int $attempttimestart = 0,
        int $attempttimefinish = 0
    ): array {
        global $DB;

        // Guard against oversized text from the PHP event observer path
        // (the JS path already trims to 50,000 chars before sending).
        if ($finaltext !== '') {
            $finaltext = mb_substr($finaltext, 0, 50000);
        }

        if (!empty($finaltext) && empty($linguistic)) {
            $linguistic = linguistic::analyse($finaltext);
        }

        $allevents = $DB->get_records(
            'plagiarism_essayguard_ev',
            [
                'userid'     => $userid,
                'cmid'       => $cmid,
                'attemptkey' => $attemptkey,
                ],
            'id ASC'
        );

        // V1.2.14: Filter to per-question events when qslot > 0.
        // In aggregate mode (qslot = 0) all events are included regardless of their
        // embedded qslot value, matching pre-v1.2.14 behaviour.
        //
        // FIX-EG-QSLOT-STRICT (v1.2.89): Use isset() instead of ?? 0 for the per-question
        // filter. The old filter `(int)($p['qslot'] ?? 0) === $qslot` correctly excluded
        // events missing the qslot key (missing → 0 → 0 !== $qslot → false). BUT there was
        // a subtle risk: if tracker.js ever accidentally emitted qslot=0 in the payload for
        // a field that should be per-question (e.g. due to parseInt('') === 0), those events
        // would satisfy the qslot=0 aggregate path AND contaminate the per-question filter
        // (0 !== N → correctly excluded — so actually fine). The real win of isset() is
        // semantic clarity and forward-safety: only events that tracker.js EXPLICITLY tagged
        // with a question slot number are counted toward that question's score. Events with
        // no qslot key belong to the aggregate, period. This mirrors the JS lock-at-bind-time
        // fix in tracker.js v1.2.89 — both sides now enforce strict qslot isolation.
        if ($qslot > 0) {
            $events = array_filter(
                $allevents,
                static function ($ev) use ($qslot) {
                    $p = json_decode($ev->payloadjson ?? '{}', true) ?: [];
                    return isset($p['qslot']) && (int)$p['qslot'] === $qslot;
                    }
            );
        } else {
            $events = $allevents;
        }

        // FIX-EG-QUE-QSLOT-FALLBACK (v1.2.74, refined in v1.2.75): When per-question
        // events are empty and NO events in the pool carry any qslot tag, this is a
        // pre-fix session where tracker.js failed to tag events with qslot (e.g.
        // Atto/TinyMCE essayguardName lookup failed before the student's first event).
        // Attempt to attribute paste events to this specific question using text-length
        // matching: a paste event whose insertlen is close to the submitted text length
        // is very likely the paste that produced this answer.
        //
        // v1.2.74 matching criteria (80–110 % band) was too wide and caused two bugs:
        // • Non-paste events were included unconditionally → all questions shared the
        // same keystroke pool → identical timing signals → identical base scores.
        // • The 20 % lower slack allowed Q1's paste (200 chars) to match Q2's text
        // (190 chars): 200 ≤ 190×1.10=209 AND 200 ≥ 190×0.80=152 → both pass.
        //
        // v1.2.75 fix: non-paste events excluded; band tightened to 90–110 %.
        // See FIX-EG-QSLOT-FALLBACK-V2 below for full rationale.
        // FIX-EG-PERQ-UNTAGGED-PASTE (v1.2.99): Per-question events exist (qslot-tagged
        // keystrokes correctly captured by bindField), but the paste event was stored
        // WITHOUT a qslot tag because TinyMCE's doc-level capture handler could not
        // resolve the question slot at paste time (body.dataset.essayguardQslot unset).
        //
        // Result under v1.2.98: per-question filter (isset($p['qslot']) && qslot===N)
        // correctly excluded the untagged paste → pastecount=0, large_inserts=0 for
        // qslot=N → Signal 1 (+60) never fires → score ~44% from speed/pause signals.
        // Aggregate (qslot=0 pool includes all events) correctly detected paste → 80%+.
        // lib.php FIX-EG-PERQ-TRUST shows per-question (44%) because it's non-zero.
        //
        // Fix: when the per-question event pool is non-empty but contains zero paste/
        // drop_paste events, search $all_events for untagged paste events (no qslot key)
        // and include any whose insertlen is within 90–110 % of the finaltext length.
        // Same band logic as FIX-EG-QSLOT-FALLBACK-V2/V3 — attribute only the paste
        // that matches this specific question's text, preventing cross-question bleed.
        if ($qslot > 0 && !empty($events)) {
            $haspasteinqslot = false;
            foreach ($events as $ev) {
                /* The large_insert event is also a paste proxy (TinyMCE input-event fallback). */
                if (
                    $ev->eventname === 'paste' || $ev->eventname === 'drop_paste'
                        || $ev->eventname === 'large_insert'
                ) {
                    $haspasteinqslot = true;
                    break;
                }
            }
            if (!$haspasteinqslot) {
                // FIX-EG-ENTITY-DECODE (v1.2.101): strip_tags() removes HTML tags but
                // does NOT decode HTML entities (&amp; → &, &lt; → <, &nbsp; → space, etc.).
                // TinyMCE stores content with entities, so mb_strlen(strip_tags($finaltext))
                // overcounts by 4–5 chars per special character. A pasted answer with even
                // a few ampersands (e.g. "A&B", "cats & dogs") causes the ratio to fall
                // outside the 90–110 % band, silently dropping the paste attribution and
                // leaving the per-question score at ~44 % from speed/pause signals alone.
                // Fix: decode entities (ENT_QUOTES | ENT_HTML5, UTF-8) before counting so
                // the PHP character count matches the JS clipboard text.length.
                // Band also widened from 90–110 % to 70–140 % to absorb minor TinyMCE
                // whitespace normalisation (paragraph wrapping, line-ending conversion).
                $textcharspfix = ($finaltext !== '')
                    ? (int)mb_strlen(html_entity_decode(strip_tags($finaltext), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                    : 0;
                foreach ($allevents as $key => $ev) {
                    if (
                        $ev->eventname !== 'paste' && $ev->eventname !== 'drop_paste'
                            && $ev->eventname !== 'large_insert'
                    ) {
                        continue; // Only attribute paste/drop_paste/large_insert events here.
                    }
                    $p = json_decode($ev->payloadjson ?? '{}', true) ?: [];
                    if (isset($p['qslot']) && (int)$p['qslot'] > 0) {
                        continue; // Already tagged for a specific question — not untagged.
                    }
                    // FIX-EG-MIXED-BAND (v1.2.104): Lower bound reduced from 0.70 → 0.10.
                    // The 70 % floor was designed for whole-answer pastes (insertlen ≈ 100 %
                    // of text). For mixed sessions (student typed part + pasted part), the
                    // pasted portion is only a fraction of the total text — commonly 20–60 %.
                    // The 70 % floor excluded these partial pastes, leaving pastecount=0 for
                    // the per-question record and producing a false LOW · 0 % badge.
                    // Fix: 10 % lower bound retains cross-question bleed protection (a paste
                    // from Q1 that is < 10 % of Q2's text length is very unlikely to belong
                    // to Q2) while correctly attributing partial pastes in mixed sessions.
                    // Use delta field for large_insert events (clipboard text length proxy).
                    $plen = ($ev->eventname === 'large_insert')
                        ? (int)($p['delta'] ?? 0)
                        : (int)($p['insertlen'] ?? 0);
                    if ($textcharspfix > 0 && $plen > 0) {
                        $ratiook = ($plen <= $textcharspfix * 1.40)
                                 && ($plen >= $textcharspfix * 0.10);
                        if (!$ratiook) {
                            continue; // Length mismatch — paste belongs to another question.
                        }
                    }
                    /* When plen=0 or finaltext is unknown: include (evidence of paste exists). */
                    $events[$key] = $ev;
                }
                ksort($events); // Restore id/time order after inserting untagged pastes.
                debugging(
                    '[EssayGuard] FIX-EG-PERQ-UNTAGGED-PASTE: checked untagged pastes'
                        . ' for qslot=' . $qslot . ' text_chars=' . $textcharspfix
                        . ' events_after=' . count($events),
                    DEBUG_DEVELOPER
                );
            }
        }

        /* FIX-EG-QUE-QSLOT-FALLBACK (existing — runs only when events is EMPTY): */
        if ($qslot > 0 && empty($events) && !empty($allevents)) {
            $hasanyqslottag = false;
            foreach ($allevents as $ev) {
                $p = json_decode($ev->payloadjson ?? '{}', true) ?: [];
                if ((int)($p['qslot'] ?? 0) > 0) {
                    $hasanyqslottag = true;
                    break;
                }
            }

            if (!$hasanyqslottag) {
                // FIX-EG-QSLOT-FALLBACK-V2 (v1.2.75): Pre-fix session — no qslot tags in
                // any event. The v1.2.74 fallback included ALL non-paste events from the
                // entire attempt for every question. This caused two overlapping bugs:
                //
                // 1. Shared keystroke pool: every question received the same keydown /
                // input / timing events, so speed, pause-count and backspace-ratio
                // signals were identical for Q1 and Q2.  Result: identical base scores.
                //
                // 2. Wide paste band (80–110 %): if Q1 (copy-pasted, 200 chars) and Q2
                // (typed, 190 chars) have similar text lengths, Q1's paste
                // (insertlen ≈ 200) satisfied Q2's band too
                // (200 ≤ 190×1.10=209 AND 200 ≥ 190×0.80=152 → both in range).
                // Result: Q1's paste was attributed to BOTH questions → identical HIGH.
                //
                // Fix: include ONLY paste events (attribute each by text-length match with
                // a tighter 90–110 % band).  Non-paste events are excluded — without qslot
                // tags we cannot tell which keystrokes belong to which question, so sharing
                // them produces meaningless (and identical) per-question scores.
                //
                // Outcome:
                // Q1 (paste, text length ≈ insertlen) → paste included → HIGH score.
                // Q2 (typed, no paste or paste length outside 90–110 % band) → 0 events
                // → score = 0 → LOW badge.  Correctly distinguishes the two questions.
                //
                // Trade-off: typing-rhythm signals (speed, pauses) are unavailable for
                // pre-fix sessions.  Only paste detection differentiates questions.  This
                // is acceptable: sessions with proper qslot tagging (v1.2.74+ tracker) use
                // the full signal set; the fallback only applies to older sessions.
                // FIX-EG-ENTITY-DECODE (v1.2.101): apply html_entity_decode before
                // mb_strlen so TinyMCE entity-encoded chars (&amp;, &lt;, &nbsp;…)
                // are counted as single characters, matching the JS clipboard text.length.
                // Band widened from 90–110 % → 70–140 % (same rationale as FIX-EG-PERQ-
                // UNTAGGED-PASTE block above).
                $textcharsearly = ($finaltext !== '')
                    ? (int)mb_strlen(html_entity_decode(strip_tags($finaltext), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                    : 0;
                $events = [];
                foreach ($allevents as $key => $ev) {
                    if (
                        $ev->eventname !== 'paste' && $ev->eventname !== 'drop_paste'
                            && $ev->eventname !== 'large_insert'
                    ) {
                        // Non-paste events excluded: cannot attribute to a specific question
                        // without qslot tags — sharing them makes all questions score identically.
                        continue;
                    }
                    // FIX-EG-PASTE-FALLBACK-V3 (v1.2.86): apply the insertlen
                    // band ONLY when both sides are reliably known.
                    //
                    // FIX-EG-MIXED-BAND (v1.2.104): Lower bound reduced from 0.70 → 0.10
                    // (same rationale as FIX-EG-PERQ-UNTAGGED-PASTE block above).
                    // large_insert events carry delta instead of insertlen.
                    $p = json_decode($ev->payloadjson ?? '{}', true) ?: [];
                    $plen = ($ev->eventname === 'large_insert')
                        ? (int)($p['delta'] ?? 0)
                        : (int)($p['insertlen'] ?? 0);
                    if ($textcharsearly > 0 && $plen > 0) {
                        $ratiook = ($plen <= $textcharsearly * 1.40)
                                 && ($plen >= $textcharsearly * 0.10);
                        if (!$ratiook) {
                            continue; // Verifiable length mismatch — skip this paste.
                        }
                    }
                    /* When plen=0 or the text is unknown: include paste (evidence of paste exists). */
                    $events[$key] = $ev;
                }
            }
        }

        // FIX-EG-PARTIAL-PASTE-FALLBACK (v1.2.105): Handles the gap left by the two
        // existing fallback paths:
        //
        // • FIX-EG-PERQ-UNTAGGED-PASTE  — only runs when $events is NON-EMPTY after
        // the strict qslot filter (i.e. the question has at least some tagged events).
        // • FIX-EG-QUE-QSLOT-FALLBACK   — only runs when $events is EMPTY *and*
        // $has_any_qslot_tag is FALSE.
        //
        // Gap: when THIS question has no qslot-tagged events ($events==[]) but at least
        // one OTHER question's events carry qslot tags ($has_any_qslot_tag==TRUE), both
        // fallbacks are skipped.  Result: $events stays empty, all counters are 0, and
        // the question scores LOW regardless of how much the student pasted.
        //
        // This arises in mixed-session quizzes where tracker.js tagged keystrokes for Q1
        // but could not tag the paste event (or any event) for Q2.
        //
        // Fix: when $events is still empty for a per-question slot, perform a two-tier
        // search of $all_events for untagged paste events:
        //
        // Tier 1 — full-answer paste (insertlen 70 %–140 % of finaltext):
        // Strong evidence the whole answer was pasted (or very close to it).
        // Assign these to this question; $partial_paste_only stays false so Signal 1
        // awards the full +60 and $is_paste_session fires S5/S7.
        //
        // Tier 2 — partial paste (insertlen 10 %–69 % of finaltext):
        // The student typed some content and pasted the rest.
        // Assign these; set $partial_paste_only=true so Signal 1 uses the
        // proportional formula (max(30, 60×paste_frac)) and $is_paste_session is
        // suppressed (preventing S5/S7 pure-paste bonuses for a mixed session).
        //
        // The 70 % split between tiers prevents full-answer pastes from another question
        // (whose text length happens to be similar to this one) from bleeding into the
        // Tier-2 search; and the 85 % upper bound on Tier 2 prevents full-answer pastes
        // from bleeding into Tier-2 attribution.
        // Untagged full-answer pastes from OTHER questions (ratio > 0.85 to this question's
        // text) are excluded from Tier 2 entirely — they belong to the question they were
        // copied into, not here.
        $partialpasteonly = false;
        if ($qslot > 0 && empty($events) && !empty($allevents)) {
            $textcharsppf = ($finaltext !== '')
                ? (int)mb_strlen(html_entity_decode(strip_tags($finaltext), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                : 0;
            if ($textcharsppf > 0) {
                $ppffull    = [];  // Tier 1: ratio 0.70–1.40 (full-answer paste).
                $ppfpartial = [];  // Tier 2: ratio 0.10–0.69 (partial paste).
                foreach ($allevents as $key => $ev) {
                    if (
                        $ev->eventname !== 'paste' && $ev->eventname !== 'drop_paste'
                            && $ev->eventname !== 'large_insert'
                    ) {
                        continue;
                    }
                    $p = json_decode($ev->payloadjson ?? '{}', true) ?: [];
                    if (isset($p['qslot']) && (int)$p['qslot'] > 0) {
                        continue; // Tagged for another question — not untagged.
                    }
                    $plen = ($ev->eventname === 'large_insert')
                        ? (int)($p['delta'] ?? 0)
                        : (int)($p['insertlen'] ?? 0);
                    if ($plen > 0) {
                        $ratio = $plen / $textcharsppf;
                        if ($ratio >= 0.70 && $ratio <= 1.40) {
                            $ppffull[$key] = $ev;
                        } else if ($ratio >= 0.10 && $ratio < 0.70) {
                            $ppfpartial[$key] = $ev;
                        }
                        // Ratio < 0.10 or ratio > 1.40 → too far off, skip.
                    } else {
                        /* When plen=0 the size is unknown - conservatively count as full-answer. */
                        $ppffull[$key] = $ev;
                    }
                }
                if (!empty($ppffull)) {
                    // Tier 1 hit: treat as full-answer paste.
                    $events = $ppffull;
                    debugging(
                        '[EssayGuard] FIX-EG-PARTIAL-PASTE-FALLBACK tier1:'
                            . ' qslot=' . $qslot . ' found=' . count($ppffull)
                            . ' text_chars=' . $textcharsppf,
                        DEBUG_DEVELOPER
                    );
                } else if (!empty($ppfpartial)) {
                    // Tier 2 hit: partial paste — mixed session.
                    $events = $ppfpartial;
                    $partialpasteonly = true;
                    debugging(
                        '[EssayGuard] FIX-EG-PARTIAL-PASTE-FALLBACK tier2:'
                            . ' qslot=' . $qslot . ' found=' . count($ppfpartial)
                            . ' text_chars=' . $textcharsppf,
                        DEBUG_DEVELOPER
                    );
                }
            }
        }

        // V1.2.224 FIX-EG-CONFIG-ZERO: `?:` fires on a legitimate 0 as well as on the
        // unset key, so an admin who deliberately set minchars to 0 (score every burst,
        // however short) silently got 120 instead. The paste_weight block immediately
        // below already documents this exact trap; these two lines were left behind.
        //
        // Written inline rather than calling plagiarism_essayguard_config_int() from
        // lib.php: this class is autoloaded and reached from the observer, the scheduled
        // tasks and the external functions, and Moodle does NOT autoload a plugin's
        // lib.php. Depending on it being loaded is how v1.2.222's "Call to undefined
        // function plagiarism_essayguard_check_unlock()" fatal happened.
        $mincharsraw      = get_config('plagiarism_essayguard', 'minchars');
        $maxburstcharsraw = get_config('plagiarism_essayguard', 'maxburstchars');
        $minchars      = ($mincharsraw === false || $mincharsraw === null || $mincharsraw === '')
            ? 120 : (int)$mincharsraw;
        $maxburstchars = ($maxburstcharsraw === false || $maxburstcharsraw === null || $maxburstcharsraw === '')
            ? 150 : (int)$maxburstcharsraw;
        // PASTE-WEIGHT (v1.2.212): Admin-configurable multiplier (0–100 %) applied to
        // Signal 1 (paste/drop) and Signal 2 (large_insert) scores. Default 100 % preserves
        // existing behaviour. RTOs whose students write offline and paste answers should
        // reduce this (e.g. 25 %) so paste-only sessions score MEDIUM rather than HIGH.
        // v1.2.219: PASTE-DETECTION-DISABLED-ON-FRESH-INSTALL. The v1.2.212 code read
        // (int)(get_config(...) ?? 100)
        // which is broken. Moodle's get_config() returns bool FALSE — not null — for a
        // key that has never been written, and ?? only fires on null. So on any site
        // that never saved the Essay Guard settings page, the expression evaluated to
        // (int)false = 0, paste_weight became 0.0, and Signals 1 and 2 were multiplied
        // to zero. A student pasting an entire AI-written essay scored LOW, silently,
        // on every fresh install. Explicitly test for the "never saved" sentinels and
        // fall back to 100 % only then, so an admin who deliberately sets 0 still gets 0.
        $pasteweightraw = get_config('plagiarism_essayguard', 'paste_weight');
        $pasteweightpct = ($pasteweightraw === false || $pasteweightraw === null || $pasteweightraw === '')
            ? 100
            : (int)$pasteweightraw;
        $pasteweightpct = max(0, min(100, $pasteweightpct));
        $pasteweight     = $pasteweightpct / 100.0;

        /* --- Raw counters --- */
        $charsadded        = 0;
        $pastecharstotal = 0; // Sum of insertlen from paste events (fallback for gate).
        $pastecount        = 0;
        $burstsuspicious   = 0;
        $backspaces      = 0;
        $deletes         = 0;
        $cursormoves    = 0;
        $totalkeystrokes = 0;
        $pausecount      = 0;
        $longpauses      = 0;
        // FIX-EG-LARGE-INSERT (v1.2.73): count of large_insert events (delta > 20 chars).
        $largeinserts       = 0;
        $largeinsertmaxdelta = 0;

        /* --- Inter-key delays --- */
        $ikdelays = [];

        /* --- Pause lists (ms) --- */
        $pausesall   = [];
        $pausesmajor = [];

        /* --- Burst data --- */
        $burstwordslist = [];

        // V1.2.224 FIX-EG-PASTE-DOUBLE-COUNTED: a browser fires BOTH a 'paste' event and
        // an 'input' event for one clipboard insertion, and each branch below compared
        // its own insertlen against maxburstchars, so a single paste incremented
        // burstsuspicious twice. The teacher-facing explain_burst string then reported
        // "2 suspicious bursts" for one action. Remember the last paste so the input
        // event it generates is not counted a second time.
        $lastpastetime = null;
        $lastpastelen  = 0;

        /* --- WPM snapshots --- */
        $wpmsnapshots = [];

        /* --- Session time --- */
        $typingtime = 0;
        $idletime   = 0;

        $prevtime = null;
        $sessionstart = null;
        $sessionend   = null;

        foreach ($events as $event) {
            $payload  = json_decode($event->payloadjson ?? '{}', true) ?: [];
            $evtime   = (int)$event->eventtime;

            if ($sessionstart === null) {
                $sessionstart = $evtime;
            }
            $sessionend = $evtime;

            if ($prevtime !== null) {
                $delta = max(0, $evtime - $prevtime);
                if ($delta > 500) {
                    $pausesall[] = $delta;
                }
                if ($delta > 2000) {
                    $pausecount++;
                    $pausesmajor[] = $delta;
                    $idletime += $delta;
                } else {
                    $typingtime += $delta;
                }
                if ($delta > 10000) {
                    $longpauses++;
                }
            }
            $prevtime = $evtime;

            switch ($event->eventname) {
                case 'keydown':
                    $totalkeystrokes++;
                    $ikd = isset($payload['ikd']) ? (int)$payload['ikd'] : null;
                    if ($ikd !== null && $ikd > 0 && $ikd < 5000) {
                        $ikdelays[] = $ikd;
                    }
                    break;

                case 'backspace':
                    $backspaces++;
                    $totalkeystrokes++;
                    $ikd = isset($payload['ikd']) ? (int)$payload['ikd'] : null;
                    if ($ikd !== null && $ikd > 0 && $ikd < 5000) {
                        $ikdelays[] = $ikd;
                    }
                    break;

                case 'delete':
                    $deletes++;
                    $totalkeystrokes++;
                    // BUG-EG-IKD-DELETE (v1.2.53): Extract inter-key delay from delete events
                    // — identical to how backspace events are handled. The tracker sends ikd
                    // in the delete event payload but the analyser was discarding it, reducing
                    // the number of data points for interkey_mean / entropy_score analysis.
                    $ikd = isset($payload['ikd']) ? (int)$payload['ikd'] : null;
                    if ($ikd !== null && $ikd > 0 && $ikd < 5000) {
                        $ikdelays[] = $ikd;
                    }
                    break;

                case 'input':
                    $charsadded += (int)($payload['addedchars'] ?? 0);
                    $iinsertlen  = (int)($payload['insertlen'] ?? 0);

                    // V1.2.224: `>= $maxburstchars` alone made every input event
                    // suspicious when an administrator set maxburstchars to 0 - including
                    // events carrying no insertlen at all, because 0 >= 0. A burst of
                    // nothing is not a burst.
                    $isburst = ($iinsertlen > 0 && $iinsertlen >= $maxburstchars);

                    // The input event a paste generates arrives immediately after it with
                    // the same length; that pair is one action, already counted.
                    $frompaste = ($lastpastetime !== null
                        && ($evtime - $lastpastetime) <= 500
                        && $iinsertlen === $lastpastelen);

                    if ($isburst && !$frompaste) {
                        $burstsuspicious++;
                    }
                    break;

                case 'paste':
                    $pastecount++;
                    $pinsertlen = (int)($payload['insertlen'] ?? 0);
                    if ($pinsertlen > 0 && $pinsertlen >= $maxburstchars) {
                        $burstsuspicious++;
                    }
                    // V1.2.224: recorded so the input event this paste generates is not
                    // counted as a second burst. See FIX-EG-PASTE-DOUBLE-COUNTED above.
                    $lastpastetime = $evtime;
                    $lastpastelen  = $pinsertlen;
                    // Credit paste insertlen toward the minchars gate as a fallback.
                    // In normal flow the subsequent input event also adds to $charsadded,
                    // but in Atto/TinyMCE environments the input event may not fire before
                    // the first flush — this ensures the gate never silently blocks
                    // paste-only sessions even if the input event is delayed or missing.
                    $pastecharstotal += $pinsertlen;
                    break;

                case 'drop_paste':
                    $pastecount++;
                    $burstsuspicious++;
                    break;

                case 'large_insert':
                    // FIX-EG-LARGE-INSERT (v1.2.73): emitted by tracker.js when any
                    // input event delta > 20 chars — catches medium-sized pastes that
                    // don't meet the maxburstchars threshold.
                    $largeinserts++;
                    $d = (int)($payload['delta'] ?? 0);
                    if ($d > $largeinsertmaxdelta) {
                        $largeinsertmaxdelta = $d;
                    }
                    break;

                case 'burst_end':
                    $wc = (int)($payload['wordcount'] ?? 0);
                    if ($wc > 0) {
                        $burstwordslist[] = $wc;
                    }
                    break;

                case 'selection':
                    $cursormoves += (int)($payload['cursormoves'] ?? 1);
                    break;

                case 'wpm_snapshot':
                    $wpm = (int)($payload['wpm'] ?? 0);
                    if ($wpm > 0) {
                        $wpmsnapshots[] = $wpm;
                    }
                    break;
            }
        }

        /* --- Derived metrics --- */
        $interkeymean    = !empty($ikdelays) ? array_sum($ikdelays) / count($ikdelays) : 0.0;
        $interkeystddev = !empty($ikdelays) ? self::std_dev($ikdelays) : 0.0;
        $entropyscore    = self::entropy_from_sd($interkeystddev);

        // V1.2.224 FIX-EG-CONSTANT-RHYTHM-READS-LOW: entropy_score = 0.0 has meant two
        // opposite things, and every consumer read it as the harmless one.
        //
        // (a) There were no inter-key delays to measure - a paste, a session the
        // editor swallowed - so there is NO rhythm data.
        // (b) There were plenty of delays and every one of them was identical, so the
        // standard deviation is 0 - a PERFECTLY CONSTANT rhythm.
        //
        // (b) is the textbook signature of automated input: a script typing a stored
        // answer at a fixed interval. Signal 7 is gated on `$entropy_score > 0`, so (b)
        // scored nothing; and the paste-gate cap below treats `=== 0.0` as "unavailable"
        // and caps the whole session at 29. Measured: 200 keystrokes exactly 100ms apart
        // scored 29 / LOW. The one input pattern a human cannot produce was the one the
        // scorer was most confident about.
        //
        // The sample floor matches the Shannon-entropy gate a few lines down (>= 30
        // delays): below that a run of equal intervals is chance, not a signature.
        $hasrhythmdata = (count($ikdelays) >= 30);

        // TypeShield-matched derived signals (v1.2.112).
        // Shannon IKI entropy: measures whether keystroke intervals cluster in
        // very few timing bands (low entropy = robotic). TypeShield threshold < 0.35.
        $ikishannon  = self::iki_shannon_entropy($ikdelays);
        // Autocorrelation lag-1: human baseline ≈ 0.1; highly repetitive or
        // artificially jittered rhythms deviate significantly. TypeShield flags
        // |autocorr − 0.1| > 0.5.
        $ikiautocorr = self::iki_autocorr_lag1($ikdelays);
        // Speed-burst CV: human typing speed is highly variable; constant rate
        // (low CV < 0.3) indicates robotic or AI-streamed content.
        $speedcv     = self::speed_burst_cv($wpmsnapshots);

        $averagewpm  = !empty($wpmsnapshots) ? array_sum($wpmsnapshots) / count($wpmsnapshots) : 0.0;
        $wpmstddev  = !empty($wpmsnapshots) ? self::std_dev($wpmsnapshots) : 0.0;

        // BUG-EG-WPM-FALLBACK (v1.2.53): When no wpm_snapshot events exist (student typed
        // for < 60 s without triggering the periodic window), estimate WPM from the final
        // submitted text word count and the measured typing time.
        // BUG-EG-WPM-ZERO (v1.2.53): The blur-based wpm_snapshot in tracker.js captures WPM
        // for short sessions — this fallback covers the PHP observer path (no JS snapshots)
        // and the rare case where blur fired but the snapshot payload was 0.
        if ($averagewpm <= 0.0 && $typingtime > 0 && $finaltext !== '') {
            $wordcount = preg_match_all('/\b\w+\b/', strip_tags($finaltext));
            if ($wordcount > 0) {
                $typingminutes = $typingtime / 60000.0;
                if ($typingminutes > 0) {
                    $averagewpm = round($wordcount / $typingminutes, 2);
                }
            }
        }

        $pausemean    = !empty($pausesmajor) ? array_sum($pausesmajor) / count($pausesmajor) : 0.0;
        $pausestddev = !empty($pausesmajor) ? self::std_dev($pausesmajor) : 0.0;

        $burstcount   = count($burstwordslist);
        $burstmean    = $burstcount > 0 ? array_sum($burstwordslist) / $burstcount : 0.0;
        $burststddev = $burstcount > 0 ? self::std_dev($burstwordslist) : 0.0;

        $backspaceratio  = $totalkeystrokes > 0 ? $backspaces / $totalkeystrokes : 0.0;

        $thinkingpausecount = 0;
        foreach ($pausesall as $p) {
            if ($p >= 800 && $p <= 2000) {
                $thinkingpausecount++;
            }
        }
        $thinkingpausescore = $pausecount > 0
            ? $thinkingpausecount / max(1, count($pausesall))
            : 0.0;

        // Linguistic metrics (if available).
        $sentencevariance = (float)($linguistic['sentence_variance'] ?? 0.0);
        $vocabdiversity   = (float)($linguistic['vocab_diversity'] ?? 0.0);
        $rarewordratio   = (float)($linguistic['rare_word_ratio'] ?? 0.0);
        // FIX-EG-SINGLE-SENTENCE (v1.2.140): sentence_count is needed to gate the
        // linguistic fallback correctly for single-sentence answers.
        $sentencecountling = (int)($linguistic['sentence_count'] ?? 0);

        /* --- Derived: typing speed in chars/sec across the full session --- */
        // Used by Signal 3 to detect AI-speed text generation.
        $sessiontotalms = ($sessionend !== null && $sessionstart !== null)
            ? max(1, $sessionend - $sessionstart) : 1;

        // FIX-EG-ENTITY-DECODE-TEXTCHARS (v1.2.105): strip_tags() leaves HTML entities
        // (&amp; &lt; &nbsp; etc.) intact.  TinyMCE stores content with entities, so
        // mb_strlen(strip_tags($finaltext)) overcounts relative to the JS clipboard
        // text.length used for $paste_chars_total.  This inflates $text_chars, depresses
        // $paste_frac in the FIX-EG-MIXED-PROPORTIONAL formula, and can push a pure-paste
        // session's Signal 1 below the value needed to reach HIGH.
        // Fix: apply html_entity_decode (same as $text_chars_pfix / $text_chars_early).
        //
        // FIX-EG-TEXTCHARS-FORWARD-REF (v1.2.113): Moved here from inside the score
        // engine (was after the $metrics array) so that $metrics['text_chars'] records
        // the correct entity-decoded length instead of always storing 0. The stored
        // metricsjson value is read by explainer.php for the Signal 12 keystroke-ratio
        // display; with the forward reference, Signal 12 always showed "n/a" even when
        // keystroke data was available.
        $textchars = ($finaltext !== '')
            ? (int)mb_strlen(html_entity_decode(strip_tags($finaltext), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : 0;

        /* --- Full metrics array --- */
        $metrics = [
            'charsadded'             => $charsadded,
            'pastecount'             => $pastecount,
            'paste_events'           => $pastecount,
            'large_inserts'          => $largeinserts,
            'large_insert_max_delta' => $largeinsertmaxdelta,
            'burstsuspicious'        => $burstsuspicious,
            'backspaces'             => $backspaces,
            'backspace_count'        => $backspaces,
            'delete_count'           => $deletes,
            'cursor_moves'           => $cursormoves,
            'total_keystrokes'       => $totalkeystrokes,
            'pausecount'             => $pausecount,
            'pause_count'            => $pausecount,
            'longpauses'             => $longpauses,
            'typing_time'            => $typingtime,
            'idle_time'              => $idletime,
            'average_wpm'            => round($averagewpm, 2),
            'wpm_std_dev'            => round($wpmstddev, 2),
            'interkey_mean'          => round($interkeymean, 2),
            'interkey_std_dev'       => round($interkeystddev, 2),
            'pause_mean'             => round($pausemean, 2),
            'pause_std_dev'          => round($pausestddev, 2),
            'burst_count'            => $burstcount,
            'burst_mean'             => round($burstmean, 2),
            'burst_std_dev'          => round($burststddev, 2),
            'backspace_ratio'        => round($backspaceratio, 4),
            'thinking_pause_score'   => round($thinkingpausescore, 4),
            'entropy_score'          => round($entropyscore, 4),
            'iki_shannon'            => round($ikishannon, 4),
            'iki_autocorr'           => round($ikiautocorr, 4),
            'speed_burst_cv'         => round($speedcv, 4),
            'sentence_variance'      => $sentencevariance,
            'vocab_diversity'        => $vocabdiversity,
            'rare_word_ratio'        => $rarewordratio,
            'avgdelta'               => round($interkeymean, 2),
            'revisionratio'          => round($backspaceratio, 4),
            // V1.2.113: stored so explainer.php can compute Signal 12 keystroke ratio
            // without approximation (text_chars = final submitted text length after
            // HTML entity decode, the same value used by the Signal 12 gate).
            'text_chars'             => $textchars,

            // ----------------------------------------------------------------
            // V1.2.225 FIX-EG-EXPLAINER-GATE-MISMATCH
            //
            // explainer.php has to decide, from this array alone, whether a signal fired.
            // Several of the analyser's gates turn on values that were never stored, so
            // the explainer approximated them - and approximated them differently. The
            // result is a signal that scores points with no sentence beside it, or a
            // sentence beside a signal that did not score:
            //
            // - Signals 7 and 10 require at least 30 inter-key delays. The explainer
            // could not count them, so Signal 10's rule fired on any non-zero
            // autocorrelation, including sessions with three keystrokes.
            // - v1.2.224 made an entropy of exactly 0.0 the STRONGEST rhythm evidence
            // when there is real data behind it, but 0.0 also means "nothing was
            // measured". Without has_rhythm_data the explainer cannot tell those
            // apart, so it excludes 0.0 and stays silent on the most suspicious
            // rhythm the plugin can detect.
            // - The single-sentence linguistic fallback scores at a variance of exactly
            // 0.0, which again is indistinguishable from "no sentences measured"
            // without the sentence count.
            // - Signal 4 fires on max(charsadded, text_chars); the explainer only had
            // charsadded, so a TinyMCE paste scored +20 for having no thinking pauses
            // with no sentence explaining it.
            //
            // Storing the four deciding values lets the explainer use the analyser's own
            // gates rather than a guess at them. They are small integers and booleans; the
            // metrics blob already carries forty-odd fields.
            //
            // Records written before v1.2.225 do not have these keys. Every consumer must
            // treat a missing key as "unknown" and fall back to its previous behaviour -
            // see the ?? defaults in explainer.php - so an old record explains exactly as
            // it did before and a new one explains correctly.
            'ikdelay_count'          => count($ikdelays),
            'has_rhythm_data'        => $hasrhythmdata,
            'sentence_count'         => $sentencecountling,
            // Computed inline rather than from $effective_charsadded, which is assigned
            // below this array: max($charsadded, $paste_chars_total) IS
            // $effective_charsadded, and both inputs are final by this point.
            's4_chars'               => max(max($charsadded, $pastecharstotal), $textchars),
        ];

        // FIX-EG-S8-DEDUP (v1.2.124): Compute $events_empty_for_scoring HERE —
        // before the signal engine — so Signals 8 and 9 can be gated to prevent
        // double-counting with the linguistic fallback block below.
        //
        // Previously this was computed AFTER the signal engine (at the linguistic
        // fallback block). When no events were captured but text was present:
        // • $text_gate = true → the signal engine ran → S8 fired (+10 pts)
        // • Linguistic fallback also fired for the same sentence_variance (+20 pts)
        // → sentence_variance was counted TWICE, inflating no-event scores by 10 pts
        // (S8) + 0–15 pts (S9 vocab) for a total overcounting of up to 25 pts.
        //
        // With the computation moved here, Signals 8 and 9 are gated with
        // !$events_empty_for_scoring: they only fire in regular (events-present)
        // sessions, while the linguistic fallback handles the no-events case with its
        // own amplified weights (designed to push AI-uniform text into MEDIUM range).
        $eventsemptyforscoring = empty($allevents)
            || (empty($events) && $qslot > 0 && !empty($allevents));

        /* --- 0-100 Score Engine (v1.2.112 — TypeShield-aligned thresholds) --- */
        //
        // Design goals:
        // REAL TYPING  → LOW    (0–29):  natural speed, pauses, corrections
        // FAST/AI      → MEDIUM (30–65): no pauses/corrections, robotic rhythm
        // PASTE        → HIGH   (66+):   paste detected → 60 pts + Signals 5+7 = 85+
        // MIXED        → MEDIUM or HIGH depending on other signals.
        $score = 0.0;

        // V1.2.113: Per-signal point tracker. Keys 1–12 = signal number; values =
        // points contributed by that signal. Stored in metricsjson as 'signal_breakdown'
        // so student.php can display a TypeShield-quality per-signal attribution table.
        // Reflects raw contributions before the false-positive cap; the final score100
        // may be lower than array_sum($signal_pts) when the cap fires.
        $signalpts = [];

        // FIX-EG-METRICS-CPS-PASTEFRAC (v1.2.124): Initialise here so they are always
        // defined and can be stored in metricsjson even if the scoring gate doesn't fire.
        $charspersec = 0.0;
        $pastefracraw = 0.0;

        // Use paste insertlen as a fallback for the minchars gate.
        $effectivecharsadded = max($charsadded, $pastecharstotal);

        // ------------------------------------------------------------------------
        // V1.2.225 FIX-EG-PASTEWEIGHT-UNREACHABLE
        //
        // paste_weight is documented as: "RTOs whose students write offline and paste
        // answers should reduce this (e.g. 25 %) so paste-only sessions score MEDIUM
        // rather than HIGH." It could not do that at any value, including 0.
        //
        // The multiplier reached Signals 1 and 2 only. But in a pure-paste session,
        // Signals 3, 4, 5, 6 and 7 are not independent evidence - they are five more
        // descriptions of the SAME single paste, and each says so in its own comment:
        //
        // S3  chars-per-second is enormous because 500 characters arrived at once
        // S4  "paste produces 0 pauses by definition"
        // S5  "a paste-only session has no corrections at all"
        // S6  content "appeared almost instantly, consistent with paste"
        // S7  "Pure paste: no rhythm data = max entropy suspicion"
        //
        // All five are unweighted, so they alone total 100 points. Measured before this
        // fix: paste_weight = 0, one 500-character paste, breakdown
        // {1:0, 3:30, 4:20, 5:15, 6:25, 7:10} = 100, HIGH. An administrator who set the
        // multiplier to zero got exactly the same verdict as one who left it at 100.
        //
        // The weight now scales every signal that is firing *because of* the paste, which
        // is the whole set when the session is effectively a pure paste. Signals measuring
        // genuine typing keep their full value: S5's backspace-ratio branches and S7's
        // entropy branches are typing evidence and are deliberately left alone, as is S13.
        //
        // S13 is the important exclusion. It exists to catch a paste that produced NO
        // JavaScript evidence at all - it fires on server-side characters-per-second when
        // the event stream is empty or shows no paste. Weighting it would let a site turn
        // paste_weight down and blind the one signal that still works when the tracker is
        // defeated, which is the opposite of what the setting is for.
        //
        // Determined here rather than at Signal 5 because Signals 3, 4 and 6 are scored
        // earlier in the function and need the same answer. The inputs are all final by
        // this point.
        /* ------------------------------------------------------------------------ */
        $keystrokeratioval = ($textchars > 0 && $totalkeystrokes > 0)
            ? ($totalkeystrokes / max(1, $textchars))
            : ($totalkeystrokes === 0 ? 0.0 : 1.0);

        $ispastesession = ($pastecount > 0 || $largeinserts > 0)
                         && ($totalkeystrokes === 0
                             || ($textchars > 100 && $keystrokeratioval < 0.25))
                         && !$partialpasteonly;

        // 1.0 for every session that is not a pure paste, so nothing about a typed or
        // mixed session changes. At the default paste_weight of 100 this is 1.0 too, so
        // an existing site sees no change in any score until it chooses one.
        $pastederivedweight = $ispastesession ? $pasteweight : 1.0;

        $textgate = ($textchars >= $minchars) || ($qslot > 0 && $textchars > 0);
        if ($effectivecharsadded >= $minchars || $pastecount > 0 || $largeinserts > 0 || $textgate) {
            // ----------------------------------------------------------------
            // SIGNAL 1: Paste/drop events — primary HIGH trigger (max 60 pts)
            // ANY paste event is a strong authorship signal.
            //
            // FIX-EG-PASTE-DETECT (v1.2.81): also trigger on large_inserts > 0
            // because TinyMCE and Atto intercept the native paste event and fire
            // input/large_insert instead — the JS 'paste' event never fires.
            //
            // FIX-EG-PASTE-SCORE-FLOOR (v1.2.98): Raised to +60. Pure paste + Signals
            // 5 (15 pts) + 7 (10 pts) + 4 (20 pts) = 105 → 100 % HIGH.
            //
            // FIX-EG-MIXED-PROPORTIONAL (v1.2.104): Mixed sessions (paste detected AND
            // keystrokes > 0) should score MEDIUM, not HIGH. Flat +60 for any paste
            // caused a student who typed half an answer and pasted the other half to
            // receive the same HIGH badge as a pure copy-paste — misleading for educators.
            //
            // Fix: when the session is mixed (keystrokes > 0) and the paste size is
            // known ($paste_chars_known > 0 and $text_chars > 0), award points
            // proportional to the paste fraction:
            // paste_frac < 0.05 (incidental, e.g. autocorrect): +10 pts
            // paste_frac ≥ 0.05 (meaningful paste): max(30, 60 × paste_frac)
            // → guarantees MEDIUM threshold (≥30) for any meaningful paste in a
            // mixed session while scaling toward HIGH for large fractions.
            // → e.g. 50 % paste: max(30, 30) = 30 → MEDIUM + secondary signals.
            // → e.g. 80 % paste: max(30, 48) = 48 → MEDIUM-HIGH + secondary signals.
            // Pure paste (no keystrokes) or unknown ratio → conservative full +60.
            /* ---------------------------------------------------------------- */
            if ($pastecount > 0 || $largeinserts > 0) {
                // Estimate paste chars: prefer native paste insertlen, fall back to
                // the largest large_insert delta (TinyMCE clipboard proxy).
                $pastecharsknown = $pastecharstotal > 0 ? $pastecharstotal
                                   : ($largeinsertmaxdelta > 0 ? $largeinsertmaxdelta : 0);

                // Pre-compute paste fraction for the threshold checks below.
                $pastefracraw = ($pastecharsknown > 0 && $textchars > 0)
                    ? min(1.0, $pastecharsknown / max(1, $textchars))
                    : 0.0;

                // FIX-EG-PASTE-HIGH (v1.2.105): The FIX-EG-MIXED-PROPORTIONAL formula
                // (v1.2.104) checks $total_keystrokes===0 to detect pure paste.  Ctrl+V /
                // Cmd+V pastes record 2 keydown events (modifier + V), so $total_keystrokes
                // is 2 even for a session where every character was pasted.  This flips the
                // condition, causing the proportional branch to run.  When $paste_chars_known
                // is depressed (e.g. TinyMCE clipboard unreadable, only a smaller
                // cross-question paste was attributed via FIX-EG-PERQ-UNTAGGED-PASTE),
                // paste_frac < 0.50 → Signal 1 = 30 → total may fall short of HIGH
                // → MEDIUM instead of HIGH.
                //
                // Fix: treat sessions where ≥ 75 % of the submitted text was detected as
                // pasted as "effectively pure paste" and award the full +60.  This covers:
                // • Ctrl/Cmd+V pastes (keystrokes==2 but entire answer was pasted)
                // • Sessions where paste size is slightly under-reported but still high
                // The 75 % threshold safely excludes genuine mixed sessions (student typed
                // a majority) while catching all realistic pure-paste and near-pure-paste
                // scenarios.
                // FIX-EG-PASTE-HIGH-V2 (v1.2.106): Extend the pure-paste gate to cover
                // Ctrl/Cmd+V sessions. A Ctrl+V paste records exactly 2 keydown events
                // (modifier key + V), giving total_keystrokes=2. The v1.2.105 check
                // (total_keystrokes===0) correctly detected raw JS clipboard-event pastes
                // (no keyboard activity at all) but still missed keyboard-shortcut pastes.
                // When paste_chars_known is underestimated (TinyMCE clipboard API blocked,
                // large_insert_max_delta proxy undercount), paste_frac_raw can fall below
                // 0.75 even for a 100%-pasted answer — routing it to the proportional
                // branch (Signal 1 = max(30, 60 × paste_frac) ≈ 30–43 pts) → total ≈ 58
                // → MEDIUM instead of HIGH.
                // Fix: sessions with total_keystrokes ≤ 2 are treated as effectively pure
                // paste. 2 is safe: any genuine mixed session (real typing + paste) always
                // produces ≥ 3 keystrokes, so no false HIGH badges for mixed attempts.
                // FIX-EG-PASTE-HIGH-V3 (v1.2.114): Keystroke-ratio gate — third path to
                // the full +60 Signal 1 award.
                //
                // Background: a Ctrl+V paste produces exactly 2 keydown events (modifier +
                // V key). The existing ≤2-keystroke gate (FIX-EG-PASTE-HIGH-V2) correctly
                // catches raw paste events where keystrokes==0 or 1, but FAILS when the
                // per-question event pool contains additional tagged events (focus, cursor
                // moves, or keystrokes from BEFORE the paste). In a typical copy-paste
                // session the student may press a few arrow keys or click inside the field
                // before pasting, pushing total_keystrokes to 5–15. The ≤2 gate misses
                // this entirely.
                //
                // The paste_frac_raw ≥ 0.75 gate (FIX-EG-PASTE-HIGH-V2) also fails when
                // TinyMCE's clipboard API reports a compressed insertlen (common when the
                // browser restricts cross-origin clipboard reads inside iframes), making
                // paste_chars_known well below the actual answer length.
                //
                // Fix: when the keystroke-to-character ratio is very low (< 0.25 keystrokes
                // per final character), the student could not possibly have typed all that
                // text — the overwhelming majority of characters arrived via paste. Treat
                // this as effectively pure paste and award the full +60.
                //
                // Example: student types 3 keystrokes then pastes 500 chars → ratio=6/500=0.012 < 0.25.
                // Example: honest typist writes 200 chars → typically 220–280 keystrokes → ratio≥1.0.
                //
                // The 0.25 threshold safely excludes genuine mixed sessions where the
                // student typed a significant portion of the answer (they always produce
                // ratio ≥ 0.5 because edits, deletions and corrections inflate keystrokes).
                // v1.2.225: $keystroke_ratio_val is computed once, up with the derived
                // metrics, because the paste-session determination now needs it before
                // Signal 1 runs. The value is identical; this assignment was removed
                // rather than duplicated so the two can never drift apart.
                if (
                    $totalkeystrokes <= 2 || $pastefracraw >= 0.75
                        || ($textchars > 100 && $keystrokeratioval < 0.25)
                ) {
                    // Pure paste (keystrokes = 0, 1, or 2 modifier keys) OR substantially
                    // pasted (≥ 75 %) OR keystroke ratio too low to be genuine typing:
                    // award full signal even if modifier-key presses inflated
                    // $total_keystrokes, or if clipboard reported a compressed insertlen.
                    // PASTE-WEIGHT (v1.2.212): scale by admin-configured multiplier.
                    $s1 = (int)round(60 * $pasteweight);
                    $score += $s1;
                    $signalpts[1] = $s1;
                } else if ($pastecharsknown > 0 && $textchars > 0) {
                    // Mixed session with measurable paste size: weight proportionally.
                    $pastefrac = $pastefracraw;
                    if ($pastefrac >= 0.05) {
                        // Meaningful paste (≥5 % of answer): guarantee MEDIUM (30 pts floor).
                        // PASTE-WEIGHT (v1.2.212): scale floor and ceiling by paste_weight.
                        $s1 = (int)round(max(30.0 * $pasteweight, 60.0 * $pastefrac * $pasteweight));
                        $score += $s1;
                        $signalpts[1] = $s1;
                    } else {
                        // Incidental paste (<5 % of answer — autocorrect, short snippet).
                        // PASTE-WEIGHT (v1.2.212): scale by paste_weight.
                        $s1 = (int)round(10 * $pasteweight);
                        $score += $s1;
                        $signalpts[1] = $s1;
                    }
                } else {
                    // Paste detected but size unknown (TinyMCE clipboard unreadable) or
                    // no finaltext: conservative full signal.
                    // PASTE-WEIGHT (v1.2.212): scale by admin-configured multiplier.
                    $s1 = (int)round(60 * $pasteweight);
                    $score += $s1;
                    $signalpts[1] = $s1;
                }
            }

            // ----------------------------------------------------------------
            // SIGNAL 2: Large insert events (delta > 20 chars) — catches
            // medium-sized pastes that don't meet the burst threshold (max 20 pts).
            // PASTE-WEIGHT (v1.2.212): scaled by the same paste_weight multiplier
            // as Signal 1 — both signals represent the same underlying behaviour
            // (offline preparation + paste) and should be dampened together.
            /* ---------------------------------------------------------------- */
            if ($largeinserts > 0) {
                $s2 = (int)round(min(20, $largeinserts * 8) * $pasteweight);
                $score += $s2;
                $signalpts[2] = $s2;
            }

            // ----------------------------------------------------------------
            // SIGNAL 3: Typing speed — chars per second across the session.
            // > 15 cps = superhuman (AI generation or paste without JS paste event).
            // > 8 cps  = very fast but borderline (max 30 / 15 pts).
            // ----------------------------------------------------------------
            // v1.2.225: scaled by $paste_derived_weight - in a pure-paste session this
            // signal is measuring how fast the clipboard is, not how fast the student is.
            $charspersec = ($effectivecharsadded * 1000.0) / $sessiontotalms;
            if ($charspersec > 15.0 && $effectivecharsadded > 50) {
                $s3 = (int)round(30 * $pastederivedweight);
                $score += $s3;
                $signalpts[3] = $s3;
            } else if ($charspersec > 8.0 && $effectivecharsadded > 50) {
                $s3 = (int)round(15 * $pastederivedweight);
                $score += $s3;
                $signalpts[3] = $s3;
            }

            // ----------------------------------------------------------------
            // SIGNAL 4: Low thinking pauses — genuine writers pause to think.
            // Zero major pauses (>2 s) for substantial content → suspicious
            // (max 20 pts).
            //
            // FIX-EG-S4-TINYMCE (v1.2.103): TinyMCE intercepts the native paste event and
            // fires an input event instead, meaning $charsadded and $paste_chars_total are
            // both 0 for many editor-mediated paste sessions ($effective_charsadded = 0).
            // The student still produced a long answer (measurable via $text_chars from the
            // submitted finaltext), so use that as a fallback character gate. Without this
            // fix, Signal 4 never fires for TinyMCE pastes, shaving 20 pts off the score
            // and preventing those sessions from reaching the HIGH threshold.
            //
            // FIX-EG-S4-ONE-PAUSE (v1.2.175): Changed threshold from pausecount < 2 to
            // pausecount === 0 (strictly zero pauses).
            //
            // The "pre-thinker" pattern: a student formulates their full answer mentally
            // before starting to type, then types it out in one fast, nearly-uninterrupted
            // burst. This is legitimate exam technique — they pause to think BEFORE typing,
            // so no inter-keystroke pauses appear in the event log. With pausecount < 2,
            // even a student who paused once to re-read the question was flagged.
            //
            // New threshold: only award Signal 4 when pausecount is literally 0 (no
            // inter-keystroke gap ever exceeded 2 seconds). One thinking pause during
            // typing is enough to exempt the student from this penalty. This is still
            // suspicious for paste sessions (paste produces 0 pauses by definition).
            /* ---------------------------------------------------------------- */
            $s4chars = max($effectivecharsadded, $textchars);
            // FIX-EG-S4-NODATA (v1.2.121): Signal 4 measures "student typed without thinking
            // pauses" — only meaningful when there is SOME behavioral evidence. Without any
            // keystrokes, paste events, or large_inserts, $pausecount is always 0 (there are
            // no inter-key intervals to measure), so Signal 4 was firing for EVERY zero-data
            // session with text present, adding a false +20 "robotic cadence" penalty to
            // sessions where the tracker simply failed to bind (honest typist whose events
            // were lost scores MEDIUM instead of LOW because of this false positive).
            // Gate: require at least one captured behavioral event before awarding S4.
            // A confirmed paste (pastecount or large_inserts > 0) is explicitly allowed —
            // no-pause IS genuinely suspicious for a paste session.
            $s4hasevidence = ($totalkeystrokes > 0 || $pastecount > 0 || $largeinserts > 0);
            // V1.2.225: scaled by $paste_derived_weight - "paste produces 0 pauses by
            // definition", as the comment above says, so in a pure-paste session this is
            // the paste being counted again rather than an independent observation.
            if ($pausecount === 0 && $s4chars > 100 && $s4hasevidence) {
                $s4 = (int)round(20 * $pastederivedweight);
                $score += $s4;
                $signalpts[4] = $s4;
            }

            // ----------------------------------------------------------------
            // SIGNAL 5: Backspace/correction ratio — very low editing (max 15 pts).
            // Human writers make mistakes and correct them. A paste-only session
            // (zero keystrokes) has no corrections at all — maximum suspicion.
            //
            // FIX-EG-SIGNALS57-LARGE-INSERT (v1.2.98): Signals 5 and 7 previously
            // checked only $pastecount > 0, so TinyMCE-intercepted pastes (which set
            // large_inserts=1 with pastecount=0) never triggered these +15 and +10 pt
            // bonuses. Extended both conditions to also cover $large_inserts > 0.
            // ----------------------------------------------------------------
            // FIX-EG-PARTIAL-PASTE-S5S7 (v1.2.105): $partial_paste_only is true when
            // events came from the Tier-2 branch of FIX-EG-PARTIAL-PASTE-FALLBACK (mixed
            // session: student typed part and pasted part).  Treating such sessions as
            // pure-paste would award the S5 (+15) and S7 (+10) bonuses, pushing the total
            // past the HIGH threshold and masking the genuine mixed behaviour.
            // Suppress $is_paste_session for partial-paste-only pools so those signals
            // do not fire; the MEDIUM-range score from Signal 1 (proportional) is sufficient.
            //
            // FIX-EG-PASTE-SESSION-RATIO (v1.2.114): Extend the pure-paste gate to also
            // cover the keystroke-ratio case added to Signal 1 (FIX-EG-PASTE-HIGH-V3).
            // When the keystroke ratio is < 0.25 the session is effectively pure paste
            // regardless of whether $total_keystrokes is exactly 0. Without this extension,
            // a Ctrl+V paste with 3–15 tagged keystroke events (total_keystrokes > 0)
            // would not set $is_paste_session, suppressing the S5 (+15) and S7 (+10)
            // bonuses and capping the score at MEDIUM instead of HIGH.
            // v1.2.225: $is_paste_session is now determined once, up with the derived
            // metrics, because Signals 3, 4 and 6 run before this point and need it.
            if ($ispastesession) {
                // Scaled: this branch fires because the session IS a paste. The two
                // backspace-ratio branches below are typing evidence and keep full value.
                $s5 = (int)round(15 * $pastederivedweight);
                $score += $s5;
                $signalpts[5] = $s5; // Pure paste: no manual corrections whatsoever.
            } else if ($totalkeystrokes > 0 && $backspaceratio < 0.02) {
                $score += 15;
                $signalpts[5] = 15;
            } else if ($totalkeystrokes > 0 && $backspaceratio < 0.04) {
                $score += 8;
                $signalpts[5] = 8;
            }

            // ----------------------------------------------------------------
            // SIGNAL 6: Near-zero session typing time — content appeared almost
            // instantly, consistent with paste or programmatic insertion (max 25 pts).
            // Only fires when typing_time is non-zero (i.e. some events fired) but
            // still very short. Pure single-event paste sessions have typing_time=0
            // and are already covered by Signals 1 + 3 + 4 + 5.
            // ----------------------------------------------------------------
            // v1.2.225: scaled by $paste_derived_weight - "content appeared almost
            // instantly, consistent with paste", which is the paste itself.
            if ($typingtime > 0 && $typingtime < 10000 && $effectivecharsadded > 50) {
                $s6 = (int)round(25 * $pastederivedweight);
                $score += $s6;
                $signalpts[6] = $s6;
            }

            // ----------------------------------------------------------------
            // SIGNAL 7: Typing entropy — suspiciously smooth rhythm (max 10 pts).
            //
            // v1.2.112 (TypeShield-aligned): prefer Shannon IKI entropy when
            // ≥ 30 inter-key delays are available. Shannon entropy is more
            // discriminating than SD-based entropy for AI-retyped content
            // (TypeShield threshold < 0.35). Fall back to SD-based score for
            // short sessions. Pure paste sessions still get the full bonus.
            /* ---------------------------------------------------------------- */
            if ($ispastesession) {
                // V1.2.225: scaled. The Shannon and SD-entropy branches below measure real
                // typing rhythm and keep full value.
                $s7 = (int)round(10 * $pastederivedweight);
                $score += $s7;
                $signalpts[7] = $s7; // Pure paste: no rhythm data = max entropy suspicion.
            } else if ($ikishannon > 0 && count($ikdelays) >= 30) {
                // Shannon entropy available — use TypeShield's threshold.
                if ($ikishannon < 0.35) {
                    $score += 10;
                    $signalpts[7] = 10;
                } else if ($ikishannon < 0.55) {
                    $score += 5;
                    $signalpts[7] = 5;
                }
            } else if ($hasrhythmdata && $entropyscore < 0.3) {
                // V1.2.224: was `$entropy_score > 0 && ...`, which excluded the most
                // suspicious value the metric can take. See FIX-EG-CONSTANT-RHYTHM-READS-LOW.
                $score += 10;
                $signalpts[7] = 10;
            } else if ($hasrhythmdata && $entropyscore < 0.5) {
                $score += 5;
                $signalpts[7] = 5;
            }

            // ----------------------------------------------------------------
            // SIGNAL 8: Linguistic — sentence length uniformity (max 10 pts)
            //
            // FIX-EG-S8-DEDUP (v1.2.124): Gated with !$events_empty_for_scoring.
            // When no behavioural events were captured, the linguistic fallback
            // block (below) handles sentence_variance with amplified +20 pts.
            // Allowing S8 to also fire (+10) for the same metric caused double-
            // counting that inflated no-event scores by up to 10 extra pts.
            /* ---------------------------------------------------------------- */
            if (!$eventsemptyforscoring) {
                if ($sentencevariance > 0 && $sentencevariance < 6) {
                    $score += 10;
                    $signalpts[8] = 10;
                } else if ($sentencevariance > 0 && $sentencevariance < 12) {
                    $score += 5;
                    $signalpts[8] = 5;
                }
            }

            // ----------------------------------------------------------------
            // SIGNAL 9: Linguistic — vocabulary diversity (max 5 pts)
            //
            // FIX-EG-S8-DEDUP (v1.2.124): Same gate as Signal 8 — suppressed
            // for no-events sessions to prevent double-counting with the
            // linguistic fallback's +15 pts for vocab_diversity < 0.30.
            /* ---------------------------------------------------------------- */
            if (!$eventsemptyforscoring) {
                if ($vocabdiversity > 0 && $vocabdiversity < 0.30) {
                    $score += 5;
                    $signalpts[9] = 5;
                } else if ($vocabdiversity > 0 && $vocabdiversity < 0.40) {
                    $score += 2;
                    $signalpts[9] = 2;
                }
            }

            // ----------------------------------------------------------------
            // SIGNAL 10 (TypeShield-matched, v1.2.112): IKI autocorrelation
            // lag-1. Human baseline ≈ 0.1. Highly repetitive (autocorr >> 0.1)
            // or artificially jittered (autocorr << 0) strongly suggests
            // automated input. Requires ≥ 30 IKI samples (max 10 pts).
            /* ---------------------------------------------------------------- */
            if (count($ikdelays) >= 30) {
                $autocorrdev = abs($ikiautocorr - 0.1);
                if ($autocorrdev > 0.5) {
                    $score += 10;
                    $signalpts[10] = 10;
                } else if ($autocorrdev > 0.3) {
                    $score += 5;
                    $signalpts[10] = 5;
                }
            }

            // ----------------------------------------------------------------
            // SIGNAL 11 (TypeShield-matched, v1.2.112): Speed-burst coefficient
            // of variation. Human typing speed is highly variable across 10-second
            // windows; robotic/AI content is produced at a constant rate (low CV).
            // TypeShield threshold CV < 0.3 for sessions ≥ 30 s (max 10 pts).
            /* ---------------------------------------------------------------- */
            if (count($wpmsnapshots) >= 3 && $typingtime >= 30000) {
                if ($speedcv < 0.30) {
                    $score += 10;
                    $signalpts[11] = 10;
                } else if ($speedcv < 0.50) {
                    $score += 5;
                    $signalpts[11] = 5;
                }
            }

            // ----------------------------------------------------------------
            // SIGNAL 12 (TypeShield-matched, v1.2.112): Keystroke ratio.
            // Humans produce ~1.4 keystrokes per final character (edits, deletions).
            // A ratio well below 1.0 means most characters were inserted without
            // typing — strong paste/AI insertion indicator (max 10 pts).
            // TypeShield flags ratio < 0.5. Only fires for sessions with real
            // text content to avoid false positives on empty/very short answers.
            /* ---------------------------------------------------------------- */
            if ($textchars > 100 && $totalkeystrokes > 0) {
                $keystrokeratio = $totalkeystrokes / max(1, $textchars);
                if ($keystrokeratio < 0.5) {
                    $score += 10;
                    $signalpts[12] = 10;
                } else if ($keystrokeratio < 0.8) {
                    $score += 5;
                    $signalpts[12] = 5;
                }
            }
        }

        // LINGUISTIC FALLBACK (v1.2.81 / v1.2.86):
        //
        // Fires when behavioural events are unavailable for THIS scoring pass:
        // Case A (v1.2.81): $all_events is empty — JS never loaded, quiz timed out,
        // or an older session before the sesskey/attemptkey fixes.
        // Case B (v1.2.86): $all_events is non-empty BUT $events is empty after the
        // per-question qslot filter — events exist in the DB but none carry a qslot
        // tag matching this question (qslot detection failed in tracker.js, common
        // with TinyMCE 6 on some Moodle themes). Without this extension, the scorer
        // sees no behavioural signals AND no linguistic boost → score=0 → LOW for
        // every per-question record, hiding pastes that the aggregate DID detect.
        //
        // In both cases linguistic signals are the only available evidence, so up to
        // 35 extra points (20 + 15) are added to push AI-uniform text into MEDIUM range
        // (threshold 30 under v1.2.112 TypeShield-aligned thresholds).
        // (FIX-EG-S8-DEDUP v1.2.124: $events_empty_for_scoring was moved to before the
        // signal engine — no re-declaration needed here, the variable is already set.)
        // v1.2.113: Track linguistic fallback points separately so student.php can
        // include them in the pre-cap total and show them as a distinct row in the
        // signal breakdown table. Without this, the pre-cap total understates the
        // actual raw score for no-events sessions by up to 35 pts.
        // FIX-EG-PERQ-NO-EVENTS-LOW (v1.2.173): Gate the linguistic fallback so it
        // only fires for aggregate scoring (qslot=0, e.g. assignment/forum) or for
        // per-question scoring where paste evidence WAS attributed to this question.
        //
        // Problem: when a student genuinely types their answer for quiz question N but
        // the tracker fails to capture any events for that specific qslot (TinyMCE did
        // not bind, or qslot detection failed), $events_empty_for_scoring=true and the
        // linguistic fallback runs unconditionally — awarding up to +35 pts even though
        // no misbehaviour occurred. Signal 13 (server CPS) also fired via the
        // $events_empty_for_scoring path, potentially adding another +25 pts.
        // Combined: genuine typed answer scored MEDIUM 43% instead of LOW 0%.
        //
        // Fix: for per-question scoring (qslot > 0) with no captured events AND no
        // paste/large_insert attributed to this question, skip the linguistic fallback
        // entirely. With zero evidence of any kind, we cannot conclude misbehaviour and
        // must default to LOW. The aggregate (qslot=0) record retains the full fallback
        // so assignment/forum and aggregate quiz scoring are not affected.
        //
        // Cases unaffected:
        // • qslot=0 (assignment/forum/aggregate): $run_linguistic_fallback=true ✓
        // • qslot>0 with paste found (pastecount or large_inserts > 0): true ✓
        // • qslot>0 with events present (events_empty_for_scoring=false): false
        // anyway (gate already requires events_empty_for_scoring) ✓.
        $runlinguisticfallback = $eventsemptyforscoring
            && $textchars > 100
            && ($qslot === 0 || $pastecount > 0 || $largeinserts > 0);

        $linguisticfallbackpts = 0;
        if ($runlinguisticfallback) {
            // FIX-EG-SINGLE-SENTENCE (v1.2.140): The previous guard was
            // ($sentence_variance > 0.0 && $sentence_variance < 6.0).
            //
            // linguistic::variance() returns 0.0 when the text has fewer than 2
            // sentences (it needs ≥ 2 data points to compute a non-zero result).
            // So ANY single-sentence answer — regardless of length — produced
            // sentence_variance = 0.0, the "> 0.0" check failed, and the +20 pts
            // were never awarded.
            //
            // This is the wrong semantic: a single-sentence answer has PERFECT
            // uniformity by definition (every sentence is the same length as every
            // other sentence, trivially). It is the most suspicious case and should
            // earn the maximum uniformity points, not zero.
            //
            // Fix: gate on $sentence_count_ling >= 1 (at least one sentence was
            // parsed from the text) instead of $sentence_variance > 0.0. The
            // $text_chars > 100 outer gate already excludes truly empty or trivially
            // short submissions, so there is no risk of awarding points for blank answers.
            //
            // Correct behaviour after fix:
            // Single sentence (variance = 0.0, count = 1)  → count >= 1 AND 0.0 < 6.0 → +20 ✓
            // Multiple uniform sentences (variance 1–5.9)  → count >= 1 AND < 6.0     → +20 ✓
            // Multiple varied sentences (variance ≥ 6.0)   → count >= 1 AND >= 6.0    →   0 ✓
            // No text parsed (count = 0, variance = 0.0)   → count = 0                →   0 ✓.
            if ($sentencecountling >= 1 && $sentencevariance < 6.0) {
                $score += 20;
                $linguisticfallbackpts += 20;
            }
            if ($vocabdiversity > 0.0 && $vocabdiversity < 0.30) {
                $score += 15;
                $linguisticfallbackpts += 15;
            }
        }

        // FIX-EG-SERVER-TIMING (v1.2.126): Server-side chars-per-second signal.
        //
        // When JS tracker events are completely absent (paste_events=0, keystrokes=0),
        // the only available evidence is the submitted text and Moodle's quiz_attempts
        // timing data (timestart + timefinish). A student who copy-pastes a 500-char
        // answer and submits within 15 seconds is physically impossible to have typed
        // — the server can detect this without any JS cooperation.
        //
        // Thresholds (chars submitted / attempt duration in seconds):
        // > 10 cps  — extremely fast, impossible sustained (120 WPM = ~10 cps)
        // → +50 pts — strong HIGH indicator
        // > 4 cps   — very fast, borderline (48 WPM sustained over whole attempt)
        // → +25 pts — MEDIUM-HIGH indicator
        //
        // Requires: events_empty_for_scoring (only fires as a fallback, not alongside
        // real paste events which already produce HIGH via Signal 1), text_chars > 50
        // (ignore trivially short answers), duration > 0 (guard against missing data).
        //
        // This signal is stored as Signal 13 in the breakdown and as server_cps in
        // metricsjson so diag.php Section 6 can display and audit it.
        //
        // FIX-EG-S13-NO-PASTE (v1.2.143): Extend Signal 13 to also fire when some
        // events ARE present but no paste/large_insert was captured AND the keystroke
        // ratio is very low (< 0.25 keystrokes per final character).
        //
        // Root cause of the gap: the existing $events_empty_for_scoring guard only
        // fires when there are literally zero events. A student who copy-pasted via
        // Ctrl+V and the paste event was swallowed by TinyMCE still leaves a handful
        // of focus and modifier-key events (typically 1–10). Those events set
        // $events_empty_for_scoring = false, suppressing Signal 13 even though the
        // student clearly pasted rather than typed — the submitted text is far too
        // long for the number of keystrokes recorded.
        //
        // Symptom: aggregate score stuck at ~45 % MEDIUM (S4+S5+S8 secondary signals)
        // instead of HIGH, even when the student submitted a 400-char answer with only
        // 5 modifier-key events. Server-side timing (attempt_timestart→timefinish) is
        // just as valid evidence in this case as when events are completely absent.
        //
        // $s13_no_paste_evidence is true when:
        // • Events exist (so events_empty_for_scoring is false),
        // • But none of them are paste/large_insert (pastecount=0, large_inserts=0),
        // • There are some keystrokes (> 0, to exclude pure no-JS sessions handled
        // by the events_empty_for_scoring branch),
        // • And the keystroke-to-character ratio is < 0.25 — physically impossible
        // for genuine typing (human typing always produces ≥ 0.5 keystrokes per
        // final character including edits and corrections).
        //
        // The 0.25 threshold safely excludes honest mixed sessions where the student
        // typed a significant portion: real typing always produces ratio ≥ 0.5 because
        // backspaces, cursor moves, and re-typing inflate keystrokes well above the
        // final character count.
        $servercps = 0.0;
        $s13nopasteevidence = (!$eventsemptyforscoring
            && $pastecount === 0
            && $largeinserts === 0
            && $totalkeystrokes > 0
            && $textchars > 100
            && ($totalkeystrokes / max(1, $textchars)) < 0.25);
        // FIX-EG-PERQ-NO-EVENTS-LOW (v1.2.173): Apply the same gate as the linguistic
        // fallback to Signal 13's events_empty_for_scoring path. When per-question
        // scoring (qslot > 0) has no events AND no paste, server CPS gives no useful
        // signal about THIS question specifically — the timer covers the entire attempt
        // (both questions combined). Only fire via events_empty when qslot=0 or paste found.
        $s13emptypath = $eventsemptyforscoring
            && ($qslot === 0 || $pastecount > 0 || $largeinserts > 0);
        if (
            ($s13emptypath || $s13nopasteevidence)
                && $attempttimestart > 0
                && $attempttimefinish > $attempttimestart
                && $textchars > 50
        ) {
            $attemptdurationsec = $attempttimefinish - $attempttimestart;
            if ($attemptdurationsec > 0) {
                $servercps = round($textchars / $attemptdurationsec, 4);
                if ($servercps > 10.0) {
                    // Impossible to type this fast over the whole attempt duration.
                    // Almost certainly a paste without JS event capture.
                    $signalpts[13] = 50;
                    $score += 50;
                    debugging(
                        '[EssayGuard] FIX-EG-S13: server_cps=' . $servercps
                            . ' > 10.0 → +50 pts HIGH signal.'
                            . ' s13_no_paste_evidence=' . (int)$s13nopasteevidence
                            . ' userid=' . $userid . ' cmid=' . $cmid . ' qslot=' . $qslot
                            . ' text_chars=' . $textchars . ' duration_sec=' . $attemptdurationsec
                            . ' keystrokes=' . $totalkeystrokes,
                        DEBUG_DEVELOPER
                    );
                } else if ($servercps > 4.0) {
                    // Suspiciously fast for a sustained attempt (48+ WPM sustained).
                    $signalpts[13] = 25;
                    $score += 25;
                    debugging(
                        '[EssayGuard] FIX-EG-S13: server_cps=' . $servercps
                            . ' > 4.0 → +25 pts MEDIUM signal.'
                            . ' s13_no_paste_evidence=' . (int)$s13nopasteevidence
                            . ' userid=' . $userid . ' cmid=' . $cmid . ' qslot=' . $qslot
                            . ' text_chars=' . $textchars . ' duration_sec=' . $attemptdurationsec
                            . ' keystrokes=' . $totalkeystrokes,
                        DEBUG_DEVELOPER
                    );
                }
            }
        }

        // FIX-EG-TYPING-FALSE-POSITIVE (v1.2.111 / updated v1.2.112): Signal 4 (no long
        // pauses, +20 pts) and Signal 5 (low backspace ratio, +15 pts) together total 35 pts
        // which exceeds the MEDIUM boundary (30 under v1.2.112 TypeShield-aligned thresholds),
        // causing a false-positive MEDIUM badge for honest quiz takers who type quickly with
        // few pauses and few corrections.  This is normal quiz behaviour for any confident,
        // efficient typist and is NOT a reliable indicator of plagiarism without additional
        // corroborating evidence (paste, superhuman speed, or robotic entropy).
        //
        // Gate: when there is no paste evidence (pastecount=0, large_inserts=0) AND
        // behavioural events ARE present for this scoring pass (!$events_empty_for_scoring —
        // we must NOT suppress the linguistic fallback, which runs when events are absent and
        // must still be able to reach MEDIUM for AI-uniform text) AND typing speed is not
        // truly superhuman (chars_per_sec <= 12.0 cps — see note below) AND entropy is not
        // suspiciously low (entropy_score >= 0.30 OR unavailable as 0.0 — Signal 7 did not
        // fire), cap the score at 29 (LOW max under v1.2.112 thresholds).
        //
        // FIX-EG-TYPING-FALSE-POSITIVE-V2 (v1.2.175): Raised the speed ceiling from
        // 8.0 cps → 12.0 cps.
        //
        // 8.0 cps = 96 wpm — achievable by any proficient typist under time pressure.
        // Many students who type fast routinely exceed 100 wpm on short bursts. With
        // the old 8.0 threshold, these students bypassed the cap and accumulated
        // Signal 3 (+15 pts) + Signal 4 (+20 pts) + Signal 5 (+15 pts) = 50 pts →
        // MEDIUM badge without any paste evidence — a false positive.
        //
        // 12.0 cps = 144 wpm — keeps the cap active for virtually all human typists
        // in a realistic quiz setting. Sustained typing above 144 wpm (> 10 min) is
        // exceptional even for competitive speed typists. Only signals that clearly
        // exceed human capability (>144 wpm sustained across the full session) now
        // bypass the cap.
        //
        // Outcomes:
        // • Honest fast typist (no paste, ≤ 144 wpm, natural entropy ≥ 0.30)
        // → capped at 29 → LOW ✓
        // • Very fast but plausible typist (96–144 wpm, no paste, no robotic entropy)
        // → NEW: also capped at 29 → LOW (was incorrectly MEDIUM) ✓
        // • AI retyper without paste (robotic entropy < 0.30, Signal 7 fires)
        // → cap does NOT apply → score remains in MEDIUM/HIGH range ✓
        // • Superhuman typist (chars_per_sec > 12.0, > 144 wpm)
        // → cap does NOT apply ✓
        // • Copy-paste session (pastecount > 0 or large_inserts > 0)
        // → cap does NOT apply → HIGH as expected ✓
        // • No-events session (TinyMCE ate all events, events_empty_for_scoring=true)
        // → cap does NOT apply → linguistic fallback can push AI-uniform text to MEDIUM ✓
        //
        // Baseline deviation (applied after this cap) can still push an otherwise-capped
        // session into MEDIUM if the deviation is extreme — a student typing dramatically
        // faster or more uniformly than their own baseline IS a meaningful signal.
        // v1.2.224: `$entropy_score === 0.0` used to satisfy this condition, which meant
        // a perfectly constant typing rhythm - automation - was capped at 29 as though no
        // rhythm had been measured at all. With enough samples, 0.0 is now the strongest
        // evidence against capping, not a reason for it. See
        // FIX-EG-CONSTANT-RHYTHM-READS-LOW above.
        if (
            $pastecount === 0 && $largeinserts === 0 && !$eventsemptyforscoring
                && $charspersec <= 12.0
                && (!$hasrhythmdata || $entropyscore >= 0.30)
        ) {
            // V1.2.224: capture the pre-cap score. This interpolated $score AFTER the
            // assignment above, so the message always read "capped at 29 (was 29)" - the
            // one number it existed to report was the one it could never show.
            $precap = $score;
            $score = min($score, 29.0);
            debugging(
                '[EssayGuard] FIX-EG-TYPING-FALSE-POSITIVE: paste gate fired,'
                    . ' score capped at 29 (was ' . $precap . ').'
                    . ' pastecount=' . $pastecount . ' large_inserts=' . $largeinserts
                    . ' chars_per_sec=' . round($charspersec, 2)
                    . ' entropy_score=' . $entropyscore
                    . ' userid=' . $userid . ' cmid=' . $cmid . ' qslot=' . $qslot,
                DEBUG_DEVELOPER
            );
        }

        $score = max(0.0, min(100.0, $score));

        /* --- Baseline deviation (adds up to 15 pts extra) --- */
        $baselinedeviation = fingerprint::deviation_score($userid, $metrics);
        $baselinefp        = fingerprint::get($userid);
        $baselinestatus    = $baselinefp ? $baselinefp->baseline_status : 'none';

        if ($baselinedeviation > 0.3) {
            $score = min(100.0, $score + ($baselinedeviation * 15));
        }

        // V1.2.113: Persist per-signal breakdown so student.php can show a
        // TypeShield-quality signal attribution table. Values reflect raw
        // contributions before the false-positive cap; the final score100 may
        // be lower than array_sum($signal_pts) when the cap fires.
        $metrics['signal_breakdown']       = $signalpts;
        // Linguistic fallback points are tracked separately — they don't map 1:1
        // to a numbered signal (they apply inflated weights vs the normal path)
        // and only fire when no behavioural events are available. Storing them
        // here lets student.php show an accurate pre-cap total even for no-events
        // sessions where signal_pts[] is empty or sparse.
        $metrics['linguistic_fallback_pts'] = $linguisticfallbackpts;

        // FIX-EG-DIAG-KSR (v1.2.122): Persist keystroke_ratio to metricsjson so
        // diag.php Section 3 can verify it without re-computing. Previously this
        // was computed locally inside Signal 12 but never stored — diag.php always
        // showed "session scored before v1.2.113" for this field, even on fully
        // fresh v1.2.121 attempts with captured keystrokes. The diagnostic was
        // misleading admins into thinking they had stale data when the real issue
        // was a live event-capture failure.
        if ($textchars > 100 && $totalkeystrokes > 0) {
            $metrics['keystroke_ratio'] = round($totalkeystrokes / max(1, $textchars), 4);
        }

        $score100  = (int)round($score);
        $riskscore = $score100 / 100.0;
        $risklevel = self::risk_level($score100);

        // ------------------------------------------------------------------------
        // V1.2.227 FIX-EG-NOTHING-MEASURED-READS-LOW
        //
        // An attempt where the tracker never ran produces no events, no keystrokes, no
        // pastes and no linguistic evidence - so every signal stays at zero, the score is
        // 0, and risk_level(0) is "low". The teacher is shown a green LOW badge that is
        // indistinguishable from a genuinely clean attempt. Nothing anywhere says the
        // measurement did not happen.
        //
        // This is not hypothetical. Found on a live Moodle 5.2 site: two OTHER plugins
        // (quizaccess_proctoring and quizaccess_hidecorrect) each declare `isCameraAllowed`
        // at the top level of an AMD module. Moodle concatenates all 910 modules into one
        // requirejs bundle and the page fetches that bundle under four entry-point names,
        // so the top-level `let` is evaluated twice in global scope: "SyntaxError:
        // Identifier 'isCameraAllowed' has already been declared". The whole bundle then
        // fails to evaluate - and Essay Guard's tracker is inside it. Every quiz attempt
        // on that site captured nothing and every student was badged LOW.
        //
        // Essay Guard cannot stop another plugin breaking the page. It can refuse to
        // report an unmeasured attempt as a clean one. "low" is a finding; this is the
        // absence of one, and the two must not look the same.
        //
        // Deliberately narrow. It fires only when there is submitted text to have measured
        // (so an empty answer is still legitimately low-risk) and NOTHING was captured by
        // any route - no events, no keystrokes, no paste, no large insert, and no
        // server-side timing signal. If any signal fired, the score stands as scored.
        $nothingmeasured = ($textchars > 0)
            && empty($allevents)
            && $totalkeystrokes === 0
            && $pastecount === 0
            && $largeinserts === 0
            && empty($signalpts);

        if ($nothingmeasured) {
            $risklevel = 'unmeasured';
            $metrics['nothing_measured'] = true;
        }

        // FIX-EG-METRICS-SCORE100 (v1.2.124): Persist the final capped score100 so
        // diag.php Section 6 can verify whether the false-positive cap applied (raw
        // signal total > score100 means the cap fired). Previously "Not stored —
        // session scored before v1.2.113" was shown for ALL sessions.
        // FIX-EG-METRICS-CPS-PASTEFRAC (v1.2.124): Persist chars_per_sec and
        // paste_frac so diag.php Section 3 shows these values instead of blanks.
        $metrics['score100']      = $score100;
        $metrics['chars_per_sec'] = round($charspersec, 4);
        $metrics['paste_frac']    = round($pastefracraw, 4);
        // FIX-EG-SERVER-TIMING (v1.2.126): Persist server_cps so diag.php Section 6
        // can display and audit the server-side timing signal.
        $metrics['server_cps']    = round($servercps, 4);

        // FIX-EG-AGG-PERQ-CONSISTENCY (v1.2.125): When no behavioural events were
        // captured for the aggregate (qslot=0) session, the linguistic fallback may
        // produce a lower score than the per-question records because it analyses the
        // combined text of all questions, which has different linguistic characteristics
        // (e.g. higher sentence-variance diversity) than each question's text in isolation.
        // This causes the Moodle Gradebook (which calls get_links() with qslot=0 / aggregate
        // fallback) to display a lower badge than the teacher's class report (which reads
        // per-question DB records directly).
        //
        // Fix: after computing the aggregate score, look up all per-question records for
        // this attemptkey and silently elevate the aggregate to the maximum per-question
        // score when that value is higher. Requires per-question records to already exist —
        // guaranteed by observer.php FIX-EG-PERQ-FIRST which scores per-question BEFORE
        // calling score_attempt() for the aggregate.
        if ($eventsemptyforscoring && $qslot === 0) {
            $perqmaxrs = $DB->get_field_sql(
                'SELECT MAX(riskscore) FROM {plagiarism_essayguard_sc}
                  WHERE userid = :userid AND cmid = :cmid AND attemptkey = :attemptkey AND qslot > 0',
                ['userid' => $userid, 'cmid' => $cmid, 'attemptkey' => $attemptkey]
            );
            if ($perqmaxrs !== false && $perqmaxrs !== null) {
                $perqmaxint = (int)round((float)$perqmaxrs * 100);
                if ($perqmaxint > $score100) {
                    debugging(
                        sprintf(
                            '[EssayGuard] FIX-EG-AGG-PERQ-CONSISTENCY: elevating aggregate'
                            . ' from %d to %d (max per-question, no events)'
                            . ' userid=%d cmid=%d attemptkey=%s',
                            $score100,
                            $perqmaxint,
                            $userid,
                            $cmid,
                            substr($attemptkey, 0, 12)
                            ),
                        DEBUG_DEVELOPER
                    );
                    $score100  = $perqmaxint;
                    $riskscore = $score100 / 100.0;
                    $risklevel = self::risk_level($score100);
                    $metrics['score100']              = $score100;
                    $metrics['agg_elevated_from_perq'] = true;
                }
            }
        }

        /* --- Explanations --- */
        $explanations = explainer::explain($metrics, $risklevel, $baselinefp);

        /* --- Persist to DB --- */
        // V1.2.14: upsert key includes qslot so per-question records are kept
        // separate from the aggregate record (qslot = 0).
        $existing = $DB->get_record(
            'plagiarism_essayguard_sc',
            [
                'userid'     => $userid,
                'cmid'       => $cmid,
                'attemptkey' => $attemptkey,
                'qslot'      => $qslot,
                ]
        );

        $record = (object)[
            'userid'               => $userid,
            'cmid'                 => $cmid,
            'contextid'            => $contextid,
            'attemptkey'           => $attemptkey,
            'qslot'                => $qslot,
            'riskscore'            => $riskscore,
            'risklevel'            => $risklevel,
            'metricsjson'          => json_encode($metrics),
            'timemodified'         => time(),
            'typing_time'          => $typingtime,
            'idle_time'            => $idletime,
            'total_keystrokes'     => $totalkeystrokes,
            'paste_events'         => $pastecount,
            'backspace_count'      => $backspaces,
            'delete_count'         => $deletes,
            'cursor_moves'         => $cursormoves,
            'average_wpm'          => $metrics['average_wpm'],
            'wpm_std_dev'          => $metrics['wpm_std_dev'],
            'interkey_mean'        => $metrics['interkey_mean'],
            'interkey_std_dev'     => $metrics['interkey_std_dev'],
            'pause_count'          => $pausecount,
            'pause_mean'           => $metrics['pause_mean'],
            'pause_std_dev'        => $metrics['pause_std_dev'],
            'burst_count'          => $burstcount,
            'burst_mean'           => $metrics['burst_mean'],
            'burst_std_dev'        => $metrics['burst_std_dev'],
            'sentence_variance'    => $sentencevariance,
            'vocab_diversity'      => $vocabdiversity,
            'rare_word_ratio'      => $rarewordratio,
            'thinking_pause_score' => $metrics['thinking_pause_score'],
            'entropy_score'        => $metrics['entropy_score'],
            'explanationsjson'     => json_encode($explanations),
            'baseline_deviation'   => round($baselinedeviation, 4),
            'baseline_status'      => $baselinestatus,
        ];

        if ($existing) {
            // FIX-EG-SCORE-NO-CLOBBER (v1.2.96): Never overwrite a non-zero score
            // with score=0 when no events are present in the DB.
            //
            // SCENARIO: The PHP observer fires synchronously during Moodle's form
            // processing, which can complete in < 500 ms. If the JS flush was still
            // in-flight when the form was posted (e.g. a slow server exhausted the
            // old 7 s single race timeout), the observer finds 0 rows in
            // plagiarism_essayguard_ev and computes riskscore=0 (LOW), permanently
            // overwriting a correct HIGH score that finalizeAttempt() had written
            // shortly before form submission.
            //
            // FIX: when all three conditions hold —
            // (a) the newly computed score is 0,
            // (b) the existing DB score is non-zero, AND
            // (c) there are no events for this (userid, cmid, attemptkey, qslot)
            // — skip the DB write and return the preserved existing score. The
            // rescore_pending scheduled task (FIX-EG-RESCORE-TASK, v1.2.96) will
            // re-invoke score_attempt once real events arrive; at that point
            // $events_empty_for_scoring is false and the normal update path runs.
            if ($score100 === 0 && ((float)$existing->riskscore > 0.0) && $eventsemptyforscoring) {
                $preserved100 = (int)round((float)$existing->riskscore * 100);
                debugging(
                    sprintf(
                        '[EssayGuard] score_attempt SKIP: no events, preserving existing'
                        . ' riskscore=%.2f for userid=%d cmid=%d qslot=%d attemptkey=%s',
                        (float)$existing->riskscore,
                        $userid,
                        $cmid,
                        $qslot,
                        substr($attemptkey, 0, 12)
                        ),
                    DEBUG_DEVELOPER
                );
                return [
                    'riskscore'    => (float)$existing->riskscore,
                    'risklevel'    => self::risk_level($preserved100),
                    'score100'     => $preserved100,
                    'metrics'      => $metrics,
                    'explanations' => $explanations,
                ];
            }
            $record->id = $existing->id;
            $DB->update_record('plagiarism_essayguard_sc', $record);
        } else {
            // V1.2.219: CHECK-THEN-INSERT RACE. The get_record() above and this insert
            // are two separate statements with no lock between them. log_event.php calls
            // \core\session\manager::write_close(), which drops the session lock that used
            // to serialise one student's 5-second flushes — so two flushes for the same
            // (userid, cmid, attemptkey, qslot) can both read "no row" and both insert,
            // and the attemptslot_uix UNIQUE index turns the loser into an uncaught
            // dml_write_exception. That surfaced to the student as a failed AJAX flush and
            // to the teacher as missing telemetry.
            //
            // A transaction would not help: the UNIQUE violation still fires, and on
            // PostgreSQL it poisons the whole transaction. The correct pattern is
            // insert-and-recover: attempt the insert, and if the index rejects it, re-read
            // the row the winner wrote and UPDATE that instead. Both writers end up with
            // the same final state and neither request errors.
            try {
                $DB->insert_record('plagiarism_essayguard_sc', $record);
            } catch (\dml_write_exception $e) {
                $winner = $DB->get_record(
                    'plagiarism_essayguard_sc',
                    [
                        'userid'     => $userid,
                        'cmid'       => $cmid,
                        'attemptkey' => $attemptkey,
                        'qslot'      => $qslot,
                        ]
                );
                if (!$winner) {
                    // Not a duplicate-key collision — a real write failure. Re-raise.
                    throw $e;
                }
                $record->id = $winner->id;
                $DB->update_record('plagiarism_essayguard_sc', $record);
            }
        }

        return [
            'riskscore'    => $riskscore,
            'risklevel'    => $risklevel,
            'score100'     => $score100,
            'metrics'      => $metrics,
            'explanations' => $explanations,
        ];
    }

    /* ── Helpers ──────────────────────────────────────────────────────────────── */

    /**
     * Population standard deviation of a list of numbers.
     *
     * @param float[] $arr The values to measure.
     * @return float The standard deviation, or 0.0 for fewer than two values.
     */
    public static function std_dev(array $arr): float {
        $n = count($arr);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($arr) / $n;
        $sq   = array_reduce($arr, fn($c, $x) => $c + ($x - $mean) ** 2, 0.0);
        return sqrt($sq / $n);
    }

    /**
     * Map inter-key standard deviation to a 0.0–1.0 entropy score.
     * Lower SD (smoother rhythm) = lower score = more suspicious.
     *
     * @param float $sd Standard deviation of inter-key intervals, in milliseconds.
     * @return float A normalised rhythm entropy from 0.0 (robotic) to 1.0 (highly varied).
     */
    public static function entropy_from_sd(float $sd): float {
        if ($sd <= 0) {
            return 0.0;
        }
        if ($sd < 60) {
            return 0.15;
        }
        if ($sd < 100) {
            return 0.25;
        }
        if ($sd < 150) {
            return 0.40;
        }
        if ($sd < 220) {
            return 0.60;
        }
        if ($sd < 300) {
            return 0.80;
        }
        return 1.0;
    }

    /**
     * v1.2.112: 3-tier risk levels — thresholds aligned with TypeShield LTI.
     *   0–29   → low    (Original — normal human typing behaviour)
     *   30–65  → medium (Suspicious — AI retyping, robotic rhythm, or minor paste)
     *   66–100 → high   (High — definitive paste / AI-speed text detected)
     *
     * Rationale for aligning with TypeShield thresholds (v1.2.112):
     *   - TypeShield (the reference LTI) uses 0–29/30–65/66–100 after extensive
     *     calibration against real submission data.
     *   - Lowering LOW ceiling from 34 → 29: sessions that accumulate only one or
     *     two minor secondary signals (e.g. low entropy +10, low vocab +5 = 15)
     *     stay firmly LOW.
     *   - Lowering HIGH floor from 70 → 66: paste session with Signals 1+4+5+7
     *     (60+20+15+10=105, capped 100) is solidly HIGH. Sessions in the 66–69
     *     range that previously showed MEDIUM are now correctly HIGH.
     *   - Paste session math: S1(60) + S4(20) + S5(15) + S7(10) = 105 → 100 ✓ HIGH
     *   - Secondary-only session math: S4(20) + S5(15) + S7(10) = 45 → MEDIUM ✓
     *   - AI-retyper: S7(10)+S10(10)+S11(10)+S12(10) = 40 → MEDIUM ✓
     *
     * Legacy DB values ('partial', 'mild') retain their stored value; the display
     * layer in reporter.js maps them to the correct Medium colour as legacy aliases.
     *
     * @param int $score100 The risk score, 0-100.
     * @return string One of "low" (0-29), "medium" (30-65) or "high" (66-100).
     */
    public static function risk_level(int $score100): string {
        if ($score100 >= 66) {
            return 'high';
        }
        if ($score100 >= 30) {
            return 'medium';
        }
        return 'low';
    }

    /* ── TypeShield-matched helper functions (v1.2.112) ───────────────────────── */

    /**
     * Inter-key timing autocorrelation at lag-1.
     * Human baseline ≈ 0.1; highly repetitive (bot) or jittered (artificial
     * randomisation) both deviate significantly. TypeShield flags |lag1 − 0.1| > 0.5.
     *
     * @param float[] $ikis Inter-key intervals in milliseconds, in typing order.
     * @return float The lag-1 autocorrelation, or 0.0 for fewer than three intervals.
     */
    private static function iki_autocorr_lag1(array $ikis): float {
        $n = count($ikis);
        if ($n < 3) {
            return 0.0;
        }
        $mu  = array_sum($ikis) / $n;
        $num = 0.0;
        $den = 0.0;
        for ($i = 0; $i < $n - 1; $i++) {
            $num += ($ikis[$i] - $mu) * ($ikis[$i + 1] - $mu);
        }
        foreach ($ikis as $v) {
            $den += ($v - $mu) ** 2;
        }
        return $den == 0.0 ? 0.0 : $num / $den;
    }

    /**
     * Shannon entropy of IKI distribution, normalised to 0.0–1.0.
     * Low entropy means keystrokes cluster in very few timing bands (robotic).
     * TypeShield uses 20 ms buckets. Threshold < 0.35 is suspicious.
     *
     * @param float[] $ikis    Inter-key intervals in milliseconds.
     * @param int     $bandms Width of each timing bucket, in milliseconds.
     * @return float Normalised entropy from 0.0 to 1.0; 0.0 for fewer than two intervals.
     */
    private static function iki_shannon_entropy(array $ikis, int $bandms = 20): float {
        $n = count($ikis);
        if ($n < 2) {
            return 0.0;
        }
        $counts = [];
        foreach ($ikis as $v) {
            $bucket = (int)floor($v / $bandms);
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
        }
        $entropy = 0.0;
        foreach ($counts as $c) {
            $p        = $c / $n;
            $entropy -= $p * log($p, 2);
        }
        $bucketcount = count($counts);
        $maxentropy  = $bucketcount > 1 ? log($bucketcount, 2) : 0.0;
        return $maxentropy > 0 ? min(1.0, $entropy / $maxentropy) : 0.0;
    }

    /**
     * Coefficient of variation for typing speed across WPM snapshot windows.
     * Low CV = constant typing rate = robotic. Human writers show high variance.
     * TypeShield threshold: CV < 0.3 (session ≥ 30 s) is suspicious.
     * Returns 1.0 (high variance — human) when fewer than 3 snapshots exist.
     *
     * @param float[] $wpmsnapshots Words-per-minute samples taken across the session.
     * @return float The coefficient of variation, or 1.0 when there are fewer than three
     *               snapshots to judge from.
     */
    private static function speed_burst_cv(array $wpmsnapshots): float {
        $n = count($wpmsnapshots);
        if ($n < 3) {
            return 1.0;
        }
        $mu = array_sum($wpmsnapshots) / $n;
        if ($mu == 0.0) {
            return 0.0;
        }
        return self::std_dev($wpmsnapshots) / $mu;
    }
}
