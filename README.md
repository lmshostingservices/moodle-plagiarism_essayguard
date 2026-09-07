<p align="center">
  <a href="https://lmshostingservices.com">
    <img src="https://raw.githubusercontent.com/lmshostingservices/lms-labs/main/attached_assets/lms-hosting-logo.png" alt="LMS Hosting Services" height="60">
  </a>
</p>

> **LMS Labs** is the Moodle plugin division of [LMS Hosting Services](https://lmshostingservices.com) — Australia's Moodle™ Certified Partner.

---

# Essay Guard — Privacy-First Writing Authenticity Engine

**Version:** 1.2.219  
**Moodle Compatibility:** Moodle 4.0 – 5.1  
**Plugin Type:** Plagiarism Plugin (`plagiarism_essayguard`)

---

## What It Does

Essay Guard analyses **how students write**, not just what they submit. It captures typing behaviour in the browser and uses a multi-signal engine to detect anomalies consistent with:

- Copy-paste from external sources
- AI-generated text pasted or manually retyped
- Unusual writing patterns vs the student's own baseline

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

| Signal | Max Points | Description |
|--------|-----------|-------------|
| Paste events | 25 | Each paste event adds risk |
| Suspicious burst insertions | 20 | Large text inserted in < 50ms |
| Backspace ratio < 2% | 15 | Very low editing activity |
| Entropy score < 0.3 | 20 | Suspiciously smooth typing rhythm |
| No thinking pauses | 5 | Zero pauses on >300 char submission |
| Sentence uniformity | 10 | Very low sentence length variance |
| Vocabulary diversity | 5 | Low type-token ratio |
| Fast inter-key timing | 5 | Faster than skilled human typists |
| Baseline deviation | +15 | Significant departure from student's own history |

---

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

## Configuration

Settings page: **Site Administration → Plugins → Plagiarism prevention → Essay Guard**

| Setting | Default | Description |
|---------|---------|-------------|
| Enable Essay Guard | Off | Master switch |
| Flush interval (ms) | 5000 | How often tracker sends data |
| Min chars before scoring | 120 | Ignore very short submissions |
| Large insertion threshold | 150 | Characters threshold for burst detection |
| Allow paste | Off | Block or log paste events |
| Retention days | 90 | Auto-delete old telemetry |
| Site ID / API Key | — | AI Grader unlock credentials |

---

## Privacy

- Student work, keystroke telemetry and all derived scores are stored in your own Moodle database and nowhere else.
- No student text, telemetry or identity is sent outside Moodle. No external AI service is used for scoring.
- The plugin does contact `lms-labs.com` for licence verification and site-wide settings. Those requests contain this site's Site ID and API key only — never student data. This is declared in the plugin's privacy metadata.
- Students are shown a disclosure above the submission form explaining what is captured, why, who can see it and how long it is kept.
- Raw keystroke telemetry is automatically pruned after the configured retention period (default 90 days). Summary risk scores are retained with the submission.
- Full Moodle Privacy API implementation in `classes/privacy/provider.php`, covering export, erasure, user lists and user preferences across all three tables.

## Pricing

**$50 USD (5,000 LMS Labs credits)** — one-time purchase per site · lifetime updates · no subscription.

Essay Guard is unlocked from your LMS Labs credit balance: activating it on a site deducts 5,000 credits once, which is the credit equivalent of the $50 price. The settings page and the unlock dialog both quote the credit figure; they refer to the same one-time charge.

Download at [lms-labs.com/plugins](https://lms-labs.com/plugins).


## ⭐ Why this plugin is unlike anything else available

**Behavioural consistency analysis — not similarity matching**

- Turnitin, Grammarly, and every similarity checker measure how much a submission resembles known text in a database. Essay Guard measures something different: whether this submission is consistent with how this student has written before. A student can pass even if their work resembles published text; they fail only if their own writing style is internally inconsistent.
- No student data leaves Moodle. No third-party similarity database, no submission upload, no text sent to an AI service. All analysis runs against the student's own previous submissions stored in the Moodle database. (The plugin does call `lms-labs.com` to verify this site's licence; that call carries the site's credentials only.)
- Signals include vocabulary diversity (type-token ratio), sentence length distribution, paragraph structure, and transition density — all compared against the student's own historical baseline, not a population average.

## Support

- **Portal:** [lms-labs.com](https://lms-labs.com)
- **Email:** support@lmshostingservices.com
- **Website:** [lmshostingservices.com](https://lmshostingservices.com)

LMS Labs is the plugin division of LMS Hosting Services, Australia's Moodle™ Certified Partner.
