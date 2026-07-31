# Essay Guard Changelog

## [1.2.216] — 2026-07-25

### FEATURE-EG-CENTRAL-CONFIG-INHERIT

`lib.php` now reads the Site ID and API key from **AI Grader Central Config** (`local_aiconfig`) before falling back to Essay Guard's own plugin settings. If `local_aiconfig` is installed and has a Site ID / API key entered, Essay Guard inherits them automatically — no need to re-enter credentials in Essay Guard's own settings page.

Two new helper functions provide the priority logic:

- `plagiarism_essayguard_get_siteid()` — returns `local_aiconfig`'s `siteid` if non-empty, otherwise Essay Guard's own `siteid` config value.
- `plagiarism_essayguard_get_apikey()` — returns `local_aiconfig`'s `apikey` if non-empty, otherwise Essay Guard's own `apikey` config value.

All internal credential reads (`check_unlock()`, `get_platform_settings()`) now call these helpers. This brings Essay Guard in line with AI Quiz Maker, DocGuard, and the other plugins in the suite, which all inherit credentials from `local_aiconfig` automatically.

**Why this matters:** Sites that had `local_aiconfig` installed and configured but had never entered credentials directly in Essay Guard's settings page were running in "open mode" (no unlock check, local-only scoring). This produced false-positive paste-gate warning badges for students who typed in an external editor before pasting — the plugin scored them with zero behavioural events and showed an orange warning. After updating to this version, Essay Guard will pick up the shared credentials and the unlock check will pass normally.

**No DB schema changes.**

---

## [1.2.123] — 2026-05-07

### FIX-EG-DIAG-CMCHECK

`diag.php` Section 1's CM-active check used `if ($eg_enabled)` where `$eg_enabled = get_config(...)`. `get_config()` returns PHP `false` when the key was *never saved* (every quiz on a fresh install, or any quiz whose settings have never been re-opened since the plugin was installed). `if (false)` evaluates as falsy, so the diagnostic reported "Not enabled — diagnostic data may be absent" (INFO) even though `is_cm_active()` returns `true` for these quizzes and the tracker runs normally. This was a false alarm that hid the real distinction: a never-saved key (enabled by default) versus an explicitly-set `'0'` (genuinely disabled). Fixed by mirroring `is_cm_active()` exactly — PHP `false` is now PASS, only an explicit `'0'` produces FAIL with `$overall_pass = false`.

### FIX-EG-DIAG-CMCHECK-S8

Section 8 now checks `$eg_cm_active` before running the ev-table query and, when the CM is disabled, immediately emits a FAIL row "CM DISABLED — zero events are expected and correct" with a clear "Fix: enable EssayGuard in quiz settings" action message. Previously, Section 8 always ran the ev-table query, found zero rows, and reported a generic "JS tracker binding failure / flush failure / flush rejected" message — admins would spend time debugging TinyMCE and the browser console when the entire problem was one unchecked checkbox in the quiz settings form.

### FIX-EG-GETLINKS-CM-DISABLED

`get_links()` (`lib.php`) now calls `is_cm_active($cmid)` at entry and returns `''` immediately when the CM is disabled. Previously, `get_links()` had no CM-active check, so stale score records written *before* the CM was disabled would continue rendering as risk badges indefinitely — teachers who disabled the plugin for a quiz would still see old wrong badges from earlier attempts, with no way to suppress them short of manually deleting DB records. No DB schema changes.

---

## [1.2.122] — 2026-05-07

### FIX-EG-DIAG-KSR

`analyser.php` computed `keystroke_ratio` (Signal 12) into a local `$ksr` variable but never wrote it to `$metrics`. As a result, `metricsjson` never contained a `keystroke_ratio` key on any scored session — including brand-new attempts made after v1.2.113. The diagnostic `diag.php` Section 3 tested for `isset($metrics['keystroke_ratio'])` and, finding it always absent, displayed "session scored before v1.2.113" even for fresh attempts, misleading admins into believing they had stale data when the real issue was a live capture failure. Fixed by adding `$metrics['keystroke_ratio'] = round($total_keystrokes / max(1, $text_chars), 4)` after the `$metrics['linguistic_fallback_pts']` assignment.

### FIX-EG-DIAG-EV-CHECK

Added Section 8 to `diag.php` — "Event capture verification" — which queries `plagiarism_essayguard_ev` directly and shows a full event-type breakdown (keydown, backspace, paste, large_insert, input) per attemptkey for the sample student. This is the ground-truth check that was missing from the diagnostic: `total_keystrokes=0` in an SC record is ambiguous (stale data vs live capture failure), but the ev table is unambiguous. Section 8 now correctly fails `$overall_pass` and sets the summary to **FAIL** when the ev table has zero behavioural events, replacing the misleading "ALL CHECKS PASSED" that admins were seeing even when the JS tracker had never sent a single keystroke to the server. Also cross-checks the most recent EV and SC `attemptkey` values and reports a FAIL with root-cause explanation if they differ (indicating an attemptkey lookup mismatch between event capture and scoring). No DB schema changes.

---

## [1.2.114] — 2026-05-04

### FIX-EG-LANG-RETENTION-DESC

`retentiondays_desc` in `lang/en/plagiarism_essayguard.php` incorrectly stated that *"Raw typing telemetry events and session scores"* are both deleted on the retention schedule. `cleanup.php` only deletes rows from `plagiarism_essayguard_ev` (raw keystroke telemetry); `plagiarism_essayguard_sc` score records (risk levels, metrics, explanations) are retained permanently regardless of this setting. Updated lang string to accurately describe the actual cleanup behaviour — important for GDPR data-retention planning by site admins. No DB schema changes.

---

## [1.2.113] — 2026-05-04

### FIX-EG-BADGE-MEDIUM

`report.php` used `'partial'` as the array key for `$risk_cfg`, the `$order` sort map, and the summary `$counts` array — but `analyser::risk_level()` has returned `'medium'` (not `'partial'`) since v1.2.112. Result: every medium-risk student received a green LOW badge, sorted to the bottom of the report alongside low-risk students, and counted as 0 in the summary row. Fixed: all three occurrences changed from `'partial'` to `'medium'`.

### FIX-EG-EXPLAIN-S10S11S12

`explainer.php` had no coverage for Signals 10, 11, and 12 introduced in v1.2.112 (IKI autocorrelation, speed-burst CV, keystroke ratio). Those three signals can contribute up to 30 pts combined with zero explanation text shown to the instructor. Added six new lang strings (`explain_ikiautocorr_high/med`, `explain_speedcv_high/med`, `explain_keystrokeratio_high/med`) and corresponding explain blocks in `explainer.php`.

### FIX-EG-EXPLAINER-S11-GATE

`explainer.php` Signal 11 gate used `$burst_count >= 3` (suspicious large-insert events — entirely unrelated to WPM windows) instead of `$typing_time >= 30000` (the actual analyser gate that ensures ≥3 WPM snapshot windows are available). This caused the explanation to be silently suppressed for sessions that had enough timing windows but zero large inserts, and to fire spuriously when burst count was high but the session was too short for meaningful CV data. Fixed: gate changed to `$typing_time_ex >= 30000`, mirroring analyser.php Signal 11 exactly.

### FIX-EG-TEXT-CHARS-METRIC

`text_chars` (final submitted text length after HTML entity decode) is now stored in `metricsjson` so `explainer.php` can compute Signal 12's keystroke ratio without approximation — using the exact same value the analyser used when scoring.

### FEATURE-EG-SIGNAL-BREAKDOWN

`analyser.php` now tracks each signal's point contribution inline with a `$signal_pts[N] = pts` assignment alongside every `$score += pts` statement, covering all 12 signals and all branches (including Signal 1's four branches and Signal 7's four branches). The full array is stored in `metricsjson` as `signal_breakdown`. A separate `linguistic_fallback_pts` key records the points added by the no-events linguistic fallback (up to 35 pts), which uses inflated weights and doesn't map to a numbered signal.

