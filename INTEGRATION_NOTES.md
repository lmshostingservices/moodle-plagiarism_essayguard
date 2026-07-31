# Essay Guard — Integration Notes

## Calling `finalize_attempt` at Submission Time

The most important integration point is calling `finalize_attempt` when the
student submits their essay. This triggers the full linguistic analysis on
the final text and updates the student's fingerprint.

### Assignment Plugin (mod_assign)

Hook into the submission event using Moodle's event observer system:

```php
// In your local plugin's db/events.php or the main plugin's:
$observers = [
    [
        'eventname'   => '\mod_assign\event\assessable_submitted',
        'callback'    => 'local_myplugin\observer::on_assign_submit',
    ],
];
```

```php
// observer.php
public static function on_assign_submit(\mod_assign\event\assessable_submitted $event) {
    $cmid = $event->contextinstanceid;
    $userid = $event->userid;

    // Retrieve the attemptkey stored during the session
    // (you need to pass this from the tracker init config and store it, e.g. in user session)
    $attemptkey = \plagiarism_essayguard\local\service\session_helper::get_attemptkey($cmid, $userid);

    if ($attemptkey) {
        $submission = $event->get_record_snapshot('assign_submission', $event->objectid);
        $finaltext = \plagiarism_essayguard\local\service\text_helper::get_assign_text($submission);

        \plagiarism_essayguard\external\finalize_attempt::execute($cmid, $attemptkey, $finaltext);
    }
}
```

### Quiz Plugin (mod_quiz)

```php
// In quiz attempt submission:
$observers = [
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback'  => 'local_myplugin\observer::on_quiz_submit',
    ],
];
```

---

## Displaying Results in the Instructor View

The `plagiarism_essayguard_get_links()` function in `lib.php` automatically
renders a risk badge on submission grading pages. This works out of the box
for supported modules (assign, quiz, forum).

To display the full report programmatically:

```php
global $DB, $OUTPUT;

$record = $DB->get_record_sql(
    "SELECT * FROM {plagiarism_essayguard_sc}
      WHERE userid = :userid AND cmid = :cmid
   ORDER BY timemodified DESC",
    ['userid' => $userid, 'cmid' => $cmid],
    IGNORE_MULTIPLE
);

if ($record) {
    $explanations = json_decode($record->explanationsjson, true) ?: [];
    $metrics      = json_decode($record->metricsjson, true)      ?: [];

    echo '<div class="essayguard-report">';
    echo '<p><strong>Risk Score:</strong> ' . ($record->score100 ?? round($record->riskscore * 100)) . '/100</p>';
    echo '<p><strong>Level:</strong> ' . $record->risklevel . '</p>';
    echo '<p><strong>Baseline:</strong> ' . $record->baseline_status . '</p>';
    echo '<ul>';
    foreach ($explanations as $exp) {
        echo '<li>' . s($exp) . '</li>';
    }
    echo '</ul>';
    echo '</div>';
}
```

---

## Fingerprint Baseline Lifecycle

```
Submission 1 → samplecount=1 → baseline_status=none   (not enough data)
Submission 2 → samplecount=2 → baseline_status=none
Submission 3 → samplecount=3 → baseline_status=preliminary  (early estimate)
Submission 4 → samplecount=4 → baseline_status=preliminary
Submission 5 → samplecount=5 → baseline_status=stable   (reliable baseline)
Submission N → rolling EWMA update (new=25%, old=75%)
```

When `baseline_status=stable`, the score engine adds a deviation bonus
of up to +15 points if the current submission significantly departs from
the student's normal writing behaviour.

---

## Tracker Initialization (lib.php inject_tracker)

The tracker is injected into every page where `$PAGE->cm` is set and
the plugin is enabled. The config passed to `tracker.init()` includes:

```javascript
{
    cmid: 123,
    attemptkey: "sha1_session_key",
    flushinterval: 5000,
    allowpaste: 0,
    maxburstchars: 150
}
```

The `attemptkey` is generated server-side as (FIX-EG-ATTEMPTKEY v1.2.81 — sesskey()
removed; it changed between page loads causing events from different pages to use
different keys):

