# Essay Guard — Comprehensive Testing Engine
## Tests for Every Feature (v1.1.0)

---

## 1. Test Categories

| Category | Tests | Priority |
|----------|-------|----------|
| Tracker JS — Event Capture | T01–T09 | Critical |
| Log Event API | T10–T14 | Critical |
| Finalize Attempt API | T15–T20 | Critical |
| Behavioural Metric Scaffold | T21–T30 | High |
| Linguistic Metric Scaffold | T31–T38 | High |
| Score Engine | T39–T50 | Critical |
| Explanation Engine | T51–T60 | High |
| Fingerprint Baseline Engine | T61–T70 | High |
| Instructor Dashboard | T71–T75 | Medium |
| Privacy & Data Retention | T76–T80 | High |

---

## 2. Tracker JS — Event Capture

### T01: Keydown event captured with inter-key delay
**Setup:** Open an assign/quiz textarea. Type "hello world".  
**Expected:** `keydown` events in DB with `ikd` field populated (>0) for all keys after the first.  
**Verify:** `SELECT eventname, payloadjson FROM plagiarism_essayguard_ev WHERE eventname='keydown' LIMIT 5`

### T02: Backspace captured separately
**Setup:** Type 5 chars, press Backspace 3 times.  
**Expected:** 3 rows with `eventname='backspace'` in `plagiarism_essayguard_ev`.  
**Verify:** `SELECT COUNT(*) FROM plagiarism_essayguard_ev WHERE eventname='backspace' AND attemptkey='...'`

### T03: Delete key captured separately from backspace
**Setup:** Type text, use Delete key.  
**Expected:** Row with `eventname='delete'` (NOT `eventname='backspace'`).

### T04: Paste event captured with insertlen
**Setup:** Type a few chars, then paste 50-char text.  
**Expected:** Row `eventname='paste'` with `payloadjson` containing `"insertlen":50`.

### T05: Drag-and-drop paste detected
**Setup:** Drag text from browser address bar or another element into the textarea.  
**Expected:** Row `eventname='drop_paste'` with `insertlen > 0`.

### T06: WPM snapshot captured every minute of typing
**Setup:** Type continuously for 60+ seconds.  
**Expected:** At least 1 row `eventname='wpm_snapshot'` with `wpm > 0`.

### T07: Burst end event captured after pause
**Setup:** Type 20 words, stop for 3+ seconds, type 5 more words.  
**Expected:** Row `eventname='burst_end'` with `wordcount > 0, durationms > 0`.

### T08: Selection event captured on mouse text selection
**Setup:** Select a word or phrase with the mouse.  
**Expected:** Row `eventname='selection'` with `selectionlen > 0`.

### T09: blur event flushes queue immediately
**Setup:** Type text, click outside the textarea.  
**Expected:** Events arrive in DB within 500ms (check browser Network tab — request fires on blur).

---

## 3. Log Event API

### T10: Valid batch of events accepted
**Call:**
```json
POST /lib/ajax/service.php
[{"methodname":"plagiarism_essayguard_log_event","args":{
  "cmid": 5,
  "attemptkey": "abc123def456",
  "events": [
    {"eventname":"keydown","eventtime":1700000000000,"payloadjson":"{\"key\":\"a\"}"},
    {"eventname":"backspace","eventtime":1700000000200,"payloadjson":"{\"ikd\":200}"}
  ]
}}]
```
**Expected:** `{"ok":true,"ignored":false,"riskscore":0,"risklevel":"low"}`

### T11: Guest user rejected
**Setup:** Log out, call log_event.  
**Expected:** Moodle authentication exception — `401` or exception JSON.

### T12: Plugin disabled — returns ignored:true
**Setup:** Set `enabled=0` in config.  
**Expected:** `{"ok":true,"ignored":true,"riskscore":0,"risklevel":"low"}`

### T13: Invalid event names are rejected by parameter validation
**Call:** Set `eventname` to `"; DROP TABLE--"`.  
**Expected:** PARAM_ALPHAEXT validation failure, no DB insert.

### T14: Incremental score is returned after events
**Setup:** Send 10+ paste events (insertlen > 150 each).  
**Expected:** `riskscore > 0` and `risklevel` is `low`, `medium`, or `high`.