`student.php` reads these keys and renders a TypeShield-quality per-signal attribution table: signal number, name, max points, points contributed, fired/not-fired status pill, and a one-line evidence description. A separate "Linguistic pattern fallback" row appears when the fallback fired. The totals row shows the final score vs the accurate pre-cap total (including baseline deviation and fallback points). Graceful degradation for records scored before v1.2.113 (shows an informational note instead of the table).

`student.php` also promotes `'medium'` as the primary colour map key (orange `#fff7ed`/`#7c2d12`/`#fdba74`/`#f97316`) matching `report.php`; `'partial'` retained as a legacy alias for DB records written under older versions.

### FIX-EG-BUILD-REPORTER-COLOURS

`amd/build/reporter.min.js` COLOURS object was stale — `medium` mapped to amber (`#fffbeb`/`#92400e`) and `partial`/`mild` mapped to yellow (`#fefce8`/`#854d0e`). The corresponding `amd/src/reporter.js` was already corrected to canonical orange (`#fff7ed`/`#7c2d12`/`#fdba74`/`#f97316`) for all three keys, but the build file was never regenerated to match. Teachers viewing the quiz grading overview page (which loads `reporter.min.js`) saw yellow/amber medium badges while the Essay Guard report page and student detail page showed the correct orange. Build file COLOURS and LABELS objects now exactly match `src/reporter.js`.

### FIX-EG-TEST-RISK-LEVEL

`tests/test_analyser.php` Scenario 9 risk_level assertions used `'partial'` as the expected return value for the medium band (30–65), with a comment stating "the function returns the internal key 'partial' for medium band". `analyser::risk_level()` has returned `'medium'` since v1.2.112. The stale assertions caused all ten boundary-value risk_level tests to fail (`expected 'partial', got 'medium'`). Fixed: expected values updated to `'medium'`; boundary values corrected to match actual thresholds (29→low, 30→medium, 65→medium, 66→high); comment updated.

### FIX-EG-TEST-VERSION

`tests/run_all.php` banner text read "Essay Guard v1.1.0 — PHP Test Suite", referencing a version number 112 minor releases out of date. Updated to "v1.2.113".

**Files changed:** `analyser.php`, `explainer.php`, `report.php`, `student.php`, `lang/en/plagiarism_essayguard.php`, `version.php`, `amd/build/reporter.min.js`, `tests/test_analyser.php`, `tests/run_all.php`. No DB schema changes. Savepoint `2026050400116`.

---

## [1.2.110] — 2026-05-01

### FIX-EG-FALLBACK-PASTE-ONLY

**Two opposite live-test failures fixed by a single rule change.**

- **Bug A — pasted answer badged LOW**. Student A pasted an answer into Q1; the badge showed LOW. Investigation: Ctrl+V emits 1–2 keydown events that were tagged with this slot, but the `paste` / `large_insert` event was tagged with a different slot (or none). The per-question scorer therefore saw `total_keystrokes > 0` but no Signal 1, so `riskscore = 0`. v1.2.109's discriminator (`!has_activity` required for fallback) read "activity > 0" and refused to consult the aggregate — even though the aggregate had clearly detected the paste. Result: real paste shown as LOW.
- **Bug B — typed answer badged MEDIUM (OVERALL)**. Student B typed Q1 naturally; the badge showed MEDIUM. Investigation: qslot detection failed entirely for this attempt — all events landed in the aggregate, the per-question record had `riskscore = 0` and no activity. v1.2.109 fell back to the aggregate, which scored ≈ 35 from honest-typing signals (Signal 4 "no long pauses" +20 plus Signal 5 "no backspaces" +15) without any paste being involved. Result: honest typist shown as MEDIUM (OVERALL).

The shared root cause is that v1.2.109's per-question `has_activity` check does not distinguish between "the aggregate genuinely detected a paste" and "the aggregate is just typing-signal noise". A single rule resolves both bugs:

> Fall back to the aggregate ONLY when the aggregate has CONCRETE paste evidence: `paste_events > 0` OR `riskscore >= 0.70` (the HIGH threshold, only reachable when Signal 1 fires from a paste / large_insert). Typing-only aggregate scores in the 35–69 MEDIUM range never rescue a per-question slot.

The previous `!has_activity` check on the per-question side was dropped because Ctrl+V keydowns are not a reliable indicator that the paste was attributed to the same slot.

**Outcomes** (the four scenarios all resolve correctly with the new rule):

| Scenario | per-question `riskscore` | aggregate paste evidence | Result | Correct? |
| --- | --- | --- | --- | --- |
| Bug A — paste mis-attributed | 0 | yes | aggregate → HIGH | ✓ |
| Bug B — honest typist, qslot failed | 0 | no | per-question → LOW | ✓ |
| Per-question paste correctly tagged | > 0 | n/a | per-question (unchanged) | ✓ |
| Honest typist, qslot worked | 0 with activity | no | per-question → LOW | ✓ |

- Applied identically to `lib.php` (Review Attempt page) and `report.php` (per-question rows in the EssayGuard Report).
- PHP-only. No AMD changes — `tracker.min.js` and `reporter.min.js` unchanged from v1.2.107.
- No DB schema changes — uses existing `paste_events` and `riskscore` columns.
- `version.php` → `2026050100113`.

---

## [1.2.94] — 2026-04-28

### FIX-EG-ENABLED-CHECK-INCONSISTENT + BUG-CURL-RESETOPT

**Root cause**: two independent bugs found by exhaustive word-by-word code audit. On a fresh Moodle install (admin has never submitted the Essay Guard settings page), Essay Guard produced no badges at all and stored no events.

- **FIX-EG-ENABLED-CHECK-INCONSISTENT** — `observer.php`, `finalize_attempt.php`, `log_event.php`: `get_config('plagiarism_essayguard', 'enabled')` returns PHP `false` when the key has never been saved to the Moodle DB. Every three files used the old `!get_config(...)` pattern — `!false = true` — so the early-exit guard fired on every fresh-install request. v1.2.88 (FIX-EG-GLOBAL-ENABLED-MISSING) fixed this identical bug in `inject_tracker()` but the same fix was not applied to the three other critical paths:
  - `observer.php::is_active()`: returned `false` → PHP quiz_attempt_submitted observer never ran → no score records written to `plagiarism_essayguard_sc`.
  - `finalize_attempt.php`: returned `empty_result` → JS `finalizeAttempt()` AJAX received an empty result → badge never shown, no score record written via JS path either.
  - `log_event.php`: returned `['ignored' => true]` **without storing events** → JS `flush()` received a success response and cleared those events from its queue, believing they were persisted — events were silently lost permanently, making it impossible for any scorer to ever produce a non-zero result.

  **Fix**: all three files now use `$v = get_config(...); if ($v !== false && empty($v)) { ... }` — only exits when the key EXISTS and is explicitly falsy (i.e. admin deliberately disabled it). Identical to the v1.2.88 fix.

- **BUG-CURL-RESETOPT in `auto_unlock()`** — `lib.php`: `auto_unlock()` set curl options (including `Content-Type: application/json` header) via `$curl->setopt()` before calling `$curl->post()`. Moodle's `\curl::post()` calls `resetopt()` internally before applying its own curl options, silently discarding every option previously set via `setopt()`. The `Content-Type: application/json` header was never sent to the unlock server; the server could not parse the JSON body; auto-unlock always silently returned false. RTOs whose site hadn't been manually unlocked could not use auto-unlock to activate the plugin. **Fix**: curl options passed as 3rd argument to `post()` — the same BUG-CURL-RESETOPT pattern just fixed in `mod_aiknowledgecheck` and `mod_aivideoactivity`.

- No AMD changes (tracker.js unchanged). No DB schema changes. version.php → 2026042800098.

---

## [1.2.89] — 2026-04-28

### FIX-EG-QSLOT-LOCKTIME + FIX-EG-QSLOT-STRICT

**Two per-question event-isolation fixes found by live-lab testing (events not reliably tagged with qslot → per-question pools empty or contaminated).**

