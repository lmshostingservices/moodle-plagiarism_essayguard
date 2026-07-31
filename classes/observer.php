<?php

namespace plagiarism_essayguard;

defined('MOODLE_INTERNAL') || die();

// lib.php is NOT auto-loaded when Moodle's event system dispatches to an observer
// via the autoloader. Without this require_once, any call to a lib.php function
// (e.g. plagiarism_essayguard_check_unlock) throws "Call to undefined function".
require_once(__DIR__ . '/../lib.php');

use plagiarism_essayguard\local\service\analyser;
use plagiarism_essayguard\local\service\fingerprint;

/**
 * Essay Guard event observer.
 *
 * Called by Moodle's event system when a student submits an assignment, quiz,
 * or forum post. This is the PRIMARY scoring trigger as described in
 * INTEGRATION_NOTES.md — the PHP observer is more reliable than the JS path
 * because it fires regardless of browser behaviour at submit time.
 *
 * Flow:
 *   1. Check plugin is enabled and unlocked.
 *   2. Retrieve the current attemptkey from Moodle user preferences — set_user_preference()
 *      is called by inject_tracker() on every page load, so the key is always available
 *      before any JS telemetry events reach the database, eliminating the race condition
 *      where the observer fires before AJAX event flushes complete.
 *   3. Extract the final submitted text from Moodle's submission record.
 *   4. Call analyser::score_attempt() directly (no HTTP round-trip).
 *   5. Update the student fingerprint/baseline.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Handle mod_assign assessable_submitted and mod_forum assessable_uploaded.
     *
     * @param \core\event\base $event
     */
    public static function on_assessable_submitted(\core\event\base $event): void {
        global $DB;

        if (!self::is_active()) {
            return;
        }

        $cmid    = (int)$event->contextinstanceid;

        if (!self::is_cm_active($cmid)) {
            return;
        }
        $userid  = (int)$event->userid;
        $context = \context_module::instance($cmid);

        // FIX-EG-ATTEMPTKEY (v1.2.81): inject_tracker() now generates the key as
        // 'qa_{quizattemptid}' for non-quiz modules (assign, forum). We read from
        // user preferences here; the quiz observer uses the attempt ID directly.
        $attemptkey = self::get_stored_attemptkey($userid, $cmid);

        // Extract submitted text depending on event type.
        $finaltext = '';

        if ($event->eventname === '\mod_assign\event\assessable_submitted') {
            $finaltext = self::get_assign_text($event->objectid);
        } elseif ($event->eventname === '\mod_forum\event\assessable_uploaded') {
            $finaltext = self::get_forum_text($event->objectid);
        }

        self::do_score($userid, $cmid, $context->id, $attemptkey, $finaltext);
    }

    /**
     * Handle mod_quiz attempt_submitted.
     *
     * @param \mod_quiz\event\attempt_submitted $event
     */
    public static function on_quiz_attempt_submitted(\core\event\base $event): void {
        global $DB;

        if (!self::is_active()) {
            return;
        }

        $cmid = (int)$event->contextinstanceid;

        if (!self::is_cm_active($cmid)) {
            return;
        }

        $userid  = (int)$event->userid;
        $context = \context_module::instance($cmid);

        // FIX-EG-ATTEMPTKEY (v1.2.81): since inject_tracker() now generates the key as
        // 'qa_{quizattemptid}', the observer can reconstruct the exact same key from the
        // event's objectid (the quiz attempt ID) — no user preference lookup needed.
        // This eliminates the race where the preference was updated mid-attempt with a new
        // sesskey()-derived key and the observer read the wrong one.
        $quizattemptid = (int)$event->objectid;
        if ($quizattemptid > 0) {
            $attemptkey = 'qa_' . $quizattemptid;
        } else {
            $attemptkey = self::get_stored_attemptkey($userid, $cmid);
        }

        $finaltext = self::get_quiz_essay_text($event->objectid);

        // FIX-EG-SERVER-TIMING (v1.2.126): Fetch quiz attempt timestart and timefinish
        // so analyser::score_attempt() can compute server-side chars-per-second as a
        // reliable fallback signal when JS tracker events are absent. Without this, a
        // student who copy-pastes and immediately submits (before the 5-second periodic
        // flush fires) appears identical to an honest typist — both produce paste_events=0
        // — and is wrongly scored MEDIUM via the linguistic fallback.
        $attempt_timestart  = 0;
        $attempt_timefinish = 0;
        if ($quizattemptid > 0) {
            $atrow = $DB->get_record('quiz_attempts', ['id' => $quizattemptid], 'timestart,timefinish');
            if ($atrow) {
                $attempt_timestart  = (int)$atrow->timestart;
                $attempt_timefinish = (int)$atrow->timefinish;
            }
        }

        // FIX-EG-PERQ-FIRST (v1.2.125): Score per-question BEFORE aggregate.
        //
        // analyser::score_attempt() FIX-EG-AGG-PERQ-CONSISTENCY elevates the aggregate
        // score to match the maximum per-question riskscore when no behavioural events
        // were captured (events_empty_for_scoring). That fix requires per-question DB
        // records to already exist when the aggregate is scored — so we must score
        // per-question first.
        //
        // Previous order (aggregate first) meant per-question records were always absent
        // during aggregate scoring, so the elevation could never fire and the gradebook
        // aggregate stayed at 0% even when per-question records showed a higher risk.
        $slot_texts = self::get_quiz_essay_texts_by_slot($event->objectid);
        foreach ($slot_texts as $slot => $slottext) {
            self::do_score($userid, $cmid, $context->id, $attemptkey, $slottext, (int)$slot,
                $attempt_timestart, $attempt_timefinish);
        }

        // Aggregate score (qslot = 0) — scored LAST so FIX-EG-AGG-PERQ-CONSISTENCY
        // can read the per-question records written above.
        self::do_score($userid, $cmid, $context->id, $attemptkey, $finaltext, 0,
            $attempt_timestart, $attempt_timefinish);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Run analyser::score_attempt() and update the student fingerprint.
     *
     * @param int $qslot  0 = aggregate, N = specific quiz question slot (v1.2.14+)
     */
    private static function do_score(
        int    $userid,
        int    $cmid,
        int    $contextid,
        string $attemptkey,
        string $finaltext,
        int    $qslot = 0,
        int    $attempt_timestart = 0,
        int    $attempt_timefinish = 0
    ): void {
        try {
            $result = analyser::score_attempt(
                $userid,
                $cmid,
                $contextid,
                $attemptkey,
                [],          // linguistic computed inline from $finaltext
                $finaltext,
                $qslot,
                $attempt_timestart,
                $attempt_timefinish
            );

            // Only update the fingerprint baseline from the aggregate score,
            // not from individual question scores, to avoid skewing the profile.
            if ($qslot === 0) {
                fingerprint::update($userid, $result['metrics']);
            }
        } catch (\Throwable $e) {
            // Never let scoring errors break submission. Log silently.
            error_log('[plagiarism_essayguard] scoring error for user=' . $userid
                . ' cmid=' . $cmid . ' qslot=' . $qslot . ': ' . $e->getMessage());
        }
    }

    /**
     * Retrieve the stable attemptkey for a userid + cmid.
     *
     * Primary source: Moodle user preferences — inject_tracker() calls
     * set_user_preference('essayguard_ak_{cmid}', $attemptkey) on every page
     * load before the tracker JS runs. This guarantees the key is available
     * in the observer even when JS telemetry events have not yet been flushed
     * to the database (the AJAX flush race condition).
     *
     * Fallback: the raw telemetry events table, for backwards compatibility
     * with any session that started before this version was installed.
     *
     * Returns empty string if the tracker was not active for this student.
     */
    private static function get_stored_attemptkey(int $userid, int $cmid): string {
        global $DB;

        // Primary: user preference set by inject_tracker() on page load.
        $key = get_user_preferences('essayguard_ak_' . $cmid, '', $userid);
        if (!empty($key)) {
            return (string)$key;
        }

        // Fallback: look up from the events table (pre-1.2.1 sessions).
        $row = $DB->get_record_sql(
            "SELECT attemptkey
               FROM {plagiarism_essayguard_ev}
              WHERE userid = :userid AND cmid = :cmid
           ORDER BY id DESC",
            ['userid' => $userid, 'cmid' => $cmid],
            IGNORE_MULTIPLE
        );

        return $row ? (string)$row->attemptkey : '';
    }

    /**
     * Extract plain text from an assignment online-text submission.
     * Returns empty string if the assignment uses file uploads only.
     *
     * @param int $submissionid  The assign_submission.id from the event objectid.
     */
    private static function get_assign_text(int $submissionid): string {
        global $DB;

        $row = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submissionid]);
        if (!$row || empty($row->onlinetext)) {
            return '';
        }

        // FIX-EG-ASSIGN-EXTRACT-PLAIN (v1.2.95): Use extract_plain_text() instead of
        // bare strip_tags(). TinyMCE stores answers as HTML with &nbsp; entities for
        // empty paragraphs. strip_tags("<p>&nbsp;</p>") → "&nbsp;" (6-char non-empty
        // string) making mb_strlen() count entity chars, inflating $text_chars and
        // causing incorrect $text_gate evaluation in analyser::score_attempt(). The
        // extract_plain_text() method decodes HTML entities (turning &nbsp; → U+00A0)
        // then collapses all whitespace, so truly empty TinyMCE submissions return ''
        // and real answers return clean plain text — consistent with the quiz path.
        return self::extract_plain_text($row->onlinetext);
    }

    /**
     * Extract plain text from a forum post.
     *
     * @param int $postid  The forum_posts.id from the event objectid.
     */
    private static function get_forum_text(int $postid): string {
        global $DB;

        $post = $DB->get_record('forum_posts', ['id' => $postid], 'message');
        if (!$post) {
            return '';
        }

        // FIX-EG-FORUM-EXTRACT-PLAIN (v1.2.95): Same fix as get_assign_text() — use
        // extract_plain_text() so TinyMCE &nbsp; entities are decoded correctly.
        return self::extract_plain_text($post->message);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Bulletproof HTML → plain-text extractor for student essay answers.
     *
     * TinyMCE stores answers as HTML. Three layers of sanitisation are needed:
     *
     * 1. strip_tags()  — removes all HTML markup.
     * 2. html_entity_decode()  — converts &nbsp; (6 chars) to U+00A0 (non-breaking
     *    space).  Without this step, a TinyMCE "empty" paragraph stored as
     *    "<p>&nbsp;</p>" passes through strip_tags as "&nbsp;" — a 6-char non-empty
     *    string — and the code wrongly treats the answer as having content.
     * 3. Replace U+00A0 (\xc2\xa0 in UTF-8) with a normal space so the subsequent
     *    whitespace collapse works uniformly regardless of encoding.
     * 4. Collapse all whitespace runs (spaces, tabs, newlines, NBSP) to one space
     *    and trim.
     *
     * Result: "<p>&nbsp;</p>" → "" (empty) and "<p>Real answer here.</p>" →
     *   "Real answer here." — which is the correct expected behaviour.
     *
     * @param string $html  Raw HTML from question_attempt_step_data.value or
     *                      question_attempts.responsesummary.
     * @return string       Trimmed plain text, or '' if the answer is effectively empty.
     */
    private static function extract_plain_text(string $html): string {
        // 1. Strip HTML tags.
        $text = strip_tags($html);
        // 2. Decode HTML entities (&nbsp; → U+00A0, &amp; → &, etc.).
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 3. Replace non-breaking spaces (U+00A0, UTF-8: \xc2\xa0) with regular space.
        $text = str_replace("\xc2\xa0", ' ', $text);
        // 4. Collapse all whitespace to one space and trim.
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Extract essay-question responses from a quiz attempt, keyed by question slot.
     *
     * Returns an array: [slot_number => plain_text] for each essay question
     * in the attempt. Used by v1.2.14 per-question scoring.
     *
     * @param int $quizattemptid  The quiz_attempts.id from the event objectid.
     * @return array<int,string>  e.g. [1 => 'Q1 answer text', 2 => 'Q2 answer text']
     */
    private static function get_quiz_essay_texts_by_slot(int $quizattemptid): array {
        global $DB;

        $attempt = $DB->get_record('quiz_attempts', ['id' => $quizattemptid], 'uniqueid');
        if (!$attempt) {
            return [];
        }

        // qas.id is the unique primary key and MUST be the first column so Moodle's
        // get_records_sql() can use it as the array key without "Duplicate value" errors.
        // qa.slot is NOT unique across rows — a question can have multiple attempt steps
        // (autosaves, state transitions) each storing an 'answer' in step_data. Without
        // ORDER BY the PHP hash-map overwrites non-deterministically; an older partial text
        // can win over the final submitted answer, yielding empty or truncated text and
        // causing the scoring gate to fail. ORDER BY qas.id DESC (most recent first) +
        // !isset guard ensures the most recently written answer per slot is always used.
        $sql = "SELECT qas.id, qa.slot, qasd.value
                  FROM {question_attempt_steps} qas
                  JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
                  JOIN {question_attempts} qa             ON qa.id = qas.questionattemptid
                 WHERE qa.questionusageid = :qubaid
                   AND qasd.name = 'answer'
                   -- FIX-EG-STATE-FILTER (v1.2.61): state filter removed. Essay questions use
                   -- 'needsgrading' in deferredfeedback mode, but other Moodle 4.x quiz
                   -- behaviours (immediatedfeedback, etc.) can produce different state names.
                   -- qasd.name = 'answer' is already specific to genuine student submissions;
                   -- the additional state filter caused empty results in some configurations,
                   -- silently preventing per-question Essay Guard records from being written.
              ORDER BY qa.slot ASC, qas.id DESC";

        $rows = $DB->get_records_sql($sql, ['qubaid' => $attempt->uniqueid]);

        $result = [];
        foreach ($rows as $row) {
            $slot = (int)$row->slot;
            // First row seen for this slot is the most recent answer (ORDER BY qas.id DESC).
            if (!isset($result[$slot])) {
                // FIX-EG-EXTRACT-BULLETPROOF (v1.2.84): Use extract_plain_text() instead of
                // bare strip_tags(). This handles TinyMCE's &nbsp; empty-paragraph pattern
                // which strip_tags alone leaves as a 6-char non-empty string, causing the
                // answer to be mis-classified as "has content" and written to the DB with
                // only whitespace, producing a misleading score.
                $text = self::extract_plain_text($row->value ?? '');
                if ($text !== '') {
                    $result[$slot] = $text;
                }
            }
        }

        // FIX-EG-RESPONSESUMMARY-FALLBACK (v1.2.84): If step-data approach yields nothing
        // (possible when TinyMCE stores all answers as &nbsp; placeholders, or when an
        // unusual quiz behaviour writes no step_data rows), try question_attempts.responsesummary.
        // This is Moodle's own auto-generated plain-text summary of the student's response
        // and is always populated for essay questions regardless of editor type.
        if (empty($result)) {
            $summary_rows = $DB->get_records_sql(
                "SELECT id, slot, responsesummary
                   FROM {question_attempts}
                  WHERE questionusageid = :qubaid
                    AND responsesummary IS NOT NULL
                    AND " . $DB->sql_isnotempty('question_attempts', 'responsesummary', true, true) . "
               ORDER BY slot ASC",
                ['qubaid' => $attempt->uniqueid]
            );
            foreach ($summary_rows as $sr) {
                $slot = (int)$sr->slot;
                if (!isset($result[$slot])) {
                    $text = self::extract_plain_text($sr->responsesummary ?? '');
                    if ($text !== '') {
                        $result[$slot] = $text;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Extract all essay-question responses from a quiz attempt.
     *
     * Joins question_attempt_steps → question_attempt_step_data to find
     * the 'answer' slot for any question attempt that is in a graded or
     * needsgrading state within the given quiz attempt's question usage.
     *
     * @param int $quizattemptid  The quiz_attempts.id from the event objectid.
     */
    private static function get_quiz_essay_text(int $quizattemptid): string {
        global $DB;

        $attempt = $DB->get_record('quiz_attempts', ['id' => $quizattemptid], 'uniqueid');
        if (!$attempt) {
            return '';
        }

        // qas.id is the unique primary key and MUST be the first column so Moodle's
        // get_records_sql() can use it as the array key without "Duplicate value" errors.
        // qa.slot is included so we can deduplicate per question (see ORDER BY note below).
        // FIX-EG-ZERO-SCORE: ORDER BY qas.id DESC + !isset deduplication ensures only the
        // most recently written answer per slot is used, preventing older autosave steps
        // from duplicating the text (which would inflate linguistic metrics and text length).
        $sql = "SELECT qas.id, qa.slot, qasd.value
                  FROM {question_attempt_steps} qas
                  JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
                  JOIN {question_attempts} qa             ON qa.id = qas.questionattemptid
                 WHERE qa.questionusageid = :qubaid
                   AND qasd.name = 'answer'
                   -- FIX-EG-STATE-FILTER (v1.2.61): state filter removed. Essay questions use
                   -- 'needsgrading' in deferredfeedback mode, but other Moodle 4.x quiz
                   -- behaviours (immediatedfeedback, etc.) can produce different state names.
                   -- qasd.name = 'answer' is already specific to genuine student submissions;
                   -- the additional state filter caused empty results in some configurations,
                   -- silently preventing per-question Essay Guard records from being written.
              ORDER BY qa.slot ASC, qas.id DESC";

        $rows = $DB->get_records_sql($sql, ['qubaid' => $attempt->uniqueid]);

        if (empty($rows)) {
            return '';
        }

        // Collect the most recent answer per slot (first seen = most recent due to DESC).
        // FIX-EG-EXTRACT-BULLETPROOF (v1.2.84): Use extract_plain_text() for same reasons
        // as in get_quiz_essay_texts_by_slot() — handles TinyMCE &nbsp; empty paragraphs.
        $per_slot = [];
        foreach ($rows as $row) {
            $slot = (int)$row->slot;
            if (!isset($per_slot[$slot])) {
                $text = self::extract_plain_text($row->value ?? '');
                if ($text !== '') {
                    $per_slot[$slot] = $text;
                }
            }
        }

        return implode("\n\n", $per_slot);
    }

    /**
     * Check that the plugin is globally enabled and the site has a valid unlock.
     */
    private static function is_active(): bool {
        // FIX-EG-ENABLED-CHECK-INCONSISTENT (v1.2.94): get_config() returns PHP false
        // when the key has never been saved (fresh install where admin hasn't submitted
        // the settings page yet). !false = true → this condition fired on fresh installs,
        // making the observer skip ALL scoring. inject_tracker() was fixed in v1.2.88
        // (FIX-EG-GLOBAL-ENABLED-MISSING) with the same logic — applying the same fix here.
        // Treat missing key as enabled; only skip when the key EXISTS and is explicitly falsy.
        $global_enabled = get_config('plagiarism_essayguard', 'enabled');
        if ($global_enabled !== false && empty($global_enabled)) {
            return false;
        }
        if (during_initial_install()) {
            return false;
        }
        // Reuse the same unlock check used by inject_tracker().
        // Backslash prefix required: inside namespace plagiarism_essayguard, PHP resolves
        // unqualified function calls within the namespace first. The global lib.php function
        // must be explicitly addressed from the root namespace.
        $unlocked = \plagiarism_essayguard_check_unlock();
        if (!$unlocked) {
            // check_unlock() already logs the reason (HTTP code, curl error, or not-unlocked).
            // This additional log entry surfaces it at the observer level so admins can
            // correlate "no Essay Guard data" in the report with the unlock failure.
            error_log('[plagiarism_essayguard] observer skipped: site not unlocked.'
                . ' No scores will be written. Check Essay Guard settings and ensure'
                . ' the plugin has been unlocked in the EssayGraderAI dashboard.');
        }
        return $unlocked;
    }

    /**
     * Check whether Essay Guard is enabled for a specific course module.
     * A missing config key (never saved) defaults to enabled.
     */
    private static function is_cm_active(int $cmid): bool {
        $value = get_config('plagiarism_essayguard', 'enabled_cm_' . $cmid);
        return ($value === false) || !empty($value);
    }
}
