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
 * plagiarism_essayguard file.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Essay Guard';
$string['pluginname_help'] = "Essay Guard monitors student writing behaviour in real time to detect authenticity risks such as pasted content, large text insertions, unusually smooth typing rhythms, and writing speeds inconsistent with the student's own baseline.\n\nWhile a student types in a Moodle assignment or quiz essay field, Essay Guard silently captures keystroke telemetry — key timings, pause patterns, paste events, and editing activity — and sends it to Moodle at a configurable flush interval. After each session the data is analysed to produce a risk score (Low, Medium, or High) and a plain-English list of the specific indicators that contributed to the score.\n\nOver time, Essay Guard builds a personalised writing baseline for each student from their legitimate submissions. Once a stable baseline exists (typically 4+ submissions), risk scores become comparative — flagging when a student writes significantly faster, with fewer corrections, or with more uniform sentence structure than their own established pattern. Baseline confidence is shown on each submission's detail panel.\n\nThe instructor dashboard surfaces all risk scores across a course, sortable by risk level, with expandable indicator explanations designed to be informative without making accusations. Flagged submissions include suggested follow-up actions such as a short oral confirmation or a supervised rewrite. Raw telemetry data is automatically deleted after the configured retention period (default 90 days). Essay Guard does not make plagiarism determinations — it provides behavioural signals to support instructor judgement.";
$string['essayguard'] = 'Essay Guard';

// V1.2.219: db/access.php has declared plagiarism/essayguard:viewreport since the plugin
// shipped, but this string never existed — so every Permissions, Check permissions and
// Define roles page in every course rendered the raw placeholder
// [[essayguard:viewreport]] where the capability name should be. Moodle derives the
// string key from the capability name by dropping the 'plagiarism/' prefix.
$string['essayguard:viewreport'] = 'View Essay Guard behaviour analysis reports';
$string['essayguard:rescore'] = 'Re-score Essay Guard attempts in bulk';

// V1.2.219: Student-facing disclosure, returned by print_disclosure(). It previously
// returned an empty string, so students were never told any of this.
$string['disclosure_heading'] = 'Writing authenticity monitoring is active in this activity';
$string['disclosure_what'] = 'While you write in this activity, Essay Guard records how you type: individual keystrokes and their timing, pauses, corrections and deletions, and paste or drag-and-drop events including the size of the inserted text. It does not send what you write to anyone outside this Moodle site.';
$string['disclosure_why'] = 'This information is used to produce an academic-integrity risk indicator for your submission. The indicator is a signal for your teacher to consider — it is not by itself a finding of misconduct, and no decision about your work is made automatically from it.';
$string['disclosure_who'] = 'Your teachers and your site administrators can see the resulting risk indicator and the behavioural measurements behind it. Other students cannot.';
$string['disclosure_retention'] = 'The detailed keystroke telemetry is automatically deleted after {$a} days. The summary risk score for your submission is kept for as long as your submission is kept.';
$string['disclosure_retention_indefinite'] = 'Automatic deletion of keystroke telemetry is currently turned off on this site, so the telemetry is kept until an administrator removes it. Contact your site administrator for this site\'s retention policy.';
$string['enabled'] = 'Enable Essay Guard';
$string['enabled_desc'] = 'Enable live typing process analysis for supported text submissions.';
$string['captureinterval'] = 'Flush interval (ms)';
$string['captureinterval_desc'] = 'How often the browser sends buffered typing telemetry to Moodle.';
$string['minchars'] = 'Minimum characters before scoring';
$string['minchars_desc'] = 'Do not calculate risk until this many characters have been typed.';
$string['maxburstchars'] = 'Large insertion threshold';
$string['maxburstchars_desc'] = 'Treat insertions above this size as a suspicious burst.';
// V1.2.221: this setting has never blocked anything. tracker.js hardcodes blocked: 0 and
// never reads state.allowpaste - by design, since v1.2.64: detection runs AFTER submission
// and students must not be interrupted mid-exam. The old description told administrators
// the opposite, so a client could configure a "proctored" exam, leave the box unticked,
// and assure their assessors that pasting was prevented when it was not. The setting is
// retained so stored values are not orphaned, but it now describes what actually happens.
$string['footerreportbutton'] = 'Essay Guard Report';
$string['allowpaste'] = 'Paste logging (always on)';
$string['allowpaste_desc'] = 'Paste events are always permitted and always recorded. Essay Guard analyses them after submission rather than blocking them during the assessment, so a student is never interrupted mid-attempt. To reduce how much pasting affects the risk score, use the "Paste signal weight" setting instead. This checkbox has no effect and is retained only for backwards compatibility.';
$string['paste_weight'] = 'Paste signal weight (%)';
$string['paste_weight_desc'] = 'Controls how much a paste contributes to the risk score. 100 % = full weight (default). Set to 25 % if students are expected to write their answers offline and paste them in — this stops normal copy-paste behaviour from triggering a High risk badge. Set to 0 % to disable paste detection entirely.<br><br>In a session that is <em>entirely</em> a paste, this scales every signal that is describing that paste: the paste and large-insertion signals, and also typing speed, absence of thinking pauses, absence of corrections, near-instant insertion and rhythm — all of which a single paste triggers by definition. In a session where the student genuinely typed, those signals are measuring the typing and keep their full weight, so this setting cannot mask a student who typed suspiciously.<br><br>The server-side timing signal is deliberately <strong>not</strong> affected. It exists to catch a paste that left no trace in the browser, and weakening it here would blind the one check that still works when the tracker is defeated. Linguistic analysis is likewise unaffected.';
$string['risklow'] = 'Low';
$string['riskmedium'] = 'Medium';
$string['riskhigh'] = 'High';

