# Changelog

## 1.4.0 - 2026-10-05

**Building the claims, instead of deleting them**

Version: `2026100700`. Schema change: new table `plagiarism_essayguard_fpm`.

v1.3.0 established that six claims in the README described a product the code did not
implement. Rather than striking them out, this release builds four of them, reframes one
honestly, and makes the sixth structurally impossible to get wrong again.

### The comparative baseline is now real

This was the headline claim — "compared against the student's own historical baseline" —
and the mechanism could not support it. The old baseline stored one point estimate per
metric and compared it against a **fixed plus-or-minus 50 percent tolerance, identical for
every student and every measurement**. Without a per-student spread there is no way to
tell a genuine anomaly from a student whose typing speed simply moves that much week to
week. An EWMA with a half-life of 2.4 samples was labelled "stable" at five.

The new `plagiarism_essayguard_fpm` table keeps Welford's running mean and M2 per
(student, activity type, metric). Deviation is a z-score in that student's own standard
deviations, for that measurement, in that kind of activity.

Four properties, each covered by a test that states it as a requirement rather than
recording current output:

- **A student with no history is never compared.** Nothing is reported until at least
  three measurements have eight submissions behind them.
- **A typical submission from an established student scores zero.**
- **Consistency is not punished.** The spread is floored at 5 % of the mean, so a
  metronomic writer cannot have trivial changes read as enormous deviations — otherwise
  the most regular students would be the most suspected.
- **Quiz history never answers for an assignment.** The distributions are different and
  pooling them inflates the variance until nothing can deviate from it.

Explanations now come from the same z-scores the score came from, so a sentence appears
only for a measurement that actually contributed, and it quotes the numbers: what this
submission measured, what the student usually measures, their spread, and how many
submissions are behind it. The student page shows that table directly, which is what an
appeal will ask to see.

### Two advertised signals now exist

**Transition density** (Signal 14) counts discourse markers per 100 words — however,
furthermore, in addition, consequently. Generated prose signposts its structure far more
heavily than most student writing. Measured on a deliberately marker-heavy generated
sample: 16.7 per 100 words; on unstructured human writing: 0.0.

**Paragraph uniformity** (Signal 15) is sentence variance one level up. Generated prose
arrives in evenly sized blocks; a written draft has a short opener, a long middle and an
uneven tail. Requires three paragraphs — variance across two is not a measurement, and the
single-sentence tautology this engine used to commit is not repeated.

Both are **writing-style** signals, and they are capped. Signals 8, 9, 14 and 15 together
could reach 35 points, past the Medium threshold; they are now bounded at 20 between them.
The innocent explanations for uniform, heavily signposted prose — a second-language
writer, a formal house style, a templated answer the training package asks for — are at
least as common as the guilty one, so style can corroborate a behavioural finding and
never produce one alone.

### "Manually retyped" is reframed, not built

The claim was that Essay Guard detects AI text that was retyped. Nothing could do that,
and nothing can: if a person types the words, they were typed, and no keystroke signal
distinguishes retyped AI text from a student retyping their own handwritten notes.

What is observable is whether typing looks like **composition** or **transcription**, and
Signal 16 now measures it — using focus and blur events that have been captured since the
first release and were read by nothing. It fires on repeated window switching, with almost
no revision, at a steady rhythm. The teacher-facing text says plainly that this indicates
copying from something on screen and **does not indicate what the source was**.

### One definition of what the plugin measures

`classes/local/signals.php` is now the single registry. Before it, the signal set was
described in four places that had drifted: the scoring branches, a hardcoded "Max" column
in the student page that ignored the paste-weight setting (printing 60 on a site
configured to award at most 15), the language strings, and a README table listing nine
signals with the wrong weights while the engine scored thirteen. A teacher cross-checking
the breakdown against the vendor's own documentation found different numbers.

Every surface now reads from the registry, including the README table, and each signal
carries its evidence class — observed, behavioural, writing style, or comparative.

### README

The claims section is rewritten to describe what the code does, and an **Honest limits**
section added: the score is not calibrated and has no published false-positive rate; a
determined student can defeat the telemetry; assistive technology can resemble the
patterns measured; and an attempt with no telemetry is reported as not assessed rather
than as low risk. Price corrected to $5 USD / 50 credits.

### Behaviour changes

1. Comparative points no longer apply until a student has eight submissions in that
   activity type with at least three comparable measurements. Expect fewer baseline
   points, and the ones that remain to be defensible.
2. Existing fingerprint rows are not migrated — the old point estimates cannot yield a
   variance. Statistics accumulate from the next submission on; comparative scoring
   resumes once the thresholds are met.
3. Three new signals can fire, bounded as described above.
4. The "Max" column in the breakdown now reflects the site's paste-weight setting.

## 1.3.0 - 2026-10-05

**Full audit: security, privacy, detection, defensibility**

Version: `2026100600`. Schema change: three indexes added to the telemetry table.

This release is the result of a line-by-line audit of the whole plugin across five
areas. It fixes a complete bypass of the product's core function, stops keystroke
capture that was happening with no disclosure and no master-switch check, removes
several ways an honest student could be reported as a cheat, and takes out the
detection claims the engine could not support.

Read the "Behaviour changes" section before upgrading a live site. Some scores will
move, and some badges that were green will become "Not assessed".

### Security

**A student could erase their own risk evidence.** Both web services validated the
attempt key negatively: a key matching `qa_<digits>` had to resolve to an attempt owned
by the caller, and **anything else was accepted unconditionally**. A student scored HIGH
could call `finalize_attempt` with `attemptkey` `"clean1"` and a plausible essay; that
inserted a fresh score row, which `scope_to_current_attempt()` then elected as "the
current attempt", hiding the real evidence from the badge, the class report and the
student page. Repeatable after every teacher visit. Attempt keys are now validated
positively against the only two forms a user can legitimately own, and the quiz attempt
check verifies the activity as well as the owner.