- **FIX-EG-QSLOT-LOCKTIME (tracker.js)**: `eq()` inside `bindField()` re-read `field.dataset.essayguardQslot` dynamically on every event call. Any event fired before qslot resolved (e.g. the very first `focus` or `keydown` before `cacheQslot` finished its DOM walk) was stored without a `qslot` key, routing it to the aggregate pool (qslot=0) rather than the per-question pool. Fix: capture `_bindQslot` once at `bindField()` entry — read from `field.dataset.essayguardQslot` if already set by `cacheQslot()` / `bindTinyMCENode()`, otherwise fall back to the name-regex `/:(\d+)[_:]/` on `field.name` or `field.dataset.essayguardName`. `_bindQslot` is closed over inside `eq()` and never re-read. A `console.log('[EssayGuard DIAG] bindField qslot lock: ...')` line reports the resolved slot at bind time for live-lab diagnostics.

- **FIX-EG-QSLOT-STRICT (analyser.php)**: The per-question filter used `(int)($p['qslot'] ?? 0) === $qslot`. This was semantically correct (missing qslot → 0 → 0 ≠ N → false, excluded) but forward-unsafe: if tracker.js ever emitted an explicit `qslot: 0` in a payload (e.g. `parseInt('') === 0` edge case), that event would land in the aggregate pool AND trigger the wrong filter branch. Fix: changed to `isset($p['qslot']) && (int)$p['qslot'] === $qslot` — only events that tracker.js explicitly tagged with a slot number are counted toward that question. Mirrors the JS lock-at-bind-time fix: both sides now enforce strict qslot isolation.

- No DB schema changes. version.php → 2026042800093.

---

## [1.2.88] — 2026-04-28

### FIX-EG-GLOBAL-ENABLED-MISSING + FIX-EG-TINYMCE-BIND + FIX-EG-NAME-OUTERNODE-SYNC + FIX-EG-PASTE-QSLOT-NAME-RETRY + FIX-EG-TINYMCE-MUTOBS

**Five root-cause fixes for "always Low" badge, targeting CM-active detection, TinyMCE AMD timing, and qslot detection failures identified by exhaustive 20-reason audit.**