// Privacy.
$string['privacy:metadata'] = 'Essay Guard stores keystroke process metrics for academic integrity analysis.';
$string['privacy:metadata:essayguard_events'] = 'Live typing telemetry events.';
$string['privacy:metadata:essayguard_events:userid'] = 'User ID.';
$string['privacy:metadata:essayguard_events:cmid'] = 'Course module ID.';
$string['privacy:metadata:essayguard_events:contextid'] = 'Context ID.';
$string['privacy:metadata:essayguard_events:attemptkey'] = 'Attempt/session key.';
$string['privacy:metadata:essayguard_events:eventname'] = 'Event type.';
$string['privacy:metadata:essayguard_events:eventtime'] = 'Event timestamp, in milliseconds, as reported by the browser.';
$string['privacy:metadata:essayguard_events:payloadjson'] = 'Event payload JSON.';
$string['privacy:metadata:essayguard_events:timecreated'] = 'Server time the event was received, used for data retention.';
$string['privacy:metadata:essayguard_scores'] = 'Calculated session scores.';
$string['privacy:metadata:essayguard_scores:userid'] = 'User ID.';
$string['privacy:metadata:essayguard_scores:cmid'] = 'Course module ID.';
$string['privacy:metadata:essayguard_scores:attemptkey'] = 'Attempt/session key.';
$string['privacy:metadata:essayguard_scores:riskscore'] = 'Numeric risk score.';
$string['privacy:metadata:essayguard_scores:risklevel'] = 'Risk level.';
$string['privacy:metadata:essayguard_scores:metricsjson'] = 'Derived metrics JSON.';
$string['privacy:metadata:essayguard_scores:timemodified'] = 'Last updated timestamp.';
// V1.2.219: the remaining _sc columns, previously undeclared.
$string['privacy:metadata:essayguard_scores:contextid'] = 'Context ID.';
$string['privacy:metadata:essayguard_scores:qslot'] = 'Quiz question slot number (0 = whole attempt).';
$string['privacy:metadata:essayguard_scores:explanationsjson'] = 'Human-readable explanations of the indicators that contributed to the score.';
$string['privacy:metadata:essayguard_scores:typing_time'] = 'Total active typing time in seconds.';
$string['privacy:metadata:essayguard_scores:idle_time'] = 'Total idle time in seconds.';
$string['privacy:metadata:essayguard_scores:total_keystrokes'] = 'Number of keystrokes recorded.';
$string['privacy:metadata:essayguard_scores:paste_events'] = 'Number of paste events recorded.';
$string['privacy:metadata:essayguard_scores:backspace_count'] = 'Number of backspace presses.';
$string['privacy:metadata:essayguard_scores:delete_count'] = 'Number of delete presses.';
$string['privacy:metadata:essayguard_scores:cursor_moves'] = 'Number of cursor movements.';
$string['privacy:metadata:essayguard_scores:average_wpm'] = 'Average typing speed in words per minute.';
$string['privacy:metadata:essayguard_scores:wpm_std_dev'] = 'Standard deviation of typing speed.';
$string['privacy:metadata:essayguard_scores:interkey_mean'] = 'Mean interval between keystrokes.';
$string['privacy:metadata:essayguard_scores:interkey_std_dev'] = 'Standard deviation of the interval between keystrokes.';
$string['privacy:metadata:essayguard_scores:pause_count'] = 'Number of pauses.';
$string['privacy:metadata:essayguard_scores:pause_mean'] = 'Mean pause length.';
$string['privacy:metadata:essayguard_scores:pause_std_dev'] = 'Standard deviation of pause length.';
$string['privacy:metadata:essayguard_scores:burst_count'] = 'Number of typing bursts.';
$string['privacy:metadata:essayguard_scores:burst_mean'] = 'Mean burst length.';
$string['privacy:metadata:essayguard_scores:burst_std_dev'] = 'Standard deviation of burst length.';
$string['privacy:metadata:essayguard_scores:sentence_variance'] = 'Variance of sentence length in the submitted text.';
$string['privacy:metadata:essayguard_scores:vocab_diversity'] = 'Vocabulary diversity (type-token ratio) of the submitted text.';
$string['privacy:metadata:essayguard_scores:rare_word_ratio'] = 'Proportion of rare words in the submitted text.';
$string['privacy:metadata:essayguard_scores:thinking_pause_score'] = 'Ratio of thinking pauses to total pauses.';
$string['privacy:metadata:essayguard_scores:entropy_score'] = 'Typing rhythm entropy score.';
$string['privacy:metadata:essayguard_scores:baseline_deviation'] = 'Deviation from this student\'s own writing baseline.';
$string['privacy:metadata:essayguard_scores:baseline_status'] = 'Confidence level of this student\'s baseline.';
$string['privacy:metadata:essayguard_fingerprint'] = 'Student writing behaviour baseline.';
$string['privacy:metadata:essayguard_fingerprint:userid'] = 'User ID.';
$string['privacy:metadata:essayguard_fingerprint:samplecount'] = 'Number of submissions used to build the baseline.';
// V1.2.219: the remaining _fp columns, previously undeclared.
$string['privacy:metadata:essayguard_fingerprint:baseline_wpm'] = 'Baseline typing speed in words per minute.';
$string['privacy:metadata:essayguard_fingerprint:baseline_pause_mean'] = 'Baseline mean pause length.';
$string['privacy:metadata:essayguard_fingerprint:baseline_backspace_ratio'] = 'Baseline backspace ratio.';
$string['privacy:metadata:essayguard_fingerprint:baseline_burst_mean'] = 'Baseline mean burst length.';
$string['privacy:metadata:essayguard_fingerprint:baseline_sentence_variance'] = 'Baseline sentence length variance.';
$string['privacy:metadata:essayguard_fingerprint:baseline_vocab_diversity'] = 'Baseline vocabulary diversity.';
$string['privacy:metadata:essayguard_fingerprint:baseline_entropy'] = 'Baseline typing rhythm entropy.';
$string['privacy:metadata:essayguard_fingerprint:baseline_interkey_mean'] = 'Baseline mean interval between keystrokes.';
$string['privacy:metadata:essayguard_fingerprint:baseline_status'] = 'Baseline confidence level.';
$string['privacy:metadata:essayguard_fingerprint:timemodified'] = 'Last updated timestamp.';