**`finalize_attempt` had no size limit and no throttle**, while its cheaper sibling was
carefully capped. Submitted text is capped at 50,000 characters and calls are throttled
to one per activity per 10 seconds.

**Licence calls did not verify TLS.** Moodle's curl wrapper resets
`CURLOPT_SSL_VERIFYPEER` to 0, and `VERIFYHOST` without `VERIFYPEER` verifies nothing,
so an attacker on the path could present any certificate and harvest the site's API key
from the query string. Peer verification is now explicit on all three calls and
redirects are refused.

**`student.php` disclosed exam questions and verbatim answers** to anyone holding only
`plagiarism/essayguard:viewreport` — a read capability `db/access.php` explicitly
anticipates granting to tutors and external examiners — with no quiz capability check.
A core quiz capability is now required for that block.

**The footer badge count ignored separate groups**, the one surface the v1.2.219-224
group sweep missed.

### Privacy

**Moodle's `$CFG->enableplagiarism` master switch was ignored by the entire capture
path.** On a site where the administrator had never switched the plagiarism subsystem on
— which is the default — the tracker was injected and every keystroke recorded, while
core suppressed both the output and the student disclosure. Full collection, no output,
no notice. The plugin's own install message said "Essay Guard will not run until
Moodle's plagiarism subsystem is switched on", which was not true. Now enforced in
`inject_tracker()` and in all three web services.

**Capture was not limited to supported activity types.** `inject_tracker()` runs from a
head hook on every page of the site and its only module gate was "is there a course
module". Combined with the tracker binding any textarea outside a `.que` container,
keystrokes were recorded in wikis, glossaries, databases, lesson pages and comment
boxes. Now gated on `supports_mod()`.

**Quizzes never showed the student disclosure.** Core calls
`plagiarism_print_disclosure()` from the assign and forum forms only; mod_quiz has no
such integration, and the quiz attempt page is where this plugin does nearly all of its
capturing. The plugin now renders the notice itself on quiz attempt pages.

**The telemetry table was a transcript of everything the student typed.** Each keydown
stored `e.key` — the literal character — so `payloadjson` could be replayed to recover
the text, including text the student typed and then deleted. Nothing in the analyser
ever read it: every signal uses timings, counts and lengths. It is no longer recorded.

**The subject-access export withheld the events.** `get_metadata()` declared
`payloadjson` and the export returned four summary numbers, which is a GDPR Art. 15(1)
failure on the one record a student exercising their rights would be asking about. The
events are now exported.

**The behavioural profile was kept forever.** `plagiarism_essayguard_fp` is a
per-student writing profile used to judge whether a later submission looks like the same
person. Raw events were pruned; this was not, not even on course-module deletion, and
the disclosure did not mention it existed. Profiles now expire with the scores they
describe, and the disclosure says so.

The disclosure also now covers assistive technology, and tells students they can ask
what was recorded and disagree with the result.

### Detection: false positives

**Dictation, screen readers, predictive text and Ctrl+Z were scored as pasting.** The
tracker emitted `large_insert` for any insertion over 20 characters regardless of cause,
and the analyser treats that as equivalent to a clipboard paste — full Signal 1 plus the
paste-session bonuses. Measured on the old engine: speech-to-text scored **100 / HIGH**;
one undo restoring a deleted paragraph scored **38 / MEDIUM**. A screen-reader user
emits no keydown events at all, so they were classified as a pure paste session by
construction. The browser reports the cause in `inputType` and the tracker was capturing
it and throwing it away. Undo, redo, composition, dictation and autocorrect no longer
produce a paste signal, on both the client and the server.

**The false-positive cap was withheld from the typists it was written to protect.** It
only applied when inter-key standard deviation was 100 ms or more; a fluent touch typist
sits around 45 ms, and the plugin's own honest-typist fixture measures 44.1 ms. The more
even your typing, the less protected you were. Rhythm is now judged by the Shannon
measure, which actually separates humans from machines (below).

**One paste was counted six times.** Signals 3-7 are five more descriptions of the same
paste — of course there were no corrections, no pauses and no rhythm; nobody typed. The
code said so in a comment and added them all anyway, producing a pre-cap total of 160
for both a 120-character paste and an 8,000-word pasted essay, both displayed as 100/100
with 60 points of invisible headroom. Their combined contribution is now capped, so the
total stays inside the scale it is printed on.

**A paste of unknown size was scored as maximum.** `insertlen` is 0 whenever the browser
refuses the clipboard read, which is ordinary inside a cross-origin TinyMCE iframe — the
most common editor configuration, not an edge case. It awarded the full 60 and called it
conservative. It is now the MEDIUM floor.

**A one-sentence answer was treated as maximally suspicious.** Variance is undefined on a
single data point; the old code converted *undefined* into the full uniformity penalty
and told the teacher "sentence lengths are unusually uniform throughout the submission".
Three sentences are now required before uniformity means anything.

**`thinking_pause_score`** counted pauses of 800-2000 ms and then gated the result on a
counter of pauses over 2000 ms, forcing it to zero in exactly the case it describes.

### Detection: the engine had no working automation detector

Signal 7's Shannon entropy normalised against the number of buckets the sample happened
to occupy, which makes the result approach 1.0 for any near-uniform distribution however
narrow. Measured over 200 samples, the old formula gave 0.972 for a human at 140±50 ms
and **0.986 for a bot at 100 ms ±3 ms** — the bot scored higher, and the 0.35 threshold
was unreachable by anything. The SD-based branch below it was dead code for any session
with rhythm data. Meanwhile teachers were being shown "Robotic keystroke entropy".

Normalising against a fixed reference range fixes it. Measured after the change:

