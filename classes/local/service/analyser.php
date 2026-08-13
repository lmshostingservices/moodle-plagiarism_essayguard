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

defined('MOODLE_INTERNAL') || die();

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
 * @package    plagiarism_essayguard
 * @copyright  2026 EssayGraderAI
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
     * @param int    $userid
     * @param int    $cmid
     * @param int    $contextid
     * @param string $attemptkey
     * @param array  $linguistic  Optional: output of linguistic::analyse()
     * @param string $finaltext   Optional: used to run linguistic analysis inline
     * @param int    $qslot       0 = aggregate, N = specific question slot (1-based)
     * @return array ['riskscore','risklevel','metrics','score100','explanations']
     */
    public static function score_attempt(
        int $userid,
        int $cmid,
        int $contextid,
        string $attemptkey,
        array $linguistic = [],
        string $finaltext = '',
        int $qslot = 0,
        int $attempt_timestart = 0,
        int $attempt_timefinish = 0
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

        $all_events = $DB->get_records('plagiarism_essayguard_ev', [
            'userid'     => $userid,
            'cmid'       => $cmid,
            'attemptkey' => $attemptkey,
        ], 'id ASC');

        // v1.2.14: Filter to per-question events when qslot > 0.
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
            $events = array_filter($all_events, static function ($ev) use ($qslot) {
                $p = json_decode($ev->payloadjson ?? '{}', true) ?: [];
                return isset($p['qslot']) && (int)$p['qslot'] === $qslot;
            });
        } else {
            $events = $all_events;
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
        //   • Non-paste events were included unconditionally → all questions shared the
        //     same keystroke pool → identical timing signals → identical base scores.
        //   • The 20 % lower slack allowed Q1's paste (200 chars) to match Q2's text
        //     (190 chars): 200 ≤ 190×1.10=209 AND 200 ≥ 190×0.80=152 → both pass.
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
            $has_paste_in_qslot = false;
            foreach ($events as $ev) {
                // large_insert is also a paste proxy (TinyMCE input-event fallback).
                if ($ev->eventname === 'paste' || $ev->eventname === 'drop_paste'
                        || $ev->eventname === 'large_insert') {
                    $has_paste_in_qslot = true;
                    break;
                }
            }
            if (!$has_paste_in_qslot) {
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
                $text_chars_pfix = ($finaltext !== '')
                    ? (int)mb_strlen(html_entity_decode(strip_tags($finaltext), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                    : 0;
                foreach ($all_events as $key => $ev) {
                    if ($ev->eventname !== 'paste' && $ev->eventname !== 'drop_paste'
                            && $ev->eventname !== 'large_insert') {
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
                    if ($text_chars_pfix > 0 && $plen > 0) {
                        $ratio_ok = ($plen <= $text_chars_pfix * 1.40)
                                 && ($plen >= $text_chars_pfix * 0.10);
                        if (!$ratio_ok) {
                            continue; // Length mismatch — paste belongs to another question.
                        }
                    }
                    // plen=0 or finaltext unknown: include (evidence of paste exists).
                    $events[$key] = $ev;
                }
                ksort($events); // Restore id/time order after inserting untagged pastes.
                error_log('[EssayGuard] FIX-EG-PERQ-UNTAGGED-PASTE: checked untagged pastes'
                    . ' for qslot=' . $qslot . ' text_chars=' . $text_chars_pfix
                    . ' events_after=' . count($events));
            }
        }

        // FIX-EG-QUE-QSLOT-FALLBACK (existing — runs only when events is EMPTY):
        if ($qslot > 0 && empty($events) && !empty($all_events)) {
            $has_any_qslot_tag = false;
            foreach ($all_events as $ev) {
                $p = json_decode($ev->payloadjson ?? '{}', true) ?: [];
                if ((int)($p['qslot'] ?? 0) > 0) {
                    $has_any_qslot_tag = true;
                    break;
                }
            }

            if (!$has_any_qslot_tag) {
                // FIX-EG-QSLOT-FALLBACK-V2 (v1.2.75): Pre-fix session — no qslot tags in
                // any event. The v1.2.74 fallback included ALL non-paste events from the
                // entire attempt for every question. This caused two overlapping bugs:
                //
                //   1. Shared keystroke pool: every question received the same keydown /
                //      input / timing events, so speed, pause-count and backspace-ratio
                //      signals were identical for Q1 and Q2.  Result: identical base scores.
                //
                //   2. Wide paste band (80–110 %): if Q1 (copy-pasted, 200 chars) and Q2
                //      (typed, 190 chars) have similar text lengths, Q1's paste
                //      (insertlen ≈ 200) satisfied Q2's band too
                //      (200 ≤ 190×1.10=209 AND 200 ≥ 190×0.80=152 → both in range).
                //      Result: Q1's paste was attributed to BOTH questions → identical HIGH.
                //
                // Fix: include ONLY paste events (attribute each by text-length match with
                // a tighter 90–110 % band).  Non-paste events are excluded — without qslot
                // tags we cannot tell which keystrokes belong to which question, so sharing
                // them produces meaningless (and identical) per-question scores.
                //
                // Outcome:
                //   Q1 (paste, text length ≈ insertlen) → paste included → HIGH score.
                //   Q2 (typed, no paste or paste length outside 90–110 % band) → 0 events
                //     → score = 0 → LOW badge.  Correctly distinguishes the two questions.
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
                $text_chars_early = ($finaltext !== '')
                    ? (int)mb_strlen(html_entity_decode(strip_tags($finaltext), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                    : 0;
                $events = [];
                foreach ($all_events as $key => $ev) {
                    if ($ev->eventname !== 'paste' && $ev->eventname !== 'drop_paste'
                            && $ev->eventname !== 'large_insert') {
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
                    if ($text_chars_early > 0 && $plen > 0) {
                        $ratio_ok = ($plen <= $text_chars_early * 1.40)
                                 && ($plen >= $text_chars_early * 0.10);
                        if (!$ratio_ok) {
                            continue; // verifiable length mismatch — skip this paste
                        }
                    }
                    // plen=0 or text unknown: include paste (evidence of paste exists).
                    $events[$key] = $ev;
                }
            }
        }

        // FIX-EG-PARTIAL-PASTE-FALLBACK (v1.2.105): Handles the gap left by the two
        // existing fallback paths:
        //
        //   • FIX-EG-PERQ-UNTAGGED-PASTE  — only runs when $events is NON-EMPTY after
        //     the strict qslot filter (i.e. the question has at least some tagged events).
        //   • FIX-EG-QUE-QSLOT-FALLBACK   — only runs when $events is EMPTY *and*
        //     $has_any_qslot_tag is FALSE.
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
        //   Tier 1 — full-answer paste (insertlen 70 %–140 % of finaltext):
        //     Strong evidence the whole answer was pasted (or very close to it).
        //     Assign these to this question; $partial_paste_only stays false so Signal 1
        //     awards the full +60 and $is_paste_session fires S5/S7.
        //
        //   Tier 2 — partial paste (insertlen 10 %–69 % of finaltext):
        //     The student typed some content and pasted the rest.
        //     Assign these; set $partial_paste_only=true so Signal 1 uses the
        //     proportional formula (max(30, 60×paste_frac)) and $is_paste_session is
        //     suppressed (preventing S5/S7 pure-paste bonuses for a mixed session).
        //
        // The 70 % split between tiers prevents full-answer pastes from another question
        // (whose text length happens to be similar to this one) from bleeding into the
        // Tier-2 search; and the 85 % upper bound on Tier 2 prevents full-answer pastes
        // from bleeding into Tier-2 attribution.
        // Untagged full-answer pastes from OTHER questions (ratio > 0.85 to this question's
        // text) are excluded from Tier 2 entirely — they belong to the question they were
        // copied into, not here.
        $partial_paste_only = false;
        if ($qslot > 0 && empty($events) && !empty($all_events)) {
            $text_chars_ppf = ($finaltext !== '')
                ? (int)mb_strlen(html_entity_decode(strip_tags($finaltext), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                : 0;
            if ($text_chars_ppf > 0) {
                $ppf_full    = [];  // Tier 1: ratio 0.70–1.40 (full-answer paste)
                $ppf_partial = [];  // Tier 2: ratio 0.10–0.69 (partial paste)
                foreach ($all_events as $key => $ev) {
                    if ($ev->eventname !== 'paste' && $ev->eventname !== 'drop_paste'
                            && $ev->eventname !== 'large_insert') {
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
                        $ratio = $plen / $text_chars_ppf;
                        if ($ratio >= 0.70 && $ratio <= 1.40) {
                            $ppf_full[$key] = $ev;
                        } elseif ($ratio >= 0.10 && $ratio < 0.70) {
                            $ppf_partial[$key] = $ev;
                        }
                        // ratio < 0.10 or ratio > 1.40 → too far off, skip.
                    } else {
                        // plen=0: unknown size — conservatively count as full-answer.
                        $ppf_full[$key] = $ev;
                    }
                }
                if (!empty($ppf_full)) {
                    // Tier 1 hit: treat as full-answer paste.
                    $events = $ppf_full;
                    error_log('[EssayGuard] FIX-EG-PARTIAL-PASTE-FALLBACK tier1:'
                        . ' qslot=' . $qslot . ' found=' . count($ppf_full)
                        . ' text_chars=' . $text_chars_ppf);
                } elseif (!empty($ppf_partial)) {
                    // Tier 2 hit: partial paste — mixed session.
                    $events = $ppf_partial;
                    $partial_paste_only = true;
                    error_log('[EssayGuard] FIX-EG-PARTIAL-PASTE-FALLBACK tier2:'
                        . ' qslot=' . $qslot . ' found=' . count($ppf_partial)
                        . ' text_chars=' . $text_chars_ppf);
                }
            }
        }

        $minchars      = (int)get_config('plagiarism_essayguard', 'minchars') ?: 120;
        $maxburstchars = (int)get_config('plagiarism_essayguard', 'maxburstchars') ?: 150;
        // PASTE-WEIGHT (v1.2.212): Admin-configurable multiplier (0–100 %) applied to
        // Signal 1 (paste/drop) and Signal 2 (large_insert) scores. Default 100 % preserves
        // existing behaviour. RTOs whose students write offline and paste answers should
        // reduce this (e.g. 25 %) so paste-only sessions score MEDIUM rather than HIGH.
        $paste_weight_pct = (int)(get_config('plagiarism_essayguard', 'paste_weight') ?? 100);
        $paste_weight_pct = max(0, min(100, $paste_weight_pct));
        $paste_weight     = $paste_weight_pct / 100.0;

        // --- Raw counters ---
        $charsadded        = 0;
        $paste_chars_total = 0; // sum of insertlen from paste events (fallback for gate)
        $pastecount        = 0;
        $burstsuspicious   = 0;
        $backspaces      = 0;
        $deletes         = 0;
        $cursor_moves    = 0;
        $total_keystrokes = 0;
        $pausecount      = 0;
        $longpauses      = 0;
        // FIX-EG-LARGE-INSERT (v1.2.73): count of large_insert events (delta > 20 chars)
        $large_inserts       = 0;
        $large_insert_max_delta = 0;

        // --- Inter-key delays ---
        $ikdelays = [];

        // --- Pause lists (ms) ---
        $pauses_all   = [];
        $pauses_major = [];

        // --- Burst data ---
        $burst_words_list = [];

        // --- WPM snapshots ---
        $wpm_snapshots = [];

        // --- Session time ---
        $typing_time = 0;
        $idle_time   = 0;

        $prevtime = null;
        $session_start = null;
        $session_end   = null;

        foreach ($events as $event) {
            $payload  = json_decode($event->payloadjson ?? '{}', true) ?: [];
            $evtime   = (int)$event->eventtime;

            if ($session_start === null) {
                $session_start = $evtime;
            }
            $session_end = $evtime;

            if ($prevtime !== null) {
                $delta = max(0, $evtime - $prevtime);
                if ($delta > 500) {
                    $pauses_all[] = $delta;
                }
                if ($delta > 2000) {
                    $pausecount++;
                    $pauses_major[] = $delta;
                    $idle_time += $delta;
                } else {
                    $typing_time += $delta;
                }
                if ($delta > 10000) {
                    $longpauses++;
                }
            }
            $prevtime = $evtime;

            switch ($event->eventname) {
                case 'keydown':
                    $total_keystrokes++;
                    $ikd = isset($payload['ikd']) ? (int)$payload['ikd'] : null;
                    if ($ikd !== null && $ikd > 0 && $ikd < 5000) {
                        $ikdelays[] = $ikd;
                    }
                    break;

                case 'backspace':
                    $backspaces++;
                    $total_keystrokes++;
                    $ikd = isset($payload['ikd']) ? (int)$payload['ikd'] : null;
                    if ($ikd !== null && $ikd > 0 && $ikd < 5000) {
                        $ikdelays[] = $ikd;
                    }
                    break;

                case 'delete':
                    $deletes++;
                    $total_keystrokes++;
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
                    if (($payload['insertlen'] ?? 0) >= $maxburstchars) {
                        $burstsuspicious++;
                    }
                    break;

                case 'paste':
                    $pastecount++;
                    $pinsertlen = (int)($payload['insertlen'] ?? 0);
                    if ($pinsertlen >= $maxburstchars) {
                        $burstsuspicious++;
                    }
                    // Credit paste insertlen toward the minchars gate as a fallback.
                    // In normal flow the subsequent input event also adds to $charsadded,
                    // but in Atto/TinyMCE environments the input event may not fire before
                    // the first flush — this ensures the gate never silently blocks
                    // paste-only sessions even if the input event is delayed or missing.
                    $paste_chars_total += $pinsertlen;
                    break;

                case 'drop_paste':
                    $pastecount++;
                    $burstsuspicious++;
                    break;

                case 'large_insert':
                    // FIX-EG-LARGE-INSERT (v1.2.73): emitted by tracker.js when any
                    // input event delta > 20 chars — catches medium-sized pastes that
                    // don't meet the maxburstchars threshold.
                    $large_inserts++;
                    $d = (int)($payload['delta'] ?? 0);
                    if ($d > $large_insert_max_delta) {
                        $large_insert_max_delta = $d;
                    }
                    break;

                case 'burst_end':
                    $wc = (int)($payload['wordcount'] ?? 0);
                    if ($wc > 0) {
                        $burst_words_list[] = $wc;
                    }
                    break;

                case 'selection':
                    $cursor_moves += (int)($payload['cursormoves'] ?? 1);
                    break;

                case 'wpm_snapshot':
                    $wpm = (int)($payload['wpm'] ?? 0);
                    if ($wpm > 0) {
                        $wpm_snapshots[] = $wpm;
                    }
                    break;
            }
        }

        // --- Derived metrics ---
        $interkey_mean    = !empty($ikdelays) ? array_sum($ikdelays) / count($ikdelays) : 0.0;
        $interkey_std_dev = !empty($ikdelays) ? self::std_dev($ikdelays) : 0.0;
        $entropy_score    = self::entropy_from_sd($interkey_std_dev);

        // TypeShield-matched derived signals (v1.2.112).
        // Shannon IKI entropy: measures whether keystroke intervals cluster in
        // very few timing bands (low entropy = robotic). TypeShield threshold < 0.35.
        $iki_shannon  = self::iki_shannon_entropy($ikdelays);
        // Autocorrelation lag-1: human baseline ≈ 0.1; highly repetitive or
        // artificially jittered rhythms deviate significantly. TypeShield flags
        // |autocorr − 0.1| > 0.5.
        $iki_autocorr = self::iki_autocorr_lag1($ikdelays);
        // Speed-burst CV: human typing speed is highly variable; constant rate
        // (low CV < 0.3) indicates robotic or AI-streamed content.
        $speed_cv     = self::speed_burst_cv($wpm_snapshots);

        $average_wpm  = !empty($wpm_snapshots) ? array_sum($wpm_snapshots) / count($wpm_snapshots) : 0.0;
        $wpm_std_dev  = !empty($wpm_snapshots) ? self::std_dev($wpm_snapshots) : 0.0;

        // BUG-EG-WPM-FALLBACK (v1.2.53): When no wpm_snapshot events exist (student typed
        // for < 60 s without triggering the periodic window), estimate WPM from the final
        // submitted text word count and the measured typing time.
        // BUG-EG-WPM-ZERO (v1.2.53): The blur-based wpm_snapshot in tracker.js captures WPM
        // for short sessions — this fallback covers the PHP observer path (no JS snapshots)
        // and the rare case where blur fired but the snapshot payload was 0.
        if ($average_wpm <= 0.0 && $typing_time > 0 && $finaltext !== '') {
            $word_count = preg_match_all('/\b\w+\b/', strip_tags($finaltext));
            if ($word_count > 0) {
                $typing_minutes = $typing_time / 60000.0;
                if ($typing_minutes > 0) {
                    $average_wpm = round($word_count / $typing_minutes, 2);
                }
            }
        }

        $pause_mean    = !empty($pauses_major) ? array_sum($pauses_major) / count($pauses_major) : 0.0;
        $pause_std_dev = !empty($pauses_major) ? self::std_dev($pauses_major) : 0.0;

        $burst_count   = count($burst_words_list);
        $burst_mean    = $burst_count > 0 ? array_sum($burst_words_list) / $burst_count : 0.0;
        $burst_std_dev = $burst_count > 0 ? self::std_dev($burst_words_list) : 0.0;

        $backspace_ratio  = $total_keystrokes > 0 ? $backspaces / $total_keystrokes : 0.0;

        $thinking_pause_count = 0;
        foreach ($pauses_all as $p) {
            if ($p >= 800 && $p <= 2000) {
                $thinking_pause_count++;
            }
        }
        $thinking_pause_score = $pausecount > 0
            ? $thinking_pause_count / max(1, count($pauses_all))
            : 0.0;

        // Linguistic metrics (if available)
        $sentence_variance = (float)($linguistic['sentence_variance'] ?? 0.0);
        $vocab_diversity   = (float)($linguistic['vocab_diversity']   ?? 0.0);
        $rare_word_ratio   = (float)($linguistic['rare_word_ratio']   ?? 0.0);
        // FIX-EG-SINGLE-SENTENCE (v1.2.140): sentence_count is needed to gate the
        // linguistic fallback correctly for single-sentence answers.
        $sentence_count_ling = (int)($linguistic['sentence_count'] ?? 0);

        // --- Derived: typing speed in chars/sec across the full session ---
        // Used by Signal 3 to detect AI-speed text generation.
        $session_total_ms = ($session_end !== null && $session_start !== null)
            ? max(1, $session_end - $session_start) : 1;

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
        $text_chars = ($finaltext !== '')
            ? (int)mb_strlen(html_entity_decode(strip_tags($finaltext), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : 0;

        // --- Full metrics array ---
        $metrics = [
            'charsadded'             => $charsadded,
            'pastecount'             => $pastecount,
            'paste_events'           => $pastecount,
            'large_inserts'          => $large_inserts,
            'large_insert_max_delta' => $large_insert_max_delta,
            'burstsuspicious'        => $burstsuspicious,
            'backspaces'             => $backspaces,
            'backspace_count'        => $backspaces,
            'delete_count'           => $deletes,
            'cursor_moves'           => $cursor_moves,
            'total_keystrokes'       => $total_keystrokes,
            'pausecount'             => $pausecount,
            'pause_count'            => $pausecount,
            'longpauses'             => $longpauses,
            'typing_time'            => $typing_time,
            'idle_time'              => $idle_time,
            'average_wpm'            => round($average_wpm, 2),
            'wpm_std_dev'            => round($wpm_std_dev, 2),
            'interkey_mean'          => round($interkey_mean, 2),
            'interkey_std_dev'       => round($interkey_std_dev, 2),
            'pause_mean'             => round($pause_mean, 2),
            'pause_std_dev'          => round($pause_std_dev, 2),
            'burst_count'            => $burst_count,
            'burst_mean'             => round($burst_mean, 2),
            'burst_std_dev'          => round($burst_std_dev, 2),
            'backspace_ratio'        => round($backspace_ratio, 4),
            'thinking_pause_score'   => round($thinking_pause_score, 4),
            'entropy_score'          => round($entropy_score, 4),
            'iki_shannon'            => round($iki_shannon, 4),
            'iki_autocorr'           => round($iki_autocorr, 4),
            'speed_burst_cv'         => round($speed_cv, 4),
            'sentence_variance'      => $sentence_variance,
            'vocab_diversity'        => $vocab_diversity,
            'rare_word_ratio'        => $rare_word_ratio,
            'avgdelta'               => round($interkey_mean, 2),
            'revisionratio'          => round($backspace_ratio, 4),
            // v1.2.113: stored so explainer.php can compute Signal 12 keystroke ratio
            // without approximation (text_chars = final submitted text length after
            // HTML entity decode, the same value used by the Signal 12 gate).
            'text_chars'             => $text_chars,
        ];

        // FIX-EG-S8-DEDUP (v1.2.124): Compute $events_empty_for_scoring HERE —
        // before the signal engine — so Signals 8 and 9 can be gated to prevent
        // double-counting with the linguistic fallback block below.
        //
        // Previously this was computed AFTER the signal engine (at the linguistic
        // fallback block). When no events were captured but text was present:
        //   • $text_gate = true → the signal engine ran → S8 fired (+10 pts)
        //   • Linguistic fallback also fired for the same sentence_variance (+20 pts)
        //   → sentence_variance was counted TWICE, inflating no-event scores by 10 pts
        //     (S8) + 0–15 pts (S9 vocab) for a total overcounting of up to 25 pts.
        //
        // With the computation moved here, Signals 8 and 9 are gated with
        // !$events_empty_for_scoring: they only fire in regular (events-present)
        // sessions, while the linguistic fallback handles the no-events case with its
        // own amplified weights (designed to push AI-uniform text into MEDIUM range).
        $events_empty_for_scoring = empty($all_events)
            || (empty($events) && $qslot > 0 && !empty($all_events));

        // --- 0-100 Score Engine (v1.2.112 — TypeShield-aligned thresholds) ---
        //
        // Design goals:
        //   REAL TYPING  → LOW    (0–29):  natural speed, pauses, corrections
        //   FAST/AI      → MEDIUM (30–65): no pauses/corrections, robotic rhythm
        //   PASTE        → HIGH   (66+):   paste detected → 60 pts + Signals 5+7 = 85+
        //   MIXED        → MEDIUM or HIGH depending on other signals
        $score = 0.0;

        // v1.2.113: Per-signal point tracker. Keys 1–12 = signal number; values =
        // points contributed by that signal. Stored in metricsjson as 'signal_breakdown'
        // so student.php can display a TypeShield-quality per-signal attribution table.
        // Reflects raw contributions before the false-positive cap; the final score100
        // may be lower than array_sum($signal_pts) when the cap fires.
        $signal_pts = [];

        // FIX-EG-METRICS-CPS-PASTEFRAC (v1.2.124): Initialise here so they are always
        // defined and can be stored in metricsjson even if the scoring gate doesn't fire.
        $chars_per_sec = 0.0;
        $paste_frac_raw = 0.0;

        // Use paste insertlen as a fallback for the minchars gate.
        $effective_charsadded = max($charsadded, $paste_chars_total);

        $text_gate = ($text_chars >= $minchars) || ($qslot > 0 && $text_chars > 0);
        if ($effective_charsadded >= $minchars || $pastecount > 0 || $large_inserts > 0 || $text_gate) {

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
            //   paste_frac < 0.05 (incidental, e.g. autocorrect): +10 pts
            //   paste_frac ≥ 0.05 (meaningful paste): max(30, 60 × paste_frac)
            //     → guarantees MEDIUM threshold (≥30) for any meaningful paste in a
            //       mixed session while scaling toward HIGH for large fractions.
            //     → e.g. 50 % paste: max(30, 30) = 30 → MEDIUM + secondary signals.
            //     → e.g. 80 % paste: max(30, 48) = 48 → MEDIUM-HIGH + secondary signals.
            // Pure paste (no keystrokes) or unknown ratio → conservative full +60.
            // ----------------------------------------------------------------
            if ($pastecount > 0 || $large_inserts > 0) {
                // Estimate paste chars: prefer native paste insertlen, fall back to
                // the largest large_insert delta (TinyMCE clipboard proxy).
                $paste_chars_known = $paste_chars_total > 0 ? $paste_chars_total
                                   : ($large_insert_max_delta > 0 ? $large_insert_max_delta : 0);

                // Pre-compute paste fraction for the threshold checks below.
                $paste_frac_raw = ($paste_chars_known > 0 && $text_chars > 0)
                    ? min(1.0, $paste_chars_known / max(1, $text_chars))
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
                //   • Ctrl/Cmd+V pastes (keystrokes==2 but entire answer was pasted)
                //   • Sessions where paste size is slightly under-reported but still high
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
                //   Example: student types 3 keystrokes then pastes 500 chars → ratio=6/500=0.012 < 0.25.
                //   Example: honest typist writes 200 chars → typically 220–280 keystrokes → ratio≥1.0.
                //
                // The 0.25 threshold safely excludes genuine mixed sessions where the
                // student typed a significant portion of the answer (they always produce
                // ratio ≥ 0.5 because edits, deletions and corrections inflate keystrokes).
                $keystroke_ratio_val = ($text_chars > 0 && $total_keystrokes > 0)
                    ? ($total_keystrokes / max(1, $text_chars))
                    : ($total_keystrokes === 0 ? 0.0 : 1.0);
                if ($total_keystrokes <= 2 || $paste_frac_raw >= 0.75
                        || ($text_chars > 100 && $keystroke_ratio_val < 0.25)) {
                    // Pure paste (keystrokes = 0, 1, or 2 modifier keys) OR substantially
                    // pasted (≥ 75 %) OR keystroke ratio too low to be genuine typing:
                    // award full signal even if modifier-key presses inflated
                    // $total_keystrokes, or if clipboard reported a compressed insertlen.
                    // PASTE-WEIGHT (v1.2.212): scale by admin-configured multiplier.
                    $s1 = (int)round(60 * $paste_weight);
                    $score += $s1; $signal_pts[1] = $s1;
                } elseif ($paste_chars_known > 0 && $text_chars > 0) {
                    // Mixed session with measurable paste size: weight proportionally.
                    $paste_frac = $paste_frac_raw;
                    if ($paste_frac >= 0.05) {
                        // Meaningful paste (≥5 % of answer): guarantee MEDIUM (30 pts floor).
                        // PASTE-WEIGHT (v1.2.212): scale floor and ceiling by paste_weight.
                        $s1 = (int)round(max(30.0 * $paste_weight, 60.0 * $paste_frac * $paste_weight));
                        $score += $s1; $signal_pts[1] = $s1;
                    } else {
                        // Incidental paste (<5 % of answer — autocorrect, short snippet).
                        // PASTE-WEIGHT (v1.2.212): scale by paste_weight.
                        $s1 = (int)round(10 * $paste_weight);
                        $score += $s1; $signal_pts[1] = $s1;
                    }
                } else {
                    // Paste detected but size unknown (TinyMCE clipboard unreadable) or
                    // no finaltext: conservative full signal.
                    // PASTE-WEIGHT (v1.2.212): scale by admin-configured multiplier.
                    $s1 = (int)round(60 * $paste_weight);
                    $score += $s1; $signal_pts[1] = $s1;
                }
            }

            // ----------------------------------------------------------------
            // SIGNAL 2: Large insert events (delta > 20 chars) — catches
            // medium-sized pastes that don't meet the burst threshold (max 20 pts).
            // PASTE-WEIGHT (v1.2.212): scaled by the same paste_weight multiplier
            // as Signal 1 — both signals represent the same underlying behaviour
            // (offline preparation + paste) and should be dampened together.
            // ----------------------------------------------------------------
            if ($large_inserts > 0) {
                $s2 = (int)round(min(20, $large_inserts * 8) * $paste_weight);
                $score += $s2; $signal_pts[2] = $s2;
            }

            // ----------------------------------------------------------------
            // SIGNAL 3: Typing speed — chars per second across the session.
            // > 15 cps = superhuman (AI generation or paste without JS paste event).
            // > 8 cps  = very fast but borderline (max 30 / 15 pts).
            // ----------------------------------------------------------------
            $chars_per_sec = ($effective_charsadded * 1000.0) / $session_total_ms;
            if ($chars_per_sec > 15.0 && $effective_charsadded > 50) {
                $score += 30; $signal_pts[3] = 30;
            } elseif ($chars_per_sec > 8.0 && $effective_charsadded > 50) {
                $score += 15; $signal_pts[3] = 15;
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
            // ----------------------------------------------------------------
            $s4_chars = max($effective_charsadded, $text_chars);
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
            $s4_has_evidence = ($total_keystrokes > 0 || $pastecount > 0 || $large_inserts > 0);
            if ($pausecount === 0 && $s4_chars > 100 && $s4_has_evidence) {
                $score += 20; $signal_pts[4] = 20;
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
            $is_paste_session = ($pastecount > 0 || $large_inserts > 0)
                             && ($total_keystrokes === 0
                                 || ($text_chars > 100 && $keystroke_ratio_val < 0.25))
                             && !$partial_paste_only;
            if ($is_paste_session) {
                $score += 15; $signal_pts[5] = 15; // Pure paste: no manual corrections whatsoever
            } elseif ($total_keystrokes > 0 && $backspace_ratio < 0.02) {
                $score += 15; $signal_pts[5] = 15;
            } elseif ($total_keystrokes > 0 && $backspace_ratio < 0.04) {
                $score += 8; $signal_pts[5] = 8;
            }

            // ----------------------------------------------------------------
            // SIGNAL 6: Near-zero session typing time — content appeared almost
            // instantly, consistent with paste or programmatic insertion (max 25 pts).
            // Only fires when typing_time is non-zero (i.e. some events fired) but
            // still very short. Pure single-event paste sessions have typing_time=0
            // and are already covered by Signals 1 + 3 + 4 + 5.
            // ----------------------------------------------------------------
            if ($typing_time > 0 && $typing_time < 10000 && $effective_charsadded > 50) {
                $score += 25; $signal_pts[6] = 25;
            }

            // ----------------------------------------------------------------
            // SIGNAL 7: Typing entropy — suspiciously smooth rhythm (max 10 pts).
            //
            // v1.2.112 (TypeShield-aligned): prefer Shannon IKI entropy when
            // ≥ 30 inter-key delays are available. Shannon entropy is more
            // discriminating than SD-based entropy for AI-retyped content
            // (TypeShield threshold < 0.35). Fall back to SD-based score for
            // short sessions. Pure paste sessions still get the full bonus.
            // ----------------------------------------------------------------
            if ($is_paste_session) {
                $score += 10; $signal_pts[7] = 10; // Pure paste: no rhythm data = max entropy suspicion
            } elseif ($iki_shannon > 0 && count($ikdelays) >= 30) {
                // Shannon entropy available — use TypeShield's threshold.
                if ($iki_shannon < 0.35) {
                    $score += 10; $signal_pts[7] = 10;
                } elseif ($iki_shannon < 0.55) {
                    $score += 5; $signal_pts[7] = 5;
                }
            } elseif ($entropy_score > 0 && $entropy_score < 0.3) {
                $score += 10; $signal_pts[7] = 10;
            } elseif ($entropy_score > 0 && $entropy_score < 0.5) {
                $score += 5; $signal_pts[7] = 5;
            }

            // ----------------------------------------------------------------
            // SIGNAL 8: Linguistic — sentence length uniformity (max 10 pts)
            //
            // FIX-EG-S8-DEDUP (v1.2.124): Gated with !$events_empty_for_scoring.
            // When no behavioural events were captured, the linguistic fallback
            // block (below) handles sentence_variance with amplified +20 pts.
            // Allowing S8 to also fire (+10) for the same metric caused double-
            // counting that inflated no-event scores by up to 10 extra pts.
            // ----------------------------------------------------------------
            if (!$events_empty_for_scoring) {
                if ($sentence_variance > 0 && $sentence_variance < 6) {
                    $score += 10; $signal_pts[8] = 10;
                } elseif ($sentence_variance > 0 && $sentence_variance < 12) {
                    $score += 5; $signal_pts[8] = 5;
                }
            }

            // ----------------------------------------------------------------
            // SIGNAL 9: Linguistic — vocabulary diversity (max 5 pts)
            //
            // FIX-EG-S8-DEDUP (v1.2.124): Same gate as Signal 8 — suppressed
            // for no-events sessions to prevent double-counting with the
            // linguistic fallback's +15 pts for vocab_diversity < 0.30.
            // ----------------------------------------------------------------
            if (!$events_empty_for_scoring) {
                if ($vocab_diversity > 0 && $vocab_diversity < 0.30) {
                    $score += 5; $signal_pts[9] = 5;
                } elseif ($vocab_diversity > 0 && $vocab_diversity < 0.40) {
                    $score += 2; $signal_pts[9] = 2;
                }
            }

            // ----------------------------------------------------------------
            // SIGNAL 10 (TypeShield-matched, v1.2.112): IKI autocorrelation
            // lag-1. Human baseline ≈ 0.1. Highly repetitive (autocorr >> 0.1)
            // or artificially jittered (autocorr << 0) strongly suggests
            // automated input. Requires ≥ 30 IKI samples (max 10 pts).
            // ----------------------------------------------------------------
            if (count($ikdelays) >= 30) {
                $autocorr_dev = abs($iki_autocorr - 0.1);
                if ($autocorr_dev > 0.5) {
                    $score += 10; $signal_pts[10] = 10;
                } elseif ($autocorr_dev > 0.3) {
                    $score += 5; $signal_pts[10] = 5;
                }
            }

            // ----------------------------------------------------------------
            // SIGNAL 11 (TypeShield-matched, v1.2.112): Speed-burst coefficient
            // of variation. Human typing speed is highly variable across 10-second
            // windows; robotic/AI content is produced at a constant rate (low CV).
            // TypeShield threshold CV < 0.3 for sessions ≥ 30 s (max 10 pts).
            // ----------------------------------------------------------------
            if (count($wpm_snapshots) >= 3 && $typing_time >= 30000) {
                if ($speed_cv < 0.30) {
                    $score += 10; $signal_pts[11] = 10;
                } elseif ($speed_cv < 0.50) {
                    $score += 5; $signal_pts[11] = 5;
                }
            }

            // ----------------------------------------------------------------
            // SIGNAL 12 (TypeShield-matched, v1.2.112): Keystroke ratio.
            // Humans produce ~1.4 keystrokes per final character (edits, deletions).
            // A ratio well below 1.0 means most characters were inserted without
            // typing — strong paste/AI insertion indicator (max 10 pts).
            // TypeShield flags ratio < 0.5. Only fires for sessions with real
            // text content to avoid false positives on empty/very short answers.
            // ----------------------------------------------------------------
            if ($text_chars > 100 && $total_keystrokes > 0) {
                $keystroke_ratio = $total_keystrokes / max(1, $text_chars);
                if ($keystroke_ratio < 0.5) {
                    $score += 10; $signal_pts[12] = 10;
                } elseif ($keystroke_ratio < 0.8) {
                    $score += 5; $signal_pts[12] = 5;
                }
            }
        }

        // LINGUISTIC FALLBACK (v1.2.81 / v1.2.86):
        //
        // Fires when behavioural events are unavailable for THIS scoring pass:
        //   Case A (v1.2.81): $all_events is empty — JS never loaded, quiz timed out,
        //     or an older session before the sesskey/attemptkey fixes.
        //   Case B (v1.2.86): $all_events is non-empty BUT $events is empty after the
        //     per-question qslot filter — events exist in the DB but none carry a qslot
        //     tag matching this question (qslot detection failed in tracker.js, common
        //     with TinyMCE 6 on some Moodle themes). Without this extension, the scorer
        //     sees no behavioural signals AND no linguistic boost → score=0 → LOW for
        //     every per-question record, hiding pastes that the aggregate DID detect.
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
        //   • qslot=0 (assignment/forum/aggregate): $run_linguistic_fallback=true ✓
        //   • qslot>0 with paste found (pastecount or large_inserts > 0): true ✓
        //   • qslot>0 with events present (events_empty_for_scoring=false): false
        //     anyway (gate already requires events_empty_for_scoring) ✓
        $run_linguistic_fallback = $events_empty_for_scoring
            && $text_chars > 100
            && ($qslot === 0 || $pastecount > 0 || $large_inserts > 0);

        $linguistic_fallback_pts = 0;
        if ($run_linguistic_fallback) {
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
            //   Single sentence (variance = 0.0, count = 1)  → count >= 1 AND 0.0 < 6.0 → +20 ✓
            //   Multiple uniform sentences (variance 1–5.9)  → count >= 1 AND < 6.0     → +20 ✓
            //   Multiple varied sentences (variance ≥ 6.0)   → count >= 1 AND >= 6.0    →   0 ✓
            //   No text parsed (count = 0, variance = 0.0)   → count = 0                →   0 ✓
            if ($sentence_count_ling >= 1 && $sentence_variance < 6.0)  {
                $score += 20; $linguistic_fallback_pts += 20;
            }
            if ($vocab_diversity   > 0.0 && $vocab_diversity   < 0.30) {
                $score += 15; $linguistic_fallback_pts += 15;
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
        //   > 10 cps  — extremely fast, impossible sustained (120 WPM = ~10 cps)
        //              → +50 pts — strong HIGH indicator
        //   > 4 cps   — very fast, borderline (48 WPM sustained over whole attempt)
        //              → +25 pts — MEDIUM-HIGH indicator
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
        //   • Events exist (so events_empty_for_scoring is false),
        //   • But none of them are paste/large_insert (pastecount=0, large_inserts=0),
        //   • There are some keystrokes (> 0, to exclude pure no-JS sessions handled
        //     by the events_empty_for_scoring branch),
        //   • And the keystroke-to-character ratio is < 0.25 — physically impossible
        //     for genuine typing (human typing always produces ≥ 0.5 keystrokes per
        //     final character including edits and corrections).
        //
        // The 0.25 threshold safely excludes honest mixed sessions where the student
        // typed a significant portion: real typing always produces ratio ≥ 0.5 because
        // backspaces, cursor moves, and re-typing inflate keystrokes well above the
        // final character count.
        $server_cps = 0.0;
        $s13_no_paste_evidence = (!$events_empty_for_scoring
            && $pastecount     === 0
            && $large_inserts  === 0
            && $total_keystrokes > 0
            && $text_chars     > 100
            && ($total_keystrokes / max(1, $text_chars)) < 0.25);
        // FIX-EG-PERQ-NO-EVENTS-LOW (v1.2.173): Apply the same gate as the linguistic
        // fallback to Signal 13's events_empty_for_scoring path. When per-question
        // scoring (qslot > 0) has no events AND no paste, server CPS gives no useful
        // signal about THIS question specifically — the timer covers the entire attempt
        // (both questions combined). Only fire via events_empty when qslot=0 or paste found.
        $s13_empty_path = $events_empty_for_scoring
            && ($qslot === 0 || $pastecount > 0 || $large_inserts > 0);
        if (($s13_empty_path || $s13_no_paste_evidence)
                && $attempt_timestart > 0
                && $attempt_timefinish > $attempt_timestart
                && $text_chars > 50) {
            $attempt_duration_sec = $attempt_timefinish - $attempt_timestart;
            if ($attempt_duration_sec > 0) {
                $server_cps = round($text_chars / $attempt_duration_sec, 4);
                if ($server_cps > 10.0) {
                    // Impossible to type this fast over the whole attempt duration.
                    // Almost certainly a paste without JS event capture.
                    $signal_pts[13] = 50;
                    $score += 50;
                    error_log('[EssayGuard] FIX-EG-S13: server_cps=' . $server_cps
                        . ' > 10.0 → +50 pts HIGH signal.'
                        . ' s13_no_paste_evidence=' . (int)$s13_no_paste_evidence
                        . ' userid=' . $userid . ' cmid=' . $cmid . ' qslot=' . $qslot
                        . ' text_chars=' . $text_chars . ' duration_sec=' . $attempt_duration_sec
                        . ' keystrokes=' . $total_keystrokes);
                } elseif ($server_cps > 4.0) {
                    // Suspiciously fast for a sustained attempt (48+ WPM sustained).
                    $signal_pts[13] = 25;
                    $score += 25;
                    error_log('[EssayGuard] FIX-EG-S13: server_cps=' . $server_cps
                        . ' > 4.0 → +25 pts MEDIUM signal.'
                        . ' s13_no_paste_evidence=' . (int)$s13_no_paste_evidence
                        . ' userid=' . $userid . ' cmid=' . $cmid . ' qslot=' . $qslot
                        . ' text_chars=' . $text_chars . ' duration_sec=' . $attempt_duration_sec
                        . ' keystrokes=' . $total_keystrokes);
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
        //   • Honest fast typist (no paste, ≤ 144 wpm, natural entropy ≥ 0.30)
        //     → capped at 29 → LOW ✓
        //   • Very fast but plausible typist (96–144 wpm, no paste, no robotic entropy)
        //     → NEW: also capped at 29 → LOW (was incorrectly MEDIUM) ✓
        //   • AI retyper without paste (robotic entropy < 0.30, Signal 7 fires)
        //     → cap does NOT apply → score remains in MEDIUM/HIGH range ✓
        //   • Superhuman typist (chars_per_sec > 12.0, > 144 wpm)
        //     → cap does NOT apply ✓
        //   • Copy-paste session (pastecount > 0 or large_inserts > 0)
        //     → cap does NOT apply → HIGH as expected ✓
        //   • No-events session (TinyMCE ate all events, events_empty_for_scoring=true)
        //     → cap does NOT apply → linguistic fallback can push AI-uniform text to MEDIUM ✓
        //
        // Baseline deviation (applied after this cap) can still push an otherwise-capped
        // session into MEDIUM if the deviation is extreme — a student typing dramatically
        // faster or more uniformly than their own baseline IS a meaningful signal.
        if ($pastecount === 0 && $large_inserts === 0 && !$events_empty_for_scoring
                && $chars_per_sec <= 12.0
                && ($entropy_score === 0.0 || $entropy_score >= 0.30)) {
            $score = min($score, 29.0);
            error_log('[EssayGuard] FIX-EG-TYPING-FALSE-POSITIVE: paste gate fired,'
                . ' score capped at 29 (was ' . $score . ').'
                . ' pastecount=' . $pastecount . ' large_inserts=' . $large_inserts
                . ' chars_per_sec=' . round($chars_per_sec, 2)
                . ' entropy_score=' . $entropy_score
                . ' userid=' . $userid . ' cmid=' . $cmid . ' qslot=' . $qslot);
        }

        $score = max(0.0, min(100.0, $score));

        // --- Baseline deviation (adds up to 15 pts extra) ---
        $baseline_deviation = fingerprint::deviation_score($userid, $metrics);
        $baseline_fp        = fingerprint::get($userid);
        $baseline_status    = $baseline_fp ? $baseline_fp->baseline_status : 'none';

        if ($baseline_deviation > 0.3) {
            $score = min(100.0, $score + ($baseline_deviation * 15));
        }

        // v1.2.113: Persist per-signal breakdown so student.php can show a
        // TypeShield-quality signal attribution table. Values reflect raw
        // contributions before the false-positive cap; the final score100 may
        // be lower than array_sum($signal_pts) when the cap fires.
        $metrics['signal_breakdown']       = $signal_pts;
        // Linguistic fallback points are tracked separately — they don't map 1:1
        // to a numbered signal (they apply inflated weights vs the normal path)
        // and only fire when no behavioural events are available. Storing them
        // here lets student.php show an accurate pre-cap total even for no-events
        // sessions where signal_pts[] is empty or sparse.
        $metrics['linguistic_fallback_pts'] = $linguistic_fallback_pts;

        // FIX-EG-DIAG-KSR (v1.2.122): Persist keystroke_ratio to metricsjson so
        // diag.php Section 3 can verify it without re-computing. Previously this
        // was computed locally inside Signal 12 but never stored — diag.php always
        // showed "session scored before v1.2.113" for this field, even on fully
        // fresh v1.2.121 attempts with captured keystrokes. The diagnostic was
        // misleading admins into thinking they had stale data when the real issue
        // was a live event-capture failure.
        if ($text_chars > 100 && $total_keystrokes > 0) {
            $metrics['keystroke_ratio'] = round($total_keystrokes / max(1, $text_chars), 4);
        }

        $score100  = (int)round($score);
        $riskscore = $score100 / 100.0;
        $risklevel = self::risk_level($score100);

        // FIX-EG-METRICS-SCORE100 (v1.2.124): Persist the final capped score100 so
        // diag.php Section 6 can verify whether the false-positive cap applied (raw
        // signal total > score100 means the cap fired). Previously "Not stored —
        // session scored before v1.2.113" was shown for ALL sessions.
        // FIX-EG-METRICS-CPS-PASTEFRAC (v1.2.124): Persist chars_per_sec and
        // paste_frac so diag.php Section 3 shows these values instead of blanks.
        $metrics['score100']      = $score100;
        $metrics['chars_per_sec'] = round($chars_per_sec, 4);
        $metrics['paste_frac']    = round($paste_frac_raw, 4);
        // FIX-EG-SERVER-TIMING (v1.2.126): Persist server_cps so diag.php Section 6
        // can display and audit the server-side timing signal.
        $metrics['server_cps']    = round($server_cps, 4);

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
        if ($events_empty_for_scoring && $qslot === 0) {
            $perq_max_rs = $DB->get_field_sql(
                'SELECT MAX(riskscore) FROM {plagiarism_essayguard_sc}
                  WHERE userid = :userid AND cmid = :cmid AND attemptkey = :attemptkey AND qslot > 0',
                ['userid' => $userid, 'cmid' => $cmid, 'attemptkey' => $attemptkey]
            );
            if ($perq_max_rs !== false && $perq_max_rs !== null) {
                $perq_max_int = (int)round((float)$perq_max_rs * 100);
                if ($perq_max_int > $score100) {
                    error_log(sprintf(
                        '[EssayGuard] FIX-EG-AGG-PERQ-CONSISTENCY: elevating aggregate'
                        . ' from %d to %d (max per-question, no events)'
                        . ' userid=%d cmid=%d attemptkey=%s',
                        $score100, $perq_max_int, $userid, $cmid, substr($attemptkey, 0, 12)
                    ));
                    $score100  = $perq_max_int;
                    $riskscore = $score100 / 100.0;
                    $risklevel = self::risk_level($score100);
                    $metrics['score100']              = $score100;
                    $metrics['agg_elevated_from_perq'] = true;
                }
            }
        }

        // --- Explanations ---
        $explanations = explainer::explain($metrics, $risklevel, $baseline_fp);

        // --- Persist to DB ---
        // v1.2.14: upsert key includes qslot so per-question records are kept
        // separate from the aggregate record (qslot = 0).
        $existing = $DB->get_record('plagiarism_essayguard_sc', [
            'userid'     => $userid,
            'cmid'       => $cmid,
            'attemptkey' => $attemptkey,
            'qslot'      => $qslot,
        ]);

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
            'typing_time'          => $typing_time,
            'idle_time'            => $idle_time,
            'total_keystrokes'     => $total_keystrokes,
            'paste_events'         => $pastecount,
            'backspace_count'      => $backspaces,
            'delete_count'         => $deletes,
            'cursor_moves'         => $cursor_moves,
            'average_wpm'          => $metrics['average_wpm'],
            'wpm_std_dev'          => $metrics['wpm_std_dev'],
            'interkey_mean'        => $metrics['interkey_mean'],
            'interkey_std_dev'     => $metrics['interkey_std_dev'],
            'pause_count'          => $pausecount,
            'pause_mean'           => $metrics['pause_mean'],
            'pause_std_dev'        => $metrics['pause_std_dev'],
            'burst_count'          => $burst_count,
            'burst_mean'           => $metrics['burst_mean'],
            'burst_std_dev'        => $metrics['burst_std_dev'],
            'sentence_variance'    => $sentence_variance,
            'vocab_diversity'      => $vocab_diversity,
            'rare_word_ratio'      => $rare_word_ratio,
            'thinking_pause_score' => $metrics['thinking_pause_score'],
            'entropy_score'        => $metrics['entropy_score'],
            'explanationsjson'     => json_encode($explanations),
            'baseline_deviation'   => round($baseline_deviation, 4),
            'baseline_status'      => $baseline_status,
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
            //   (a) the newly computed score is 0,
            //   (b) the existing DB score is non-zero, AND
            //   (c) there are no events for this (userid, cmid, attemptkey, qslot)
            // — skip the DB write and return the preserved existing score. The
            // rescore_pending scheduled task (FIX-EG-RESCORE-TASK, v1.2.96) will
            // re-invoke score_attempt once real events arrive; at that point
            // $events_empty_for_scoring is false and the normal update path runs.
            if ($score100 === 0 && ((float)$existing->riskscore > 0.0) && $events_empty_for_scoring) {
                $preserved100 = (int)round((float)$existing->riskscore * 100);
                error_log(sprintf(
                    '[EssayGuard] score_attempt SKIP: no events, preserving existing'
                    . ' riskscore=%.2f for userid=%d cmid=%d qslot=%d attemptkey=%s',
                    (float)$existing->riskscore, $userid, $cmid, $qslot,
                    substr($attemptkey, 0, 12)
                ));
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
            $DB->insert_record('plagiarism_essayguard_sc', $record);
        }

        return [
            'riskscore'    => $riskscore,
            'risklevel'    => $risklevel,
            'score100'     => $score100,
            'metrics'      => $metrics,
            'explanations' => $explanations,
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

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
     */
    public static function entropy_from_sd(float $sd): float {
        if ($sd <= 0)  {
            return 0.0;
        }
        if ($sd < 60)  {
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

    // ── TypeShield-matched helper functions (v1.2.112) ─────────────────────────

    /**
     * Inter-key timing autocorrelation at lag-1.
     * Human baseline ≈ 0.1; highly repetitive (bot) or jittered (artificial
     * randomisation) both deviate significantly. TypeShield flags |lag1 − 0.1| > 0.5.
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
     */
    private static function iki_shannon_entropy(array $ikis, int $band_ms = 20): float {
        $n = count($ikis);
        if ($n < 2) {
            return 0.0;
        }
        $counts = [];
        foreach ($ikis as $v) {
            $bucket = (int)floor($v / $band_ms);
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
        }
        $entropy = 0.0;
        foreach ($counts as $c) {
            $p        = $c / $n;
            $entropy -= $p * log($p, 2);
        }
        $bucket_count = count($counts);
        $max_entropy  = $bucket_count > 1 ? log($bucket_count, 2) : 0.0;
        return $max_entropy > 0 ? min(1.0, $entropy / $max_entropy) : 0.0;
    }

    /**
     * Coefficient of variation for typing speed across WPM snapshot windows.
     * Low CV = constant typing rate = robotic. Human writers show high variance.
     * TypeShield threshold: CV < 0.3 (session ≥ 30 s) is suspicious.
     * Returns 1.0 (high variance — human) when fewer than 3 snapshots exist.
     */
    private static function speed_burst_cv(array $wpm_snapshots): float {
        $n = count($wpm_snapshots);
        if ($n < 3) {
            return 1.0;
        }
        $mu = array_sum($wpm_snapshots) / $n;
        if ($mu == 0.0) {
            return 0.0;
        }
        return self::std_dev($wpm_snapshots) / $mu;
    }
}