// V1.2.219: external transmission to lms-labs.com — declared for the first time.
$string['privacy:metadata:lms_labs_licence'] = 'Essay Guard contacts the LMS Labs licence server to verify this site\'s licence and to read site-wide plugin settings. No student work, student identity or telemetry is sent — only this site\'s own credentials.';
$string['privacy:metadata:lms_labs_licence:siteid'] = 'The site ID identifying this Moodle site to the licence server.';
$string['privacy:metadata:lms_labs_licence:apikey'] = 'The API key authenticating this Moodle site to the licence server.';

// V1.2.219: user preferences — declared for the first time.
$string['privacy:metadata:preference:essayguard_ak'] = 'The Essay Guard typing-session key linking a student\'s telemetry to a specific activity.';
$string['privacy:metadata:preference:essayguard_lastscore'] = 'The timestamp of the last Essay Guard scoring pass for an activity, used to throttle re-scoring.';
$string['privacy:export:fingerprint'] = 'Writing behaviour fingerprint';
$string['privacy:export:scores'] = 'Authenticity risk scores';
$string['privacy:export:telemetry'] = 'Keystroke telemetry summary';

// Admin.
$string['savedconfigsuccess'] = 'Settings saved successfully.';
// V1.2.219: shown when Moodle's core plagiarism subsystem is off. Replaces the
// set_config('enableplagiarism', 1) that db/install.php and db/upgrade.php used to
// perform on the administrator's behalf.
$string['notice_enableplagiarism'] = 'Essay Guard will not run until Moodle\'s plagiarism subsystem is switched on. Go to Site administration > Advanced features and tick "Enable plagiarism plugins". Essay Guard does not change this site-wide setting for you, because it also affects any other plagiarism plugin installed on this site.';
$string['cleanup_task'] = 'Essay Guard cleanup task';
$string['rescore_task'] = 'Essay Guard rescore pending attempts';
// V1.2.219: new scheduled task that moves the lms-labs.com licence/settings HTTPS calls
// off the request path.
$string['refresh_licence_task'] = 'Essay Guard refresh licence and platform settings';
$string['retentiondays'] = 'Telemetry retention (days)';
$string['retentiondays_desc'] = 'Raw typing telemetry events older than this many days are automatically deleted by the nightly cleanup task. Session scores (risk levels, metrics) are retained permanently regardless of this setting. Default is 90 days. Set to 0 to disable automatic pruning.';
$string['siteid'] = 'AI Grader Site ID';
$string['siteid_desc'] = 'Your AI Grader site ID. Leave blank if AI Grader Central Config is installed (it will be read from there automatically).';
$string['apikey'] = 'AI Grader API Key';
$string['apikey_desc'] = 'Your AI Grader API key. Leave blank if AI Grader Central Config is installed (it will be read from there automatically).';