| Profile | Shannon |
|---|---|
| Very fast consistent typist (110±25 ms) | 0.424 |
| Fast typist (140±45 ms) | 0.583 |
| Average typist (200±80 ms) | 0.702 |
| Careful writer (350±200 ms) | 0.868 |
| Scripted input, 5 ms jitter | 0.177 |
| Scripted input, 15 ms jitter | 0.291 |
| Scripted input, no jitter | 0.000 |

The existing 0.35 threshold now separates the two populations with the most consistent
human profile still clearing it by a wide margin.

### Defensibility

**An attempt where nothing was captured was reported as a finding.** The `unmeasured`
guard required `empty($signal_pts)`, but Signal 13 and the linguistic fallback both fire
*specifically* when no events were captured — so they populated the array and suppressed
the honesty flag. A quiz where the tracker never loaded, and where the student types at
60-120 wpm, scored MEDIUM or HIGH. "We did not observe this student" is not evidence of
anything: when no behavioural event is captured, nothing is now reported.

**The explainer had no `unmeasured` branch**, so such an attempt stored "This submission
was flagged by the combined behavioural and linguistic checks" — the plugin asserting
that a submission was flagged by checks that never ran, written into the record and
rendered to teachers under the heading "Indicators".

**Baseline deviation was awarded with no baseline.** `deviation_score()` had no
sample-count gate, so up to 15 points were added from the student's second submission
onward — and after the false-positive cap, which made it the main route by which a
capped honest typist became MEDIUM. The student page printed "Baseline Confidence: No
baseline yet" and "deviates significantly from this student's baseline" on the same
screen. A stable baseline is now required.

**The published bands did not match the code.** The Interpretation Guide said HIGH
65-100 and MEDIUM 30-64; the engine uses 66 and 30-65. A score of exactly 65 was MEDIUM
in the engine and HIGH in the document the plugin prints as its own key.

**The student detail page still showed a green LOW for unmeasured attempts** — the one
surface the v1.2.227/234 work never reached, and the page that serves as the evidence
document. **The class report showed "NO DATA" and "LOW" in adjacent columns of the same
row**, the fix defeated by a stale variable one line above it. **The grading badge said
"Essay Guard is analysing this submission, reload in a moment"** about a final record,
while `render_badge()`'s purpose-built `unmeasured` state sat unused.

**Two of the six stat cards on the class report were hardcoded zeros.** "Pending: 0" and
"Errors: 0" rendered regardless of the data, so an activity where the tracker failed for
a third of the cohort produced a clean, confident, entirely green report. Replaced with
a real "Not assessed" count — the number an auditor asking "how do you know you checked
everyone?" actually needs.

**The JavaScript badge failed green.** An unrecognised risk level rendered a green badge
labelled "Original" — reassuring, and borrowing Turnitin's word for "this is the
student's own work", on a value the code did not understand. `render_badge()` in PHP
deliberately does the opposite.

**Indefensible strings rewritten.** "Superhuman typing speed" fired at 96 wpm and
appeared as the bare value of the Primary Signal column beside a named student.
"Robotic keystroke entropy" asserted automation and was also mislabelled (Signal 12 is
the keystroke ratio, not entropy). "No thinking pauses detected — unusual for original
composition" stated the accusation as a measurement. "Zero corrections recorded" was
only reachable when no keystrokes were captured, where it means "we recorded nothing",
and it was printed in a column headed Evidence. Also "paste suspected", "implausibly
constant", "consistent with programmatic or auto-generated text input", and
"Writing behaviour appears consistent with normal student patterns", which overclaims in
the exonerating direction and would be quoted straight back by the next student whose
pasted answer scored LOW.

### Wrong badges

**The call-order slot fallback is removed.** It assigned a question slot by counting
`get_links()` calls — the Nth call was taken to be the Nth question. An integrity
judgement assigned by coincidence, resting on an assumption about core's render order
that is not part of any API contract, with nothing detecting when it broke. One extra
call on the page shifted every question by one. Patched four times in fifteen releases
without being removed. When the slot cannot be determined, no badge is shown.

**`get_badges.php` was running a fourth, contradictory selection rule** — the
higher-of-two rule that v1.2.93 identified as wrong and removed from `lib.php` — with no
attempt scoping, on the quiz grading overview, the page teachers use most. It now uses
the shared rule and the shared overall score.

**The tracker invented question slots.** When tagging failed for every field it assigned
sequential numbers by position and called finalize once per invented slot, so the server
wrote per-question rows for slots 1..N while every event carried slot 0 — rows scored
from no evidence, which is exactly the "Q2: LOW 0/100, 0 keystrokes, Typing 0 s" record
a teacher reads as clean. The numbers were positional on the current page, so on a page
showing questions 3 and 4 it wrote rows for slots 1 and 2. Removed.

### Tracker

- **Infinite recursion on focus/blur in every TinyMCE editor.** The relay listeners were
  registered on the document in the capture phase with the body as their target, and
  capture-phase listeners fire for non-bubbling events — so the handler re-triggered
  itself until the stack blew, and on unwind every level enqueued an event, overrunning
  the queue and discarding real keystrokes. Re-entry guard added.
- **"Events are guaranteed in the database before scoring" was false.** A single
  `flush()` sends at most 500 events and resolves, and returns an older in-flight
  promise if one exists. The submit path now drains the queue.
- **The unload beacon sent the newest events and dropped the oldest** — the start of the
  answer, which is what the signals need. Now sends the oldest undelivered slice.
- **Any throw after `preventDefault()` permanently blocked submission** — the student
  could never submit, forever, because the intercepted flag was already set. Wrapped.
- **`field.value || field.textContent`** treated an empty value as falsy and fell back to
  the server-rendered original, so a student who cleared their answer had the old text
  scored.
- **CJK input produced almost no keystrokes**, because composing keystrokes report key
  "Process" and were skipped — the exact signature the engine reads as pasting.