```php
// Quiz: use the quiz attempt ID directly — stable across all pages of the attempt.
$attemptkey = 'qa_' . $quizattemptid;   // e.g. 'qa_142'

// Non-quiz (assignment, forum): userid:cmid hash — sesskey-free.
$attemptkey = sha1($USER->id . ':' . $cm->id);
```

This key is what links all events back to a single typing session.

---

## Events Captured by tracker.js

| Event | Payload | Description |
|-------|---------|-------------|
| `focus` | sessionms | Field gained focus |
| `keydown` | key, code, ikd | Regular key press with inter-key delay |
| `backspace` | key, ikd | Backspace key with inter-key delay |
| `delete` | key, ikd | Delete key |
| `paste` | insertlen, insertwords, blocked | Clipboard paste |
| `drop_paste` | insertlen, insertwords | Drag-and-drop text insertion |
| `input` | inputType, addedchars, removedchars, insertlen, totalchars, totalwords, suspiciousburst | DOM input event |
| `selection` | selectionlen, cursormoves | Text selected with mouse |
| `burst_end` | wordcount, durationms | Typing burst completed (after pause) |
| `wpm_snapshot` | wpm, totalwords, windowms | Per-minute WPM measurement |
| `blur` | sessionms | Field lost focus (triggers flush) |

---

## Score Engine Weights

12-signal scoring system (v1.2.113+). Scores are capped at 100.
A false-positive cap also limits the maximum score when only low-confidence
signals fire (e.g. no paste events and no large insertions).

```
#   Signal                              Max Pts  Notes
────────────────────────────────────────────────────────────────────────────
1   Paste / clipboard insert             60      Any paste event or large
                                                 clipboard insertion detected.
2   Large text insertions (>20 chars)    20      Input event delta >20 chars
                                                 (TinyMCE clipboard proxy).
3   Typing speed                         30      chars-per-second >8 = suspicious,
                                                 >15 = superhuman.
4   Thinking pauses absent               20      Fewer than 2 major pauses (>2 s)
                                                 for substantial content.
5   Correction / backspace rate          15      Backspace ratio <2 % of
                                                 keystrokes, or zero corrections
                                                 on a paste-only session.
6   Near-zero session time               25      Typing time <10 s with >50 chars
                                                 — content appeared almost instantly.
7   Typing rhythm entropy                10      Shannon IKI entropy <0.35
                                                 (TypeShield threshold) or SD-based
                                                 entropy <0.3.
8   Sentence length uniformity           10      Sentence length variance <6 (very
                                                 uniform) or <12 (somewhat uniform).
9   Vocabulary diversity                  5      Type-token ratio <0.30 (very low)
                                                 or <0.40 (low).
10  IKI autocorrelation [TypeShield]     10      |autocorr − 0.1| > 0.5 flags
                                                 robotic or jittered rhythm.
11  Speed-burst consistency [TypeShield] 10      Speed CV <0.30 across ≥3 WPM
                                                 windows — implausibly constant rate.
12  Keystroke ratio [TypeShield]         10      keystrokes ÷ text_chars <0.5 —
                                                 most content inserted not typed.
    Baseline deviation bonus            +15      Max if deviation > 0.3 from the
                                                 student's established fingerprint.
    Linguistic fallback                  35      Only when no keystroke events are
                                                 available — elevated-weight sentence
                                                 uniformity + vocab diversity.
────────────────────────────────────────────────────────────────────────────
TOTAL (max)                             ~100 (clamped)
```

Risk thresholds (analyser::risk_level()):
- LOW:    0 – 29 %
- MEDIUM: 30 – 65 %
- HIGH:   66 – 100 %

---

## Moodle Version Compatibility

- Moodle 4.0–4.2: Uses `plagiarism_essayguard_before_standard_html_head()` callback
- Moodle 4.3+: Uses Hook system via `db/hooks.php` + `classes/hook/before_standard_head_html_generation.php`
- Both paths call `plagiarism_essayguard_inject_tracker()` — no double execution