// Explanation Engine — instructor-facing, non-accusatory language.
$string['explain_clean'] = 'Writing behaviour appears consistent with normal student patterns.';
$string['explain_paste'] = 'Content was pasted {$a} time(s) during the session.';
$string['explain_manypastes'] = '{$a} paste events detected — significantly more than typical writing sessions.';
$string['explain_burst'] = '{$a} large text insertion(s) detected (above configured threshold).';
$string['explain_lowbackspace'] = 'Very low editing activity — fewer corrections than typical for this length of writing.';
$string['explain_slightlylowbackspace'] = 'Editing activity slightly lower than expected — fewer revisions during composition.';
$string['explain_lowentropy'] = 'Typing rhythm unusually smooth — inter-key timing more regular than typical human writing.';
$string['explain_medentropy'] = 'Typing rhythm slightly more regular than expected.';
$string['explain_nopauses'] = 'No thinking pauses detected during a substantial piece of writing — unusual for original composition.';
$string['explain_lowsentencevariance'] = 'Sentence lengths unusually uniform throughout the submission.';
$string['explain_medsentencevariance'] = 'Sentence structure somewhat more uniform than typical student writing.';
$string['explain_lowvocab'] = 'Vocabulary variety lower than expected for this word count.';
$string['explain_medvocab'] = 'Vocabulary variety slightly below typical range.';
$string['explain_lowthinking'] = 'Ratio of thoughtful pauses to total pauses is unusually low.';
$string['explain_fastkeys'] = 'Inter-key typing speed unusually fast — faster than typical skilled typists.';
$string['explain_fasterthanbaseline'] = 'Writing speed significantly higher than this student\'s baseline (baseline: {$a->baseline} wpm, this submission: {$a->current} wpm).';
$string['explain_backspacebelowbaseline'] = 'Editing behaviour significantly lower than this student\'s normal writing pattern.';
$string['explain_uniformvsbaseline'] = 'Sentence structure more uniform than this student\'s typical writing style.';
$string['explain_rhythmvsbaseline_smoother'] = 'The typing rhythm in this attempt is noticeably steadier than this student\'s own established pattern. This is a comparison against their previous work, not against other students.';
$string['explain_rhythmvsbaseline_rougher'] = 'The typing rhythm in this attempt is noticeably more irregular than this student\'s own established pattern. This is a comparison against their previous work, not against other students.';
$string['explain_pausevsbaseline_shorter'] = 'This student paused for markedly less time than they usually do while writing. Shorter pauses can mean the answer was prepared in advance, but they can equally mean a familiar topic or time pressure.';
$string['explain_pausevsbaseline_longer'] = 'This student paused for markedly longer than they usually do while writing. On its own this is not a concern; it is reported because it differs from their established pattern.';

// Signal 10, 11, 12 explanations (v1.2.113 — TypeShield-matched signals).
$string['explain_ikiautocorr_high'] = 'Keystroke timing autocorrelation significantly deviates from natural human rhythm — consistent with programmatic or auto-generated text input.';
$string['explain_ikiautocorr_med'] = 'Keystroke timing autocorrelation shows irregular patterns — some deviation from natural human typing rhythm.';
$string['explain_speedcv_high'] = 'Typing speed implausibly constant across the session — natural writing shows significant speed variation while thinking, planning, and composing.';
$string['explain_speedcv_med'] = 'Typing speed less variable than typical student writing — slightly lower variability than expected during natural composition.';
$string['explain_keystrokeratio_high'] = 'Very few keystrokes relative to the submitted text length — indicates most content was inserted rather than typed character by character.';
$string['explain_keystrokeratio_med'] = 'Fewer keystrokes than expected for the submitted text length — may indicate partial content insertion.';

// V1.2.225 FIX-EG-EXPLAINER-GAPS: Signals 1 (editor-mediated paste), 2, 3, 6, 7 (Shannon
// branch) and 13 could contribute points with nothing said about them, so a MEDIUM or HIGH
// record could be shown to a teacher with an empty reason list. Same non-accusatory register
// as the strings above: each says what was measured and what it does not establish.
$string['explain_largeinsert'] = '{$a} large block(s) of text appeared in the editor at once, with no paste event recorded. Some editors handle pasting internally so the browser never reports it, which is what this usually means; autocomplete and assistive software can produce the same pattern.';
$string['explain_fastcps_high'] = 'Text accumulated at around {$a} characters per second across the session — faster than sustained typing. Where content was pasted, this figure describes the arrival of the paste rather than the student\'s own writing speed.';
$string['explain_fastcps_med'] = 'Text accumulated at around {$a} characters per second across the session — fast for sustained composition, though within reach of a quick typist. Where content was pasted, this figure describes the arrival of the paste rather than the student\'s own writing speed.';
$string['explain_instantinsertion'] = 'The answer reached its submitted length after only about {$a} seconds of active editing — too little time for it to have been typed out. This indicates the content arrived in one piece; it does not show where the content came from.';
$string['explain_ikishannon_high'] = 'The gaps between keystrokes were almost identical throughout, far more regular than human typing, which varies constantly. Automated input produces this pattern, and so can steady copying from a source on screen.';
$string['explain_ikishannon_med'] = 'The gaps between keystrokes were more regular than most human typing, though not markedly so on its own.';
$string['explain_servercps_high'] = 'Measured on the server, about {$a} characters of answer were submitted per second of attempt time — too fast to have been typed. No usable keystroke record was captured for this answer, so the attempt timing is the only evidence of how the text arrived.';
$string['explain_servercps_med'] = 'Measured on the server, about {$a} characters of answer were submitted per second of attempt time, which is quick for an answer of this length. Little or no keystroke record was captured, so how the text arrived could not be observed directly.';
$string['explain_noreason'] = 'This submission scored {$a} out of 100 across the combined behavioural and linguistic checks, but no individual indicator was distinct enough to describe on its own. Open the detailed report to see which signals contributed and by how much.';
$string['explain_noreason_noscore'] = 'This submission was flagged by the combined behavioural and linguistic checks, but no individual indicator was distinct enough to describe on its own. Open the detailed report to see which signals contributed and by how much.';

// Dashboard labels.
$string['baseline_confidence'] = 'Baseline Confidence';
$string['no_baseline'] = 'No baseline yet';