- Selection tracking never fired in rich-text editors (wrong window). Stale
  `boundFrames` entries on editor re-init collected the same text twice. Timers never
  restarted after a bfcache restore. Submit buttons added after interception were never
  bound. Word counting re-scanned the whole answer on every keystroke.

### Performance

- Three indexes on the telemetry table: `timecreated` (the nightly cleanup scanned the
  whole table twice without it), a covering index for the scoring lookup, and
  `contextid` (every GDPR erasure was a full scan of the largest table in the plugin).
- The cleanup task deleted every expired row in one unbounded statement. At roughly two
  rows per keystroke, one 500-student three-essay sitting is of the order of seven
  million rows. Now batched with a time budget, so a slow night prunes less instead of
  failing every night while the table grows.
- Scoring read the full event set once per slot — four complete passes for a three-essay
  quiz, synchronously, inside the student's submit request. Now read once per request.
- The class report selected two TEXT blobs per row for every student with no pagination.
  Explicit column list.

### Behaviour changes to expect

1. Attempts with no captured telemetry report **NOT ASSESSED** instead of a score. Some
   previously-green badges will change. That is the point: they were never checked.
2. Paste scores come down somewhat (derived signals capped; unknown-size pastes at the
   MEDIUM floor). A genuine paste still reaches HIGH.
3. Honest fast typists come down sharply. The old engine put a fluent typist with no
   corrections at MEDIUM and the test suite asserted that as correct.
4. Baseline points no longer apply until a student has five submissions.
5. Badges disappear from pages where the slot cannot be determined, rather than showing
   a guessed one. Use the class report for those.
6. Keystroke characters are no longer recorded. Existing rows still contain them; run
   the cleanup task to expire them, or clear the table if you want them gone now.
7. **On a site where Moodle's plagiarism subsystem was never switched on, the plugin now
   stops collecting entirely.** If capture silently stops after this upgrade, that is
   why: switch on Site administration > Advanced features > Enable plagiarism plugins.

### Still outstanding

The scoring scale has never been calibrated against labelled real submissions, so there
is no published false-positive rate. The strings and the bands are now honest about what
a signal is, but the only thing the engine measures with confidence is bulk insertion.
Evasion remains straightforward for a determined student: retyping AI text is
indistinguishable from writing, and the telemetry is client-supplied and can be forged by
anyone who opens dev tools. Treat the output as corroborating evidence, never as proof.

## 1.2.234 - 2026-10-05

**Three display surfaces, one set of numbers**

Version: `2026100500`. No schema change. Fixes two reproducible wrong-badge reports
from live use and the misleading "0 seconds" typing figure.

### Fixed - a question badged HIGH on the attempt page and LOW in the report

Four separate causes, all in the display layer. The stored scores were correct
throughout; every one of these was a reading error.

1. **No page filtered score records by attempt.** `lib.php`, `report.php` and
   `student.php` each kept "the newest row per (userid, qslot)" across every
   attempt the student had ever made. Q1 from attempt 2 could be shown beside Q2
   from attempt 1, and a rescore of an old attempt bumped its `timemodified` and
   made it win. All three now scope to one attempt through
   `plagiarism_essayguard_scope_to_current_attempt()`.

2. **The ordering was not deterministic.** Per-question rows are written in the
   same second by the observer loop, and the queries ordered on `timemodified`
   alone. With equal timestamps the database may return tied rows in any order,
   and the three queries were worded differently - so two pages could legitimately
   select different rows from identical data. Ordering is now
   `timemodified DESC, id DESC` everywhere.

3. **The content-based slot matcher preferred an unclaimed slot over a better
   match.** Where Q2's text matched slot 2 at 95 % and slot 1 at 62 %, and slot 2
   had already been claimed earlier in the page render, it returned slot 1 - and
   the badge showed Q1's score under Q2. Match quality now decides; the claim only
   breaks ties between candidates within five points of each other.

4. **lib.php and report.php each carried their own copy of the per-question
   fallback rule.** The copies were written eight versions apart and had drifted.
   There is now one implementation,
   `plagiarism_essayguard_resolve_question_record()`, and both call it.

### Fixed - a two-question quiz with one HIGH question showed three different overalls

"Overall" was defined three times. The grading badge used the attempt-level
record, which `FIX-EG-AGG-PERQ-CONSISTENCY` may have silently raised to the worst
per-question score; the class report averaged the per-question scores; the student
page used the raw `qslot=0` row. On a quiz scoring 100 and 0 those rules give HIGH,
MEDIUM and HIGH from the same data. `plagiarism_essayguard_overall_score()` is now
the only definition and all three pages call it. The rule is the highest
per-question score, stated in one place in `lib.php` and changeable to the mean on
one line.

### Fixed - an elevated aggregate could rescue an unrelated question

When a question captured no events of its own, the attempt-level record could be
substituted for it if that record looked like paste evidence. But an aggregate
elevated to match the worst question is not independent evidence - so Q1's HIGH
was copied onto Q2, which is one half of the wrong-badge report above. Records
carrying `agg_elevated_from_perq` are no longer accepted as a fallback.

### Fixed - "Typing: 0 s" on a question that was typed

Zero typing time does not mean the student typed for zero seconds. It means no
events were ever attributed to that slot, so the score came from text analysis
alone. The per-question card now reads "Typing: not captured" and carries a notice
saying the score is weak evidence. Scores also record `event_count`, so a slot that
captured nothing is distinguishable from a fast, clean typist.

### Fixed - "Avg WPM: 17,818.5"

When no WPM snapshots exist, average WPM was estimated as final-text word count
divided by measured typing time. On a pasted answer the text arrives whole while
typing time is a fraction of a second, so the division produced figures like
17,818 wpm on a teacher-facing report. The fallback now requires at least five
keystrokes and five seconds of typing time, and rejects any result above 300 wpm.

### Fixed - a question that was never assessed was badged LOW

