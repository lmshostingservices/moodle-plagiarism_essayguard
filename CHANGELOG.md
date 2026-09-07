# Changelog

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