// V1.2.219: strings for the class report (report.php). Every heading, column label,
// status banner and empty-state message on that page was previously hardcoded English.
$string['backtograding'] = 'Back to grading';
$string['classreport'] = 'Essay Guard — Class Report';
$string['classreportheading'] = 'Essay Guard &#8212; Class Behaviour Analysis Report';
$string['colactions'] = 'Actions';
$string['colanalysed'] = 'Analysed';
$string['colprimarysignal'] = 'Primary Signal';
$string['colquestions'] = 'Questions';
$string['colrisk'] = 'Risk';
$string['colscore'] = 'Score';
$string['colstudent'] = 'Student';
$string['crossheading'] = 'Cross-Student Behaviour Summary';
$string['crossnote'] = 'Essay Guard analyses individual typing behaviour — keystroke dynamics, paste events, and timing patterns. Students listed below had HIGH risk flags detected in their submission, indicating significant copy-paste or non-human typing signals. Review each report for full signal evidence.';
$string['emptystate'] = 'No Essay Guard data found for this activity. Data is captured while students type — if no records appear here, either no students have submitted yet, Essay Guard was not active when they completed the activity, or an earlier plugin bug prevented scoring.';
$string['nohighrisk'] = 'No high-risk submissions detected in this activity.';
$string['notscoring'] = 'Essay Guard is not scoring submissions.';
$string['overallsuffix'] = ' (OVERALL)';
$string['questionlabel'] = 'Question {$a}';
$string['rescoreprompt'] = 'Students submitted before Essay Guard was working?';
$string['rescoreprompttail'] = ' to score past quiz attempts using the answers already stored in Moodle.';
$string['rescoretoollink'] = 'Retroactive Rescore Tool';
$string['settingslink'] = 'Essay Guard Settings';
$string['siglabel1'] = 'Large paste detected';
$string['siglabel2'] = 'Low keystroke ratio';
$string['siglabel3'] = 'Superhuman typing speed';
$string['siglabel4'] = 'No natural pauses';
$string['siglabel5'] = 'High backspace ratio';
$string['siglabel12'] = 'Robotic keystroke entropy';
$string['siglabel13'] = 'Server-side speed anomaly';
$string['signalnumber'] = 'Signal {$a}';
$string['staterrors'] = 'Errors';
$string['stathigh'] = 'High Risk';
$string['statlow'] = 'Low Risk';
$string['statmedium'] = 'Medium Risk';
$string['statpending'] = 'Pending';
$string['stattotal'] = 'Total Submissions';
$string['submissionsheading'] = 'Submissions';
$string['useword'] = 'Use the ';
$string['viewreport'] = 'View Report';
$string['warncmdisabled'] = 'Essay Guard is <strong>disabled for this activity</strong>. Edit the quiz settings and enable "Essay Guard" under the plagiarism section.';
$string['warnglobaldisabled'] = 'Essay Guard is globally <strong>disabled</strong>. ';
$string['warnglobaldisabledlink'] = 'Enable it in Site Admin → Plugins → Plagiarism → Essay Guard';
$string['warnnotunlocked'] = 'This site is <strong>not unlocked</strong> for Essay Guard. Check your Site ID and API Key in ';
$string['warnnotunlockedtail'] = ', or verify your credit balance on the EssayGraderAI portal.';

// V1.2.219: strings for the settings page (settings.php) status panel and the
// Test Connection form, which previously emitted their text as hardcoded English.
$string['panelnocreds'] = '<strong>&#x26a0; Credentials not configured</strong> — enter your Site ID and API Key below and click Save. Until credentials are configured Essay Guard will not capture any data.';
$string['panelnotunlocked'] = '<strong>&#x2718; Essay Guard is NOT UNLOCKED</strong>';
$string['panelnotunlockedcached'] = '<strong>&#x2718; Essay Guard NOT UNLOCKED</strong> (last checked {$a} min ago). Visit the <a href="https://lms-labs.com" target="_blank">EssayGraderAI dashboard</a> to unlock Essay Guard (5,000 credits), then click <strong>Test Connection</strong> below.';
$string['paneldetailnocreds'] = '<br><small>Your Site ID or API Key is not configured. Enter them below and save, then click Test Connection again.</small>';
$string['paneldetaillocked'] = '<br><small>Your credentials were accepted but Essay Guard is not unlocked for this site. Visit the <a href="https://lms-labs.com" target="_blank">EssayGraderAI dashboard</a> to unlock Essay Guard (5,000 credits). Once unlocked, click <strong>Test Connection</strong> again to confirm.</small>';
$string['panelunknown'] = '<strong>&#x25cc; Unlock status unknown</strong> — click <strong>Test Connection</strong> below to verify.';
$string['refreshstatus'] = 'Refresh unlock status';
$string['panelunlocked'] = '<strong>&#x2714; Essay Guard is UNLOCKED</strong> — tracking is active and data will be recorded for all enabled activities.';
$string['panelunlockedcached'] = '<strong>&#x2714; Essay Guard UNLOCKED</strong> (last verified {$a} min ago). Tracking is active.';
$string['testconnection'] = 'Test Connection &amp; Unlock Status';
$string['testconnection_desc'] = 'Forces a live check to lms-labs.com and refreshes the unlock cache. Use this after unlocking Essay Guard in the dashboard to confirm tracking will activate immediately.';
$string['whereunlock'] = 'Where to unlock Essay Guard:';
$string['whereunlockbody'] = ' Log in to ';
$string['whereunlocktail'] = ' → Dashboard → Plugins → Essay Guard → Unlock (5,000 credits). Once unlocked, click <strong>Test Connection &amp; Unlock Status</strong> above to confirm.';