A slot whose events were never attributed to it scores 0 and rendered as a green
LOW - the same badge an honest typist earns. Live example: Q1 HIGH 100/100 with
two pastes, Q2 LOW 0/100 with zero keystrokes, zero pastes and zero typing time.
Q2 was not checked; the report said it was fine. Such records now render as
NO DATA on the student page and the class report, and as a pending badge in the
grading screen, with a notice saying the question is unchecked.

### Known - the slot-tagging failure behind the missing events

These fixes stop the wrong number being displayed. They do not fix the underlying
cause of empty per-question slots, which is `tracker.js` failing to tag events with
a question slot in some editor configurations. That is the next thing to chase,
and the new `event_count` metric is how to find the affected attempts.

## 1.2.233 - 2026-09-07

**Moodle 4.4 restored as the supported floor**

Version: `2026090703`. One-line change to `version.php`, plus the reasoning behind it.

### Fixed - the plugin could not be installed on Moodle 4.4

`$plugin->requires` was `2024100700` (Moodle 4.5 LTS) and `$plugin->supported` was
`[405, 502]`. Moodle's dependency check reports the `supported` range as a hard requirement,
so a 4.4 site refused the upgrade outright - "Moodle 405 - 502 Fails" - and said only that the
requirements must be solved first.

The 4.5 floor arrived on 29 August in 1.0.85 / 1.2.225 and was a **policy** choice, not a
technical one. That release's own comment says "The real floor is Moodle 4.4"; 4.5 was chosen
because it is the lowest branch still receiving security fixes, and because it is where core
deleted `plagiarism_update_status()`, which would have made this plugin's legacy compatibility
scaffolding dead code. Neither is a code requirement, and that scaffolding was never actually
removed - so nothing in the plugin needs 4.5.

The cost of the choice was invisible until an install was attempted: every build since 29 August
has been un-installable on 4.4, and Moodle gives no hint that a declared floor is a preference
rather than a constraint.

Now `requires = 2024042200` (Moodle 4.4) and `supported = [404, 502]`. Verified before
lowering: all three output hooks registered in `db/hooks.php` exist in 4.4; the legacy
`update_status()` scaffolding and the standalone plugin class are both still present; and 4.4's
minimum PHP is 8.1, so the arrow functions that forced the floor up off Moodle 4.0 remain safe.

Moodle 4.4 is nonetheless out of general support. This change unblocks the upgrade; it is not an
endorsement of staying on 4.4.

## 1.2.232 - 2026-09-07

**Second release-pipeline pass**

Released as 1.2.232 rather than 1.2.231. The 1.2.231 tag had already been pushed to
`lmshostingservices/moodle-plagiarism_essayguard` against the build made before the README
correction below, and release tags are immutable - they cannot be repointed at new bytes.
The content of this release is the pipeline-conformance work described here plus that
correction; 1.2.231 should be treated as superseded.

Version: `2026090701`. No functional change. 1.2.230 cleared six of the nine pipeline items;
this release clears the rest. Every code edit was verified token-identical to 1.2.230 with
`token_get_all()`, and the 381 language-string values were compared by evaluating `$string`
before and after - same hash both times.

### Fixed - the PARAM_RAW blocker was a comment, not a parameter

The three parameter declarations annotated in 1.2.230 passed. What still tripped the scanner
was a docblock in `log_event.php` that happened to contain the literal token `PARAM_RAW` while
explaining why `MAX_PAYLOAD_BYTES` exists. Reworded to describe the field without naming the
constant.

### Fixed - the language file still concatenated

1.2.230 joined each assignment onto one line but left the `.` operators in place, and AMOS
does not accept concatenation in any form. All 67 are now single string literals. The one help
string that carried paragraph breaks is a double-quoted literal with `\n` escapes rather than
a concatenated `"\n\n"`, so it stays on one line; it contains no `$`, so nothing interpolates.

### Fixed - the README stated a version and a Moodle range that were both wrong

It claimed version 1.2.219 (twelve releases behind) and Moodle 4.0 - 5.1, while `version.php`
declares `requires = 2024100700` and `supported = [405, 502]` - that is Moodle 4.5 LTS to 5.2.
A site on 4.0 would have followed the README straight into the PHP 7.3 parse error that
1.2.226 exists to prevent. The version line is now a pointer to `version.php` and the changelog
rather than a fourth hand-maintained copy of the release string.

### Changed - the risk badge suffix is no longer stored upper-case

`$string['riskword']` was the literal `RISK`. It is now `Risk`, upper-cased at the point of
display with `core_text::strtoupper()`, so the rendered badge is unchanged.

### Changed - remaining coding-style warnings

82 further multi-line calls now put the opening parenthesis last on its line, across the
plugin and its test suite. Two comment blocks were reworded: one in `analyser_test.php` that
opened with a method name, and one in `lib.php` whose prose contained a literal doc-block
opener that read to the scanner as a lower-case docblock.

## 1.2.230 - 2026-09-07

**Release-pipeline conformance**

Version: `2026090700`. No functional change. 1.2.229 was rejected by the LMS-Labs release
pipeline on one blocker and two errors; this release clears those and the four warnings
alongside them. Every code edit below was verified token-identical to 1.2.229 with
`token_get_all()`, so the plugin's behaviour is unchanged.

### Fixed - PARAM_RAW usages were unannotated (approval blocker)

Three declarations use `PARAM_RAW` and each has a reason the pipeline could not see, so each
now carries a `// pipeline-ignore: PARAM_RAW` note stating it. `payloadjson` in `log_event` is
a JSON blob, capped at `MAX_PAYLOAD_BYTES` and `json_decode()`d before use. `metricsjson` in
`finalize_attempt` is a return value the server itself `json_encode()`s. `finaltext` is the
student's verbatim essay: sanitising it would corrupt the very measurements it exists to feed,
and it reaches only the numeric analysers - it is never stored verbatim by that path and never
rendered.

