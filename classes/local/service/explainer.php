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
 * Explanation Engine.
 *
 * Maps metrics to human-readable instructor explanations.
 * Language is deliberately non-accusatory and evidence-based.
 * Does NOT claim "AI detected" or "student cheated".
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class explainer {
    /**
     * Generate an array of explanation strings for instructor review.
     *
     * @param array  $metrics   All computed metrics (behavioural + linguistic)
     * @param string $risklevel low|medium|high
     * @param object|null $baseline The student's fingerprint record, or null
     * @return array  Array of explanation strings
     */
    public static function explain(array $metrics, string $risklevel, ?object $baseline = null): array {
        $explanations = [];

        // Paste events
        $pastecount = (int)($metrics['paste_events'] ?? $metrics['pastecount'] ?? 0);
        if ($pastecount >= 3) {
            $explanations[] = get_string('explain_manypastes', 'plagiarism_essayguard', $pastecount);
        } elseif ($pastecount > 0) {
            $explanations[] = get_string('explain_paste', 'plagiarism_essayguard', $pastecount);
        }

        // Suspicious burst insertions
        $bursts = (int)($metrics['burstsuspicious'] ?? 0);
        if ($bursts > 0) {
            $explanations[] = get_string('explain_burst', 'plagiarism_essayguard', $bursts);
        }

        // Backspace ratio — include zero-backspace (the most suspicious case).
        $backspace_ratio = (float)($metrics['backspace_ratio'] ?? 0);
        $total_keystrokes_ex = (int)($metrics['total_keystrokes'] ?? 0);
        if ($backspace_ratio < 0.02 && $total_keystrokes_ex > 0) {
            $explanations[] = get_string('explain_lowbackspace', 'plagiarism_essayguard');
        } elseif ($backspace_ratio > 0 && $backspace_ratio < 0.04) {
            // Threshold aligned with Signal 3 in analyser.php (< 0.04 → 8 pts).
            $explanations[] = get_string('explain_slightlylowbackspace', 'plagiarism_essayguard');
        }

        // Entropy score (typing rhythm)
        $entropy = (float)($metrics['entropy_score'] ?? 0);
        if ($entropy > 0 && $entropy < 0.3) {
            $explanations[] = get_string('explain_lowentropy', 'plagiarism_essayguard');
        } elseif ($entropy > 0 && $entropy < 0.5) {
            $explanations[] = get_string('explain_medentropy', 'plagiarism_essayguard');
        }

        // Pause behaviour
        $charsadded = (int)($metrics['charsadded'] ?? 0);
        $pausecount = (int)($metrics['pausecount'] ?? $metrics['pause_count'] ?? 0);
        if ($pausecount === 0 && $charsadded > 300) {
            $explanations[] = get_string('explain_nopauses', 'plagiarism_essayguard');
        }

        // Sentence uniformity
        $sentence_variance = (float)($metrics['sentence_variance'] ?? 0);
        if ($sentence_variance > 0 && $sentence_variance < 6) {
            $explanations[] = get_string('explain_lowsentencevariance', 'plagiarism_essayguard');
        } elseif ($sentence_variance > 0 && $sentence_variance < 12) {
            $explanations[] = get_string('explain_medsentencevariance', 'plagiarism_essayguard');
        }

        // Vocabulary diversity
        $vocab = (float)($metrics['vocab_diversity'] ?? 0);
        if ($vocab > 0 && $vocab < 0.30) {
            $explanations[] = get_string('explain_lowvocab', 'plagiarism_essayguard');
        } elseif ($vocab > 0 && $vocab < 0.40) {
            $explanations[] = get_string('explain_medvocab', 'plagiarism_essayguard');
        }

        // Thinking pause score
        $thinking = (float)($metrics['thinking_pause_score'] ?? 0);
        if ($thinking > 0 && $thinking < 0.1 && $charsadded > 300) {
            $explanations[] = get_string('explain_lowthinking', 'plagiarism_essayguard');
        }

        // Fast interkey timing
        // Threshold aligned with Signal 8 in analyser.php (< 80 ms → 5 pts).
        $interkey_mean = (float)($metrics['interkey_mean'] ?? 0);
        if ($interkey_mean > 0 && $interkey_mean < 80) {
            $explanations[] = get_string('explain_fastkeys', 'plagiarism_essayguard');
        }

        // IKI autocorrelation (Signal 10, v1.2.113 — TypeShield-matched)
        $iki_autocorr = (float)($metrics['iki_autocorr'] ?? 0);
        if ($iki_autocorr !== 0.0) {
            $autocorr_dev = abs($iki_autocorr - 0.1);
            if ($autocorr_dev > 0.5) {
                $explanations[] = get_string('explain_ikiautocorr_high', 'plagiarism_essayguard');
            } elseif ($autocorr_dev > 0.3) {
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
        $speed_cv       = (float)($metrics['speed_burst_cv'] ?? 0);
        $typing_time_ex = (int)($metrics['typing_time'] ?? 0);
        if ($speed_cv > 0 && $typing_time_ex >= 30000) {
            if ($speed_cv < 0.30) {
                $explanations[] = get_string('explain_speedcv_high', 'plagiarism_essayguard');
            } elseif ($speed_cv < 0.50) {
                $explanations[] = get_string('explain_speedcv_med', 'plagiarism_essayguard');
            }
        }

        // Keystroke ratio (Signal 12, v1.2.113 — TypeShield-matched)
        // Uses text_chars (final submitted text length) stored in metrics since v1.2.113.
        $text_chars_ex = (int)($metrics['text_chars'] ?? 0);
        $keystrokes_ex = (int)($metrics['total_keystrokes'] ?? 0);
        if ($text_chars_ex > 100 && $keystrokes_ex > 0) {
            $kr = $keystrokes_ex / max(1, $text_chars_ex);
            if ($kr < 0.5) {
                $explanations[] = get_string('explain_keystrokeratio_high', 'plagiarism_essayguard');
            } elseif ($kr < 0.8) {
                $explanations[] = get_string('explain_keystrokeratio_med', 'plagiarism_essayguard');
            }
        }

        // Baseline deviations (only if a stable/preliminary baseline exists)
        if ($baseline !== null) {
            $baseline_wpm = (float)$baseline->baseline_wpm;
            $current_wpm  = (float)($metrics['average_wpm'] ?? 0);
            if ($baseline_wpm > 0 && $current_wpm > 0) {
                $wpm_ratio = $current_wpm / $baseline_wpm;
                if ($wpm_ratio > 1.8) {
                    $explanations[] = get_string('explain_fasterthanbaseline', 'plagiarism_essayguard',
                        ['baseline' => round($baseline_wpm), 'current' => round($current_wpm)]);
                }
            }

            $bl_backspace = (float)$baseline->baseline_backspace_ratio;
            if ($bl_backspace > 0.05 && $backspace_ratio < 0.02) {
                $explanations[] = get_string('explain_backspacebelowbaseline', 'plagiarism_essayguard');
            }

            $bl_sv = (float)$baseline->baseline_sentence_variance;
            if ($bl_sv > 15 && $sentence_variance > 0 && $sentence_variance < 8) {
                $explanations[] = get_string('explain_uniformvsbaseline', 'plagiarism_essayguard');
            }
        }

        // Positive signal — no concerns when clearly clean (low risk, no specific flags).
        // FIX-EG-EXPLAINER-DEADCODE (v1.2.113): removed dead '|| $risklevel === "mild"' branch —
        // risk_level() has not returned "mild" since v1.2.112; analyser always passes low/medium/high.
        if (empty($explanations) && $risklevel === 'low') {
            $explanations[] = get_string('explain_clean', 'plagiarism_essayguard');
        }

        return $explanations;
    }
}