- **Bug 6 — `inject_tracker()` treated a missing global enabled key as disabled (lib.php)**: `get_config('plagiarism_essayguard', 'enabled')` returns PHP `false` when the key has never been saved to the Moodle DB (fresh install where admin hasn't explicitly submitted the plugin settings form yet). `!false = true` caused the very first early-exit guard to fire on every page — the tracker was NEVER injected on a fresh install. Fix: `$global_enabled = get_config(...); if ($global_enabled !== false && empty($global_enabled)) { return; }` — only skips injection when the key EXISTS and is explicitly disabled. Consistent with `is_cm_active()` which already treats a missing per-CM key as enabled.

- **Bug 7 — TinyMCE iframe binding duplicated inside `scan()` (tracker.js, refactor)**: The 120-line TinyMCE binding block was inlined inside `scan()` as an anonymous `forEach` callback, making it impossible for any other code to call it. Extracted into a named `bindTinyMCENode(node)` function with an `_egTmBound` idempotency guard. `scan()` now delegates to `bindTinyMCENode(node)` for each found iframe. No behaviour change — prerequisite for the next two fixes.

- **Bug 8 — `essayguardName` was set on iframe body but not on the outer `<iframe>` node (tracker.js)**: In `bindTinyMCENode()`, `essayguardName` (the hidden textarea's Moodle name attribute, e.g. `q123:2_answer`) was resolved and stored on `body.dataset.essayguardName` but never mirrored to `node.dataset.essayguardName` on the outer `<iframe>` element. The paste-time qslot retry runs in the outer document context and has a closure reference to `outerNode` (the outer `<iframe>`) — it could not reach into the body to read the name. Fix: `if (body.dataset.essayguardName && !node.dataset.essayguardName) { node.dataset.essayguardName = body.dataset.essayguardName; }` — mirrors the name to the outer node immediately after it is resolved.

- **Bug 9 — paste-time qslot retry only tried DOM container paths, not name-regex path (tracker.js)**: The v1.2.86 paste-time retry called `extractQslot(outerNode)` which checks `.que` container id, `[id^="question-"]` id, and `[data-slot]`. On Moodle themes where none of those containers exist (or have non-standard ids) but `essayguardName` was successfully mirrored to the outer node (Bug 8 fix), the retry still returned 0 and the paste was stored with `qslot=0`. Fix: added a third retry path — if `extractQslot` returns 0 and `outerNode.dataset.essayguardName` is set, applies `/:(\d+)[_:]/` regex to extract the slot number from the textarea name. This covers any Moodle install where the standard textarea naming convention `q{attempt}:{slot}_answer` is used, regardless of DOM container structure.

- **Bug 10 — TinyMCE iframes injected after initial `scan()` were bound up to 2 s late (tracker.js)**: `setInterval(scan, 2000)` catches late-appearing TinyMCE iframes within 2 seconds — but a student who pastes immediately after a below-the-fold question scrolls into view (triggering Moodle 4.4+ lazy TinyMCE init) could paste before the next scan tick. Fix: `observeTinyMCEIframes()` installs a `MutationObserver` on `document.body` watching for added subtrees containing `.tox-edit-area iframe` or `.tox-tinymce iframe` elements. On detection it calls `bindTinyMCENode()` with a 300 ms delay (to let the iframe body initialise), reducing the binding latency from up to 2000 ms to under 400 ms. The observer is started immediately after the first `scan()` call in `init()`.

- No DB schema changes. version.php → 2026042800092.

---

## [1.2.87] — 2026-04-28

### FIX-EG-GETLINKS-HIGHER-OF-TWO + FIX-EG-PREVLEN-CONTENTEDITABLE

**Root cause**: two more "always Low" sources found by exhaustive 20-reason audit.

- **Bug 4 — `lib.php plagiarism_get_links()` ignored aggregate when per-question record existed (lib.php)**: the v1.2.86 higher-of-two fix was only applied to `get_badges.php` (teacher overview grid). `plagiarism_get_links()` — used for every badge on the attempt-review page and the individual grading page — still used the per-question record if it existed, even if its `riskscore` was 0. `log_event.php` writes a per-question record (score=0) on the very first event batch (focus/keydown events before any paste), then updates it to High when the paste arrives. But if qslot detection failed in tracker.js, the paste was stored with `qslot=0` — the per-question record stayed at score=0 (Low) while the aggregate correctly scored High. The overview (get_badges.php) correctly showed High; every review and grading page showed Low. **Fix**: both `$pq_record` and `$agg_record` are always fetched; whichever has the higher `riskscore` is used. When the aggregate wins, `$is_aggregate_fallback = true` so the badge label appends "(overall)" (preserving v1.2.84 UX).

- **Bug 5 — `_prevLen` initialised to 0 for contenteditable fields (tracker.js)**: `field.value` is `undefined` for contenteditable elements (TinyMCE iframe body, Atto `.editor_atto_content`). The old guard `field.value ? field.value.length : 0` evaluates falsy for `undefined` AND for empty string — correct for an empty textarea, but wrong for a contenteditable with existing draft content. With `_prevLen = 0` and existing draft text of, say, 500 chars, the first `input` event produces `delta = 501` → `large_insert` emitted → Signal 1 (+40 pts) fires falsely → student who only typed a single correction in a saved draft receives a High risk badge. **Fix**: changed to `field.value != null ? field.value.length : (field.textContent ? field.textContent.length : 0)`. In JS loose equality `undefined == null` is true, so `undefined != null` is false — contenteditable correctly falls through to `textContent.length` for initialisation.

- No DB schema changes. version.php → 2026042800091.

---

## [1.2.86] — 2026-04-28

### FIX-EG-ALWAYS-LOW (three-layer root-cause fix)

**Root cause**: badges always showed "Low" regardless of student behaviour. Three separate bugs combined to cause this.

- **Bug 1 — paste fallback excluded the most common clipboard cases (`analyser.php`)**: the fallback that attributes paste events to per-question scores only included events where `insertlen` was within 90–110% of the answer text length. Two situations caused systematic exclusion: (a) `insertlen = 0` — TinyMCE 6 and some browser/OS combinations cannot read clipboard text in a capture-phase `paste` handler, so `text = cd.getData('text/plain')` returns `''`; the paste event still fires and proves a paste occurred, but `plen >= 10` was false so the event was dropped; (b) `insertlen` outside the 90–110% band — students who edited after pasting (correcting a word, adding a sentence) end up with a submitted text length different from the raw paste length, but the paste still happened. **Fix**: apply the length band only when _both_ `plen > 0` and `text_chars_early > 0`; if either side is unknown, include the paste unconditionally. `$pastecount` now increments correctly → Signal 1 (+40 pts) fires.

- **Bug 2 — linguistic fallback never fired for per-question scores with empty event pools (`analyser.php`)**: the `LINGUISTIC FALLBACK` block ran only when `$all_events` was empty. If events were in the DB (from the attempt) but qslot detection failed in `tracker.js` (events stored without a qslot tag), the per-question filter left `$events` empty while `$all_events` was non-empty — so the fallback condition `empty($all_events)` was false, no linguistic boost was added, and score stayed 0 → LOW. **Fix**: extended the condition to also fire when `empty($events) && $qslot > 0 && !empty($all_events)` — adds up to 35 pts (sentence variance +20, vocab diversity +15) for AI-uniform or copy-pasted text, pushing score to at least MEDIUM.

- **Bug 3 — per-question Low record shadowed correct aggregate High record (`get_badges.php`)**: the overview badge preferred per-question records (`qslot > 0`) over the aggregate (`qslot = 0`). When qslot detection failed (events stored as qslot=0), the aggregate scoring correctly detected the paste and produced HIGH, but the per-question scorer found no events and produced LOW. `get_badges.php` showed the LOW per-question record. **Fix**: compare both records and return whichever has the _higher_ `riskscore` — the aggregate now rescues per-question failures.

- **tracker.js — runtime `extractQslot()` retry in TinyMCE paste handler**: `scan()` caches qslot once at DOM-ready, but on Moodle 4.x with AMD deferred rendering the `.que` container may not exist yet when `scan()` first runs. Added a `try { extractQslot(outerNode) }` retry inside the doc-level paste capture handler so qslot is detected at the moment of paste even if the initial cache missed it.

- No DB schema changes. version.php → 2026042800090.

---

## [1.2.85] — 2026-04-28

### FIX-EG-PASTE-THRESHOLD

- **Root cause of "every student shows Low risk even when pasting"**: Signal 1 in `analyser.php` used `$large_insert_max_delta > 200` as the fallback paste detector for TinyMCE/Atto editors (which intercept the native `paste` event and fire `input`/`large_insert` instead). Any student pasting an answer shorter than 200 characters never crossed that threshold — only Signal 2 fired (+8 pts), producing a score of ~8 → **LOW** every time regardless of behaviour.
- **Fix (`classes/local/service/analyser.php`)**: Changed Signal 1 condition from `$large_insert_max_delta > 200` to `$large_inserts > 0`. The `large_insert` event is emitted by `tracker.js` whenever a single input event adds more than 20 characters in one shot. Normal keystroke-by-keystroke typing produces `delta = 1` per input event — never > 20. Only paste, drag-and-drop, and voice dictation produce delta > 20, all of which are legitimate authorship concerns.
- **Scoring impact**: A single pasted answer of any length now scores: Signal 1 (+40 pts) + Signal 2 (+8 pts) = **48 pts → HIGH**. Previously, answers under 200 chars scored only 8 pts → LOW.
- No DB schema changes. version.php → 2026042800089.

---

## [1.2.84] — 2026-04-27

### FIX-EG-EXTRACT-BULLETPROOF + FIX-EG-RESPONSESUMMARY-FALLBACK + DEBUG-PANEL

- **`extract_plain_text()` helper in `observer.php`**: 4-step pipeline — strip_tags → html_entity_decode → NBSP replace → whitespace collapse. Fixes TinyMCE `<p>&nbsp;</p>` being mis-classified as non-empty content (was `"&nbsp;"`, now correctly `""`). Both `get_quiz_essay_texts_by_slot()` and `get_quiz_essay_text()` use the new helper.
- **`responsesummary` fallback**: when step_data approach yields no non-empty slots, `get_quiz_essay_texts_by_slot()` now tries `question_attempts.responsesummary` (Moodle's own auto-generated plain-text summary for essay questions, always populated regardless of editor type). Diagnostic log written when fallback is used.
- **New `debug.php` in-Moodle debug panel** at `/plagiarism/essayguard/debug.php?cmid=X`: shows plugin status (enabled/unlocked/version), all DB score records for the activity, per-attempt extraction preview with raw HTML vs. extracted text side-by-side, and automated quick-diagnosis section. No PHP server log access required.
- **Debug link on `report.php`**: "Diagnose / Debug Panel" link at the bottom of the Essay Guard class report.
- version.php → 2026042700088.

---

## [1.2.83] — 2026-04-27

### FIX-EG-BADGE-FALLBACK

- **Root cause of blank badge for students**: `get_links()` was returning blank (not even a Low badge) whenever the per-question (qslot=N) DB record didn't exist — even when the aggregate (qslot=0) record DID exist. This happened because the observer's `get_quiz_essay_texts_by_slot()` sometimes returns empty (returning the wrong row structure, empty answer text, etc.), causing per-question scoring to be skipped. The old v1.2.64 "dedup" guard hard-returned early with just the report link (blank for students).
- **Fix**: When qslot > 0 but no per-question record is found, code now falls through to the aggregate (qslot=0) lookup instead of returning blank. Students always see the badge as long as the aggregate record exists.
- **Diagnostics added**: `error_log('[EssayGuard] observer: ...')` on every `quiz_attempt_submitted` showing attemptid, attemptkey, finaltext length, and slot_texts count. `error_log('[EssayGuard] get_links: ...')` on every badge lookup showing whether per-qslot and aggregate records were found. Check Moodle's PHP error log to trace the scoring chain.
- version.php → 2026042700085.

---

## [1.2.82] — 2026-04-27

### BUMP

- Version increment only — no code changes. Ensures Moodle upgrade detection fires cleanly on all installations upgrading from v1.2.81 or earlier. version.php → 2026042700084.

---

## [1.2.81] — 2026-04-27

### Root-cause fix for "always LOW badge" + three hardening changes

**FIX-EG-ATTEMPTKEY** (`lib.php`, `classes/observer.php`)
- Removed `sesskey()` from the attemptkey seed. `sesskey()` is regenerated by Moodle on every page load, autosave, and quiz navigation — each page produced a **different** sha1 key, so events flushed from page 1 were stored under key A but the scorer ran with key B (from the submission page), returning an empty event set and always scoring LOW.
- Quiz: attemptkey is now `'qa_{quizattemptid}'` — stable, unique, and the same value across every question page of the same attempt.
- Non-quiz (assignment, forum): `sha1(userid:cmid)` — sesskey-free.
- Observer's `on_quiz_attempt_submitted()` now constructs the key directly from `$event->objectid` (the quiz attempt ID) — no user preference lookup required, eliminating any read-race at submission time.

**FIX-EG-PASTE-DETECT** (`classes/local/service/analyser.php`)
- Signal 1 (paste → +40 pts) now also triggers when `large_insert_max_delta > 200`. TinyMCE and Atto intercept the native `paste` event and fire `input`/`large_insert` instead — the JS `paste` event never fires in those editors.

**LINGUISTIC-FALLBACK** (`classes/local/service/analyser.php`)
- When the event pool is empty (JS failed to load, old pre-fix session, etc.) and the submitted text is > 100 chars, score purely from linguistic signals — sentence variance and vocab diversity — so the system returns a meaningful badge instead of always returning LOW by default.

**DEBUG LOGGING** (`classes/local/service/analyser.php`)
- Added `error_log('[EssayGuard] score_attempt: ...')` showing event count and attemptkey so site admins can confirm events are reaching the scorer from the PHP error log.

---

## [1.2.80] — 2026-04-27

### Bug Fixes
- **FIX-EG-QUIZ-PAGE-GUARD-HOTFIX** — v1.2.79 pagetype-only guard silently blocked tracker injection on attempt.php on Moodle 4.3+ installations where `$PAGE->pagetype` was not yet `mod-quiz-attempt` when the `before_standard_head_html_generation` hook fired. Fix: dual check — accept the page if EITHER `$PAGE->pagetype === 'mod-quiz-attempt'` OR the REQUEST_URI contains `/mod/quiz/attempt.php`. Either condition alone is sufficient to allow injection. Review, view, summary and grade pages still blocked (none match both checks simultaneously).
- **DIAG-EG-INJECT** — Added PHP `error_log` diagnostics at every early-exit point in `plagiarism_essayguard_inject_tracker()`. Each skip now logs `[EssayGuard] inject_tracker: SKIP <reason> url=<path>` to the PHP error log, giving server admins visibility into exactly which check blocked injection without needing browser console access.

## [1.2.79] — 2026-04-27

### Bug Fixes

- **FIX-EG-QUIZ-PAGE-GUARD** (`lib.php`):
  Extends the v1.2.78 review-page guard to a full quiz-page allowlist.
  A screenshot confirmed the tracker was also firing on `view.php?id=1042`
  (the quiz landing page with the "Attempt quiz" button, Moodle pagetype
  `mod-quiz-view`) — producing cacheQslot FAIL warnings and running a
  pointless setInterval before the student had even clicked to start the
  attempt. The v1.2.78 fix only blocked `mod-quiz-review`; this fix replaces
  the single-entry denylist with an allowlist: only `mod-quiz-attempt` passes
  through. All other quiz pagetypes (`mod-quiz-view`, `mod-quiz-review`,
  `mod-quiz-summary`, `mod-quiz-grade`) are now blocked at the PHP level.
  After this fix, the tracker will be completely silent on every page except
  the live attempt page, so any future console logs will be unambiguously from
  the right page. No DB schema changes.

## [1.2.78] — 2026-04-27

### Bug Fixes

- **FIX-EG-REVIEW-PAGE** (`lib.php`):
  `plagiarism_essayguard_inject_tracker()` had no guard against the Moodle quiz
  review page (`mod-quiz-review` pagetype). Both `attempt.php?attempt=N` and
  `review.php?attempt=N` contain the `?attempt=N` query param, so the tracker was
  injected on both. On the review page the attempt is already submitted — there is
  no essay form to intercept, no keystrokes to record, and `finalizeAttempt` is
  never called. The only effect was: the tracker scanned for textareas every 2 s
  (finding the AI Tutor input and one unrelated unnamed textarea), emitted
  `[EssayGuard DIAG] cacheQslot: FAIL` console warnings on every interval, and
  ran a pointless `setInterval` for the entire review session. The false FAIL lines
  also led to three rounds of diagnostic confusion — all review-page logs, none
  from the attempt page where scoring actually happens.
  Fix: added `if ($PAGE->pagetype === 'mod-quiz-review') { return; }` immediately
  after the teacher-bypass guard.

- **FIX-EG-INIT-DIAG** (`amd/src/tracker.js`):
  Added a one-line diagnostic at the very start of `init()` that logs the base URL
  of the current page, the cmid, how many quiz submit forms were found, and the
  first 8 chars of the attemptkey. This appears as the FIRST console line whenever
  the tracker loads, making it immediately clear whether the tracker is running on
  the correct page (attempt.php with submitForms≥1) or somewhere unexpected
  (submitForms=0 = review, assignment confirmation, etc.).

## [1.2.77] — 2026-04-27

### Bug Fixes

- **FIX-EG-AITUTOR-EXCLUDE** (`amd/src/tracker.js`):
  The AI Course Format injects an AI Tutor chat textarea (`id="aicourse-ai-input"`) into
  every quiz page. Because it sits outside any `.que` container, `isTypedAnswerField()`
  returned `true` for it — causing Essay Guard to bind it as if it were an essay answer
  field. This had two consequences: (1) keystrokes typed into the AI Tutor were logged
  as telemetry events under qslot=0, polluting the aggregate score; (2) when the AI
  Tutor auto-inserted text, the paste handler fired a false "paste recorded" banner inside
  the AI chat box, alarming students unnecessarily.
  Fix: `scan()` now checks each textarea's `id` before calling `cacheQslot`/`bindField`
  and skips any node whose `id` begins with `aicourse-` (case-insensitive).

- **FIX-EG-QSLOT-ALTIDFMT / FIX-EG-QSLOT-QUESTION-ID / FIX-EG-QSLOT-DATA**
  (`amd/src/tracker.js`):
  `extractQslot()` previously only recognised `.que` containers with `id="q{N}"` (e.g.
  `id="q1"`, `id="q2"`). On some Moodle versions / third-party themes the quiz question
  container uses a different id format (`id="question-{attemptid}-{slot}"`) or an
  additional data attribute (`data-slot`). When none of the three patterns matched,
  qslot stayed 0 for ALL textareas — events were logged to the DB under slot 0, but
  `finalizeAttempt` queried for the actual slot number (1, 2, …), found 0 events, and
  the scorer returned a purely linguistic result (max 15 points) → LOW badge for every
  student regardless of how authentically they typed.
  Three new fallbacks added in priority order after the existing `id="q{N}"` check:
  1. Last numeric group of the `.que` container's id (e.g. `q363-1` → slot 1).
  2. Closest `[id^="question-"]` ancestor — last numeric group (e.g. `question-363-2` → slot 2).
  3. Closest `[data-slot]` ancestor — reads the attribute value directly.

- **FIX-EG-QSLOT-DIAG** (`amd/src/tracker.js`):
  `cacheQslot()` now emits `[EssayGuard DIAG] cacheQslot:` console lines for every
  field showing which extraction path succeeded, or a `console.warn` FAIL line listing
  the `.que`, `[id^="question-"]`, and `[data-slot]` ancestors actually found in the
  DOM. This makes the next diagnostic session self-explanatory without needing a new
  build.

## [1.2.76] — 2026-04-27

### Diagnostic Build

- **DIAG-EG-CONSOLE** (`amd/src/tracker.js`, `amd/build/tracker.js`, `amd/build/tracker.min.js`):
  Added `[EssayGuard DIAG]` console logging to every key tracker path so the root cause
  of "all students get LOW badge" can be identified from the browser console without
  needing database access.
  - `flush()`: logs how many events are being sent, the attemptkey prefix, event type list,
    and whether the server accepted or rejected the batch.
  - `bindField()`: logs every field that is discovered and bound, including its tag name,
    field name, inferred qslot, and element id — makes it immediately visible if zero
    fields are being bound (meaning the tracker is not attaching to any answer boxes).
  - `scan()`: logs how many DOM nodes match each selector (`textarea`,
    `[contenteditable="true"]`, `.editor_atto_content`) and how many TinyMCE iframes
    are found — reveals which editor type is in use.
  - `finalizeAttempt()`: logs the qslot, text length, returned risk level, score, and
    keystroke count from the server response — shows whether JS-path scoring is working.
  - `interceptSubmitForms()`: logs when a form submit is intercepted, the form action,
    queued event count, and bound field count — confirms the intercept fires at all.
  No scoring logic, DB schema, or PHP changes. version.php → 2026042700076.

## [1.2.75] — 2026-04-26

### Fixed

- **FIX-EG-QSLOT-FALLBACK-V2** (`classes/local/service/analyser.php`):
  The v1.2.74 fallback for pre-fix sessions (where tracker.js failed to tag events with
  qslot) had two overlapping bugs that caused every essay question to display the same
  badge.

  **Bug 1 — Shared keystroke pool:** All non-paste events (keydown, input, typing rhythm)
  were included unconditionally for every question. This meant Q1 and Q2 both received the
  entire session's keystroke data, making their speed, pause-count and backspace-ratio
  signals identical. Even when only Q1 was copy-pasted, both questions produced the same
  base score.

  **Bug 2 — Wide paste band (80–110 %):** A paste of 200 chars attributed to Q1 also
  matched Q2 (text length 190 chars): 200 ≤ 190×1.10=209 AND 200 ≥ 190×0.80=152 — both
  conditions passed — so Q1's paste was credited to Q2 too. Both questions scored HIGH.

  **Fix:** Non-paste events are now excluded from the fallback. Only paste events are
  included, attributed by text-length matching with a tighter 90–110 % band (was 80–110 %).
  Result: Q1 (pasted) scores HIGH from the paste signal; Q2 (typed, no matching paste)
  scores 0 → LOW. Questions are now correctly differentiated even in pre-fix sessions.

- **FIX-EG-QSLOT-CONTENT** (`lib.php`):
  Older Moodle versions (< 4.1) do not pass a `questionattempt` object in the `$linkarray`
  when rendering essay responses on the attempt-review or grading page. Without it,
  `$qslot` stayed 0 and every question read the same aggregate (qslot=0) DB record,
  displaying an identical badge for all questions regardless of their individual scores.

  **Fix:** New helper `plagiarism_essayguard_find_qslot_by_content()` uses the submitted
  content text to look up the matching quiz question slot from the attempt step data. When
  `questionattempt` is not in the linkarray, this function is tried automatically. Results
  are cached in a static map so the DB lookup only runs once per student+cm pair per page
  load. Falls back to aggregate (qslot=0) if no match is found (assignment, forum, or no
  finished quiz attempt on record).

## [1.2.73] — 2026-04-26

### Fixed

- **FIX-EG-LARGE-INSERT** (`amd/src/tracker.js`, `amd/build/tracker.js`, `amd/build/tracker.min.js`):
  A delta > 20 characters in the `input` handler now emits a separate `large_insert` event.
  Previously, pastes between 21 and 149 characters were recorded only as normal input events,
  scoring zero paste-signal points. This caused copied sentences/paragraphs to score LOW even
  when the content was clearly not typed character-by-character.

- **FIX-EG-SCORE-ENGINE** (`classes/local/service/analyser.php`):
  Complete scoring rebalance targeting accurate paste / AI-generation detection.
  - Paste event: **+40 pts** flat (was +7 per event, which scored only 7% for a single paste).
  - Large insert (>20 chars): **+20 pts** (new signal).
  - Keystroke speed > 15 cps: **+30 pts** (was part of entropy blend).
  - Pause count < 2: **+20 pts** (AI-generated text pasted in shows zero thinking pauses).
  - Near-zero backspace ratio (<0.02): **+15 pts** (AI has no typos to correct).
  - Total typing time < 10 s: **+25 pts** (catches instant-paste submissions).
  - Entropy signal capped at **+10 pts** (was +20; reduced to avoid penalising fast-but-genuine typists).
  - Sentence variance and vocab diversity each capped at +10 and +5 respectively (unchanged in weight).
  - New risk thresholds: **LOW 0–15** (was 0–9), **MEDIUM 16–40** (was 10–49), **HIGH 41–100** (was 50–100).
  - Net effect: pure paste → ~100 (HIGH). AI-speed + no pauses → ~80 (HIGH). Normal student → 0–10 (LOW).

- **FIX-EG-STUDENT-BADGE** (`report.php`):
  The student-row badge in the quiz grading overview now derives from the **worst per-question
  score** instead of the diluted aggregate (qslot=0). When a student pasted into Q1 but typed
  cleanly through Q2, the aggregate blended the scores and showed LOW overall. The fix selects
  the highest-risk per-question record and uses that for the badge. Falls back to the aggregate
  when no per-question records exist (assignments, forums, or tracker not yet active).

- **FIX-EG-STUDENT-LEVEL** (`student.php`):
  The student detail page and per-question breakdown table now call `analyser::risk_level($score)`
  instead of reading the stale `risklevel` DB column. The `risk_colours` map is updated to
  include the `'partial'` key (matching the analyser's output) and retains legacy
  `'mild'` / `'medium'` keys for backwards compatibility with older DB records.

## [1.2.71] — 2026-04-23

### Fixed
- **FIX-EG-BADGE-LEVEL** (`report.php`, `lib.php`, `get_badges.php`): Risk badge labels
  (Low / Medium / High) were read directly from the `risklevel` DB column, which contained
  stale values written under old threshold configurations. Consequence: a 10% or 16% score
  (range 10–49, which the documented schema classifies as "Medium") displayed the "LOW"
  label and green colour instead of the correct amber "MEDIUM" badge.

  Root cause: the `risklevel` DB column is not automatically backfilled when thresholds
  change. Any record written under a previous 4-tier scheme (`low / mild / medium / high`)
  would continue to show the old label even after the analyser was updated to 3 tiers
  (`low / partial / high`).

  Fix (all three files): replace the direct `$record->risklevel` read with a call to
  `analyser::risk_level($risk_pct)`, which re-derives the level from the live score at
  display time. `analyser::risk_level()` is now the single source of truth for
  label and colour.

- **FIX-EG-BADGE-LEVEL** (`report.php`): The `$risk_cfg` config array used the old
  4-tier keys (`low / mild / medium / high`). The current analyser returns `'partial'`
  for 10–49 scores, but `'partial'` was missing from `$risk_cfg`, so `$risk_cfg[$level]`
  fell back to `$risk_cfg['low']` (green), producing the wrong colour regardless of
  the recalculated level. Fixed: replaced the 4-tier array with the 3-tier array
  (`low / partial / high`) matching `analyser::risk_level()` output.

- **FIX-EG-BADGE-LEVEL** (`report.php`): The sort-order map for `usort` did not include
  `'partial'`, so "Medium" students were sorted into the same bucket as "Low" students,
  appearing out of order in the class report. Fixed: sort map updated to
  `['high' => 0, 'partial' => 1, 'low' => 2]`.

- **FIX-EG-BADGE-LEVEL** (`report.php`): The summary counts array included stale keys
  `['low', 'mild', 'medium', 'high']`; counts for `'partial'` students were silently
  dropped, causing the "Low 2" summary pill to show incorrect student counts. Fixed:
  counts array updated to `['low', 'partial', 'high']` with level re-derived from score.

  No DB schema changes. No AMD changes. version.php → 2026042300071.

## [1.2.68] — 2026-04-21

### Fixed
- **FIX-EG-BADGE-OVERVIEW-ALL-COLS** (`lib.php`): The v1.2.67 static-dedup guard suppressed the badge on Q.2, Q.3, … columns of the quiz grading overview page. All aggregate (`qslot=0`) calls for the same `user+cmid` shared the same static map key, so only the Q.1 column rendered the badge; subsequent columns returned only a report link. Teachers saw an empty cell for Q.2, which looked like missing data. Root cause: the guard was intended to prevent an identical aggregate badge from appearing in every question column (visual duplication), but the consequence — showing nothing for Q.2 — was worse than the duplication it was trying to solve. Fix: remove the static `$eg_badge_rendered` map from `plagiarism_essayguard_get_links()` entirely. The aggregate badge now renders on every question column on the overview page. Showing the same overall score on each column is correct behaviour — there is one aggregate record per student submission. Per-question badges (`qslot > 0`, rendered on the attempt-review page) are unaffected; they already have unique DB keys per slot.

### Documentation
- **FIX-EG-DOCS-SYNC** (SaaS `EssayGuard.tsx`): The public documentation page described an outdated 5-signal scoring engine with stale per-signal weights and a 4-tier risk band system that no longer matched the plugin code. Updated to reflect the current engine: 8 signals (Paste Count, Suspicious Burst Insertions, Low Backspace Ratio, Typing Rhythm Entropy, No Thinking Pauses, Sentence Length Uniformity, Vocabulary Diversity, Fast Inter-Keystroke Speed), per-signal weights corrected (0–100-point scale), and risk bands updated to the current 3-tier system — Low 0–9 / Medium 10–49 / High 50–100.

## [1.2.39] — 2026-04-02

### Fixed
- **FIX-EG-ZERO-SCORE-GATE** (`classes/local/service/analyser.php`): The minchars gate (`$effective_charsadded >= $minchars || $pastecount > 0`) blocked all scoring — including linguistic signals — whenever per-question behavioral events were absent. This happens when `qslot > 0` filters the event set to zero rows (tracker events not tagged with the slot, or tracker was not active for the session). Result: every essay question scored exactly 0%, producing identical "Low (0%)" badges. Fix: added `$text_chars = mb_strlen(strip_tags($finaltext))` as a third gate condition. When the PHP observer passes per-question submitted text that is long enough (≥ minchars), linguistic signals (sentence variance, vocab diversity) now fire even without behavioral data, producing distinct non-zero scores per question. Questions with different text compositions now receive meaningfully different risk percentages.

- **FIX-EG-ZERO-SCORE-SQL** (`classes/observer.php`): Both `get_quiz_essay_text()` and `get_quiz_essay_texts_by_slot()` lacked an `ORDER BY` clause. Moodle creates multiple `question_attempt_step` rows per question during a quiz attempt (autosaves, state transitions), each potentially storing an `answer` entry in `question_attempt_step_data`. Without ordering, PHP's hash-map overwrote values non-deterministically — an older partial text from an earlier autosave step could overwrite the final submitted answer, yielding empty or truncated text. An empty `$finaltext` combined with no behavioral events equals a zero score regardless of what the student actually wrote ("not calculating at all"). Fix: both SQL queries now use `ORDER BY qa.slot ASC, qas.id DESC`. The `!isset` guard on the PHP accumulator loop ensures the first row seen per slot (the most recent, highest-ID step due to `DESC` ordering) always wins. Final submitted text is now reliably extracted for every question slot.

## [1.2.32] — 2026-03-28

### Fixed
- **TINYMCE-QSLOT** (`amd/src/tracker.js`): Essay Guard failed to detect the quiz question slot when students typed into TinyMCE iframe editors. Root cause: `tracker.js` detected `qslot` from the textarea `name` attribute (`qXXXXX_answer` format), but TinyMCE replaces the textarea with an iframe — the name-based selector returned no match, leaving `qslot = 0` for all TinyMCE quiz questions. Fix: `tracker.js` now also checks the `essayguardName` data attribute set by the TinyMCE iframe binder, and resolves the quiz question slot from the TinyMCE iframe id for Atto contenteditable divs. No DB schema changes.

## [1.2.30] — 2026-03-27
### Fixed
- **BUG-EG-QUIZ-DUPLICATE-KEY** (`classes/observer.php`): `get_quiz_essay_text()` and `get_quiz_essay_texts_by_slot()` both used a non-unique first column in their `get_records_sql()` queries (`qasd.value` and `qa.slot` respectively). Moodle uses the first SELECT column as the PHP array key — when multiple `question_attempt_step_data` rows had the same value (including literal `'0'` for empty draft steps), Moodle threw `"Duplicate value '0' found in column 'value'"` and aborted the observer, preventing Essay Guard from scoring any quiz submission. Fixed: `qas.id` (unique primary key of `question_attempt_steps`) is now prepended as the first column in both queries. No DB schema changes. version.php → 2026032700301.

## [1.2.22] — 2026-03-20
### Fixed
- **BUG-EG-UPDATE-STATUS** (`lib.php`): Moodle's `plagiarism_update_status()` in `plagiarismlib.php` uses `ReflectionMethod::__construct()` to check whether the plugin class implements `update_status()`. When the method was absent, PHP threw `ReflectionException: Method plagiarism_plugin_essayguard::update_status() does not exist`, crashing the quiz attempts report page entirely. Added a no-op stub — Essay Guard triggers analysis on submission events, not via status polling, so the body is intentionally empty. No DB schema change.

## [1.2.21] — 2026-03-20
### Fixed
- **BUG-EG-DOUBLE-LIMIT** (`lib.php`, `student.php`): MariaDB SQL error `LIMIT 1 LIMIT 0, 1` on every badge render. Root cause: `get_record_sql(..., IGNORE_MULTIPLE)` calls `get_records_sql(..., 0, 1)` internally, which appends `LIMIT 0, 1` to the query string. All three affected queries also had `LIMIT 1` hard-coded in the SQL, producing illegal double-`LIMIT` syntax rejected by MariaDB. Fixed by removing `LIMIT 1` from the raw SQL in `lib.php` (per-question qslot lookup + aggregate qslot=0 fallback) and `student.php`. Also added `IGNORE_MULTIPLE` as the third argument to the `student.php` `get_record_sql()` call which was missing it, ensuring Moodle adds the single-row limit correctly. No DB schema change.

## [1.2.20] — 2026-03-20
### Fixed
- **BUG-EG-MISSING-METHODS** (`lib.php`): After removing `extends plagiarism_plugin` in v1.2.19, Moodle calls on the class instance that were previously satisfied by the base class default implementations now threw `Call to undefined method plagiarism_plugin_essayguard::print_disclosure()`. Added explicit implementations of all three methods Moodle calls on the class instance: `print_disclosure($cmid)` returns `''` (no disclosure required), `save_form_elements($data)` is a no-op (form saving is handled by the `plagiarism_essayguard_coursemodule_edit_post_actions()` standalone callback Moodle invokes via hook), and `get_form_elements_module($mform, $context, $modulename)` returns `false` (form fields injected by the `plagiarism_essayguard_coursemodule_standard_elements()` standalone callback). No DB schema change.

## [1.2.19] — 2026-03-20
### Fixed
- **BUG-EG-INSTALL-FATAL** (`lib.php`) — root cause fix: removed `extends plagiarism_plugin` from `class plagiarism_plugin_essayguard`. All prior fixes (v1.2.16–v1.2.18) attempted to load `plagiarismlib.php` before the class declaration. The actual root cause: `plagiarismlib.php` itself requires grade/DB files that are not available during Moodle's very early bootstrap scan (`setup.php` → `get_plugins_with_function` → `core_component::get_plugin_list_with_file`). The file is found and begins executing, but hits an unmet dependency and never reaches the `abstract class plagiarism_plugin` declaration — leaving the class undefined despite the `require_once` succeeding. Moodle's dispatcher (`plagiarism_get_links`) only calls `new plagiarism_plugin_{component}()->get_links()` and never checks `instanceof plagiarism_plugin`, so the `extends` is pure convention, not a runtime requirement. Removing it eliminates the dependency entirely. No DB schema change.

## [1.2.18] — 2026-03-20
### Fixed
- **BUG-EG-INSTALL-FATAL** (`lib.php`) — superseded by v1.2.19: `__DIR__`-based path found the file correctly but `plagiarismlib.php` still failed internally due to unmet bootstrap dependencies. The plugin lives at `{moodle_root}/plagiarism/essayguard/lib.php`, so two `dirname()` calls reach the Moodle root at the filesystem level, independent of whether `$CFG` is populated at that point in the bootstrap. This removes all `$CFG` timing sensitivity and works on any server layout. No DB schema change.

## [1.2.17] — 2026-03-20
### Fixed
- **BUG-EG-INSTALL-FATAL** (`lib.php`) — second attempt (superseded by v1.2.18): `$CFG->libdir` approach was still unreliable on some server configurations. The v1.2.16 fix used `class_exists('plagiarism_plugin')` as a guard before `require_once` — this returned `true` because Moodle's PSR-4 autoloader had the class *registered* but not actually *loaded*. PHP then threw when resolving the `extends` clause. Fix: unconditional `require_once($CFG->libdir . '/plagiarismlib.php')` at the very top of `lib.php` immediately after the `MOODLE_INTERNAL` guard — the same pattern used by Turnitin and PlagScan. `require_once` is inherently idempotent; no `class_exists` guard is needed or correct. No DB schema change.

## [1.2.16] — 2026-03-20
### Fixed
- **BUG-EG-INSTALL-FATAL** (`lib.php`) — incomplete, superseded by v1.2.17: `class_exists` guard approach failed due to PSR-4 autoloader false positive.

## [1.2.15] — 2026-03-20
### Changed
- Routine version bump. No DB schema change. No logic change. version.php → 2026032001144.

## v1.2.11 — 20 Mar 2026

### Fixed
- **BUG-EG-FLUSH-RACE** (`tracker.js` `flush()`): `Ajax.call()` returns an array
  of Promises. The previous code did `await Ajax.call([...])` which awaits the plain
  array (non-thenable) and resolves immediately — meaning `flush()` returned before
  telemetry was actually delivered to the server. This caused the
  `flush().then(() => finalizeAttempt(text))` chain to invoke `finalizeAttempt`
  before events were stored, scoring against an incomplete event set and producing a
  lower-than-correct final risk score at submission time. The catch block was also
  dead code — AJAX errors were never propagated, so `console.warn` never fired.
  Fixed: `await Ajax.call([...])[0]`.
- **BUG-EG-PRIVACY-USERLIST** (`privacy/provider.php`): `delete_data_for_users()`
  was declared with an `approved_contextlist` type-hint (wrong interface type) and
  the class did not declare `core_userlist_provider`. This caused a PHP `TypeError`
  if Moodle's GDPR privacy framework invoked the method for bulk per-context user
  deletion. Fixed: declared `core_userlist_provider`, added `get_users_in_context()`,
  and corrected `delete_data_for_users(approved_userlist $userlist)` with proper
  per-user bulk deletion using `$DB->get_in_or_equal()`.
- **BUG-EG-STUDENT-LIMIT** (`student.php`): The student detail page SQL fetched all
  rows for a `userid + cmid` pair ordered by `timemodified DESC` and discarded extras
  in PHP via `IGNORE_MULTIPLE`. Without `LIMIT 1`, the database scanned and returned
  all historical score rows before PHP discarded them. Fixed: added `LIMIT 1` to
  match the identical fix applied to `lib.php get_links()` in v1.2.10.

## v1.2.10 — 19 Mar 2026

### Fixed
- **FIX-RISK-THRESHOLDS**: Risk level thresholds corrected to match specification.
  Required bands: Low < 0.35 · Medium 0.35–0.69 · High ≥ 0.70.
  Previous code used Low < 0.25 · Mild 0.25–0.49 · Medium 0.50–0.69 · High ≥ 0.70.
  The "Mild" category does not exist in the product specification. As a result,
  students scoring 25–34% were incorrectly labelled "Mild" instead of "Low", and
  students scoring 35–49% were incorrectly labelled "Mild" instead of "Medium".
  Testing protocol expecting a green "Low" badge for genuine writing was seeing no
  match because the badge label did not equal "Low". Fix: `analyser.php::risk_level()`
  thresholds updated; "Mild" level removed from lang strings, CSS, and class docblock.
- **PERF-GET-LINKS**: `get_links()` DB query now includes `LIMIT 1` so only the most
  recent score row is fetched instead of loading all rows for a student+cm pair into
  memory before returning the first.

## v1.2.9 — 18 Mar 2026

### Fixed
- **BUG-BADGE-NAV**: Risk badge injected into DOM was immediately lost when form.requestSubmit() navigated the page away. Fix: injectRiskBadge() now saves badge data to sessionStorage before navigation; new restorePendingBadge() function called in init() reads sessionStorage on the next page load and displays a fixed-position toast badge (12 s auto-dismiss).
- **BUG-BADGE-REFACTOR**: Extracted buildBadgeEl() as shared helper function used by both injectRiskBadge() and restorePendingBadge() to ensure consistent badge appearance.


## v1.2.4 — 16 Mar 2026

### Fixed
- CRITICAL: Plugin permanently stuck showing "upgrade required" in Moodle™ admin after every
  page visit. Root cause: `upgrade.php` was missing savepoints for all versions released after
  v1.2.0 (that is, v1.2.1, v1.2.2, and v1.2.3). Moodle™'s upgrade engine calls
  `xmldb_upgrade()` but when no savepoint block fires, the DB version is never updated to the
  installed version. On the next admin page visit Moodle™ detects a version mismatch again,
  calls `xmldb_upgrade()` again, still no savepoints fire — the plugin is stuck in an infinite
  upgrade loop. Fixed by adding the missing savepoint for v1.2.3 (`2026031200133`) and the new
  v1.2.4 savepoint (`2026031600134`). No DB schema changes required.

## v1.2.3 — 12 Mar 2026

### Fixed
- FIX: `\plagiarism_essayguard_check_unlock()` called with backslash namespace prefix inside
  `observer::is_active()` to resolve the global function correctly. Without the backslash,
  PHP resolves unqualified function calls within the `plagiarism_essayguard` namespace first,
  causing a fatal "undefined function" error on every assignment/quiz submission.

## v1.2.2 — 12 Mar 2026

### Fixed
- FIX: `savedconfigsuccess` lang string added — `settings.php` was referencing the Moodle
  core plagiarism component (`plagiarism`) which has no such string, causing a warning on
  every settings save. String is now defined in the plugin's own lang file.
- FIX: Stable attempt key for multi-page quizzes — `microtime()` removed from key generation;
  quiz attempt ID is now included so every question page within the same quiz attempt uses one
  consistent key. Telemetry events from earlier pages were previously invisible to the observer.

## v1.2.1 — 12 Mar 2026

### Bug Fixes

- **savedconfigsuccess lang string added.** `settings.php` was calling
  `get_string('savedconfigsuccess', 'plagiarism')` — the core `plagiarism`
  component has no such string, causing a warning/exception on every settings
  save. The string is now defined in the plugin's own lang file and the
  component reference corrected to `'plagiarism_essayguard'`.

- **Stable attempt key for multi-page quizzes.** `inject_tracker()` was
  appending `microtime(true)` to the key seed, so every question page within
  a single quiz attempt received a different `attemptkey`. Telemetry events
  from earlier pages were invisible to the observer (which only scored events
  matching the final page's key). `microtime()` is removed. For quiz pages the
  quiz attempt ID (URL param `?attempt=N`) is included instead, so all pages
  within the same quiz attempt share one stable key.

- **Observer race condition eliminated.** The PHP event observer previously
  retrieved the `attemptkey` by querying the telemetry events table. When the
  student submitted, the observer ran in the same HTTP request as the form
  submission — the AJAX event-flush from the browser was still in-flight and
  events had not yet reached the database. The observer found no key, returned
  early, and no score was written. `inject_tracker()` now calls
  `set_user_preference('essayguard_ak_{cmid}', $attemptkey)` on every page
  load before the tracker JS initialises. The observer reads this preference
  via `get_user_preferences()`, guaranteeing it always has the key regardless
  of AJAX timing. The events-table lookup is kept as a fallback for sessions
  that started before this version.

- **Quiz summary-page flush.** The `interceptSubmitForms()` function in
  `tracker.js` was skipping the intercept when `collectFieldText()` returned
  an empty string — which always happens on the "Submit all and finish"
  summary page (no bound text fields visible). The early exit on empty text
  is removed. Events are now always flushed before any matched form submits,
  including the summary-page confirmation form.

- **Assignment form selector corrected.** The selector
  `form[action*="mod/assign"][action*="submission"]` required the word
  "submission" to appear in the form's action URL. Moodle's assignment form
  action is `/mod/assign/view.php`, which never contains "submission", so
  assignment submissions were never intercepted. Changed to
  `form[action*="/mod/assign"]`.

---

## v1.2.0 — 7 Mar 2026

10-fix full audit release. See pluginConfig.ts for full notes.

---

## v1.1.2 — Prior

Badges initial fix, class report, paste warning banners, Moodle 4.3+ hook
support, per-activity toggle, cleanup task, settings page rewrite.