### Fixed - thirdpartylibs.xml was missing

Required even when a plugin bundles nothing. Essay Guard bundles no third-party libraries, so
the file now declares that explicitly rather than leaving it to be inferred from absence.

### Fixed - multi-line string concatenation in the language file

67 `$string[...]` assignments were split across continuation lines. Each is now a single-line
assignment. The 381 resulting string values were compared before and after and hash identical.

### Changed - three metric labels no longer lead with an acronym

`IKI autocorrelation`, `IKI samples` and `WPM std dev` become `Keystroke-interval
autocorrelation`, `Keystroke-interval samples` and `Words-per-minute std dev`, with the
matching signal name updated to agree. These are the labels a teacher reads on the report, so
spelling them out is worth doing for its own sake.

### Changed - coding-style warnings

15 multi-line `debugging()` and `mtrace()` calls now put the opening parenthesis last on its
line; 14 comment blocks were reworded to begin with a capital; and `function (` became
`function(` in `amd/src/tracker.js` (the built bundle already carried no such space, so
`amd/build` is unaffected).

## 1.2.229 - 2026-09-07

**Integration tests for the untested half of the plugin**

Version: `2026082906`. No database schema changes. One new event observer registration
(`\core\event\course_module_deleted`), so `db/events.php` is re-read on upgrade.

68 new PHPUnit tests covering the observer, the privacy provider, the three web services,
the writing-baseline engine and the three scheduled tasks — the files that previously had
no test at all. Every event the observer tests use is built by core's own factories from a
real activity and a real attempt, so the tests fail when the plugin stops matching what
Moodle really sends. The defects below were found by writing them.

### Fixed — telemetry submitted on a student's behalf was scored against the wrong person

`FIX-EG-OBSERVER-WRONG-USER` (landed in this release, now pinned by regression tests in
both handlers). `$event->userid` is whoever performed the action. `mod_assign` sets
`relateduserid` to the author when a teacher submits for a student, and
`\mod_quiz\event\attempt_submitted` *requires* `relateduserid` because an overdue attempt
is auto-submitted by cron, where `userid` is the cron user.

### Fixed — deleting an activity left its keystroke profiles permanently unreachable

`FIX-EG-ORPHAN-ON-CM-DELETE`. The score and event rows key on `contextid`, and core deletes
the module context when the module goes. `contextlist_base::get_contexts()` silently drops a
context id that no longer resolves, so from the moment a teacher deleted the quiz, that
student's risk scores, their keystroke-level behavioural profile and the explanations
written about them were invisible to both the export and the erasure paths while still
sitting in the database — with no remaining route that could ever delete them. The plugin
now observes `\core\event\course_module_deleted` and purges its rows, the per-activity
setting and the two per-activity user preferences.

### Fixed — the observer used a private copy of the per-activity gate

`FIX-EG-OBSERVER-CM-GATE-DIVERGES`. The observer tested only the `enabled_cm_<cmid>`
checkbox, while `plagiarism_essayguard_is_cm_active()` — used by every other caller — lets
the lms-labs.com site-wide "all assignments" / "all quizzes" flags override it. On a site
driving Essay Guard from the platform switch, the tracker was injected and the keystrokes
were recorded, and then the one component that turns telemetry into a score returned early,
forever. The observer now delegates.

### Fixed — the GDPR export dated every keystroke to the year 55,000

`FIX-EG-PRIVACY-EVENTTIME-MS`. `plagiarism_essayguard_ev.eventtime` holds milliseconds:
`tracker.js` stamps `Date.now()` and the analyser's pause thresholds are literally 500, 2000
and 10000 ms. The export handed that raw value to `transform::datetime()`, which reads
seconds. Those two timestamps are the only means a student has of checking that the record
describes the session they think it does.

### Fixed — the export declared 34 columns and handed over 7

`FIX-EG-PRIVACY-EXPORT-UNDERSTATED`. The 27 dropped columns are the substance of the record:
every behavioural signal, the baseline deviation, and `explanationsjson` — the plain-English
case against the student that a teacher reads in the report. Five of the eight baseline
columns were declared and never exported either. A student contesting an academic-integrity
finding is exactly the person entitled to that reasoning.

### Fixed — a submission that measured nothing pulled the writing baseline towards zero

`FIX-EG-FINGERPRINT-ZERO-SAMPLE`. When the tracker captured nothing usable — a mobile
submission, a theme that drops the footer hook, JS off, a flush that never landed — every
metric was 0, and the EWMA multiplied the student's real baseline by 0.75 and added nothing,
while `samplecount` still ticked up towards "stable". Three such submissions leave a
confident baseline at about 42 % of the student's true speed; the next time they type
normally, Signal 11 adds up to 15 risk points for typing at their usual speed. The students
it hits hardest are the ones whose devices the tracker works worst on. A sample with no
keystrokes now leaves the baseline where it is.

### Fixed — both web services ignored the per-activity switch

`FIX-EG-WS-CM-GATE`. `log_event` and `finalize_attempt` checked the site-wide switch and the
licence, then wrote telemetry and score rows for any course module the caller named.
`inject_tracker()` only decides at page load, so every student who already had the page open
kept a live tracker flushing every five seconds — and every flush was accepted and scored.
The class report then showed a risk badge for an activity the teacher had switched off.

### Fixed — `eventname` was unbounded against a `char(32)` column

`FIX-EG-LOGEVENT-EVENTNAME-LEN`. The same hole v1.2.219 closed for `attemptkey`, one field
along: `PARAM_ALPHAEXT` bounds the character set but not the length, so an over-long name was
an uncaught `dml_write_exception` — a 500 to the student's browser mid-attempt with the whole
batch lost — or, on a non-strict MySQL, a silent truncation.

### Fixed — smaller privacy defects