---

## 4. Finalize Attempt API

### T15: Finalize returns full report
**Call:**
```json
POST /lib/ajax/service.php
[{"methodname":"plagiarism_essayguard_finalize_attempt","args":{
  "cmid": 5,
  "attemptkey": "abc123def456",
  "finaltext": "The student typed this essay about climate change over the course of the session."
}}]
```
**Expected:** Response includes `score100` (int), `risklevel`, `explanations` (array), `baseline_status`, `metricsjson`.

### T16: Linguistic metrics are populated when finaltext is provided
**Call:** Include a 500-word essay as `finaltext`.  
**Verify DB:** `SELECT sentence_variance, vocab_diversity, rare_word_ratio FROM plagiarism_essayguard_sc WHERE attemptkey='...'` — all should be > 0.

### T17: Fingerprint is updated after finalize
**Setup:** Run finalize 5 times for the same student (different attemptkeys, same cmid user).  
**Verify:** `SELECT samplecount, baseline_status FROM plagiarism_essayguard_fp WHERE userid=X`  
**Expected:** `samplecount=5, baseline_status='stable'`

### T18: Missing finaltext still returns behavioural-only score
**Call:** Omit `finaltext` (or pass empty string).  
**Expected:** Valid response with `score100 >= 0`. `sentence_variance` and `vocab_diversity` will be 0.

### T19: Finalize upserts — calling twice updates, not duplicates
**Setup:** Call finalize twice with same `cmid + attemptkey`.  
**Verify:** `SELECT COUNT(*) FROM plagiarism_essayguard_sc WHERE attemptkey='...'` = 1 (not 2).

### T20: Plugin disabled — returns safe empty result
**Setup:** `enabled=0`. Call finalize.  
**Expected:** `{"ok":true,"riskscore":0,"score100":0,"risklevel":"low","explanations":[]}`

---

## 5. Behavioural Metric Scaffold

### T21: Inter-key mean calculated correctly
**Input events:** 10 keydown events with `ikd` values: 100,120,90,110,130,100,95,115,105,125  
**Expected:** `interkey_mean ≈ 109` (sum/count)  
**Unit test:** Call `analyser::score_attempt()` with synthetic events, check returned `metrics['interkey_mean']`.

### T22: Inter-key std dev calculated correctly
**Input:** Same 10 delays above.  
**Expected:** `interkey_std_dev ≈ 13` (low value = smooth = suspicious)

### T23: Entropy score maps low SD to low entropy
**Input:** `interkey_std_dev = 50` → `entropy_score = 0.15`  
**Input:** `interkey_std_dev = 200` → `entropy_score = 0.60`  
**Input:** `interkey_std_dev = 400` → `entropy_score = 1.0`

### T24: Backspace ratio calculation
**Input:** 100 keydown events, 8 backspace events.  
**Expected:** `backspace_ratio = 0.0741` (8/108 including backspaces in keystrokes)