// V1.2.219: strings for the inline risk badge rendered by plagiarism_essayguard_render_badge()
// in lib.php. These were hardcoded English inside the renderer.
$string['badgeunmeasured'] = 'Not measured';
$string['tooltipunmeasured'] = 'Essay Guard captured no writing activity for this attempt, so it has NOT been assessed. This is not a low-risk result - it means nothing was measured. The usual cause is that the page\'s JavaScript did not run: another plugin throwing a script error, an unsupported editor, or the student disabling JavaScript. Check the browser console on the attempt page.';
$string['badgeerror'] = 'Essay Guard Error';
$string['badgelabel'] = 'Essay Guard {$a->level}{$a->suffix} · {$a->score}%';
$string['badgepending'] = 'Essay Guard Pending';
$string['badgesuffixoverall'] = ' (overall)';
$string['classreportlink'] = 'Class report';
$string['detaillink'] = 'Essay Guard detail';
$string['tooltiperror'] = 'Essay Guard could not analyse this submission. {$a}';
$string['tooltiperrordefault'] = 'Check plugin settings.';
$string['tooltiphigh'] = 'Essay Guard: HIGH authenticity risk ({$a}/100). Significant signals detected — a detailed review is strongly recommended.';
$string['tooltiplow'] = 'Essay Guard: LOW authenticity risk ({$a}/100). Writing behaviour appears consistent with normal student patterns.';
$string['tooltipmedium'] = 'Essay Guard: MEDIUM authenticity risk ({$a}/100). Some signals triggered — review the detailed report before drawing conclusions.';
$string['tooltippending'] = 'Essay Guard is analysing this submission. Reload the page in a moment to see the result.';

// V1.2.219: strings for the retroactive rescore tool (rescore.php).
$string['rescore_alreadyscored'] = 'All attempts already scored';
$string['rescore_attemptid'] = 'Attempt ID';
$string['rescore_attemptsfound'] = '{$a} finished attempts found';
$string['rescore_attemptsfoundone'] = '{$a} finished attempt found';
$string['rescore_backtoreport'] = 'Back to Essay Guard Report';
$string['rescore_batchnote'] = 'Each run is limited to a fixed batch so it cannot exceed the PHP execution time limit. Press <strong>Rescore</strong> again to continue — attempts already scored are skipped, so the run picks up where it left off.';
$string['rescore_colresult'] = 'Result';
$string['rescore_colstatus'] = 'Essay Guard Status';
$string['rescore_colstudent'] = 'Student';
$string['rescore_colsubmitted'] = 'Submitted';
$string['rescore_complete'] = 'Rescore complete.';
$string['rescore_detailaggregateonly'] = ' (aggregate only)';
$string['rescore_detailerror'] = 'Error: {$a}';
$string['rescore_detailnotext'] = 'No essay text found (quiz may not have essay questions, or attempt was not fully submitted)';
$string['rescore_detailskipped'] = 'Already has Essay Guard data (use "Overwrite" to re-score)';
$string['rescore_detailslots'] = ' (Q{$a} + aggregate)';
$string['rescore_errordisabled'] = 'Essay Guard is globally disabled. Enable it via Site Administration → Plugins → Plagiarism → Essay Guard.';
$string['rescore_errorlocked'] = 'Essay Guard is not unlocked for this site. Check your Site ID and API Key in the Essay Guard settings, or verify your credit balance on the EssayGraderAI portal.';
$string['rescore_heading'] = 'Essay Guard — Rescore Past Attempts';
$string['rescore_intro'] = 'Reads each student\'s quiz answers directly from the Moodle database and runs the full Essay Guard risk-scoring algorithm — exactly the same logic that runs when a student submits. Use this when students submitted before a working version of Essay Guard was installed. Behavioural signals (keystrokes, paste events) will not be available for old attempts, so the score is based on linguistic analysis and server-side timing only.';
$string['rescore_introheading'] = 'What does this do?';
$string['rescore_noattempts'] = 'No finished quiz attempts found for this activity.';
$string['rescore_notscored'] = 'Not yet scored';
$string['rescore_onlyquiz'] = 'Essay Guard rescore is only available for quiz activities.';
$string['rescore_overwrite'] = 'Overwrite all &amp; re-score ({$a} total)';
$string['rescore_pagetitle'] = 'Essay Guard — Rescore past attempts';
$string['rescore_previewheading'] = 'Attempt Preview';
$string['rescore_previewnote'] = 'Showing the {$a} most recent finished attempts.';
$string['rescore_remaining'] = '{$a} attempt(s) not yet processed.';
$string['rescore_scorebutton'] = 'Score {$a} unscored attempts';
$string['rescore_scorebuttonone'] = 'Score {$a} unscored attempt';
$string['rescore_scored'] = 'Scored';
$string['rescore_statusdisabled'] = 'Essay Guard is globally disabled — rescoring will not run.';
$string['rescore_statuslockedstart'] = 'Essay Guard is not unlocked for this site. Rescoring will fail until your Site ID and API Key are configured in ';
$string['rescore_statuslockedtail'] = ', or until your credit balance is sufficient for automatic unlock.';
$string['rescore_statusok'] = 'Plugin is active and unlocked. Rescoring will work correctly.';
$string['rescore_summary'] = '{$a->scored} scored &nbsp;&middot;&nbsp; {$a->skipped} skipped (already had data) &nbsp;&middot;&nbsp; {$a->notext} no essay text found &nbsp;&middot;&nbsp; {$a->errors} errors';