* `FIX-EG-PRIVACY-PREF-DESCRIPTION`: both user preferences were exported with the
  attempt-key description, so a student's export explained the scoring-throttle marker as
  the typing-session key.
* `FIX-EG-PRIVACY-CONTEXT-PREFS`: purging a module context left every student's
  typing-session key for that activity behind in `user_preferences`.
* `FIX-EG-EV-METADATA-TIMECREATED`: the one event column still undeclared — and the one the
  retention task prunes on.

### Changed — two static caches are bypassed under PHPUnit

`FIX-EG-STATIC-CACHE-UNTESTABLE`. `plagiarism_essayguard_check_unlock()` and
`plagiarism_essayguard_get_platform_settings()` memoise in function-level statics, which
survive `phpunit_util::reset_all_data()`; the first value computed in a test process was
returned to every later test. In production each request is a fresh process, so nothing a
site sees changes.

### Verified — on real Moodle, both branches

| | Moodle 4.5.13 | Moodle 5.2.2 |
|---|---|---|
| PHPUnit | 277 tests, 717 assertions, green | 277 tests, 717 assertions, green |
| phpcs (`moodlehq/moodle-cs`) | zero violations | zero violations |

---

## 1.2.228 - 2026-08-29

**Real toolchain, real standard**

Version: `2026082905`. No database schema changes.

### Fixed - the browser was told the burst threshold was 0 on every fresh install

`inject_tracker()` passed `maxburstchars` to the JavaScript using the raw `get_config()`
accessor, while the setting on the line above it went through `config_int()` — and the
comment two lines up explains exactly why the raw accessor is wrong: `get_config()` returns
`false` for a key nobody has written, and `(int)false` is `0`. Neither `db/install.php` nor
`db/upgrade.php` ever writes a `maxburstchars` default, so on **every fresh install** the
tracker received `0` instead of `150`.

The consequence is on the client: `tracker.js` flags a burst with `delta >= maxburstchars`,
so a threshold of zero marks **every input event** as a suspicious burst. This is the
identical defect v1.2.224 fixed on the server side — "`>= $maxburstchars` alone made every
input event suspicious when an administrator set maxburstchars to 0". The server was
hardened; the configuration feed to the client was missed, so the two halves of the same
plugin disagreed about what a burst is on every site that had not saved its settings.

### Changed - the real Moodle coding standard, from 3,458 violations to zero

Previous releases reported "phpcs clean" against a hand-assembled proxy ruleset. The real
`moodlehq/moodle-cs`, built from git source, reported **3,458 violations** — including 1,326
instances of the rule that Moodle forbids underscores in variable names, which the proxy did
not implement at all.

Now zero. 299 distinct variables renamed through PHP's tokenizer, with a token-level semantic
diff proving that the multiset of `->property` tokens and of every string literal and quoted
array key is byte-identical before and after. That mattered more here than in the sibling
plugin: the `$metrics` array keys are persisted to `metricsjson` and read back by the
explainer, so `has_rhythm_data`, `iki_shannon`, `s4_chars` and the rest are a data contract,
not variable names. None of them changed.

Comment text was preserved word for word throughout; comments that cannot satisfy the sniff
in any arrangement were converted to block comments rather than reworded.

### Verified - on real Moodle, both branches

| | Moodle 4.5.13 | Moodle 5.2.2 |
|---|---|---|
| PHPUnit | 9.6.36 | 11.5.56 |
| Result | **OK (209 tests, 393 assertions)** | **OK (209 tests, 393 assertions)** |

Real PostgreSQL, real Moodle installs, identical counts on both branches. Moodle 5.2's own
core suite emits more PHPUnit-runner deprecations than either plugin does, confirming those
are an artefact of PHPUnit 11 deprecating doc-comment metadata rather than anything in this
plugin.

**Forward-compatibility note:** these suites use `@covers` and `@dataProvider` annotations,
which PHPUnit 12 will not support. Converting them to PHP attributes will be needed before
Moodle moves to PHPUnit 12. Moodle core has the same work ahead of it.

### Noted - `plagiarism_update_status()` is gone in Moodle 5.2

The one core symbol these plugins were built around that 5.2 has deleted. Both reference it
only in comments; there is no executable call site. No action needed, recorded for the next
person reading the compatibility scaffolding.

## 1.2.227 - 2026-08-29

**An unmeasured attempt is no longer reported as low risk**

Version: `2026082904`. No database schema changes.

Found by testing on a live Moodle 5.2 site, not by reading code.

### Fixed - a green LOW badge on an attempt where nothing was captured

If the tracker never runs, the attempt has no events, no keystrokes and no pastes, so no
signal fires, the score is 0, and 0 bands as "low". The teacher is shown a green LOW badge
that is indistinguishable from a genuinely clean attempt, and nothing anywhere says the
measurement did not happen.

This is not hypothetical. On the site tested, two **other** plugins — `quizaccess_proctoring`
and `quizaccess_hidecorrect` — each declare `isCameraAllowed` at the top level of an AMD
module. Moodle concatenates all 910 of the site's modules into a single 9 MB requirejs
bundle, and the quiz attempt page fetches that bundle under four different entry-point
names. A top-level `let` in a classic script is global scope, so the second evaluation
throws `SyntaxError: Identifier 'isCameraAllowed' has already been declared` and the entire
bundle fails — taking Essay Guard's tracker with it. Every quiz attempt on that site
captured nothing, and every student was badged LOW.

Essay Guard cannot stop another plugin breaking the page. It can refuse to report an
unmeasured attempt as a clean one. Such attempts now carry a distinct **"Not measured"**
state — slate, neither the reassuring green nor the alarming red — whose tooltip says
plainly that no writing activity was captured, that this is not a low-risk result, and
where to look (the browser console on the attempt page).

