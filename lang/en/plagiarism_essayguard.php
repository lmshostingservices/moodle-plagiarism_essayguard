<?php

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Essay Guard';
$string['pluginname_help'] = 'Essay Guard monitors student writing behaviour in real time to detect authenticity risks such as pasted content, large text insertions, unusually smooth typing rhythms, and writing speeds inconsistent with the student\'s own baseline.

While a student types in a Moodle assignment or quiz essay field, Essay Guard silently captures keystroke telemetry — key timings, pause patterns, paste events, and editing activity — and sends it to Moodle at a configurable flush interval. After each session the data is analysed to produce a risk score (Low, Medium, or High) and a plain-English list of the specific indicators that contributed to the score.

Over time, Essay Guard builds a personalised writing baseline for each student from their legitimate submissions. Once a stable baseline exists (typically 4+ submissions), risk scores become comparative — flagging when a student writes significantly faster, with fewer corrections, or with more uniform sentence structure than their own established pattern. Baseline confidence is shown on each submission\'s detail panel.

The instructor dashboard surfaces all risk scores across a course, sortable by risk level, with expandable indicator explanations designed to be informative without making accusations. Flagged submissions include suggested follow-up actions such as a short oral confirmation or a supervised rewrite. Raw telemetry data is automatically deleted after the configured retention period (default 90 days). Essay Guard does not make plagiarism determinations — it provides behavioural signals to support instructor judgement.';
$string['essayguard'] = 'Essay Guard';
$string['enabled'] = 'Enable Essay Guard';
$string['enabled_desc'] = 'Enable live typing process analysis for supported text submissions.';
$string['captureinterval'] = 'Flush interval (ms)';
$string['captureinterval_desc'] = 'How often the browser sends buffered typing telemetry to Moodle.';
$string['minchars'] = 'Minimum characters before scoring';
$string['minchars_desc'] = 'Do not calculate risk until this many characters have been typed.';
$string['maxburstchars'] = 'Large insertion threshold';
$string['maxburstchars_desc'] = 'Treat insertions above this size as a suspicious burst.';
$string['allowpaste'] = 'Allow paste';
$string['allowpaste_desc'] = 'If disabled, paste events are blocked. If enabled, paste is logged and scored.';
$string['paste_weight'] = 'Paste signal weight (%)';
$string['paste_weight_desc'] = 'Controls how much weight paste and large-insertion events contribute to the risk score (Signal 1 and Signal 2). 100 % = full weight (default). Set to 25 % if students are expected to write answers offline and paste them in — this prevents normal copy-paste behaviour from triggering a High risk badge. Set to 0 % to disable paste detection entirely. Other signals (typing speed, rhythm, linguistic analysis) are not affected.';
$string['risklabel'] = 'Essay Guard risk';
$string['risklow'] = 'Low';
$string['riskmedium'] = 'Medium';
$string['riskhigh'] = 'High';

// Privacy
$string['privacy:metadata'] = 'Essay Guard stores keystroke process metrics for academic integrity analysis.';
$string['privacy:metadata:essayguard_events'] = 'Live typing telemetry events.';
$string['privacy:metadata:essayguard_events:userid'] = 'User ID.';
$string['privacy:metadata:essayguard_events:cmid'] = 'Course module ID.';
$string['privacy:metadata:essayguard_events:contextid'] = 'Context ID.';
$string['privacy:metadata:essayguard_events:attemptkey'] = 'Attempt/session key.';
$string['privacy:metadata:essayguard_events:eventname'] = 'Event type.';
$string['privacy:metadata:essayguard_events:eventtime'] = 'Event timestamp.';
$string['privacy:metadata:essayguard_events:payloadjson'] = 'Event payload JSON.';
$string['privacy:metadata:essayguard_scores'] = 'Calculated session scores.';
$string['privacy:metadata:essayguard_scores:userid'] = 'User ID.';
$string['privacy:metadata:essayguard_scores:cmid'] = 'Course module ID.';
$string['privacy:metadata:essayguard_scores:attemptkey'] = 'Attempt/session key.';
$string['privacy:metadata:essayguard_scores:riskscore'] = 'Numeric risk score.';
$string['privacy:metadata:essayguard_scores:risklevel'] = 'Risk level.';
$string['privacy:metadata:essayguard_scores:metricsjson'] = 'Derived metrics JSON.';
$string['privacy:metadata:essayguard_scores:timemodified'] = 'Last updated timestamp.';
$string['privacy:metadata:essayguard_fingerprint'] = 'Student writing behaviour baseline.';
$string['privacy:metadata:essayguard_fingerprint:userid'] = 'User ID.';
$string['privacy:metadata:essayguard_fingerprint:samplecount'] = 'Number of submissions used to build the baseline.';
$string['privacy:export:fingerprint'] = 'Writing behaviour fingerprint';
$string['privacy:export:scores'] = 'Authenticity risk scores';
$string['privacy:export:telemetry'] = 'Keystroke telemetry summary';

// Admin
$string['savedconfigsuccess'] = 'Settings saved successfully.';
$string['eventlogservice'] = 'Log Essay Guard telemetry';
$string['cleanup_task'] = 'Essay Guard cleanup task';
$string['rescore_task'] = 'Essay Guard rescore pending attempts';
$string['retentiondays'] = 'Telemetry retention (days)';
$string['retentiondays_desc'] = 'Raw typing telemetry events older than this many days are automatically deleted by the nightly cleanup task. Session scores (risk levels, metrics) are retained permanently regardless of this setting. Default is 90 days. Set to 0 to disable automatic pruning.';
$string['siteid'] = 'AI Grader Site ID';
$string['siteid_desc'] = 'Your AI Grader site ID. Leave blank if AI Grader Central Config is installed (it will be read from there automatically).';
$string['apikey'] = 'AI Grader API Key';
$string['apikey_desc'] = 'Your AI Grader API key. Leave blank if AI Grader Central Config is installed (it will be read from there automatically).';

// Explanation Engine — instructor-facing, non-accusatory language
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

// Signal 10, 11, 12 explanations (v1.2.113 — TypeShield-matched signals)
$string['explain_ikiautocorr_high'] = 'Keystroke timing autocorrelation significantly deviates from natural human rhythm — consistent with programmatic or auto-generated text input.';
$string['explain_ikiautocorr_med'] = 'Keystroke timing autocorrelation shows irregular patterns — some deviation from natural human typing rhythm.';
$string['explain_speedcv_high'] = 'Typing speed implausibly constant across the session — natural writing shows significant speed variation while thinking, planning, and composing.';
$string['explain_speedcv_med'] = 'Typing speed less variable than typical student writing — slightly lower variability than expected during natural composition.';
$string['explain_keystrokeratio_high'] = 'Very few keystrokes relative to the submitted text length — indicates most content was inserted rather than typed character by character.';
$string['explain_keystrokeratio_med'] = 'Fewer keystrokes than expected for the submitted text length — may indicate partial content insertion.';

// Dashboard labels
$string['authenticity_risk'] = 'Authenticity Risk';
$string['baseline_confidence'] = 'Baseline Confidence';
$string['top_indicators'] = 'Top Indicators';
$string['score100_label'] = 'Risk Score';
$string['no_baseline'] = 'No baseline yet';
$string['baseline_preliminary'] = 'Preliminary baseline ({$a} samples)';
$string['baseline_stable'] = 'Stable baseline ({$a} samples)';
$string['instructor_review'] = 'Instructor review recommended';
$string['followup_viva'] = 'Consider a short oral follow-up';
$string['followup_rewrite'] = 'Consider a supervised rewrite task';