// V1.2.219: strings for the per-student detail page (student.php) — headings, metric
// labels, the signal definition table, the evidence lines and the interpretation guide.
$string['appliedtitle'] = 'Applied — This supplementary factor was active and its points were added to the risk score.';
$string['avgwpmlabel'] = 'Avg WPM: {$a}';
$string['backtoclassreport'] = 'Back to class report';
$string['baselinebonusdesc'] = 'Writing speed / pattern deviates significantly from this student\'s baseline.';
$string['baselinebonusname'] = 'Baseline deviation bonus';
$string['baselinebuilding'] = 'Building ({$a}/5 submissions)';
$string['baselinestable'] = 'Stable ({$a} submissions)';
$string['colevidence'] = 'Evidence';
$string['colmax'] = 'Max';
$string['colnum'] = '#';
$string['colpts'] = 'Pts';
$string['colsignal'] = 'Signal';
$string['colstatus'] = 'Status';
$string['deviationscore'] = 'Deviation score: {$a}';
$string['evidence_correctionratio'] = 'Correction ratio: {$a->ratio}% of keystrokes ({$a->count} backspace/delete)';
$string['evidence_entropyscore'] = 'Entropy score: {$a}';
$string['evidence_ikiautocorr'] = 'Keystroke-interval autocorrelation: {$a->value} (|autocorr − 0.1| = {$a->dev}{$a->threshold})';
$string['evidence_ikisamples'] = 'Keystroke-interval samples: {$a}';
$string['evidence_keystrokeratiochars'] = 'Keystroke ratio: {$a} (keystrokes ÷ chars)';
$string['evidence_keystrokeratiolow'] = 'Keystroke ratio: {$a} (very low — paste suspected)';
$string['evidence_keystrokescaptured'] = 'Keystrokes captured: {$a}';
$string['evidence_nocorrections'] = 'Zero corrections recorded';
$string['evidence_pauses'] = 'Pauses > 2 s recorded: {$a}';
$string['evidence_pasteevents'] = 'Paste events captured: {$a}';
$string['evidence_pastefraction'] = 'Paste fraction: {$a}% of answer';
$string['evidence_sentencecount'] = 'Sentences analysed: {$a}';
$string['evidence_sentencevariance'] = 'Sentence length variance: {$a}';
$string['evidence_servercps'] = 'Server-side speed: {$a} chars/sec';
$string['evidence_shannoniki'] = 'Shannon IKI entropy: {$a}';
$string['evidence_speed'] = 'Speed: {$a} chars/sec';
$string['evidence_speedcv'] = 'Speed coefficient of variation: {$a}';
$string['evidence_typingtime'] = 'Typing time: {$a} s';
$string['evidence_typetoken'] = 'Type-token ratio: {$a}';
$string['finalscore'] = 'Final Score (after cap)';
$string['indicatorsheading'] = 'Indicators';
$string['interprethigh'] = '<strong style="color:#991b1b;">HIGH (65–100):</strong> Multiple strong indicators detected. A detailed review is strongly recommended before drawing conclusions.';
$string['interpretlow'] = '<strong style="color:#166534;">LOW (0–29):</strong> Writing behaviour appears consistent with authentic student patterns. No significant concern detected.';
$string['interpretmedium'] = '<strong style="color:#c2410c;">MEDIUM (30–64):</strong> Some signals triggered. Human review is recommended — contextual factors may explain the result.';
$string['interpretnote'] = 'Essay Guard uses behavioural and linguistic heuristics — it does not make definitive academic misconduct determinations. Always apply professional judgement.';
$string['interpretationguide'] = 'Interpretation Guide';
$string['keystrokeslabel'] = 'Keystrokes: {$a}';
$string['lastscored'] = 'Last scored: {$a}';
$string['lessword'] = 'less';
$string['lingfallbackdesc'] = 'No keystroke events captured — sentence uniformity and vocabulary diversity used at elevated weights as the only available evidence.';
$string['lingfallbackname'] = 'Linguistic pattern fallback';
$string['metric_avgwpm'] = 'Avg WPM';
$string['metric_backspace'] = 'Backspace count';
$string['metric_baselinedev'] = 'Baseline deviation';
$string['metric_delete'] = 'Delete count';
$string['metric_entropy'] = 'Entropy score';
$string['metric_idletime'] = 'Idle time (s)';
$string['metric_interkeymean'] = 'Interkey mean (ms)';
$string['metric_pastes'] = 'Paste events';
$string['metric_pausecount'] = 'Pause count';
$string['metric_sentencevariance'] = 'Sentence variance';
$string['metric_totalkeystrokes'] = 'Total keystrokes';
$string['metric_typingtime'] = 'Typing time (s)';
$string['metric_vocabdiversity'] = 'Vocab diversity';
$string['metric_wpmstddev'] = 'Words-per-minute std dev';
$string['metricsheading'] = 'Behavioural Metrics';
$string['moreword'] = 'more';
$string['nobreakdown'] = 'Signal breakdown available for v1.2.113+ records only.';
$string['nodata'] = 'No Essay Guard data found for this student in this activity.';
$string['pasteslabel'] = 'Pastes: {$a}';
$string['perquestionheading'] = 'Per-Question Analysis';
$string['precaptotal'] = 'Pre-cap total: {$a}';
$string['questionheading'] = 'Question';
$string['riskscorelabel'] = '{$a} Risk';
$string['riskword'] = 'Risk';
$string['showless'] = 'show less';
$string['showmore'] = 'show more';
$string['sig10desc'] = '|autocorr − 0.1| > 0.5 flags robotic or artificially jittered rhythm.';
$string['sig10name'] = 'Keystroke-interval autocorrelation [TypeShield]';
$string['sig11desc'] = 'Speed CV <0.30 across ≥3 WPM windows — implausibly constant typing rate.';
$string['sig11name'] = 'Speed-burst consistency [TypeShield]';
$string['sig12desc'] = 'keystrokes ÷ text_chars <0.5 — most content inserted rather than typed.';
$string['sig12name'] = 'Keystroke ratio [TypeShield]';
$string['sig13desc'] = 'Server timing: text_chars ÷ attempt duration >10 cps (impossible to type) or >4 cps (very fast).';
$string['sig13name'] = 'Server-side CPS [fallback]';
$string['sig1desc'] = 'Any paste event or large clipboard insertion detected.';
$string['sig1name'] = 'Paste / clipboard insert';
$string['sig2desc'] = 'Input events with delta >20 characters (TinyMCE clipboard proxy).';
$string['sig2name'] = 'Large text insertions (>20 chars)';
$string['sig3desc'] = 'Characters-per-second across the session (>8 cps = suspicious, >15 = superhuman).';
$string['sig3name'] = 'Typing speed';
$string['sig4desc'] = 'Zero major pauses (>2 s) for substantial content — no inter-typing gaps recorded.';
$string['sig4name'] = 'Thinking pauses absent';
$string['sig5desc'] = 'Backspace ratio <2% of keystrokes, or zero corrections on a paste-only session.';
$string['sig5name'] = 'Correction / backspace rate';
$string['sig6desc'] = 'Entire session typing time <10 s with >50 chars — content appeared almost instantly.';
$string['sig6name'] = 'Near-zero session time';
$string['sig7desc'] = 'Shannon IKI entropy <0.35 (TypeShield threshold) or SD-based entropy <0.3.';
$string['sig7name'] = 'Typing rhythm entropy';
$string['sig8desc'] = 'Sentence length variance <6 (very uniform) or <12 (somewhat uniform).';
$string['sig8name'] = 'Sentence length uniformity';
$string['sig9desc'] = 'Type-token ratio <0.30 (very low) or <0.40 (low).';
$string['sig9name'] = 'Vocabulary diversity';
$string['signalbreakdownheading'] = 'Signal Breakdown';
$string['signalbreakdownnote'] = 'Each row shows how many points the signal contributed. The false-positive cap may reduce the final score below the pre-cap total.';
$string['signalsword'] = 'signals';
$string['statusapplied'] = 'Applied';
$string['statusapplieddesc'] = 'A supplementary factor (baseline deviation or linguistic fallback) was active and contributed additional points to the score.';
$string['statusfired'] = 'Fired';
$string['statusfireddesc'] = 'This signal detected a suspicious behaviour pattern and its points were added to the risk score.';
$string['statusfiredtitle'] = 'Fired — This signal detected a suspicious behaviour pattern and its points were added to the risk score.';
$string['statuskey'] = 'Signal status key';
$string['statussilent'] = 'Silent';
$string['statussilentdesc'] = 'This signal was evaluated but found nothing suspicious. No points were added.';
$string['statussilenttitle'] = 'Silent — This signal was evaluated but found nothing suspicious. No points were added.';
$string['studentanswer'] = 'Student\'s Answer';
$string['typinglabel'] = 'Typing: {$a} s';