The test is deliberately narrow, and the boundaries are pinned by tests: it fires only when
there is submitted text that should have been measured AND nothing was captured by any
route. A student who submitted nothing is still legitimately low risk; an attempt with no
events but a server-side timing signal was still assessed and keeps its score.

## 1.2.226 - 2026-08-29

**Supported-version declarations and the external API**

Version: `2026082903`. No database schema changes.

### Fixed - the declared Moodle range was wrong at both ends

`requires` said Moodle 4.0 and `supported` said `[400, 501]`.

**Too low.** Moodle 4.0's minimum PHP is 7.3, and this plugin uses arrow functions, which
are PHP 7.4. On a Moodle 4.0 or 4.1 site running PHP 7.3 that is a parse error in `lib.php`
— which the plagiarism subsystem loads on every page, so the whole site goes white, not
just this plugin. The real floor is 4.4, where the three output hook classes this plugin
registers were introduced; below that the registrations are inert and the floating report
button and the quiz grading-overview badges silently never appear.

Now `requires = 2024100700` (Moodle 4.5 LTS), the lowest branch still receiving any
support, and the branch where core removed the deprecated plagiarism methods this plugin
carries compatibility scaffolding for.

**Too high.** `supported = [400, 501]` excludes branch 502, so on a Moodle 5.2 site both
plugins were listed as unsupported in Site administration → Plugins. Now `[405, 502]`.

### Changed - external API moved to the core_external namespace

The three web service classes used the global `external_api` aliases and
`require_once($CFG->libdir/externallib.php)`. That file's own header says it will be
deprecated from Moodle 5.0 and it survives only as a back-compatibility shim; when core
removes it, every web service call becomes "Class external_api not found" — the exact
failure v1.2.141 was written to prevent, arriving from the other direction. The
`\core_external\` classes have existed since Moodle 4.2, autoload with no `require_once`,
and are guaranteed present at the new 4.5 floor.

## 1.2.225 - 2026-08-29

**Correctness pass**

Version: `2026082902`. No database schema changes; the metrics blob gains four fields,
which is additive and needs no upgrade step.

This release finishes the work 1.2.224 started. 1.2.224 fixed what the tests found;
1.2.225 fixes the four things that were left as judgement calls, and the further defects
that closing them uncovered. The principle throughout: where a setting or a display
promised something the code could not deliver, the code changed to match the promise.

### Fixed - the paste weight could not do what it says

`paste_weight` is documented as "set to 25 % so paste-only sessions score MEDIUM rather
than HIGH". It could not reach that outcome at any value, including 0.

The multiplier scaled the paste and large-insertion signals only. But in a pure-paste
session, typing speed, absence of thinking pauses, absence of corrections, near-instant
insertion and rhythm are not independent evidence - they are five more descriptions of the
same single paste, and each says so in its own comment. Unweighted, those five total 100
points on their own, so `paste_weight = 0` produced exactly the same verdict as `100`.

The weight now scales every signal that is describing the paste. Measured, on one
500-character paste: 100 % gives 100/HIGH as before, 25 % gives 41/MEDIUM - the documented
case - and 0 % gives 0/LOW. A session the student genuinely typed is unaffected at every
value, so the setting cannot be used to hide a student who typed suspiciously. The
server-side timing signal is deliberately excluded: it exists to catch a paste that left
no trace in the browser, and weakening it would blind the one check that still works when
the tracker is defeated.

### Fixed - a MEDIUM or HIGH record could carry no explanation at all

The reassuring "nothing concerning found" line was gated to the low band, and typing
speed, near-instant insertion and server-side timing had no explanation rule at all. A
session scored HIGH purely on speed handed the teacher a red badge and, because the
report suppresses the whole section when the list is empty, no reasons section whatsoever.

A teacher acting on a HIGH badge may be starting a misconduct process. Every signal that
can score now has a rule, and a guaranteed fallback names the score and points to the
breakdown rather than inventing a reason. The reassuring line stays gated to low - printing
"consistent with normal student patterns" beside a red badge would have been worse.

### Fixed - four explanations that disagreed with the scoring engine

The explainer decides from the stored metrics whether a signal fired, and four of the
analyser's gates turned on values that were never stored - so it approximated them, and
approximated them differently:

- A paste through TinyMCE scored for having no thinking pauses, with no sentence saying so.
- A perfectly constant typing rhythm - the strongest evidence 1.2.224 added - could not be
  distinguished from "no rhythm measured", so nothing was said about it.
- A rhythm autocorrelation was explained on any sample size, where the engine requires 30.
- A single-sentence answer scored for uniformity that could not be described.

The analyser now stores the four deciding values. Records written before this release
carry none of them and explain exactly as they always did, so no existing report changes
meaning retroactively.

### Fixed - two baseline comparisons scored but were never explained

The per-student fingerprint averages five components for up to 15 points; typing-rhythm
entropy and mean pause length had no explanation. That is the worst place for the gap: a
comparison against the student's own past work is the most persuasive evidence this plugin
produces and the hardest for a teacher to guess at. Both directions of both components are
now reported, and a partial baseline object no longer emits a PHP warning into the page.

### Fixed - bulk rescore skipped attempts and reported itself complete

The resume position was a numeric offset into a list ordered newest-first. That list is
not stable: a teacher rescores a quiz precisely when students are still submitting to it,
and every attempt finished mid-run is inserted at the front, shifting every index.
Simulated on a twelve-attempt quiz with two submissions between each batch of four, the
old code processed four attempts twice and never touched four others at all - then
reported the run complete.

It now resumes from the last attempt id, which is immutable. The logic moved into a
testable function, because the page had no testable seam at all, which is how an
off-by-a-shifting-amount bug survived two releases of work on that same loop.

Separately, the "remaining" count included attempts that needed no work, so on a quiz where
most attempts were already scored the page reported hundreds outstanding and the number
barely moved when the teacher pressed the button.