### T25: Pause count and timing
**Input:** Events with gaps: 3000ms, 5000ms, 1500ms, 8000ms between events.  
**Expected:** `pause_count = 3` (>2000ms), `longpauses = 2` (>10000ms: only 8000 doesn't qualify; adjust to: 3000, 15000, 1500, 11000 → pause_count=3, longpauses=2)

### T26: Burst metrics from burst_end events
**Input:** burst_end events with wordcounts: [8, 12, 6, 15, 9]  
**Expected:** `burst_count=5, burst_mean=10.0, burst_std_dev≈3.16`

### T27: WPM snapshot mean and std dev
**Input:** wpm_snapshot events: [35, 42, 38, 48, 41]  
**Expected:** `average_wpm=40.8, wpm_std_dev≈4.35`

### T28: Thinking pause score
**Setup:** Events include pauses: [400ms, 1200ms, 900ms, 3000ms, 1500ms]  
**Expected:** Pauses 800–2000ms are thinking pauses = [1200, 900, 1500] = 3 out of 4 pauses > 500ms  
**Expected:** `thinking_pause_score ≈ 0.75`

### T29: Typing time vs idle time split
**Setup:** Events with 200ms gaps (typing) and two 5000ms gaps (idle).  
**Expected:** `idle_time` = sum of gaps > 2000ms; `typing_time` = sum of gaps <= 2000ms.

### T30: Cursor moves count
**Input:** 4 `selection` events with cursormoves payloads: [1,2,1,3].  
**Expected:** `cursor_moves = 7` (cumulative from last selection event payload).

---

## 6. Linguistic Metric Scaffold

### T31: Sentence variance — highly uniform text
**Input:** "I like dogs. I like cats. I like birds. I like fish." (all 3-word sentences)  
**Expected:** `sentence_variance = 0.0`

### T32: Sentence variance — varied sentence lengths
**Input:** "The quick brown fox jumped. It was a short jump. However the distance it covered relative to its size was impressive."  
**Expected:** `sentence_variance > 20`

### T33: Vocab diversity — highly repetitive text
**Input:** "the the the the the" (5 words, 1 unique)  
**Expected:** `vocab_diversity = 0.2`

### T34: Vocab diversity — natural varied text (200+ words essay)
**Input:** A 200-word human essay with no repeated phrases.  
**Expected:** `vocab_diversity > 0.55`

### T35: Rare word ratio
**Input:** "The implementation of comprehensive educational frameworks facilitates extraordinary developmental outcomes."  
**Expected:** `rare_word_ratio > 0.4` (many 8+ char words)

### T36: Linguistic analyse returns empty metrics for very short text
**Input:** `linguistic::analyse("Hi.")` (3 chars)  
**Expected:** All values = 0.0

### T37: Sentence splitting handles multiple punctuation correctly
**Input:** "Hello! How are you? I am fine. Really."  
**Expected:** `sentence_count = 4`

### T38: Variance function handles single-sentence input
**Input:** One sentence — `sentence_lengths = [10]`  
**Expected:** `sentence_variance = 0.0` (not NaN/error)

---

## 7. Score Engine (0–100)

### T39: Clean typing session scores 0
**Input:** 500 chars typed, 50 backspaces, no pastes, interkey_std_dev=250, 5 pauses, sentence_variance=25  
**Expected:** `score100 = 0`

### T40: Heavy paste session scores high
**Input:** 4 paste events each with insertlen=200, 0 backspaces, no pauses  
**Expected:** Score ≥ 28 (pastes: min(25,28) + burst: min(20,32) + low backspace:15 + no pauses:5 ≥ 60) → `risklevel = medium` or `high`

### T41: Pure copy-paste session scores maximum
**Input:** 3 paste events (>150 chars each), backspace_ratio=0, zero pauses, entropy=0.15, sentence_variance=4, vocab_diversity=0.25  
**Expected:** `score100 >= 70, risklevel = 'high'`

### T42: Risk level boundaries are correct (Low < 35, Medium 35–69, High ≥ 70)
- score100=0 → `low`
- score100=24 → `low`
- score100=25 → `low`
- score100=34 → `low`
- score100=35 → `medium`
- score100=49 → `medium`
- score100=50 → `medium`
- score100=69 → `medium`
- score100=70 → `high`
- score100=100 → `high`

### T43: Score clamped to 100 maximum
**Input:** Every signal maximally triggered.  
**Expected:** `score100 <= 100`

### T44: Score is 0 when below minchars threshold
**Setup:** `minchars=120`. Submit 50 chars of events.  
**Expected:** `score100 = 0` regardless of other signals.

### T45: Baseline deviation adds bonus points
**Setup:** Student has stable baseline with `baseline_wpm=30`. This submission: `average_wpm=80`.  
**Expected:** `baseline_deviation > 0.3`, `score100` is higher than without baseline.

### T46: No baseline = no deviation bonus
**Setup:** First submission — no fingerprint row exists.  
**Expected:** `baseline_deviation = 0.0, baseline_status = 'none'`.

### T47: Backspace ratio 0.02–0.04 adds partial points (8 not 15)
**Input:** `backspace_ratio = 0.03`, no other signals.  
**Expected:** Score includes 8 points for low backspace, not 15.

### T48: Multiple signals combine additively
**Input:** 2 pastes (+14) + 1 burst (+8) + backspace_ratio=0.01 (+15) + entropy=0.2 (+20) = 57  
**Expected:** `score100 ≈ 57, risklevel = 'medium'`

### T49: Missing linguistic metrics don't cause errors
**Input:** Score called with empty `$linguistic = []`.  
**Expected:** No PHP error, `sentence_variance=0, vocab_diversity=0` in metrics.

### T50: Score persisted to DB with all columns
**After calling score_attempt**, check:  
```sql
SELECT typing_time, idle_time, total_keystrokes, paste_events, backspace_count,
       delete_count, average_wpm, interkey_mean, entropy_score,
       sentence_variance, vocab_diversity, explanationsjson
FROM plagiarism_essayguard_sc WHERE attemptkey='test_attempt'
```
All columns populated (not null/zero where data existed).

---

## 8. Explanation Engine

### T51: Clean session returns positive explanation
**Input:** `risklevel='low'`, no signals triggered.  
**Expected:** Explanation: "Writing behaviour appears consistent with normal student patterns."

### T52: Paste events generate paste explanation
**Input:** `paste_events=1`.  
**Expected:** "Content was pasted 1 time(s) during the session."

### T53: Many pastes generate stronger explanation
**Input:** `paste_events=4`.  
**Expected:** "4 paste events detected — significantly more than typical writing sessions."

### T54: Low backspace ratio triggers explanation
**Input:** `backspace_ratio=0.005`.  
**Expected:** "Very low editing activity — fewer corrections than typical for this length of writing."

### T55: Low entropy triggers explanation
**Input:** `entropy_score=0.2`.  
**Expected:** "Typing rhythm unusually smooth — inter-key timing more regular than typical human writing."

### T56: No pauses explanation fires correctly
**Input:** `pause_count=0, charsadded=500`.  
**Expected:** "No thinking pauses detected during a substantial piece of writing — unusual for original composition."

### T57: Uniform sentences explanation
**Input:** `sentence_variance=4.5`.  
**Expected:** "Sentence lengths unusually uniform throughout the submission."

### T58: Baseline WPM deviation explanation
**Input:** `baseline_wpm=30, average_wpm=85`. Stable baseline exists.  
**Expected:** "Writing speed significantly higher than this student's baseline (baseline: 30 wpm, this submission: 85 wpm)."

### T59: No duplicate explanations in output
**Input:** Trigger 3 different signals.  
**Expected:** Each explanation string appears exactly once in the returned array.

### T60: Explanation array is empty when all signals are clean (risklevel='low')
**Input:** No signals triggered, risklevel='low'.  
**Expected:** Array contains only the positive "consistent" explanation string.

---

## 9. Fingerprint Baseline Engine

### T61: First submission creates fingerprint record
**Setup:** Student has no fingerprint. Call `fingerprint::update($userid, $metrics)`.  
**Verify:** Row exists in `plagiarism_essayguard_fp` with `samplecount=1, baseline_status='none'`.

### T62: Third submission changes status to preliminary
**Setup:** Call update 3 times.  
**Verify:** `samplecount=3, baseline_status='preliminary'`

### T63: Fifth submission changes status to stable
**Setup:** Call update 5 times.  
**Verify:** `samplecount=5, baseline_status='stable'`

### T64: EWMA rolling average converges correctly
**Setup:** Initial baseline: `baseline_wpm=40`. New submission: `average_wpm=100`.  
**Expected:** New `baseline_wpm = 0.75*40 + 0.25*100 = 55.0`

### T65: Deviation score is 0 when below threshold
**Input:** Baseline WPM=40, current WPM=50 (25% above, threshold=50%).  
**Expected:** `deviation_score = 0.0`

### T66: Deviation score is > 0 when above threshold
**Input:** Baseline WPM=40, current WPM=90 (125% above, threshold=50%).  
**Expected:** `deviation_score > 0.0`

### T67: Deviation score is capped at 1.0
**Input:** Baseline WPM=10, current WPM=500.  
**Expected:** `deviation_score = 1.0`

### T68: get() returns null when baseline_status is none
**Setup:** Student with samplecount=2 (status=none).  
**Expected:** `fingerprint::get($userid)` returns null.

### T69: get() returns record when status is preliminary or stable
**Setup:** Student with samplecount=3.  
**Expected:** `fingerprint::get($userid)` returns a non-null record.

### T70: Fingerprint upserts — no duplicate rows
**Setup:** Call `update()` 10 times for same user.  
**Verify:** `SELECT COUNT(*) FROM plagiarism_essayguard_fp WHERE userid=X` = 1

---

## 10. Instructor Dashboard / Risk Badge

### T71: Risk badge renders for low risk
**Setup:** `risklevel='low'`.  
**Expected:** Badge rendered by `riskbadge.mustache` with level class `essayguard-low`.

### T72: Risk badge rendered only for users with `plagiarism/essayguard:viewreport`
**Setup:** Log in as student (no capability).  
**Expected:** `get_links()` returns empty string.

### T73: Risk badge rendered for teacher
**Setup:** Log in as teacher (has capability).  
**Expected:** Badge HTML returned with correct risk level.

### T74: Most recent score is shown (not oldest)
**Setup:** Two score records for same user+cmid (different attempts).  
**Expected:** `get_links()` shows the most recently modified one.

### T75: Risk badge shows correct percentage
**Setup:** `riskscore=0.73`.  
**Expected:** Badge shows "73" (percent).

---

## 11. Privacy & Data Retention

### T76: Cleanup task deletes events older than retention period
**Setup:** Insert old event row (timecreated = now - 100 days). `retentiondays=90`.  
**Run:** `php admin/cli/scheduled_task.php --execute=\\plagiarism_essayguard\\task\\cleanup`  
**Verify:** Old row deleted. Recent rows (< 90 days) remain.

### T77: Cleanup task does not delete recent events
**Setup:** Insert event from yesterday.  
**Run:** Cleanup task.  
**Expected:** Row still present.

### T78: Setting retentiondays=0 disables cleanup
**Setup:** `retentiondays=0`. Insert old event.  
**Run:** Cleanup task.  
**Expected:** Old row NOT deleted.

### T79: Privacy API export includes event and score data
**Setup:** Submit as test student. Run:  
```php
$manager = new \core_privacy\local\request\approved_userlist(\context_system::instance(), 'plagiarism_essayguard', [$userid]);
```  
**Expected:** Export includes event rows and score rows for the user.

### T80: Privacy API delete removes all user data
**Setup:** Student has events + score rows. Delete user data via Privacy API.  
**Verify:** `SELECT COUNT(*) FROM plagiarism_essayguard_ev WHERE userid=X` = 0  
**Verify:** `SELECT COUNT(*) FROM plagiarism_essayguard_sc WHERE userid=X` = 0  
**Verify:** `SELECT COUNT(*) FROM plagiarism_essayguard_fp WHERE userid=X` = 0

---

## 12. Automated Unit Test Scripts

### PHP Unit Test Skeleton (for PHPUnit)

```php
// tests/analyser_test.php
class plagiarism_essayguard_analyser_testcase extends advanced_testcase {

    public function test_entropy_from_sd() {
        $this->assertEquals(0.15, analyser::entropy_from_sd(50));
        $this->assertEquals(0.40, analyser::entropy_from_sd(130));
        $this->assertEquals(1.0,  analyser::entropy_from_sd(400));
    }

    public function test_risk_level() {
        $this->assertEquals('low',    analyser::risk_level(20));
        $this->assertEquals('low',    analyser::risk_level(30));
        $this->assertEquals('medium', analyser::risk_level(35));
        $this->assertEquals('medium', analyser::risk_level(55));
        $this->assertEquals('high',   analyser::risk_level(75));
    }

    public function test_std_dev() {
        $data = [2, 4, 4, 4, 5, 5, 7, 9];
        $this->assertEquals(2.0, analyser::std_dev($data));
    }
}
```

```php
// tests/linguistic_test.php
class plagiarism_essayguard_linguistic_testcase extends advanced_testcase {

    public function test_uniform_sentences_low_variance() {
        $text = "I like dogs. I like cats. I like birds.";
        $result = linguistic::analyse($text);
        $this->assertEquals(0.0, $result['sentence_variance']);
    }

    public function test_vocab_diversity_repetitive() {
        $result = linguistic::vocab_diversity("the the the the");
        $this->assertEquals(0.25, $result);
    }

    public function test_rare_word_ratio() {
        $result = linguistic::rare_word_ratio("implementation comprehensive outstanding");
        $this->assertGreaterThan(0.5, $result);
    }
}
```

```php
// tests/fingerprint_test.php
class plagiarism_essayguard_fingerprint_testcase extends advanced_testcase {

    public function test_ewma_update() {
        // baseline_wpm=40, new=100, weight_new=0.25
        // expected = 0.75*40 + 0.25*100 = 55
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        fingerprint::update($user->id, ['average_wpm' => 40]);
        fingerprint::update($user->id, ['average_wpm' => 100]);
        global $DB;
        $fp = $DB->get_record('plagiarism_essayguard_fp', ['userid' => $user->id]);
        $this->assertEquals(55.0, (float)$fp->baseline_wpm);
    }

    public function test_status_progression() {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $metrics = ['average_wpm' => 40];
        fingerprint::update($user->id, $metrics);
        fingerprint::update($user->id, $metrics);
        fingerprint::update($user->id, $metrics);
        $fp = fingerprint::get($user->id);
        $this->assertEquals('preliminary', $fp->baseline_status);
    }
}
```

---

## 13. Browser / Integration Test Checklist

Run these manually in a Moodle test instance:

```
[ ] tracker.js loads on assign submission page (no JS errors in console)
[ ] keydown events appear in DB while typing in assignment textarea
[ ] paste event fires and is logged when Ctrl+V used
[ ] drag-and-drop text into textarea fires drop_paste event
[ ] delete key fires separate event from backspace
[ ] blur on textarea triggers immediate flush (check Network tab)
[ ] finalize_attempt called at assignment submission (add debug log)
[ ] risk badge appears on grading page for teacher
[ ] risk badge does NOT appear for student (no viewreport capability)
[ ] score100 > 0 after a paste-heavy test submission
[ ] explanations array not empty for paste-heavy submission
[ ] baseline_status changes from 'none' → 'preliminary' → 'stable' after submissions
[ ] cleanup task runs without errors
[ ] settings page saves and loads correctly
[ ] plugin can be disabled and re-enabled without errors
```

---

## 14. Test Data Scenarios

### Scenario A: Genuine Student (expected: Low risk)
```
Events:
- 400 keydown events over 25 minutes
- interkey_std_dev ≈ 220ms
- 45 backspace events (ratio ≈ 10%)
- 8 pause events > 2000ms
- 3 burst_end events, wordcount 8–12
- No paste events
- sentence_variance ≈ 28
- vocab_diversity ≈ 0.62

Expected: score100 ≈ 0–5, risklevel='low'
```

### Scenario B: Copy-Paste + Minor Edits (expected: High risk)
```
Events:
- 3 paste events, insertlen=300 each
- 2 keydown events (trivial edits)
- 1 backspace event
- No pauses
- sentence_variance = 5.2 (uniform)
- vocab_diversity = 0.31 (low)

Expected: score100 ≈ 70–85, risklevel='high'
Explanations should include: paste, burst, low backspace, no pauses, uniform sentences
```

### Scenario C: Retyping AI Output (expected: Medium-High risk)
```
Events:
- No paste events
- 600 keydown events over 12 minutes
- interkey_std_dev ≈ 55ms (very smooth)
- backspace_ratio = 0.018 (very low)
- 2 burst_end events with wordcount=35, 42
- 1 pause > 2000ms
- sentence_variance = 8.3
- vocab_diversity = 0.38

Expected: score100 ≈ 55–70, risklevel='medium' or 'high'
Explanations: typing rhythm smooth, low editing, large bursts
```

### Scenario D: Baseline Deviation (expected: Medium risk)
```
Student fingerprint: wpm=28, backspace_ratio=0.14, sentence_variance=32
This submission: wpm=71, backspace_ratio=0.01, sentence_variance=6

Expected: baseline_deviation ≈ 0.8, +12 baseline bonus
score100 would be elevated compared to same signals without baseline
```