// V1.2.219: threshold annotations appended to the evidence lines on student.php.
// Each begins with a space because it is concatenated onto the measured value.
$string['thr_autocorrrobotic'] = ' dev > 0.5 — robotic';
$string['thr_autocorrsuspicious'] = ' dev > 0.3 — suspicious';
$string['thr_cvconstant'] = ' < 0.30 — constant rate';
$string['thr_cvlowvariation'] = ' < 0.50 — low variation';
$string['thr_entropyborderline'] = ' < 0.50 — borderline';
$string['thr_entropyrobotic'] = ' < 0.30 — robotic';
$string['thr_ikiborderline'] = ' < 0.55 — borderline';
$string['thr_ikifired'] = ' < 0.35 — TypeShield fired';
$string['thr_krfired'] = ' < 0.5 — TypeShield fired';
$string['thr_krlow'] = ' < 0.8 — low';
$string['thr_scpsimpossible'] = ' > 10 — impossible to type';
$string['thr_scpsveryfast'] = ' > 4 — very fast';
$string['thr_speedsuperhuman'] = ' > 15 — superhuman';
$string['thr_speedveryfast'] = ' > 8 — very fast';
$string['thr_ttrlow'] = ' < 0.40 — low';
$string['thr_ttrverylow'] = ' < 0.30 — very low';
$string['thr_varsomewhatuniform'] = ' < 12 — somewhat uniform';
$string['thr_varveryuniform'] = ' < 6 — very uniform';
