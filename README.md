<p align="center">
  <a href="https://lmshostingservices.com">
    <img src="https://raw.githubusercontent.com/lmshostingservices/lms-labs/main/attached_assets/lms-hosting-logo.png" alt="LMS Hosting Services" height="60">
  </a>
</p>

> **LMS Labs** is the Moodle plugin division of [LMS Hosting Services](https://lmshostingservices.com) — Australia's Moodle™ Certified Partner.

---

# Essay Guard — Privacy-First Writing Authenticity Engine

**Version:** see `version.php` and the top of [CHANGELOG.md](CHANGELOG.md) - deliberately not repeated here, so it cannot go stale  
**Moodle Compatibility:** Moodle 4.4 - 5.2 (`$plugin->requires = 2024042200`, `$plugin->supported = [404, 502]`)  
**Plugin Type:** Plagiarism Plugin (`plagiarism_essayguard`)  
**Licence:** GNU GPL v3 or later

---

## What It Does

Essay Guard analyses **how students write**, not just what they submit. It captures typing behaviour in the browser and reports measurements across three classes of evidence:

**Observed** — the clipboard or a bulk insertion was used. This is the strongest thing the plugin reports, because it is a thing that happened rather than an inference about style.

**Behavioural** — how the typing itself proceeded: speed, pauses, corrections, rhythm, and whether the pattern looks like composition or like transcription from something on screen.

**Compared with the student** — how this submission sits against that student's own established pattern, measured in their own standard deviations, in the same kind of activity.

What it does **not** do, and cannot: tell you that text was written by AI. No keystroke signal can distinguish AI-authored text that a student retyped from any other text a student retyped — including their own notes. Essay Guard reports the behaviour; the judgement is the assessor's.

**Student work is analysed entirely inside Moodle.** No submitted text, no keystroke telemetry and no student identity is ever sent outside your server, and no external AI service is involved in scoring.

Essay Guard does make three outbound HTTPS calls to `lms-labs.com` — licence verification, one-time unlock, and site-wide plugin settings. Those calls carry **only this site's own Site ID and API key**. They carry no student data of any kind. They are declared in the plugin's Moodle privacy metadata under "LMS Labs licence server".

---

## Architecture

```
Student types in Moodle assignment/quiz
    ↓
tracker.js — captures keystroke events, inter-key timings, paste events, WPM snapshots, bursts
    ↓  (AJAX every 5s + on blur)
log_event.php — saves raw events, runs incremental score
    ↓  (at submission)
finalize_attempt.php — runs full linguistic + behavioural analysis, updates fingerprint
    ↓
analyser.php — 0–100 score engine (8 signals + baseline deviation)
linguistic.php — sentence variance, vocab diversity, rare word ratio
fingerprint.php — student baseline, EWMA rolling average
explainer.php — non-accusatory explanations for instructors
    ↓
plagiarism_essayguard_sc — score record with all metrics
plagiarism_essayguard_fp — student fingerprint / baseline
    ↓
Instructor sees riskbadge on submission + full report
```

---

## Detection Signals (0–100 Score)

Definitions live in `classes/local/signals.php` and every surface reads from there — this
table, the breakdown a teacher sees, and the scoring engine. They cannot drift apart.

The **evidence class** matters more than the points.

| # | Signal | Max | Evidence class |
|---|--------|-----|----------------|
| 1 | Paste / drop events | 60 | Observed |
| 2 | Large insertions | 20 | Observed |
| 3 | Characters per second | 30 | Behavioural |
| 4 | No thinking pauses | 20 | Behavioural |
| 5 | Backspace ratio | 15 | Behavioural |
| 6 | Near-zero session time | 25 | Behavioural |
| 7 | Keystroke rhythm entropy | 10 | Behavioural |
| 8 | Sentence length uniformity | 10 | Writing style |
| 9 | Vocabulary diversity | 5 | Writing style |
| 10 | Inter-key autocorrelation | 10 | Behavioural |
| 11 | Typing-speed variation | 10 | Behavioural |
| 12 | Keystroke ratio | 10 | Behavioural |
| 13 | Server-side typing speed | 50 | Behavioural |
| 14 | Linking-phrase density | 10 | Writing style |
| 15 | Paragraph uniformity | 10 | Writing style |
| 16 | Transcription pattern | 15 | Behavioural |
| — | Comparative deviation | +15 | Compared with the student |

**Writing-style signals are capped at 20 points between them** — below the Medium
threshold of 30. They can corroborate a behavioural finding; they can never produce one
on their own. This is deliberate: the innocent explanations for uniform, heavily
signposted prose — a second-language writer, a formal house style, a templated answer the
training package asks for — are at least as common as the guilty one.

Signals 1–7 are scaled by the site's **Paste signal weight** setting, and the maxima shown
to teachers reflect that setting rather than the unscaled figure.

### What a paste is worth

The paste-derived signals (3–7) describe the consequences of one paste — no corrections,
no pauses, no rhythm, because nobody typed. They are five restatements of one event, so
their combined contribution is bounded rather than summed. A paste whose size the browser
would not report is scored at the Medium floor, not the maximum.

## Risk Levels

| Score | Level | Recommended Action |
|-------|-------|-------------------|
| 0–29 | Low | No action needed |
| 30–65 | Medium | Instructor review recommended |
| 66–100 | High | Follow-up suggested (viva, supervised rewrite) |

---

## Database Tables

### `plagiarism_essayguard_ev` — Raw Telemetry Events
Stores every keyboard/paste/burst event from the student session.

### `plagiarism_essayguard_sc` — Session Scores
Full set of computed metrics per attempt:
- Behavioural: `typing_time`, `idle_time`, `total_keystrokes`, `paste_events`, `backspace_count`, `delete_count`, `cursor_moves`, `average_wpm`, `wpm_std_dev`, `interkey_mean`, `interkey_std_dev`, `pause_count`, `pause_mean`, `pause_std_dev`, `burst_count`, `burst_mean`, `burst_std_dev`, `entropy_score`, `thinking_pause_score`
- Linguistic: `sentence_variance`, `vocab_diversity`, `rare_word_ratio`
- Fingerprint: `baseline_deviation`, `baseline_status`
- Output: `riskscore`, `risklevel`, `explanationsjson`

### `plagiarism_essayguard_fp` — Student Fingerprint / Baseline
Rolling average of the student's writing behaviour across submissions.
- Status: `none` (< 3 samples), `preliminary` (3–4), `stable` (5+)

---

## Web Services

### `plagiarism_essayguard_log_event`
Called every 5 seconds by the browser tracker. Saves raw events (max 500 per call, 2KB per payload) and re-scores at most once per 60 seconds per attempt. Returns the risk score only to callers holding `plagiarism/essayguard:viewreport`.

### `plagiarism_essayguard_finalize_attempt`
Called at submission. Runs full linguistic analysis on final text, performs complete behavioural scoring, updates student fingerprint.

**Parameters:**
- `cmid` — Course module ID
- `attemptkey` — Session key from tracker init
- `finaltext` — The essay text at submission time

**Returns:** Full authenticity report including `score100`, `risklevel`, `explanations[]`, `baseline_status`, `baseline_deviation` — **only to a caller holding `plagiarism/essayguard:viewreport` in the activity context.** Students receive an acknowledgement with no score, no metrics and no explanations: the analysis must not be readable by the person being analysed, or it becomes something to iterate against.

---

## Instructor Integration

Call `finalize_attempt` from your assignment/quiz submission hook:

```php
$client = new \plagiarism_essayguard\external\finalize_attempt();
$result = $client::execute($cmid, $attemptkey, $submittedtext);
// $result['score100']    — 0–100 risk score
// $result['risklevel']   — low|medium|high
// $result['explanations'] — array of explanation strings
// $result['baseline_status'] — none|preliminary|stable
```

---

## Compatibility

Moodle 4.4 to 5.3 (`$plugin->supported = [404, 503]`). Tested on Moodle 4.5 with PostgreSQL 16,
Moodle 5.2 with MariaDB 10.11 and Moodle 5.3 with PostgreSQL 17, on PHP 8.3.

## Configuration

Settings page: **Site Administration → Plugins → Plagiarism prevention → Essay Guard**

| Setting | Default | Description |
|---------|---------|-------------|
| Enable Essay Guard | Off | Master switch. Stored as Off at install; nothing is captured until an administrator turns it on. |
| Flush interval (ms) | 5000 | How often tracker sends data |
| Min chars before scoring | 120 | Ignore very short submissions |
| Large insertion threshold | 150 | Characters threshold for burst detection |
| Allow paste | Off | Block or log paste events |
| Retention days | 90 | Auto-delete old telemetry |
| Site ID / API Key | — | AI Grader unlock credentials |

### Turning monitoring on

Essay Guard is **opt-in at two levels**. Installing it does not start capture anywhere, even on a site where "Enable plagiarism plugins" is already on:

1. An administrator ticks **Enable Essay Guard** on the settings page.
2. A teacher ticks **Enable Essay Guard** in the Plagiarism section of each assignment, quiz or forum. Activities that existed before the plugin was installed are **not** monitored until a teacher does this.

The per-activity setting is included in course backups and is restored onto the new activity when a course is restored, imported or an activity is duplicated. An activity restored from a backup that has no Essay Guard setting stays off.

On upgrade from 1.4.0 or earlier, sites keep what they were already monitoring: if the site switch was never saved but Essay Guard has data, it is recorded as on, and every activity that already holds Essay Guard data is recorded as on. Everything else is off.

### Quiz scoring

When a student submits a quiz, scoring is queued as a background (ad-hoc) task rather than run inside the submit request. Badges read **Essay Guard Pending** until cron has run the task, normally within a minute.

---

## Privacy

- Student work, keystroke telemetry and all derived scores are stored in your own Moodle database and nowhere else.
- No student text, telemetry or identity is sent outside Moodle. No external AI service is used for scoring.
- The plugin does contact `lms-labs.com` for licence verification and site-wide settings. Those requests contain this site's Site ID and API key only — never student data. This is declared in the plugin's privacy metadata.
- Students are shown a disclosure above the submission form explaining what is captured, why, who can see it and how long it is kept.
- Raw keystroke telemetry is automatically pruned after the configured retention period (default 90 days). Summary risk scores are retained with the submission.
- Full Moodle Privacy API implementation in `classes/privacy/provider.php`, covering export, erasure, user lists and user preferences across all four tables (`_ev` telemetry, `_sc` scores, and the writing baseline in `_fp` and its per-metric statistics `_fpm`) and the `essayguard_ak_*`, `essayguard_lastscore_*` and `essayguard_fin_*` preferences. The writing baseline is reported in the system context.
- The API key is sent to `lms-labs.com` in an `Authorization: Bearer` header (or a POST body), never in a URL.

## Pricing

**$5 USD (50 LMS Labs credits)** — one-time purchase per site · lifetime updates · no subscription.

Essay Guard is unlocked from your LMS Labs credit balance: activating it on a site deducts 50 credits once, which is the credit equivalent of the $5 price.

Credits are only ever spent by an administrator pressing **Unlock Essay Guard…** on the settings page and then confirming the amount on the confirmation screen. Saving the settings, checking the unlock status and the scheduled licence task only *check* the status; none of them can spend credits.

Download at [lms-labs.com/plugins](https://lms-labs.com/plugins).


## ⭐ Why this plugin is unlike anything else available

**Behavioural consistency analysis — not similarity matching**

- Turnitin, Grammarly and every similarity checker measure how much a submission resembles
  known text in a database. Essay Guard measures something different: how the writing was
  produced, and whether this submission is consistent with how this student has written
  before. A student can pass with work that resembles published text; an AI answer with no
  match anywhere in the world still shows how it arrived in the box.

- **The comparison is statistical, not a fixed percentage.** Essay Guard keeps a running
  mean and variance for each measurement, per student, per activity type, and expresses a
  departure as a z-score in that student's own standard deviations. A student whose typing
  speed naturally moves 30% week to week is not flagged for moving 30%; a student whose
  speed has never moved more than 5% is. Comparison begins only once at least three
  measurements have eight submissions behind them — before that, nothing comparative is
  reported, because there is nothing to compare against.

- **Quiz writing is never compared against assignment writing.** Short time-pressured
  answers and leisurely drafted essays are different distributions. Pooling them inflates
  the variance until nothing can deviate from it, which is the quiet way a comparative
  signal stops working while still appearing to run.

- **No student data leaves Moodle.** No third-party similarity database, no submission
  upload, no text sent to an AI service. All analysis runs against the student's own
  previous submissions stored in your Moodle database. The plugin calls `lms-labs.com` only
  to verify this site's licence; that request carries the site's own Site ID and API key and
  nothing else. Peer certificate verification is enforced and redirects are refused.

- **Keystroke characters are never recorded.** The telemetry holds timings, counts and
  insertion lengths. It is not a transcript of what the student typed, and it cannot be
  replayed to reconstruct their work or their deleted drafts.

## Honest limits

A vendor that will not tell you what its product cannot do is not worth buying from.

- **The score is not calibrated.** The thresholds are reasoned, not derived from a labelled
  dataset, so there is no published false-positive rate. Treat the bands as triage, not as
  a measurement.
- **A determined student can defeat it.** The telemetry comes from the browser and can be
  blocked, disabled or forged by anyone willing to open developer tools. Retyping text from
  a second window is indistinguishable from writing it, beyond the transcription pattern in
  Signal 16 — which tells you copying occurred, not what was copied.
- **Assistive technology can look like the patterns this measures.** Dictation, screen
  readers, predictive text and translation tools change how writing arrives in the box.
  Signals are suppressed where the browser reports an insertion was not a paste, but the
  general caution stands, and students are told so in the disclosure.
- **An attempt with no telemetry is reported as "not assessed", never as low risk.** If the
  tracker did not run, the plugin says so rather than issuing a clean result it did not earn.

Essay Guard produces corroborating evidence for a human decision. It does not make
academic misconduct determinations and nothing in it should be presented as proof.

## Support

- **Portal:** [lms-labs.com](https://lms-labs.com)
- **Email:** support@lmshostingservices.com
- **Website:** [lmshostingservices.com](https://lmshostingservices.com)

LMS Labs is the plugin division of LMS Hosting Services, Australia's Moodle™ Certified Partner.
