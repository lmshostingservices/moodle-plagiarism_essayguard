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
 * Explanation Engine.
 *
 * Maps metrics to human-readable instructor explanations.
 * Language is deliberately non-accusatory and evidence-based.
 * Does NOT claim "AI detected" or "student cheated".
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class explainer {
    /**
     * Generate an array of explanation strings for instructor review.
     *
     * @param array  $metrics   All computed metrics (behavioural + linguistic)
     * @param string $risklevel The band the score fell into: low, medium or high
     * @param object|null $baseline The student's fingerprint record, or null
     * @return array  Array of explanation strings
     */
    public static function explain(array $metrics, string $risklevel, ?object $baseline = null): array {
        $explanations = [];

        // Paste events.
        $pastecount = (int)($metrics['paste_events'] ?? $metrics['pastecount'] ?? 0);
        if ($pastecount >= 3) {
            $explanations[] = get_string('explain_manypastes', 'plagiarism_essayguard', $pastecount);
        } else if ($pastecount > 0) {
            $explanations[] = get_string('explain_paste', 'plagiarism_essayguard', $pastecount);
        }

        // Suspicious burst insertions.
        $bursts = (int)($metrics['burstsuspicious'] ?? 0);
        if ($bursts > 0) {
            $explanations[] = get_string('explain_burst', 'plagiarism_essayguard', $bursts);
        }

        // Large inserts with no paste event (Signal 1 alternate path + Signal 2).
        // FIX-EG-EXPLAINER-GAPS (v1.2.225): analyser.php awards Signal 1 (up to 60 pts) and
        // Signal 2 (up to 20 pts) on $large_inserts alone — the FIX-EG-PASTE-DETECT path for
        // TinyMCE/Atto, which swallow the browser paste event so $pastecount stays 0. The
        // explainer only ever looked at $pastecount, so an editor-mediated paste — the most
        // common paste there is — could contribute 80 points and produce no sentence at all.
        // Gated on $pastecount === 0 because when a native paste WAS recorded the two paste
        // rules above already describe the same behaviour, and saying it twice is noise.
        $largeinserts = (int)($metrics['large_inserts'] ?? 0);
        if ($largeinserts > 0 && $pastecount === 0) {
            $explanations[] = get_string('explain_largeinsert', 'plagiarism_essayguard', $largeinserts);
        }

        // Session-wide characters per second (Signal 3).
        // FIX-EG-EXPLAINER-GAPS (v1.2.225): Signal 3 contributes up to 30 pts and had no rule,
        // so a session scored HIGH on speed alone showed a red badge with an empty reason list.
        // Thresholds mirror analyser.php Signal 3 (> 15 cps → 30 pts, > 8 cps → 15 pts). The
        // analyser's character gate is max($charsadded, $paste_chars_total) > 50; the latter is
        // not carried in $metrics, so $text_chars stands in for it — the final text is never
        // shorter than the paste that produced it, so the gate can only be more permissive,
        // never less. Wording notes that in a pasted session the figure measures the clipboard
        // rather than the student, which is the same reasoning as FIX-EG-PASTEWEIGHT-UNREACHABLE
        // in analyser.php, where this signal is scaled down for exactly that reason.
        $charspersec = (float)($metrics['chars_per_sec'] ?? 0);
        $speedchars  = max((int)($metrics['charsadded'] ?? 0), (int)($metrics['text_chars'] ?? 0));
        if ($speedchars > 50 && $charspersec > 15.0) {
            $explanations[] = get_string(
                'explain_fastcps_high',
                'plagiarism_essayguard',
                round($charspersec, 1)
            );
        } else if ($speedchars > 50 && $charspersec > 8.0) {
            $explanations[] = get_string(
                'explain_fastcps_med',
                'plagiarism_essayguard',
                round($charspersec, 1)
            );
        }

        // Near-instant insertion (Signal 6).
        // FIX-EG-EXPLAINER-GAPS (v1.2.225): Signal 6 contributes up to 25 pts and had no rule.
        // Gate mirrors analyser.php Signal 6 exactly (typing_time > 0 and < 10 s, with the same
        // > 50 character gate as Signal 3 above). The wording states only what the clock shows —
        // that there was not enough editing time to have typed the answer — and explicitly
        // declines to say where the text came from, because this measurement cannot tell.
        $typingtimes6 = (int)($metrics['typing_time'] ?? 0);
        if ($typingtimes6 > 0 && $typingtimes6 < 10000 && $speedchars > 50) {
            $explanations[] = get_string(
                'explain_instantinsertion',
                'plagiarism_essayguard',
                round($typingtimes6 / 1000, 1)
            );
        }

        // Backspace ratio — include zero-backspace (the most suspicious case).
        $backspaceratio = (float)($metrics['backspace_ratio'] ?? 0);
        $totalkeystrokesex = (int)($metrics['total_keystrokes'] ?? 0);
        if ($backspaceratio < 0.02 && $totalkeystrokesex > 0) {
            $explanations[] = get_string('explain_lowbackspace', 'plagiarism_essayguard');
        } else if ($backspaceratio > 0 && $backspaceratio < 0.04) {
            // Threshold aligned with Signal 3 in analyser.php (< 0.04 → 8 pts).
            $explanations[] = get_string('explain_slightlylowbackspace', 'plagiarism_essayguard');
        }

        // Typing rhythm (Signal 7).
        // FIX-EG-EXPLAINER-GAPS (v1.2.225): analyser.php Signal 7 has preferred Shannon IKI
        // entropy over the SD-based entropy score since v1.2.112 whenever >= 30 inter-key
        // delays are available, but the explainer only ever read entropy_score. An AI-retyped
        // session flagged on iki_shannon therefore scored 10 pts with nothing said about it.
        // The two are alternatives, not additions — the analyser scores one or the other — so
        // this mirrors that precedence rather than emitting two rhythm sentences for the same
        // observation. Shannon thresholds are analyser Signal 7's (< 0.35 / < 0.55).
        $ikishannon = (float)($metrics['iki_shannon'] ?? 0);
        $entropy    = (float)($metrics['entropy_score'] ?? 0);
        // Null on a record written before v1.2.225, meaning "this record cannot tell us".
        $hasrhythm  = array_key_exists('has_rhythm_data', $metrics)
            ? (bool)$metrics['has_rhythm_data'] : null;
        $ikcount    = array_key_exists('ikdelay_count', $metrics)
            ? (int)$metrics['ikdelay_count'] : null;
        if ($ikishannon > 0) {
            if ($ikishannon < 0.35) {
                $explanations[] = get_string('explain_ikishannon_high', 'plagiarism_essayguard');
            } else if ($ikishannon < 0.55) {
                $explanations[] = get_string('explain_ikishannon_med', 'plagiarism_essayguard');
            }
        } else if ($hasrhythm !== null ? ($hasrhythm && $entropy < 0.3) : ($entropy > 0 && $entropy < 0.3)) {
            // V1.2.225 FIX-EG-EXPLAINER-GATE-MISMATCH: `$entropy > 0` excluded exactly the
            // value v1.2.224 made the strongest rhythm evidence there is. An entropy of
            // 0.0 means either "a perfectly constant rhythm" - automation - or "no rhythm
            // was measured at all", and from this array alone those were indistinguishable,
            // so the rule stayed silent on both. The analyser now stores has_rhythm_data
            // (its own >= 30 delay test), which separates them: with rhythm data behind it
            // a 0.0 fires, without it stays silent. Old records have no such key and keep
            // the previous, conservative behaviour.
            $explanations[] = get_string('explain_lowentropy', 'plagiarism_essayguard');
        } else if ($hasrhythm !== null ? ($hasrhythm && $entropy < 0.5) : ($entropy > 0 && $entropy < 0.5)) {
            $explanations[] = get_string('explain_medentropy', 'plagiarism_essayguard');
        }

        // Pause behaviour
        //
        // v1.2.225 FIX-EG-EXPLAINER-GATE-MISMATCH: the analyser fires this signal on
        // max($effective_charsadded, $text_chars) > 100, but this rule required
        // charsadded > 300 and ignored the text length entirely. A paste through TinyMCE
        // reports charsadded = 0 with a 500-character answer, so the session scored +20
        // for having no thinking pauses and the teacher was shown no sentence saying so.
        // The analyser now stores its own gate value as s4_chars; use it where present.
        //
        // Records written before v1.2.225 have no s4_chars, so they keep the old
        // threshold and explain exactly as they always did rather than changing meaning
        // retroactively.
        $charsadded = (int)($metrics['charsadded'] ?? 0);
        $pausecount = (int)($metrics['pausecount'] ?? $metrics['pause_count'] ?? 0);
        $s4chars    = $metrics['s4_chars'] ?? null;
        $nopausegate = ($s4chars !== null) ? ((int)$s4chars > 100) : ($charsadded > 300);
        if ($pausecount === 0 && $nopausegate) {
            $explanations[] = get_string('explain_nopauses', 'plagiarism_essayguard');
        }

        // Sentence uniformity
        //
        // v1.2.225 FIX-EG-EXPLAINER-GATE-MISMATCH: FIX-EG-SINGLE-SENTENCE scores at a
        // variance of exactly 0.0, and `> 0` excluded it - the same "is this evidence or
        // is this nothing?" ambiguity as the entropy rule above. sentence_count tells the
        // two apart: at least one sentence measured means 0.0 is a real uniformity
        // reading. Old records without the count keep the previous behaviour.
        $sentencevariance = (float)($metrics['sentence_variance'] ?? 0);
        $sentcount = array_key_exists('sentence_count', $metrics)
            ? (int)$metrics['sentence_count'] : null;
        $variancemeasured = ($sentcount !== null) ? ($sentcount >= 1) : ($sentencevariance > 0);
        if ($variancemeasured && $sentencevariance < 6) {
            $explanations[] = get_string('explain_lowsentencevariance', 'plagiarism_essayguard');
        } else if ($variancemeasured && $sentencevariance < 12) {
            $explanations[] = get_string('explain_medsentencevariance', 'plagiarism_essayguard');
        }

        // Vocabulary diversity.
        $vocab = (float)($metrics['vocab_diversity'] ?? 0);
        if ($vocab > 0 && $vocab < 0.30) {
            $explanations[] = get_string('explain_lowvocab', 'plagiarism_essayguard');
        } else if ($vocab > 0 && $vocab < 0.40) {
            $explanations[] = get_string('explain_medvocab', 'plagiarism_essayguard');
        }

        // Thinking pause score.
        $thinking = (float)($metrics['thinking_pause_score'] ?? 0);
        if ($thinking > 0 && $thinking < 0.1 && $charsadded > 300) {
            $explanations[] = get_string('explain_lowthinking', 'plagiarism_essayguard');
        }

        // Fast interkey timing
        // Threshold aligned with Signal 8 in analyser.php (< 80 ms → 5 pts).
        $interkeymean = (float)($metrics['interkey_mean'] ?? 0);
        if ($interkeymean > 0 && $interkeymean < 80) {
            $explanations[] = get_string('explain_fastkeys', 'plagiarism_essayguard');
        }

        // IKI autocorrelation (Signal 10, v1.2.113 — TypeShield-matched)
        // v1.2.225 FIX-EG-EXPLAINER-GATE-MISMATCH: the analyser requires at least 30
        // inter-key delays before it trusts an autocorrelation; this rule fired on any
        // non-zero value, so a three-keystroke session could be told its rhythm was
        // machine-like when no such signal had scored. ikdelay_count carries the
        // analyser's own count. Old records have no count and keep the old behaviour.
        $ikiautocorr = (float)($metrics['iki_autocorr'] ?? 0);
        if ($ikiautocorr !== 0.0 && ($ikcount === null || $ikcount >= 30)) {
            $autocorrdev = abs($ikiautocorr - 0.1);
            if ($autocorrdev > 0.5) {
                $explanations[] = get_string('explain_ikiautocorr_high', 'plagiarism_essayguard');
            } else if ($autocorrdev > 0.3) {
                $explanations[] = get_string('explain_ikiautocorr_med', 'plagiarism_essayguard');
            }
        }

        // Speed-burst coefficient of variation (Signal 11, v1.2.113 — TypeShield-matched)
        // Gate mirrors analyser.php Signal 11: requires typing_time >= 30 s (30000 ms)
        // which ensures at least 3 WPM snapshot windows are available. The previous
        // gate used burst_count >= 3 (suspicious burst insertions — unrelated) and was
        // incorrect: it could suppress explanations for sessions that had enough windows
        // but zero large inserts, and fire spuriously when bursts were high but timing
        // data was insufficient. Fixed to match analyser's actual condition.
        $speedcv       = (float)($metrics['speed_burst_cv'] ?? 0);
        $typingtimeex = (int)($metrics['typing_time'] ?? 0);
        if ($speedcv > 0 && $typingtimeex >= 30000) {
            if ($speedcv < 0.30) {
                $explanations[] = get_string('explain_speedcv_high', 'plagiarism_essayguard');
            } else if ($speedcv < 0.50) {
                $explanations[] = get_string('explain_speedcv_med', 'plagiarism_essayguard');
            }
        }

        // Keystroke ratio (Signal 12, v1.2.113 — TypeShield-matched)
        // Uses text_chars (final submitted text length) stored in metrics since v1.2.113.
        $textcharsex = (int)($metrics['text_chars'] ?? 0);
        $keystrokesex = (int)($metrics['total_keystrokes'] ?? 0);
        if ($textcharsex > 100 && $keystrokesex > 0) {
            $kr = $keystrokesex / max(1, $textcharsex);
            if ($kr < 0.5) {
                $explanations[] = get_string('explain_keystrokeratio_high', 'plagiarism_essayguard');
            } else if ($kr < 0.8) {
                $explanations[] = get_string('explain_keystrokeratio_med', 'plagiarism_essayguard');
            }
        }

        // Server-side submission speed (Signal 13).
        // FIX-EG-EXPLAINER-GAPS (v1.2.225): Signal 13 is the largest single award in the
        // engine after Signal 1 — 50 pts on its own is enough for HIGH — and it had no rule.
        // It is also the one signal that fires precisely when the browser evidence is missing,
        // so every other rule above is silent too: the teacher saw a red badge and nothing
        // else. Thresholds mirror analyser.php Signal 13 (> 10 cps → 50 pts, > 4 cps → 25 pts);
        // server_cps is left at 0.0 by the analyser whenever that signal's own gates did not
        // pass, so reading the stored value cannot fire this rule for a session it did not score.
        $servercps = (float)($metrics['server_cps'] ?? 0);
        if ($servercps > 10.0) {
            $explanations[] = get_string(
                'explain_servercps_high',
                'plagiarism_essayguard',
                round($servercps, 1)
            );
        } else if ($servercps > 4.0) {
            $explanations[] = get_string(
                'explain_servercps_med',
                'plagiarism_essayguard',
                round($servercps, 1)
            );
        }

        // Baseline deviations (only if a stable/preliminary baseline exists).
        //
        // v1.2.225: every property is read with ?? 0. The fingerprint table always has all
        // five columns, but this method is also called with objects assembled elsewhere,
        // and a component that is simply absent should mean "no baseline to compare
        // against" rather than an undefined-property warning rendered into a teacher's
        // report page.
        if ($baseline !== null) {
            $baselinewpm = (float)($baseline->baseline_wpm ?? 0);
            $currentwpm  = (float)($metrics['average_wpm'] ?? 0);
            if ($baselinewpm > 0 && $currentwpm > 0) {
                $wpmratio = $currentwpm / $baselinewpm;
                if ($wpmratio > 1.8) {
                    $explanations[] = get_string(
                        'explain_fasterthanbaseline',
                        'plagiarism_essayguard',
                        ['baseline' => round($baselinewpm), 'current' => round($currentwpm)]
                    );
                }
            }

            $blbackspace = (float)($baseline->baseline_backspace_ratio ?? 0);
            if ($blbackspace > 0.05 && $backspaceratio < 0.02) {
                $explanations[] = get_string('explain_backspacebelowbaseline', 'plagiarism_essayguard');
            }

            $blsv = (float)($baseline->baseline_sentence_variance ?? 0);
            if ($blsv > 15 && $sentencevariance > 0 && $sentencevariance < 8) {
                $explanations[] = get_string('explain_uniformvsbaseline', 'plagiarism_essayguard');
            }

            // V1.2.225 FIX-EG-BASELINE-UNEXPLAINED: fingerprint::deviation_score() averages
            // FIVE components for up to +15 points - words per minute, backspace ratio,
            // sentence variance, typing-rhythm entropy and mean pause length - and only the
            // first three had a rule here. A student whose rhythm or pausing had shifted
            // sharply from their own established baseline was scored for it and told
            // nothing about it, which is the worst version of this defect: the comparison
            // is against the student's own past work, so it is both the most persuasive
            // evidence the plugin produces and the hardest for a teacher to guess at.
            //
            // The thresholds mirror deviation_score()'s own tolerances (0.3 for entropy,
            // 0.5 for pause mean), so a sentence appears when and only when that component
            // actually contributed.
            $blentropy = (float)($baseline->baseline_entropy ?? 0);
            $curentropy = (float)($metrics['entropy_score'] ?? 0);
            if (
                $blentropy > 0 && $curentropy > 0
                    && abs($curentropy - $blentropy) / $blentropy > 0.3
            ) {
                $explanations[] = get_string(
                    $curentropy < $blentropy
                        ? 'explain_rhythmvsbaseline_smoother'
                        : 'explain_rhythmvsbaseline_rougher',
                    'plagiarism_essayguard'
                );
            }

            $blpause = (float)($baseline->baseline_pause_mean ?? 0);
            $curpause = (float)($metrics['pause_mean'] ?? 0);
            if (
                $blpause > 0 && $curpause > 0
                    && abs($curpause - $blpause) / $blpause > 0.5
            ) {
                $explanations[] = get_string(
                    $curpause < $blpause
                        ? 'explain_pausevsbaseline_shorter'
                        : 'explain_pausevsbaseline_longer',
                    'plagiarism_essayguard'
                );
            }
        }

        // Positive signal — no concerns when clearly clean (low risk, no specific flags).
        // FIX-EG-EXPLAINER-DEADCODE (v1.2.113): removed dead '|| $risklevel === "mild"' branch —
        // risk_level() has not returned "mild" since v1.2.112; analyser always passes low/medium/high.
        //
        // FIX-EG-EXPLAINER-EMPTY (v1.2.225): the reassuring line stays gated on the low band —
        // it must not be widened to cover the gap below it. "Writing behaviour appears
        // consistent with normal student patterns" printed beside an amber or red badge would
        // contradict the badge, and asserting a clean session for a record the engine scored at
        // 66+ would be the one wrong thing this method could say.
        //
        // Instead every other band gets its own guaranteed line. Before this, a MEDIUM or HIGH
        // record that matched no rule returned [] and the teacher was shown a coloured badge
        // with an empty reason list — nothing to weigh, nothing to put to the student, and
        // nothing a misconduct process could be started from. The rules added above close the
        // known holes, but a rule set can only cover the signals it knows about, and future
        // signals will be added to the analyser before the explainer catches up; this branch
        // makes an empty list structurally impossible rather than merely unlikely.
        //
        // The fallback deliberately invents no behaviour. It states the score, says that no
        // single indicator was individually strong enough to describe, and sends the reader to
        // the per-signal breakdown — all of which is true by construction whenever it fires.
        // Naming a cause here would be a guess presented to a teacher as a finding.
        if (empty($explanations)) {
            if ($risklevel === 'low') {
                $explanations[] = get_string('explain_clean', 'plagiarism_essayguard');
            } else {
                $score100 = (int)round((float)($metrics['score100'] ?? 0));
                if ($score100 > 0) {
                    $explanations[] = get_string('explain_noreason', 'plagiarism_essayguard', $score100);
                } else {
                    // Score not carried in metrics (pre-v1.2.124 record, or a caller that
                    // builds metrics by hand) — say the same thing without quoting a figure
                    // rather than printing a fabricated 0.
                    $explanations[] = get_string('explain_noreason_noscore', 'plagiarism_essayguard');
                }
            }
        }

        return $explanations;
    }
}
