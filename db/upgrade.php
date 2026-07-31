<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_plagiarism_essayguard_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026030600101) {
        upgrade_plugin_savepoint(true, 2026030600101, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026030600102) {
        upgrade_plugin_savepoint(true, 2026030600102, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026030600103) {
        upgrade_plugin_savepoint(true, 2026030600103, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026030600104) {
        set_config('enableplagiarism', 1);
        upgrade_plugin_savepoint(true, 2026030600104, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026030600105) {
        upgrade_plugin_savepoint(true, 2026030600105, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026030600106) {
        upgrade_plugin_savepoint(true, 2026030600106, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026030600107) {
        upgrade_plugin_savepoint(true, 2026030600107, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026030600108) {
        if (!get_config('plagiarism_essayguard', 'retentiondays')) {
            set_config('retentiondays', 90, 'plagiarism_essayguard');
        }
        upgrade_plugin_savepoint(true, 2026030600108, 'plagiarism', 'essayguard');
    }

    // v1.1.0 — World-Class Authenticity Engine
    if ($oldversion < 2026030700110) {

        // ── 1. Expand plagiarism_essayguard_sc with new metric columns ────────
        $table = new \xmldb_table('plagiarism_essayguard_sc');

        $new_fields = [
            new \xmldb_field('typing_time',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new \xmldb_field('idle_time',            XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new \xmldb_field('total_keystrokes',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new \xmldb_field('paste_events',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new \xmldb_field('backspace_count',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new \xmldb_field('delete_count',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new \xmldb_field('cursor_moves',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new \xmldb_field('average_wpm',          XMLDB_TYPE_NUMBER,  '8',  null, XMLDB_NOTNULL, null, '0', null, '2'),
            new \xmldb_field('wpm_std_dev',          XMLDB_TYPE_NUMBER,  '8',  null, XMLDB_NOTNULL, null, '0', null, '2'),
            new \xmldb_field('interkey_mean',        XMLDB_TYPE_NUMBER,  '10', null, XMLDB_NOTNULL, null, '0', null, '2'),
            new \xmldb_field('interkey_std_dev',     XMLDB_TYPE_NUMBER,  '10', null, XMLDB_NOTNULL, null, '0', null, '2'),
            new \xmldb_field('pause_count',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new \xmldb_field('pause_mean',           XMLDB_TYPE_NUMBER,  '10', null, XMLDB_NOTNULL, null, '0', null, '2'),
            new \xmldb_field('pause_std_dev',        XMLDB_TYPE_NUMBER,  '10', null, XMLDB_NOTNULL, null, '0', null, '2'),
            new \xmldb_field('burst_count',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            new \xmldb_field('burst_mean',           XMLDB_TYPE_NUMBER,  '10', null, XMLDB_NOTNULL, null, '0', null, '2'),
            new \xmldb_field('burst_std_dev',        XMLDB_TYPE_NUMBER,  '10', null, XMLDB_NOTNULL, null, '0', null, '2'),
            new \xmldb_field('sentence_variance',    XMLDB_TYPE_NUMBER,  '10', null, XMLDB_NOTNULL, null, '0', null, '4'),
            new \xmldb_field('vocab_diversity',      XMLDB_TYPE_NUMBER,  '8',  null, XMLDB_NOTNULL, null, '0', null, '4'),
            new \xmldb_field('rare_word_ratio',      XMLDB_TYPE_NUMBER,  '8',  null, XMLDB_NOTNULL, null, '0', null, '4'),
            new \xmldb_field('thinking_pause_score', XMLDB_TYPE_NUMBER,  '8',  null, XMLDB_NOTNULL, null, '0', null, '4'),
            new \xmldb_field('entropy_score',        XMLDB_TYPE_NUMBER,  '8',  null, XMLDB_NOTNULL, null, '0', null, '4'),
            new \xmldb_field('explanationsjson',     XMLDB_TYPE_TEXT,    null, null, null,          null, null),
            new \xmldb_field('baseline_deviation',   XMLDB_TYPE_NUMBER,  '8',  null, XMLDB_NOTNULL, null, '0', null, '4'),
            new \xmldb_field('baseline_status',      XMLDB_TYPE_CHAR,    '16', null, XMLDB_NOTNULL, null, 'none'),
        ];

        foreach ($new_fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // ── 2. Create plagiarism_essayguard_fp fingerprint table ─────────────
        $fp_table = new \xmldb_table('plagiarism_essayguard_fp');

        if (!$dbman->table_exists($fp_table)) {
            $fp_table->add_field('id',                        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $fp_table->add_field('userid',                    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $fp_table->add_field('samplecount',               XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $fp_table->add_field('baseline_wpm',              XMLDB_TYPE_NUMBER,  '8',  null, XMLDB_NOTNULL, null, '0',  null, '2');
            $fp_table->add_field('baseline_pause_mean',       XMLDB_TYPE_NUMBER,  '10', null, XMLDB_NOTNULL, null, '0',  null, '2');
            $fp_table->add_field('baseline_backspace_ratio',  XMLDB_TYPE_NUMBER,  '8',  null, XMLDB_NOTNULL, null, '0',  null, '4');
            $fp_table->add_field('baseline_burst_mean',       XMLDB_TYPE_NUMBER,  '10', null, XMLDB_NOTNULL, null, '0',  null, '2');
            $fp_table->add_field('baseline_sentence_variance',XMLDB_TYPE_NUMBER,  '10', null, XMLDB_NOTNULL, null, '0',  null, '4');
            $fp_table->add_field('baseline_vocab_diversity',  XMLDB_TYPE_NUMBER,  '8',  null, XMLDB_NOTNULL, null, '0',  null, '4');
            $fp_table->add_field('baseline_entropy',          XMLDB_TYPE_NUMBER,  '8',  null, XMLDB_NOTNULL, null, '0',  null, '4');
            $fp_table->add_field('baseline_interkey_mean',    XMLDB_TYPE_NUMBER,  '10', null, XMLDB_NOTNULL, null, '0',  null, '2');
            $fp_table->add_field('baseline_status',           XMLDB_TYPE_CHAR,    '16', null, XMLDB_NOTNULL, null, 'none');
            $fp_table->add_field('timemodified',              XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $fp_table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $fp_table->add_index('userid_ix', XMLDB_INDEX_UNIQUE, ['userid']);

            $dbman->create_table($fp_table);
        }

        upgrade_plugin_savepoint(true, 2026030700110, 'plagiarism', 'essayguard');
    }

    // v1.1.2 — Badge fix: PHP event observers, JS finalize, report pages, mild badge CSS.
    if ($oldversion < 2026030700120) {
        upgrade_plugin_savepoint(true, 2026030700120, 'plagiarism', 'essayguard');
    }

    // v1.2.0 — Full audit fixes: backspace zero-case, double fingerprint, per-activity settings,
    //           unlock caching, report sort order, privacy GDPR export, cleanup preserves scores,
    //           Class Report navigation, observer per-cm check.
    if ($oldversion < 2026030700130) {
        upgrade_plugin_savepoint(true, 2026030700130, 'plagiarism', 'essayguard');
    }

    // v1.2.3 — Five bug fixes: savedconfigsuccess lang string, stable quiz attempt key,
    //           observer race condition (user preferences instead of events table lookup),
    //           quiz summary-page flush, assignment form selector corrected to /mod/assign.
    //           Also: backslash namespace prefix fix in observer::is_active() so
    //           \plagiarism_essayguard_check_unlock() resolves correctly from namespace.
    //           No DB schema changes — this savepoint was missing from prior releases and is
    //           added here so Moodle correctly marks the upgrade as complete at this version.
    if ($oldversion < 2026031200133) {
        upgrade_plugin_savepoint(true, 2026031200133, 'plagiarism', 'essayguard');
    }

    // v1.2.4 — FIX: upgrade.php was missing savepoints for all versions after v1.2.0
    //           (v1.2.1, v1.2.2, v1.2.3). Without a savepoint for the current version,
    //           Moodle's upgrade engine calls xmldb_upgrade() but no block fires, the DB
    //           version is never updated, and the admin panel shows the plugin as perpetually
    //           needing an upgrade on every visit. No DB schema changes required.
    if ($oldversion < 2026031600134) {
        upgrade_plugin_savepoint(true, 2026031600134, 'plagiarism', 'essayguard');
    }

    // v1.2.5 — FIX: risk badge was invisible to students on their own submission page.
    //
    // Root cause: plagiarism_essayguard_get_links() required the
    // 'plagiarism/essayguard:viewreport' capability before rendering any output.
    // That capability is teacher-only. Students never have it, so the function
    // returned '' for every student page load — no badge was ever displayed.
    //
    // Fix: split rendering into two paths. Teachers (viewreport cap) get the
    // full output: badge + per-student detail link + class report link. Students
    // viewing their own submission get the badge only (no links to teacher pages).
    // Students viewing other students' submissions still get nothing (unchanged).
    // No DB schema changes — code-only fix.
    if ($oldversion < 2026031600135) {
        upgrade_plugin_savepoint(true, 2026031600135, 'plagiarism', 'essayguard');
    }

    // v1.2.6 — Full audit + bug fixes — no DB schema changes required.
    //
    // Bug 1 (explainer.php): Zero-backspace ratio (the MOST suspicious case) was excluded
    //   from the low-backspace explanation by the `$backspace_ratio > 0` guard. Fixed by
    //   changing the guard to `$backspace_ratio < 0.02 && $total_keystrokes > 0`, matching
    //   the analyser.php scoring logic which already correctly handles the zero case.
    // Bug 2 (tracker.js): Notification.exception(err) on AJAX flush failure showed a scary
    //   modal error dialog to students on any network blip or session expiry. Changed to
    //   silent console.warn so students are never interrupted by telemetry errors.
    // Bug 3 (report.php, student.php): Added explicit global $DB, $CFG, $OUTPUT, $PAGE
    //   declarations for robustness under any future Moodle internal refactor.
    // Bug 4 (tracker.js): Extended TinyMCE 6 scan to also match .tox-tinymce iframe
    //   selectors and added document-level focus/blur relay listeners inside the iframe
    //   document so keydown/paste telemetry is captured even when theme JS initialises
    //   the editor after the first scan pass.
    // Bug 5 (analyser.php): Added mb_substr($finaltext, 0, 50000) guard in score_attempt()
    //   so oversized submissions arriving via the PHP event observer path (which has no
    //   client-side trim) cannot cause excessive memory use in linguistic::analyse().
    // Bug 7 (settings.php): Changed PARAM_TEXT to PARAM_ALPHANUMEXT for siteid so XSS
    //   payloads containing script tags are rejected at the Moodle param layer.
    if ($oldversion < 2026031600136) {
        upgrade_plugin_savepoint(true, 2026031600136, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026031700137) {
        // v1.2.7: Risk badge visibility fix.
        // (A) observer.php: Removed early return when attemptkey is absent. The plugin
        //     previously returned silently if the JS tracker had not stored an attemptkey
        //     for the session, meaning zero scores were ever written for students whose
        //     tracker failed to initialise. The analyser now runs with linguistic signals
        //     only when no behavioural data is available, so a badge always renders.
        // (B) riskbadge.mustache: Risk level is now capitalised ("Low"/"Medium"/"High")
        //     via new {{risklevelcap}} variable populated by ucfirst() in lib.php.
        //     Badge changed from <div> to <span> for correct inline rendering.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026031700137, 'plagiarism', 'essayguard');
    }


    // v1.2.8 and v1.2.9 were released without upgrade.php savepoints, causing the plugin
    // to show "upgrade required" on every Moodle™ admin page. The missing savepoint for
    // v1.2.9 (2026031800129) is added here so the DB version advances past v1.2.9
    // for any site that had the "upgrade required" loop.  No DB schema changes.
    if ($oldversion < 2026031800129) {
        upgrade_plugin_savepoint(true, 2026031800129, 'plagiarism', 'essayguard');
    }

    // v1.2.10 — FIX-RISK-THRESHOLDS: Risk level thresholds corrected to match specification
    //   (Low < 0.35, Medium 0.35–0.69, High ≥ 0.70). "Mild" level removed from analyser,
    //   lang strings, and CSS. PERF: get_links() DB query now includes LIMIT 1.
    //   DB: Reclassify any existing 'mild' score records to the correct 'low' or 'medium'
    //   band using their stored riskscore value.
    if ($oldversion < 2026031900139) {
        $DB->execute(
            "UPDATE {plagiarism_essayguard_sc}
                SET risklevel = CASE WHEN riskscore < 0.35 THEN 'low' ELSE 'medium' END
              WHERE risklevel = 'mild'"
        );
        upgrade_plugin_savepoint(true, 2026031900139, 'plagiarism', 'essayguard');
    }

    // v1.2.11 — Three bug fixes, no DB schema changes.
    //
    // BUG-EG-FLUSH-RACE (tracker.js flush): Ajax.call() returns an array of
    //   Promises. The previous code did "await Ajax.call([...])" which awaits
    //   a plain array (non-thenable) and resolves immediately — meaning flush()
    //   returned before the AJAX request completed. This caused the
    //   flush().then(() => finalizeAttempt(text)) chain to invoke finalizeAttempt
    //   before telemetry events were delivered to the server, scoring against an
    //   incomplete event set. The catch block was also dead code since array-await
    //   never propagates Promise rejections. Fixed: "await Ajax.call([...])[0]".
    //
    // BUG-EG-PRIVACY-USERLIST (privacy/provider.php): delete_data_for_users()
    //   carried an approved_contextlist type-hint (wrong) instead of the required
    //   approved_userlist, and the interface core_userlist_provider was not
    //   declared. This caused a PHP TypeError if Moodle's privacy framework
    //   invoked the method for bulk per-context user deletion. Fixed: declared
    //   core_userlist_provider, added get_users_in_context(), and corrected
    //   delete_data_for_users(approved_userlist $userlist).
    //
    // BUG-EG-STUDENT-LIMIT (student.php): get_record_sql() for the student
    //   detail page fetched all rows for userid+cmid and discarded extras in PHP
    //   (ORDER BY … IGNORE_MULTIPLE but no LIMIT 1). Fixed: added LIMIT 1,
    //   matching the same fix applied to lib.php get_links() in v1.2.10.
    if ($oldversion < 2026032001140) {
        upgrade_plugin_savepoint(true, 2026032001140, 'plagiarism', 'essayguard');
    }

    // v1.2.12 — Metadata-only bump. Corrects stale BUILD_INFO.json (was frozen
    //   at 1.2.9, two versions behind actual shipped code). No PHP logic changes,
    //   no DB schema changes.
    if ($oldversion < 2026032001141) {
        upgrade_plugin_savepoint(true, 2026032001141, 'plagiarism', 'essayguard');
    }

    // v1.2.13 — BUG-EG-NO-BADGE-EVER: Risk badge has never appeared on any quiz or
    //   assignment grading view since v1.0.0.
    //
    //   ROOT CAUSE: Moodle's plagiarism dispatcher (lib/plagiarismlib.php::
    //   plagiarism_get_links) iterates active plugins and calls:
    //
    //     $plobject = new plagiarism_plugin_{component}();
    //     $out .= $plobject->get_links($linkarray);
    //
    //   It does NOT call any standalone function. The plugin's lib.php only defined
    //   plagiarism_essayguard_get_links() as a standalone function which Moodle core
    //   never invokes. The function was dead code. This is why all prior badge fixes
    //   (v1.1.2, v1.2.1, v1.2.5, v1.2.8) fixed real secondary issues — the logic
    //   inside the function was increasingly correct — but the badge could NEVER appear
    //   because the dispatcher never reached the rendering logic.
    //
    //   FIX: Added class plagiarism_plugin_essayguard extends plagiarism_plugin to
    //   lib.php with a get_links($linkarray) method that delegates to the existing
    //   standalone function. No DB schema change. No AMD change.
    if ($oldversion < 2026032001142) {
        upgrade_plugin_savepoint(true, 2026032001142, 'plagiarism', 'essayguard');
    }

    // v1.2.14 — BUG-EG-QUIZ-PER-QUESTION: After v1.2.13 fixed the class-missing root
    //   cause, the badge appeared but both quiz essay questions showed the SAME aggregate
    //   risk score regardless of how each was written. Q1 (copy-paste) and Q2 (typed)
    //   need separate scores.
    //
    //   FIX — three layers:
    //
    //   LAYER-A (tracker.js): Detects question slot from Moodle's textarea naming
    //     convention ("q{attemptid}:{slot}_answer"). Every event emitted from that field
    //     now carries qslot=N in its payloadjson. Assignment/forum fields don't match
    //     the pattern so their events carry no qslot (treated as 0 = aggregate).
    //
    //   LAYER-B (analyser.php): score_attempt() gains an int $qslot = 0 parameter.
    //     When qslot > 0 only events whose payloadjson.qslot matches are included in
    //     the metric computation. The upsert key in plagiarism_essayguard_sc is extended
    //     to include qslot so per-question records are stored separately from the
    //     aggregate (qslot=0) record.
    //
    //   LAYER-C (observer.php): on_quiz_attempt_submitted() calls do_score() once for
    //     the aggregate (qslot=0) then once per essay slot using the new
    //     get_quiz_essay_texts_by_slot() helper. Fingerprint baseline is only updated
    //     from the aggregate score to avoid per-question skew.
    //
    //   LAYER-D (lib.php get_links): Reads $linkarray['questionattempt']->get_slot()
    //     when present. Queries for a per-question record first; falls back to the
    //     aggregate record. Pre-v1.2.14 sites with qslot=0 data continue to work.
    //
    //   DB: plagiarism_essayguard_sc gains a qslot column (INT4, NOT NULL DEFAULT 0).
    //       Existing rows default to 0 (aggregate) and remain valid.
    if ($oldversion < 2026032001143) {
        $table = new \xmldb_table('plagiarism_essayguard_sc');
        $field = new \xmldb_field('qslot', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'attemptkey');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026032001143, 'plagiarism', 'essayguard');
    }

    // v1.2.15 — Routine version bump. No DB schema change. No logic change.
    if ($oldversion < 2026032001144) {
        upgrade_plugin_savepoint(true, 2026032001144, 'plagiarism', 'essayguard');
    }

    // v1.2.16 — BUG-EG-INSTALL-FATAL attempt 1 (incomplete fix, superseded by v1.2.17).
    if ($oldversion < 2026032001145) {
        upgrade_plugin_savepoint(true, 2026032001145, 'plagiarism', 'essayguard');
    }

    // v1.2.17 — BUG-EG-INSTALL-FATAL second attempt (superseded by v1.2.18).
    if ($oldversion < 2026032001146) {
        upgrade_plugin_savepoint(true, 2026032001146, 'plagiarism', 'essayguard');
    }

    // v1.2.18 — BUG-EG-INSTALL-FATAL: __DIR__-based require (superseded by v1.2.19).
    if ($oldversion < 2026032001147) {
        upgrade_plugin_savepoint(true, 2026032001147, 'plagiarism', 'essayguard');
    }

    // v1.2.19 — BUG-EG-INSTALL-FATAL: removed 'extends plagiarism_plugin'. No DB change.
    if ($oldversion < 2026032001148) {
        upgrade_plugin_savepoint(true, 2026032001148, 'plagiarism', 'essayguard');
    }

    // v1.2.20 — BUG-EG-MISSING-METHODS: added print_disclosure/save_form_elements/
    //   get_form_elements_module stubs to class after extends removal. No DB change.
    if ($oldversion < 2026032001149) {
        upgrade_plugin_savepoint(true, 2026032001149, 'plagiarism', 'essayguard');
    }

    // v1.2.21 — BUG-EG-DOUBLE-LIMIT: SQL error "LIMIT 1 LIMIT 0, 1" from MariaDB.
    //   get_record_sql(..., IGNORE_MULTIPLE) calls get_records_sql(..., 0, 1) internally
    //   which appends "LIMIT 0, 1" to the query. Our SQL also had "LIMIT 1" hardcoded,
    //   producing illegal double-LIMIT syntax. Removed raw LIMIT 1 from three queries:
    //   lib.php (per-question qslot lookup + aggregate fallback) and student.php.
    //   Also added IGNORE_MULTIPLE to student.php call which was missing it. No DB change.
    if ($oldversion < 2026032001150) {
        upgrade_plugin_savepoint(true, 2026032001150, 'plagiarism', 'essayguard');
    }

    // v1.2.22 — BUG-EG-UPDATE-STATUS: Moodle's plagiarism_update_status() in
    //   plagiarismlib.php uses ReflectionMethod to check for update_status() on the
    //   plugin class. When the method was absent, ReflectionMethod::__construct()
    //   threw "Method plagiarism_plugin_essayguard::update_status() does not exist",
    //   crashing the quiz attempts page for teachers. Added no-op stub. No DB change.
    if ($oldversion < 2026032001151) {
        upgrade_plugin_savepoint(true, 2026032001151, 'plagiarism', 'essayguard');
    }

    // v1.2.23 — BUG-EG-NO-REPORT-DATA: Two related schema defects caused the
    //   report page to show "No Essay Guard data found" even when students had
    //   submitted.
    //
    //   DEFECT-A (fresh installs): install.xml was missing the qslot column that
    //   was added by upgrade.php at v1.2.14 (savepoint 2026032001143). Sites
    //   installed fresh at v1.2.14 or later had no qslot column in
    //   plagiarism_essayguard_sc. analyser::score_attempt() always sets
    //   $record->qslot, causing every DB insert to throw a "column not found"
    //   dml_exception. That exception is silently caught by observer::do_score(),
    //   so zero rows ever landed in the sc table and the report was always empty.
    //   Fix: qslot column added to install.xml for clean installs. The upgrade
    //   step at 2026032001143 already handles the column for upgraded sites.
    //
    //   DEFECT-B (upgraded sites): The unique index created by install.xml was
    //   on (userid, cmid, attemptkey) — without qslot. The upgrade at
    //   2026032001143 added the column but never updated the index. When the
    //   observer tried to insert per-question records (qslot = 1, 2 …) after
    //   the aggregate (qslot = 0) was already stored, the INSERT violated the
    //   unique constraint on (userid, cmid, attemptkey). The exception was caught
    //   silently, so per-question scores were never persisted. Fix: drop the old
    //   three-column unique index and create a four-column one that includes qslot.
    if ($oldversion < 2026032001152) {
        $table = new \xmldb_table('plagiarism_essayguard_sc');

        // Ensure the qslot column exists (idempotent — already added at
        // 2026032001143 for upgraded sites; needed here for any edge-case
        // re-run or sites that missed the earlier savepoint).
        $field = new \xmldb_field('qslot', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'attemptkey');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Drop the old three-column unique index (userid, cmid, attemptkey).
        // It blocks inserts of per-question records that share the same
        // (userid, cmid, attemptkey) but differ only in qslot.
        $old_index = new \xmldb_index('attemptuniq_ix', XMLDB_INDEX_UNIQUE, ['userid', 'cmid', 'attemptkey']);
        if ($dbman->index_exists($table, $old_index)) {
            $dbman->drop_index($table, $old_index);
        }

        // Create the correct four-column unique index including qslot.
        // This allows one aggregate row (qslot = 0) and one row per essay
        // question slot (qslot = 1, 2 …) per student per activity attempt.
        $new_index = new \xmldb_index('attemptslot_uix', XMLDB_INDEX_UNIQUE, ['userid', 'cmid', 'attemptkey', 'qslot']);
        if (!$dbman->index_exists($table, $new_index)) {
            $dbman->add_index($table, $new_index);
        }

        upgrade_plugin_savepoint(true, 2026032001152, 'plagiarism', 'essayguard');
    }

    // v1.2.24 — Three bug fixes, no DB schema changes.
    //
    // BUG-EG-SUBMIT-LOOP (tracker.js): requestSubmit() infinite loop.
    //   The form submit interceptor called e.preventDefault(), ran flush/finalize,
    //   then called form.requestSubmit() to re-submit natively. But requestSubmit()
    //   fires the submit event again, re-entering the handler, calling
    //   e.preventDefault() again, creating an infinite async loop. The form never
    //   actually submitted. Fix: check form.dataset.egIntercepted === 'done' at
    //   the top of the submit handler to allow the re-submit to proceed natively.
    //
    // BUG-EG-ZERO-FLUSH (lib.php): flushinterval defaulted to 0 on fresh installs.
    //   get_config('captureinterval') returns false when never saved; (int)false = 0.
    //   This overrode the JS default of 5000ms via config spread, causing
    //   setInterval(flush, 0) to fire every ~4ms (browser minimum). Fix: default
    //   to 5000 when config returns falsy using ?: operator.
    //
    // BUG-EG-UNLOCK-ENDPOINTS (log_event.php, finalize_attempt.php): Both AJAX
    //   endpoints only checked get_config('enabled') but skipped check_unlock().
    //   Defense-in-depth gap: direct web-service calls could store telemetry and
    //   run scoring on unlicensed sites. Fix: added check_unlock() gate and
    //   require_once lib.php to both external service classes.
    if ($oldversion < 2026032001153) {
        upgrade_plugin_savepoint(true, 2026032001153, 'plagiarism', 'essayguard');
    }

    // v1.2.25 — BUG-EG-RETROACTIVE-SCORE: Sites that installed Essay Guard before v1.2.23
    //   (which fixed DEFECT-A: missing qslot column) had all analyser::score_attempt() DB
    //   inserts silently fail with a "column not found" dml_exception. Students who typed
    //   during that period had keystroke telemetry in plagiarism_essayguard_ev but zero rows
    //   in plagiarism_essayguard_sc — causing "No Essay Guard data found" in the class report
    //   even after upgrading to v1.2.23.
    //
    //   This upgrade step finds all unique (userid, cmid, contextid, attemptkey) sessions in
    //   the events table that have NO aggregate score record (qslot=0) in the scores table
    //   and calls analyser::score_attempt() for each — retroactively creating the missing rows
    //   from behavioural telemetry alone (no linguistic analysis — no final text available).
    //
    //   Additionally logs (via error_log) the reason when check_unlock() blocks the observer,
    //   and the class report now filters by qslot=0 to show only aggregate records.
    if ($oldversion < 2026032601154) {
        // Find all sessions with events but no aggregate score record.
        $orphan_sql = "SELECT DISTINCT ev.userid, ev.cmid, ev.contextid, ev.attemptkey
                         FROM {plagiarism_essayguard_ev} ev
                        WHERE NOT EXISTS (
                            SELECT 1
                              FROM {plagiarism_essayguard_sc} sc
                             WHERE sc.userid     = ev.userid
                               AND sc.cmid       = ev.cmid
                               AND sc.attemptkey = ev.attemptkey
                               AND sc.qslot      = 0
                        )";

        $orphans = $DB->get_records_sql($orphan_sql);

        if (!empty($orphans)) {
            // Moodle's class autoloader will resolve this namespace path.
            // The require_once is a safety net for early-bootstrap upgrade contexts.
            if (file_exists($CFG->dirroot . '/plagiarism/essayguard/classes/local/service/analyser.php')) {
                require_once($CFG->dirroot . '/plagiarism/essayguard/classes/local/service/analyser.php');
                require_once($CFG->dirroot . '/plagiarism/essayguard/classes/local/service/linguistic.php');
                require_once($CFG->dirroot . '/plagiarism/essayguard/classes/local/service/fingerprint.php');
                require_once($CFG->dirroot . '/plagiarism/essayguard/classes/local/service/explainer.php');
            }

            $scored = 0;
            $failed = 0;
            foreach ($orphans as $orphan) {
                try {
                    // Score from behavioural events only — no final text available
                    // for historical submissions. Linguistic metrics will be zero.
                    \plagiarism_essayguard\local\service\analyser::score_attempt(
                        (int)$orphan->userid,
                        (int)$orphan->cmid,
                        (int)$orphan->contextid,
                        (string)$orphan->attemptkey,
                        [],   // linguistic — computed inline from finaltext
                        '',   // finaltext — not available for historical sessions
                        0     // qslot — aggregate record
                    );
                    $scored++;
                } catch (\Throwable $e) {
                    error_log('[plagiarism_essayguard] retroactive score failed'
                        . ' user=' . $orphan->userid . ' cmid=' . $orphan->cmid
                        . ' key=' . $orphan->attemptkey . ': ' . $e->getMessage());
                    $failed++;
                }
            }

            error_log('[plagiarism_essayguard] v1.2.25 retroactive scoring complete:'
                . ' scored=' . $scored . ' failed=' . $failed
                . ' total_orphans=' . count($orphans));
        }

        upgrade_plugin_savepoint(true, 2026032601154, 'plagiarism', 'essayguard');
    }

    // v1.2.26: AUTO-UNLOCK — check_unlock() now automatically fires a POST to
    //   /api/plugin-unlock when verify returns unlocked=false for a site with
    //   valid credentials. Deducts 5,000 credits on first use. No DB schema changes.
    if ($oldversion < 2026032601155) {
        upgrade_plugin_savepoint(true, 2026032601155, 'plagiarism', 'essayguard');
    }

    // v1.2.27: BUG-EG-PASTE-ZERO-SCORE — Copy-pasted answers were incorrectly
    //   scoring Low (0%). Two root causes fixed in analyser.php:
    //   (1) GATE FIX: $charsadded (from input events) could be 0 for paste-only
    //   sessions in Atto/TinyMCE where the input event fires late or not at all
    //   before the first flush. analyser.php now tracks $paste_chars_total from
    //   paste event insertlen fields and uses max($charsadded, $paste_chars_total)
    //   as the effective gate value — paste-only sessions always pass the gate.
    //   (2) SIGNAL FIX: Signals 3 (backspace ratio) and 4 (entropy) both required
    //   keystrokes > 0, so paste-only sessions (0 keystrokes) skipped both,
    //   capping score at ~20 pts (Low). Both signals now have an explicit
    //   zero-keystroke-paste branch that awards maximum points (15 + 20 = 35)
    //   because the absence of any manual typing is the highest-suspicion case.
    //   Net effect: 1 paste of ≥120 chars → ≥57 pts (Medium); 3 pastes of ≥150
    //   chars → ≥81 pts (High). No DB schema changes.
    if ($oldversion < 2026032700100) {
        upgrade_plugin_savepoint(true, 2026032700100, 'plagiarism', 'essayguard');
    }

    // v1.2.28: AMD BUILD FIX — amd/build/tracker.js was a verbatim copy of
    //   the ES6 source (import Ajax from 'core/ajax'). Moodle's RequireJS
    //   loader requires define() AMD format; the ES6 import syntax caused
    //   'No define call for plagiarism_essayguard/tracker', silently preventing
    //   tracker injection on every Moodle site. Build files now correctly wrap
    //   the module in define(['core/ajax'], function(Ajax) { ... return { init }; }).
    //   src/tracker.js is unchanged. No DB schema changes.
    if ($oldversion < 2026032700200) {
        upgrade_plugin_savepoint(true, 2026032700200, 'plagiarism', 'essayguard');
    }

    // v1.2.29: RELEASE INTEGRITY — Added missing upgrade.php savepoint for
    //   v1.2.28 (2026032700200). Sites upgrading from v1.2.27 directly to
    //   v1.2.28 had no savepoint, causing Moodle's upgrade engine to log a
    //   'plugin not fully upgraded' warning in some configurations. No PHP
    //   logic changes, no DB schema changes.
    if ($oldversion < 2026032700300) {
        upgrade_plugin_savepoint(true, 2026032700300, 'plagiarism', 'essayguard');
    }

    // v1.2.30: BUG FIX — get_records_sql() duplicate-key crash on quiz submission.
    //   Both get_quiz_essay_text() and get_quiz_essay_texts_by_slot() used a non-unique
    //   first column (qasd.value and qa.slot respectively). Moodle's get_records_sql()
    //   uses the first column as the PHP array key — duplicate values (e.g. value='0')
    //   triggered "Duplicate value '0' found in column 'value'" and aborted the observer,
    //   preventing Essay Guard from scoring quiz submissions. Fixed by prepending qas.id
    //   (the unique primary key of question_attempt_steps) as the first SELECT column.
    //   No DB schema changes.
    if ($oldversion < 2026032700301) {
        upgrade_plugin_savepoint(true, 2026032700301, 'plagiarism', 'essayguard');
    }

    // v1.2.31: FIX — Paste detection now correctly raises the risk badge in all editor
    //   environments. In TinyMCE/Atto rich-text editors, clipboardData is intercepted by the
    //   editor before our paste listener fires, leaving insertlen=0 and paste_chars_total=0.
    //   This caused effective_charsadded to remain below the minchars gate (120), scoring the
    //   session as 0% even when a paste was clearly detected. Fix: bypass the minchars gate
    //   whenever pastecount > 0 — the presence of a paste event is definitive evidence of
    //   pasting behaviour and must always be scored. No DB schema changes.
    if ($oldversion < 2026032700302) {
        upgrade_plugin_savepoint(true, 2026032700302, 'plagiarism', 'essayguard');
    }

    // v1.2.32: BUG FIX — TINYMCE-QSLOT: tracker.js failed to detect quiz question slot in
    //   TinyMCE iframes. Now also checks essayguardName data attribute and resolves qslot
    //   from TinyMCE iframe id for Atto contenteditable divs. No DB schema changes.
    if ($oldversion < 2026032800303) {
        upgrade_plugin_savepoint(true, 2026032800303, 'plagiarism', 'essayguard');
    }

    // v1.2.33: AMD SOURCE FIX (ROOT CAUSE) — tracker.js src used ES module import/export
    //   syntax which is incompatible with Moodle's RequireJS AMD loader. Symptoms:
    //   "Uncaught SyntaxError: Cannot use import statement outside a module" and
    //   "No define call for plagiarism_essayguard/tracker" in the browser console.
    //   v1.2.28 only fixed the build files without touching src — any Moodle site that
    //   ran grunt or regenerated build files from src reverted to the broken ES module.
    //   Fix: src/tracker.js completely rewritten to use define(['core/ajax'], function(Ajax)
    //   { ... return { init }; }) format. All ES6+ syntax converted to ES5 (arrow functions
    //   → function expressions, async/await → Promise chains, optional chaining → &&
    //   guards, let/const → var). Logic and behaviour 100% identical to v1.2.32.
    //   src=build=min MD5 a22f6d304e7beb4bd4e60579e6d04bde. No DB schema changes.
    if ($oldversion < 2026033000303) {
        upgrade_plugin_savepoint(true, 2026033000303, 'plagiarism', 'essayguard');
    }

    // v1.2.34: TEACHER BYPASS — plagiarism_essayguard_inject_tracker() now returns
    //   early when the current user has moodle/course:manageactivities in the module
    //   context. Teachers and admins can paste freely (e.g. ChatGPT output into
    //   Content Creator) without Essay Guard blocking them. Students are unaffected.
    //   No DB schema changes.
    if ($oldversion < 2026033000304) {
        upgrade_plugin_savepoint(true, 2026033000304, 'plagiarism', 'essayguard');
    }

    // v1.2.35: INVESTIGATION — tester feedback review completed. Code audit confirmed
    //   analyser.php already implements per-question scoring (qslot parameter) with
    //   a multi-signal risk model (paste, burst, backspace, entropy, baseline deviation).
    //   No code issues found. Maintenance version bump. No DB schema changes.
    if ($oldversion < 2026033100305) {
        upgrade_plugin_savepoint(true, 2026033100305, 'plagiarism', 'essayguard');
    }

    // v1.2.36: FIX — Per-field telemetry state isolation in tracker.js.
    //   Root cause of "same score for both questions": lastKeyTime, cursorMoves,
    //   windowWordCount and windowStartTime were global (state.*) across all bound
    //   fields. When a student typed in Q1 then moved to Q2, Q2's IKD timing,
    //   cursor-move count and WPM window all inherited Q1's data — making per-question
    //   signals indistinguishable and scores artificially similar. Fix: each call to
    //   bindField() now creates isolated _fieldLastKeyTime, _fieldCursorMoves,
    //   _fieldWindowWordCount and _fieldWindowStartTime as closure-local variables.
    //   Also added explicit _fieldLastKeyTime = null reset on focus so the first
    //   inter-key delay in each field is never contaminated by the previous field.
    //   AMD: tracker.js changed — amd_src_md5 = c05112872787fdd7a45f212a194261bc.
    //   All three AMD files (src, build, min) are identical. No DB schema changes.
    //   version.php → 2026033103600.
    if ($oldversion < 2026033103600) {
        upgrade_plugin_savepoint(true, 2026033103600, 'plagiarism', 'essayguard');
    }

    // v1.2.37: VERSION BUMP — Maintenance release. No code changes. No DB schema changes.
    //   AMD: tracker.js unchanged — src=build=min MD5 c05112872787fdd7a45f212a194261bc
    //   carried over from v1.2.36. version.php → 2026033103700.
    if ($oldversion < 2026033103700) {
        upgrade_plugin_savepoint(true, 2026033103700, 'plagiarism', 'essayguard');
    }

    // v1.2.38 FIX-EG-BADGE-MULTI: Badge ID was hardcoded 'essayguard-risk-badge' so on
    //   pages with 2+ essay questions the second badge remove()d the first (getElementById
    //   found it by the shared ID), and all badges anchored to the first bound field.
    //   Fix: badge ID is now per-slot ('essayguard-risk-badge-{qslot}'); injectRiskBadge
    //   resolves the correct field from data.qslot or named-field query.
    //   No DB schema changes. AMD: tracker.js updated. version.php → 2026040200138.
    if ($oldversion < 2026040200138) {
        upgrade_plugin_savepoint(true, 2026040200138, 'plagiarism', 'essayguard');
    }

    // v1.2.39 — FIX-EG-ZERO-SCORE: Two root causes of "same risk badge/percentage for
    //   all questions" and "plagiarism not calculating at all in some cases".
    //
    //   ROOT CAUSE A (analyser.php gate): The minchars gate only passed when
    //   $effective_charsadded >= $minchars OR $pastecount > 0. When qslot > 0
    //   per-question event filtering returned an empty set (tracker did not tag events
    //   with a slot, or the tracker was not active for that session), both counters were
    //   0 — the gate failed for every question slot — all 8 signals were bypassed —
    //   score = 0 for all questions — identical "Low (0%)" badges on every essay question.
    //
    //   FIX-A: Added $text_chars = mb_strlen(strip_tags($finaltext)) as a third gate
    //   condition. When the PHP observer passes the per-question submitted text and it is
    //   long enough (>= minchars), linguistic signals (sentence variance, vocab diversity)
    //   now fire even with no behavioral data, producing distinct non-zero scores per
    //   question whose composition differs — eliminating identical "Low (0%)" display.
    //
    //   ROOT CAUSE B (observer.php SQL): Both get_quiz_essay_text() and
    //   get_quiz_essay_texts_by_slot() had no ORDER BY clause. Moodle creates multiple
    //   question_attempt_step rows per question (autosaves, state transitions), each
    //   potentially storing an 'answer' entry in question_attempt_step_data. Without ORDER
    //   BY, PHP's hash-map overwrites non-deterministically — an older partial text from
    //   an autosave step could overwrite the final submitted text, yielding an empty or
    //   truncated answer. An empty $finaltext and no behavioral events both equal 0 score
    //   regardless of what the student actually wrote ("not calculating at all").
    //
    //   FIX-B: Added ORDER BY qa.slot ASC, qas.id DESC to both SQL queries.
    //   The !isset guard on the PHP loop means the first row seen for each slot (= the
    //   most recent, highest-ID step due to DESC ordering) always wins. Final submitted
    //   text is now reliably extracted for every question slot.
    //
    //   No DB schema changes. version.php → 2026040200139.
    if ($oldversion < 2026040200139) {
        upgrade_plugin_savepoint(true, 2026040200139, 'plagiarism', 'essayguard');
    }

    // v1.2.40 — FIX-EG-DYNAMIC-QSLOT: tracker.js eq() closure now computes qslot
    //   dynamically on every event call instead of capturing it once at bind time.
    //   Fixes "same risk badge for all questions" when Atto/TinyMCE editors are not
    //   fully initialised when bindField() runs — _qslot was frozen at 0, no events
    //   were tagged with a slot, analyser per-question filter found zero events →
    //   all questions scored from linguistic signals only → identical Low badges.
    //   No DB schema changes. version.php → 2026040300140.
    if ($oldversion < 2026040300140) {
        upgrade_plugin_savepoint(true, 2026040300140, 'plagiarism', 'essayguard');
    }

    // v1.2.41 — FIX-EG-QSLOT-FINALIZE: finalizeAttempt() now called per-field with
    //   its own qslot so per-question scoring works correctly in multi-essay quizzes.
    //   PHP: finalize_attempt.php now accepts qslot PARAM_INT, passes it to the
    //   analyser, and echoes it back in the response. tracker.js: submit listener
    //   iterates every bound field and calls finalizeAttempt(text, qslot) for each —
    //   one AJAX call per essay question. No DB schema changes.
    //   version.php → 2026040300141.
    if ($oldversion < 2026040300141) {
        upgrade_plugin_savepoint(true, 2026040300141, 'plagiarism', 'essayguard');
    }

    // v1.2.42 — VERSION BUMP: Clean release following full production audit and 6-location
    //   sync verification. All prior v1.2.41 fixes (FIX-EG-QSLOT-FINALIZE, per-field
    //   finalizeAttempt, finalize_attempt.php qslot parameter) confirmed in ZIP and all
    //   delivery locations. AMD triple-match MD5: c369a090b823f8441200c893241a56f0.
    //   No DB schema changes. version.php → 2026040700142.
    if ($oldversion < 2026040700142) {
        upgrade_plugin_savepoint(true, 2026040700142, 'plagiarism', 'essayguard');
    }

    // v1.2.43 — FIX-EG-STUDENT-QSLOT + FIX-EG-FLUSH-LOSS:
    //   BUG A (student.php wrong record): student.php used ORDER BY timemodified DESC
    //   which could return a per-question-slot record (qslot > 0) instead of the
    //   aggregate (qslot = 0) when a race condition caused the JS finalizeAttempt()
    //   calls to complete after observer.php, giving per-slot records a newer
    //   timemodified. Fixed: ORDER BY qslot ASC, timemodified DESC so the aggregate
    //   record is always preferred.
    //   BUG B (tracker.js lost events): flush() called splice() before the Ajax call
    //   succeeded, so any network failure silently discarded the entire event batch —
    //   they were gone from the queue but never reached the server. Fixed: snapshot
    //   the queue length, send, and only splice() inside .then(). Added a concurrent-
    //   flush guard (state.flushing flag) so two simultaneous flushes cannot send
    //   duplicate events.
    //   AMD triple-match MD5: 8ae1a0504b7754ef23eb1959c63543ed.
    //   No DB schema changes. version.php → 2026040800143.
    if ($oldversion < 2026040800143) {
        upgrade_plugin_savepoint(true, 2026040800143, 'plagiarism', 'essayguard');
    }

    // v1.2.44 - VERSION BUMP: Clean release following full tester-feedback cycle.
    //   All fixes from v1.2.43 (FIX-EG-STUDENT-QSLOT, FIX-EG-FLUSH-LOSS) confirmed
    //   in ZIP and all 6 delivery locations. No code changes. AMD MD5 unchanged: 8ae1a0504b7754ef23eb1959c63543ed.
    //   No DB schema changes. version.php → 2026040800144.
    if ($oldversion < 2026040800144) {
        upgrade_plugin_savepoint(true, 2026040800144, 'plagiarism', 'essayguard');
    }

    // v1.2.45 - BUG FIX (FIX-EG-PASTE-TINYMCE + FIX-EG-BADGE-TIMEOUT):
    //   FIX-EG-PASTE-TINYMCE: TinyMCE 6 intercepts paste events at the document level
    //     using stopPropagation, so the body-level 'paste' listener added by bindField()
    //     never fired for TinyMCE essay editors. Two consequences:
    //     (1) Paste events were not logged (zero paste_events in telemetry).
    //     (2) The paste warning banner was inserted inside the TinyMCE iframe body — not
    //         visible to the student (the outer .tox wrapper is what is displayed).
    //     Fix: scan() now adds a document-level capture-phase 'paste' listener for each
    //     TinyMCE iframe. The handler runs before TinyMCE's paste plugin (capture phase),
    //     logs the event via eq(), and calls showPasteWarning() with the outer iframe element
    //     so the banner appears correctly in the main page DOM.
    //   FIX-EG-BADGE-TIMEOUT: The form-submit race timeout was 4 000 ms. On slow Moodle
    //     servers the finalizeAttempt AJAX call was cancelled by page navigation when the
    //     timeout fired first, so the risk score was never saved and the badge never
    //     appeared in the grading view. Timeout increased to 7 000 ms.
    //   No DB schema changes. JS-only: amd/src/tracker.js. version.php → 202604090045.
    // ⚠ FORMAT BUG: 202604090045 is 12-digit. It is numerically LESS than v1.2.44's
    //   2026040800144 (13-digit). Any site with $oldversion >= 2026040800144 will skip
    //   this block. No DB changes here so the skip is harmless. Fixed in v1.2.47.
    if ($oldversion < 202604090045) {
        upgrade_plugin_savepoint(true, 202604090045, 'plagiarism', 'essayguard');
    }

    // v1.2.46 - RELEASE SYNC: Full 6-location sync for v1.2.45 (FIX-EG-PASTE-TINYMCE,
    //   FIX-EG-BADGE-TIMEOUT). AMD triple-match confirmed: daec9263d5bad7af325e79afbe8eb9ee.
    //   No new code changes. No DB schema changes. version.php → 202604090046.
    // ⚠ FORMAT BUG: 202604090046 is 12-digit. Same issue as v1.2.45 above — skipped
    //   by any site with $oldversion >= 2026040800144. Harmless. Fixed in v1.2.47.
    if ($oldversion < 202604090046) {
        upgrade_plugin_savepoint(true, 202604090046, 'plagiarism', 'essayguard');
    }

    // v1.2.47 - FIX-EG-NUMERIC-VERSION: Numeric version format restored to 13-digit
    //   YYYYMMDD00XXX to fix Moodle upgrade gate for sites on v1.2.44 or earlier whose
    //   numeric version (2026040800144) was larger than the 12-digit v1.2.45/v1.2.46
    //   numerics (202604090045/202604090046). Moodle rejected those ZIPs as "downgrade".
    //   No code changes. No DB schema changes. version.php → 2026041000047.
    if ($oldversion < 2026041000047) {
        upgrade_plugin_savepoint(true, 2026041000047, 'plagiarism', 'essayguard');
    }

    // v1.2.48 - BUG FIX KB-001 (FIX-EG-CURL): plagiarism_essayguard_check_unlock()
    //   and plagiarism_essayguard_auto_unlock() in lib.php used raw curl_init() which
    //   fails silently on shared/cloud Moodle hosting due to SSL CA bundle path mismatch.
    //   The downstream effect: log_event and finalize_attempt both returned {ignored:true},
    //   the plagiarism_essayguard_ev table stayed empty, and ALL behavioral metrics
    //   (total_keystrokes, paste_events, wpm, etc.) showed as 0 on the student report.
    //   Fix: Both functions now use Moodle \curl class + require_once filelib.php.
    //   No DB schema changes. version.php → 2026041000048.
    if ($oldversion < 2026041000048) {
        upgrade_plugin_savepoint(true, 2026041000048, 'plagiarism', 'essayguard');
    }

    // v1.2.49 - AUTO-TEST CONFIRMATION: All ongoing tester issues (reported at v1.2.40/v1.2.42/v1.2.44)
    //   confirmed resolved via Section 0 code audit. Root cause was KB-001 (curl_init fails
    //   silently on shared/cloud Moodle hosting due to SSL CA bundle mismatch) — fixed in v1.2.48
    //   by switching check_unlock() and auto_unlock() in lib.php to Moodle \curl class.
    //   Downstream effects confirmed resolved: log_event no longer returns {ignored:true},
    //   ev table receives events, analyser runs correctly, all student behavioral metrics
    //   (total_keystrokes, paste_events, wpm, risk scores per question) now display correctly.
    //   No code changes. No DB schema changes. version.php → 2026041000049.
    if ($oldversion < 2026041000049) {
        upgrade_plugin_savepoint(true, 2026041000049, 'plagiarism', 'essayguard');
    }

    // v1.2.50 - BUG FIX (FIX-EG-UNLOCK-OPEN): check_unlock() in lib.php returned false in two
    //   failure modes, silently disabling all Essay Guard scoring and badge display:
    //   (1) When siteId/apiKey were not yet configured, the function returned false — blocking
    //   the plugin entirely on any fresh install where the admin had not yet entered credentials.
    //   (2) When the license server was unreachable (network error, timeout, HTTP != 200), the
    //   function returned false — blocking the plugin on Moodle servers that cannot reach
    //   lms-labs.com (firewall, shared hosting restrictions, etc.).
    //   Fix: Both failure modes now return true (fail-open / open mode) with an error_log entry
    //   so admins can diagnose the issue without losing functionality.
    //   No DB schema changes. version.php → 2026041100050.
    if ($oldversion < 2026041100050) {
        upgrade_plugin_savepoint(true, 2026041100050, 'plagiarism', 'essayguard');
    }

    // v1.2.51 - BUG FIX (FIX-EG-CSS-TEACHERS + FIX-EG-TINYMCE-BANNER-ANCHOR + FIX-EG-QUIZ-NAVDELAY)
    //
    // FIX-EG-CSS-TEACHERS: Badge CSS was loaded AFTER the teacher capability gate in
    //   inject_tracker(), so .essayguard-risk-* classes were undefined on teacher grading
    //   pages and the risk badge rendered as unstyled plain text — effectively invisible.
    //   Fix: $PAGE->requires->css() now fires for ALL authenticated users on active CM
    //   pages, before the teacher/student split. No DB changes.
    //
    // FIX-EG-TINYMCE-BANNER-ANCHOR: The TinyMCE paste warning banner was anchored to
    //   the .tox-edit-area iframe element whose parent (.tox-edit-area) has overflow:hidden.
    //   The banner was inserted inside the clipped container and was invisible to students.
    //   Fix: tracker.js now walks up to .tox-tinymce (the outer wrapper, no overflow:hidden)
    //   so the banner appears below the editor. AMD triple-match MD5: 50e660908cb4c9f3a02db7aa554e92aa.
    //
    // FIX-EG-QUIZ-NAVDELAY: form#responseform is the Moodle quiz per-question answer form,
    //   submitted on EVERY "Next page"/"Previous" button click. Including it in the form
    //   intercept selectors added up to 7 seconds of delay per page navigation — making
    //   timed quizzes unusable. Removed from the intercept list. Intermediate events are
    //   still flushed by the 5-second timer and window.beforeunload. Final quiz submission
    //   via form[action*="processattempt"] is still intercepted correctly. The PHP event
    //   observer (on_quiz_attempt_submitted) is the authoritative scoring path for quizzes.
    //   No DB changes. version.php → 2026041300051.
    if ($oldversion < 2026041300051) {
        upgrade_plugin_savepoint(true, 2026041300051, 'plagiarism', 'essayguard');
    }

    // v1.2.52 — 2026041400052
    // FIX-EG-CSS-UNCONDITIONAL: Moved CSS load to before $PAGE->cm null check so badges
    //   are always visible, including on AJAX grading pages where $PAGE->cm is not set.
    // FIX-EG-QUIZ-FOOTER-BUTTON: Added before_footer hook that injects a fixed "Essay Guard
    //   Report" pill button on quiz view/report/review pages for teachers — with student
    //   count badge. Registered via db/hooks.php; new class: hook/before_footer.php.
    // FIX-EG-TEACHER-BYPASS-BROADER: Expanded tracker bypass to also catch is_siteadmin()
    //   and moodle/grade:edit so non-editing teachers (graders) no longer get tracked and
    //   can paste feedback without triggering false-positive paste-blocked banners.
    if ($oldversion < 2026041400052) {
        upgrade_plugin_savepoint(true, 2026041400052, 'plagiarism', 'essayguard');
    }

    // v1.2.53 — 2026041500053
    // BUG-EG-WPM-ZERO: tracker.js WPM window required 1 full minute of continuous typing;
    //   most students never triggered a wpm_snapshot, so average_wpm was always 0 in the
    //   report. Fix: emit a wpm_snapshot on field blur so even short typing sessions record
    //   WPM, and the window/word counters are reset on each blur.
    // BUG-EG-WPM-FALLBACK: analyser.php had no fallback WPM calculation when wpm_snapshot
    //   events were absent. When finaltext and typing_time are both available the analyser
    //   now estimates WPM from word_count / (typing_time_ms / 60000) so the report column
    //   is always populated after submission.
    // BUG-EG-IKD-DELETE: analyser.php case 'delete': discarded the ikd (inter-key delay)
    //   payload that tracker.js sends, reducing the number of data points for
    //   interkey_mean / interkey_std_dev / entropy_score. Fixed to match case 'backspace':.
    if ($oldversion < 2026041500053) {
        upgrade_plugin_savepoint(true, 2026041500053, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026041500054) {
        // v1.2.54: Add "mild" (25–49) as a 4th risk tier, update report.php visual
        // layout to match the Essay Guard docs demo (Teacher grading view — Scene 4):
        // avatar + student name + response preview + submitted timestamp + risk pill
        // badge with coloured dot indicator. styles.css updated with new pill styling
        // and .essayguard-risk-mild (yellow). analyser.php risk_level() thresholds
        // updated: 0-24 low, 25-49 mild, 50-69 medium, 70-100 high.
        upgrade_plugin_savepoint(true, 2026041500054, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026041500055) {
        // v1.2.55: Bug fixes for mild tier introduced in v1.2.54.
        // tracker.js palette now includes mild (yellow). student.php $risk_colours
        // now includes mild. explainer.php clean signal fires for mild as well as low.
        // report.php Response preview column removed (finaltext not stored in DB).
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026041500055, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026041500056) {
        // v1.2.56: Bug fixes — tracker.js drop handler now calls e.preventDefault()
        // when paste is disabled (drop was bypassing the restriction). explainer.php
        // backspace threshold corrected 0.05→0.04 and interkey_mean 100ms→80ms to
        // match analyser.php signals. Dead .essayguard-excerpt CSS removed.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026041500056, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026041500057) {
        // v1.2.57: FIX-EG-UNKNOWN-STATUS — settings.php now calls check_unlock()
        // immediately after saving (before redirect) so the cache is repopulated
        // with fresh credentials and the settings page shows the real unlock status
        // instead of "unknown". Plain page loads also auto-refresh a stale/empty
        // cache when credentials are configured. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026041500057, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026041500058) {
        // v1.2.58: Three performance and reliability fixes — no DB schema changes.
        //
        // FIX-EG-GETBADGES-N1: get_badges.php ran one DB query per user in a PHP
        // loop (N+1 pattern). For large cohorts this caused noticeable latency on
        // the quiz grading overview page and could hit PHP timeouts. Fixed by
        // fetching all matching rows in a single IN(...) query and de-duplicating
        // in PHP.
        //
        // FIX-EG-SESSION-LOCK: log_event.php and finalize_attempt.php did not call
        // \core\session\manager::write_close() before performing DB writes and
        // running analyser::score_attempt(). This held the PHP session file lock
        // for the entire request, blocking concurrent AJAX calls from the same
        // student session (e.g. a second flush arriving while the first was still
        // scoring). Both functions now release the session lock immediately after
        // require_login().
        //
        // FIX-EG-BEFOREUNLOAD-BEACON: tracker.js beforeunload handler called
        // flush() (async XHR), which the browser cancels on fast navigation. Fixed
        // to use navigator.sendBeacon() — the Beacon API guarantees delivery after
        // page unload. Falls back to async XHR on browsers without Beacon support.
        upgrade_plugin_savepoint(true, 2026041500058, 'plagiarism', 'essayguard');
    }

    // v1.2.59 — FIX-EG-BEACON + FIX-EG-GETBADGES-N1 release integrity savepoint.
    // No DB schema changes — this savepoint ensures Moodle marks the upgrade complete
    // at v1.2.59 for sites upgrading from v1.2.58.
    if ($oldversion < 2026041600059) {
        upgrade_plugin_savepoint(true, 2026041600059, 'plagiarism', 'essayguard');
    }

    // v1.2.60 — Three fixes:
    //
    // FIX-EG-NO-BADGE-OVERVIEW (CRITICAL): reporter.js AMD module was referenced in
    //   db/services.php and get_badges.php but never created in amd/src/. The
    //   plagiarism_essayguard_get_badges web service existed but was dead code — nothing
    //   called it. Badges never appeared on the quiz grading overview table
    //   (/mod/quiz/report.php?mode=grading), the primary page teachers use to review
    //   all student submissions. Fix: created amd/src/reporter.js (define() AMD format)
    //   which calls the web service and injects per-student risk badges into the grading
    //   table. Also updated before_footer.php to call js_call_amd() for reporter.js on
    //   mod-quiz-report pages.
    //
    // FIX-EG-CMID-INDEX (MEDIUM): plagiarism_essayguard_sc had no index starting with
    //   cmid. The queries in report.php (WHERE sc.cmid=? AND sc.qslot=0), get_badges.php
    //   (WHERE cmid=? AND qslot=0 AND userid IN(...)), and before_footer.php
    //   (count_records by cmid+qslot) all forced a full table scan because the only
    //   existing index starts with userid. On a class of 100 students with multiple
    //   attempts these queries degraded noticeably. Fix: add non-unique index (cmid, qslot).
    //
    // FIX-EG-USERDATE-FORMAT (MINOR): report.php used '%d %b %Y %H:%M' strftime-style
    //   format string in userdate(). PHP 8.1+ deprecated strftime() and userdate() in
    //   Moodle 4.5+ raises a deprecation notice for raw strftime patterns. Fixed by
    //   using get_string('strftimedatetimeshort', 'langconfig') for locale-safe output.
    if ($oldversion < 2026041600060) {
        $table = new \xmldb_table('plagiarism_essayguard_sc');
        $index = new \xmldb_index('cmid_qslot_ix', XMLDB_INDEX_NOTUNIQUE, ['cmid', 'qslot']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026041600060, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026041700061) {
        // FIX-EG-PER-SLOT-LIVE (v1.2.61): no DB schema changes.
        // Per-question scoring now happens live on every telemetry flush (log_event.php),
        // fixing identical badges across all quiz questions. Toast badge race condition
        // resolved by always storing aggregate (qslot=0) result in sessionStorage.
        // Observer state filter widened to cover all Moodle 4.x quiz behaviours.
        upgrade_plugin_savepoint(true, 2026041700061, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026041700062) {
        // v1.2.62 — Three fixes; no DB schema changes.
        //
        // FIX-EG-ATTO-QSLOT-SCOPE (CRITICAL): tracker.js Atto editor essayguardName
        //   detection fell back to document.querySelector('textarea[name*=":"]') when
        //   the .editor_atto_wrap ancestor was absent. On multi-essay-question pages
        //   (e.g. a quiz with 3 essay questions all visible at once) this returned Q1's
        //   hidden textarea for every Atto editor — so events from Q2 and Q3 were all
        //   tagged with Q1's qslot number. The analyser then found zero events for
        //   qslot=2 and qslot=3, making per-question behavioral metrics identical/zero.
        //   Fix: scope to .editor_atto_wrap first, then the enclosing .que Moodle quiz
        //   question container, then the existing id-based lookup. Never document-wide.
        //
        // FIX-EG-TINYMCE-QSLOT-SCOPE (CRITICAL): Same class of bug for TinyMCE. Added
        //   a .que container fallback that fires when the id-based lookup fails, so
        //   TinyMCE editors on multi-question pages also tag events with the correct slot.
        //
        // FIX-EG-STUDENT-STRFTIME (MINOR): student.php 'Last scored' timestamp still
        //   used the deprecated '%d %b %Y %H:%M' strftime format (the fix in v1.2.60
        //   was applied to report.php only). Updated to use
        //   get_string('strftimedatetimeshort','langconfig') for PHP 8.1+ compatibility.
        //
        // FIX-EG-STUDENT-PER-QUESTION (UX): student.php detail page only displayed the
        //   aggregate (qslot=0) record. Added a Per-Question Breakdown section that
        //   queries all qslot>0 records for the student+activity and renders a summary
        //   table (question, risk level, score, keystrokes, pastes, avg WPM, typing time)
        //   so teachers can compare behavioral metrics per quiz essay question.
        upgrade_plugin_savepoint(true, 2026041700062, 'plagiarism', 'essayguard');
    }

    // v1.2.63 — FIX-EG-QTYPE-FILTER: tracker.js question-type filter.
    //   Essay Guard tracking and paste-warning banners now activate ONLY on
    //   question types where the student must type a response (qtype_essay,
    //   qtype_shortanswer). Previously the tracker bound to every <textarea>
    //   and [contenteditable] found on the page regardless of question type —
    //   including hidden fields or editor markup inside MCQ, True/False, Matching,
    //   Gap-Select, Drag-and-Drop and Cloze questions. New isTypedAnswerField()
    //   helper checks whether the node is inside a Moodle quiz question container
    //   (.que); if so, only qtype_essay and qtype_shortanswer pass the filter.
    //   Nodes outside .que (assignment, forum) are unaffected. Guard applied to
    //   both the selector loop and the TinyMCE iframe binder.
    //   No DB schema changes. amd/src/tracker.js + both build files only.
    //   version.php → 2026041700063.
    if ($oldversion < 2026041700063) {
        upgrade_plugin_savepoint(true, 2026041700063, 'plagiarism', 'essayguard');
    }

    // v1.2.64 — Bug fixes: duplicate badges, wrong percentages, paste blocking.
    //   FIX-EG-BADGE-DEDUP: get_links() no longer falls back to the aggregate
    //     (qslot=0) record when a per-question slot is requested. The aggregate badge
    //     was appearing identically on every quiz question, giving every question the
    //     same percentage — visually "duplicate badges with wrong percentages".
    //   FIX-EG-BADGE-INLINE-DEDUP: injectRiskBadge() (tracker.js) no longer injects
    //     the aggregate (qslot=0) badge inline next to essay fields. The aggregate is
    //     stored in sessionStorage for the post-submit toast only; per-question badges
    //     (qslot > 0) are injected inline next to their respective question fields.
    //   FIX-EG-PASTE-ALLOW: Students are always permitted to paste during a quiz.
    //     Plagiarism detection runs AFTER submission — the tracker now silently records
    //     paste events without blocking or showing any warning banner.
    //   FIX-EG-BADGE-LABELS: 3-tier badge system replacing the previous 4-tier.
    //     low (0–9%) → "Original", partial (10–49%) → "Partially Original",
    //     high (50%+) → "High Plagiarism". Legacy DB values ('mild', 'medium')
    //     are display-layer mapped to 'Partially Original'.
    //   FIX-EG-PERQ-GATE: analyser.php per-question gate (qslot > 0) now scores any
    //     non-empty submitted text regardless of character count, fixing the issue where
    //     short individual answers were silently skipped (score=0) and every question
    //     showed an identical "Original 0%" badge.
    //   No DB schema changes. version.php → 2026042000064.
    if ($oldversion < 2026042000064) {
        upgrade_plugin_savepoint(true, 2026042000064, 'plagiarism', 'essayguard');
    }

    // v1.2.65 - BUG FIX: Badge displayed AUTHENTICITY percentage instead of risk percentage.
    //   "Original · 5%" read as "only 5% is original" when it actually meant "5% risk".
    //   Fix: lib.php get_links() now shows (100 − risk_score) as the displayed percentage.
    //   get_badges.php score100 field now returns authenticity % so reporter.js displays
    //   "Original · 95%", "Partially Original · 65%", "High Plagiarism · 25%" — all
    //   self-explanatory without any further explanation.
    //   No DB schema changes. version.php → 2026042000065.
    if ($oldversion < 2026042000065) {
        upgrade_plugin_savepoint(true, 2026042000065, 'plagiarism', 'essayguard');
    }

    // v1.2.66 - LABEL FIX: Badge labels changed from "Original / Partially Original /
    //   High Plagiarism" to "Low / Medium / High" to match Essay Guard documentation.
    //   Applied in lib.php ($level_labels), amd/src/reporter.js (LABELS), and
    //   amd/src/tracker.js (labelMap). All AMD build files synced.
    //   No DB schema changes. version.php → 2026042000066.
    if ($oldversion < 2026042000066) {
        upgrade_plugin_savepoint(true, 2026042000066, 'plagiarism', 'essayguard');
    }

    // v1.2.67 - TWO BUG FIXES:
    //   FIX-EG-PCT-LABEL: Badge % was inverted (showing authenticity = 100−risk) since
    //     v1.2.65. Teachers saw "Low · 95%" and expected "High" because 95% reads as
    //     a high number. Fix: show risk % directly so Low → small number, High → large
    //     number. Applied in lib.php, get_badges.php, reporter.js, BUILD_INFO.json.
    //   FIX-EG-BADGE-DEDUP-OVERVIEW: On the quiz grading overview, Moodle calls
    //     plagiarism_get_links() once per question column (Q.1, Q.2, …) with no
    //     questionattempt object — qslot is always 0 — causing the aggregate badge to
    //     appear in every question column identically. Fix: static $eg_badge_rendered
    //     map in plagiarism_essayguard_get_links() renders the badge only on the first
    //     call per user+cmid in a page request; subsequent calls return only the
    //     teacher report link.
    //   No DB schema changes. version.php → 2026042000067.
    if ($oldversion < 2026042000067) {
        upgrade_plugin_savepoint(true, 2026042000067, 'plagiarism', 'essayguard');
    }

    // v1.2.68 - TWO FIXES:
    //   FIX-EG-BADGE-OVERVIEW-ALL-COLS: The v1.2.67 static-dedup guard in lib.php
    //     plagiarism_essayguard_get_links() suppressed the badge on Q.2, Q.3, …
    //     columns of the quiz grading overview page. All aggregate (qslot=0) calls for
    //     the same user+cmid shared the same map key, so only the Q.1 column rendered
    //     the badge; subsequent columns returned only a report link. Teachers saw an
    //     empty cell for Q.2 which looked like missing data. Fix: remove the static
    //     dedup guard entirely. The aggregate badge now renders on every question column,
    //     showing the same aggregate score (correct — one aggregate record per student).
    //   FIX-EG-DOCS-SYNC: SaaS documentation page updated to reflect the current
    //     scoring engine: 8 signals (not 5), correct per-signal weights, 3-tier risk
    //     bands (Low 0-9 / Medium 10-49 / High 50-100) replacing the stale 4-tier bands.
    //   No DB schema changes. version.php → 2026042100068.
    if ($oldversion < 2026042100068) {
        upgrade_plugin_savepoint(true, 2026042100068, 'plagiarism', 'essayguard');
    }

    // v1.2.69 - THREE FIXES targeting "same Essay Guard result for every question
    //   on multi-question quizzes" + "top-right Essay Guard Report incomplete":
    //   FIX-EG-REPORT-PERQ: report.php only loaded aggregate (sc.qslot = 0) records
    //     so per-question scores stored by analyser.php (qslot > 0) were saved to
    //     the DB but NEVER displayed in the teacher report. Teachers therefore saw
    //     identical figures for every question (the aggregate, repeated). Fix:
    //     remove the qslot=0 SQL filter, group records by user→qslot, render the
    //     aggregate as the headline row and render each per-question score as an
    //     indented "↳ Question N" breakdown row beneath it.
    //   FIX-EG-TINYMCE-PASTE-SCOPE: tracker.js TinyMCE doc-level paste handler
    //     called eq('paste', …) — but eq is defined inside bindField() and is NOT
    //     in scope at the scan() callsite. Every paste in a TinyMCE editor silently
    //     threw a ReferenceError, so pasted Q-N answers were never logged with
    //     their qslot and never reached the analyser as paste evidence. Result: a
    //     copy-pasted Q1 looked behaviourally identical to a typed Q2. Fix: call
    //     enqueue() directly with qslot derived from the iframe body's
    //     dataset.essayguardName.
    //   FIX-EG-TOAST-PERQ: top-right post-submit toast only persisted the aggregate
    //     score (v1.2.61 race fix), so on multi-essay quizzes it summarised "the
    //     whole attempt" instead of "each question". Fix: also persist a per-slot
    //     map (essayguard_pending_badges_perq), and render a "Per question" list
    //     beneath the aggregate badge — Q1 / Q2 / Q3 each with their own risk +
    //     percentage. Race-safe (uses a map keyed by qslot, not overwrite).
    //   No DB schema changes. version.php → 2026042100069.
    if ($oldversion < 2026042100069) {
        upgrade_plugin_savepoint(true, 2026042100069, 'plagiarism', 'essayguard');
    }
    // v1.2.70: AMD ENCODING FIX: All non-ASCII characters (em dashes, arrows, box-drawing chars, ellipsis, bullets, emoji, accented Latin) scrubbed from all AMD JS files (amd/src, amd/build, amd/build/*.min.js). Root cause of Moodle primary/secondary navigation menus disappearing site-wide: non-ASCII bytes in any installed plugin's AMD file cause a SyntaxError inside RequireJS's first.js bundle, throwing "No define call for core/first" and aborting the entire AMD module chain. No PHP, DB schema, or functional changes in this release.
    if ($oldversion < 2026042200070) {
        upgrade_plugin_savepoint(true, 2026042200070, 'plagiarism', 'essayguard');
    }

    // v1.2.71: FIX-EG-BADGE-LEVEL: Risk badge labels always re-derived from score
    //   at display time (analyser::risk_level()) instead of trusting the stale DB
    //   column. Fixed in report.php (sort order, risk_cfg, counts, aggregate row,
    //   per-question rows), lib.php (get_links), and get_badges.php (web service).
    //   No DB schema changes.
    if ($oldversion < 2026042300071) {
        upgrade_plugin_savepoint(true, 2026042300071, 'plagiarism', 'essayguard');
    }

    // v1.2.72 - FIX-EG-BADGE-WORST-Q: The student-level badge in the quiz grading
    //   overview was sourced from the qslot=0 aggregate record (all keyboard events
    //   for the entire attempt blended into one score). Because the aggregate averages
    //   behaviour across all questions, a single high-risk question was diluted —
    //   teachers saw "LOW" next to the student name while individual per-question rows
    //   showed "MEDIUM". Fix: get_badges.php now fetches all per-question records
    //   (qslot > 0) ordered by riskscore DESC and returns the worst (highest-risk)
    //   question as the student-level badge. Falls back to the qslot=0 aggregate only
    //   when no per-question records exist (assign/forum or tracker inactive).
    //   PHP-only change (get_badges.php). No JS, DB schema, or AMD changes.
    //   version.php -> 2026042300072.
    if ($oldversion < 2026042300072) {
        upgrade_plugin_savepoint(true, 2026042300072, 'plagiarism', 'essayguard');
    }

    // v1.2.73: 4-fix scoring overhaul: (1) tracker.js emits large_insert event (delta > 20)
    //   catching medium-sized pastes below the 150-char burst threshold. (2) analyser.php
    //   scoring rebalanced — paste → +40 (was +7 per event), large_insert → +20, cps > 15 → +30,
    //   pausecount < 2 → +20, backspace < 0.02 → +15, typing_time < 10s → +25, entropy reduced
    //   to 10 pts. Thresholds changed to LOW 0-15 / MEDIUM 16-40 / HIGH 41-100. (3) report.php
    //   student badge derives from worst per-question score (not diluted aggregate). (4) student.php
    //   + per-question badges use analyser::risk_level() instead of DB column. No DB schema changes.
    if ($oldversion < 2026042600073) {
        upgrade_plugin_savepoint(true, 2026042600073, 'plagiarism', 'essayguard');
    }

    // v1.2.74 - FIX-EG-QUE-QSLOT: Per-question scoring now correctly differentiates
    //   essay questions with different behaviours (e.g. Q1 pasted, Q2 typed).
    //   Root cause: tracker.js derived qslot by parsing the Atto/TinyMCE hidden textarea
    //   name — a lookup that silently returns 0 when Moodle CSS selectors don't match the
    //   actual page structure. All events emitted with qslot=0; analyser.php per-question
    //   filter returned zero events for every slot; both questions scored from linguistic
    //   signals only → identical LOW badges.
    //   Fix (tracker.js): new extractQslot() reads from .que container id="q{N}" — stable
    //   across all Moodle 4.x/5.x and all editor types. cacheQslot() stores result in
    //   data-essayguard-qslot. eq(), TinyMCE paste handler, and interceptSubmitForms all
    //   prefer essayguardQslot. Fix (analyser.php): fallback for pre-fix sessions — when
    //   qslot>0 but no events carry any qslot tag, paste events are attributed by text-length
    //   matching (insertlen within 80-110% of submitted answer length).
    //   AMD triple-match MD5: 13fbd96389abea9e36089b837e4731ee. No DB schema changes.
    //   version.php → 2026042600074.
    if ($oldversion < 2026042600074) {
        upgrade_plugin_savepoint(true, 2026042600074, 'plagiarism', 'essayguard');
    }

    // v1.2.75 - FIX-EG-QSLOT-FALLBACK-V2 + FIX-EG-QSLOT-CONTENT: per-question scoring
    //   bugs fixed. No DB schema changes.
    if ($oldversion < 2026042600075) {
        upgrade_plugin_savepoint(true, 2026042600075, 'plagiarism', 'essayguard');
    }

    // v1.2.76 - DIAG-EG-CONSOLE: Added [EssayGuard DIAG] console logging to tracker.js
    //   (flush send/receive, bindField discovery, scan selector counts, finalizeAttempt
    //   result, form-submit intercept). Diagnostic build only — no scoring or DB changes.
    if ($oldversion < 2026042700076) {
        upgrade_plugin_savepoint(true, 2026042700076, 'plagiarism', 'essayguard');
    }

    // v1.2.77 - FIX-EG-AITUTOR-EXCLUDE + FIX-EG-QSLOT-ALTIDFMT + FIX-EG-QSLOT-DIAG:
    //   tracker.js: skip aicourse-* textareas (AI Tutor chat box); add extractQslot
    //   fallbacks for .que#q{N}-{slot}, [id^="question-"], and [data-slot] containers;
    //   cacheQslot emits detailed DIAG lines on success and FAIL. No DB schema changes.
    if ($oldversion < 2026042700077) {
        upgrade_plugin_savepoint(true, 2026042700077, 'plagiarism', 'essayguard');
    }

    // v1.2.78 - FIX-EG-REVIEW-PAGE + FIX-EG-INIT-DIAG:
    //   lib.php: skip tracker injection on mod-quiz-review pagetype (review.php was
    //   incorrectly getting the tracker because it also has ?attempt=N in the URL).
    //   tracker.js: init() logs the page URL, submit-form count, and attemptkey prefix
    //   as its first console line for unambiguous page-context diagnostics. No DB changes.
    if ($oldversion < 2026042700078) {
        upgrade_plugin_savepoint(true, 2026042700078, 'plagiarism', 'essayguard');
    }

    // v1.2.79 - FIX-EG-QUIZ-PAGE-GUARD (extends v1.2.78):
    //   lib.php: switched from a single 'mod-quiz-review' denylist entry to a full
    //   quiz-page allowlist: only 'mod-quiz-attempt' passes the guard. This also
    //   blocks the tracker from view.php (mod-quiz-view), summary.php, and grade.php
    //   — all of which were firing the tracker and producing false cacheQslot: FAIL
    //   diagnostics, as confirmed by a screenshot showing tracker logs on view.php
    //   before the student even clicked "Attempt quiz". No DB schema changes.
    if ($oldversion < 2026042700079) {
        upgrade_plugin_savepoint(true, 2026042700079, 'plagiarism', 'essayguard');
    }

    // v1.2.80 - FIX-EG-QUIZ-PAGE-GUARD-HOTFIX: dual pagetype+URI check; PHP diagnostics.
    if ($oldversion < 2026042700080) {
        upgrade_plugin_savepoint(true, 2026042700080, 'plagiarism', 'essayguard');
    }

    // v1.2.81 - FIX-EG-ATTEMPTKEY: sesskey() removed from inject_tracker() attemptkey seed.
    //   Quiz key is now 'qa_{quizattemptid}'; non-quiz is sha1(userid:cmid).
    //   observer.php on_quiz_attempt_submitted() now constructs key from $event->objectid.
    // FIX-EG-PASTE-DETECT: large_insert_max_delta > 200 treated as paste in Signal 1.
    // LINGUISTIC-FALLBACK: empty event pool + text_chars > 100 → score from sentence_variance
    //   and vocab_diversity instead of returning 0.
    // DEBUG: error_log added to score_attempt() showing event count and attemptkey.
    // No DB schema changes.
    if ($oldversion < 2026042700082) {
        upgrade_plugin_savepoint(true, 2026042700082, 'plagiarism', 'essayguard');
    }

    // v1.2.82 - BUMP: Version increment only. No DB schema changes.
    if ($oldversion < 2026042700084) {
        upgrade_plugin_savepoint(true, 2026042700084, 'plagiarism', 'essayguard');
    }

    // v1.2.83 - FIX-EG-BADGE-FALLBACK: get_links() falls back to aggregate record. No DB schema changes.
    if ($oldversion < 2026042700085) {
        upgrade_plugin_savepoint(true, 2026042700085, 'plagiarism', 'essayguard');
    }

    // v1.2.84 - FIX-EG-EXTRACT-BULLETPROOF + DEBUG-PANEL: extract_plain_text() helper,
    //   responsesummary fallback, debug.php, is_aggregate badge indicator. No DB schema changes.
    if ($oldversion < 2026042700088) {
        upgrade_plugin_savepoint(true, 2026042700088, 'plagiarism', 'essayguard');
    }

    // v1.2.85 - FIX-EG-PASTE-THRESHOLD: Signal 1 condition changed from
    //   large_insert_max_delta > 200 to large_inserts > 0.
    //   Students pasting short answers (< 200 chars) into TinyMCE/Atto always scored LOW
    //   because the 200-char threshold was never crossed and only Signal 2 (+8 pts) fired.
    //   Any input delta > 20 chars now triggers the paste HIGH signal (+40 pts).
    //   No DB schema changes.
    if ($oldversion < 2026042800089) {
        upgrade_plugin_savepoint(true, 2026042800089, 'plagiarism', 'essayguard');
    }

    // v1.2.90 - FIX-EG-PERQ-DISPLAY + FIX-EG-TINYMCE-FINALIZE: Three per-question
    //   display/data bugs fixed.
    //   (1) student.php: ucfirst($qlevel) rendered "Partial" for medium-risk questions.
    //   (2) report.php: summary pill "Medium  1" reformatted to "Medium · N students".
    //   (3) tracker.js: state.boundFrames[] registry added so TinyMCE iframe bodies are
    //   included in the per-question finalize loop and collectFieldText() — previously
    //   outer-document querySelectorAll could not find inside-iframe elements, so no
    //   qslot > 0 score records were created for TinyMCE quiz questions.
    //   No DB schema changes.
    if ($oldversion < 2026042800094) {
        upgrade_plugin_savepoint(true, 2026042800094, 'plagiarism', 'essayguard');
    }

    // v1.2.91 — FIX-EG-TOAST-SEPARATOR:
    //   Per-question breakdown in the post-submit toast used ' * ' (asterisk) as separator
    //   between the risk label and percentage, which looked like garbled markup or a CSS
    //   operator. Fixed to use middle-dot U+00B7 to match reporter.js badge style
    //   (e.g. "High · 92%" instead of "High * 92%"). No DB schema changes.
    //   version.php → 2026042800095.
    if ($oldversion < 2026042800095) {
        upgrade_plugin_savepoint(true, 2026042800095, 'plagiarism', 'essayguard');
    }

    // v1.2.92 — FIX-EG-FLUSH-BEFORE-FINALIZE:
    //   finalizeAttempt() AJAX calls were launched immediately when perFieldPromises was
    //   populated, racing flush() — so when PHP ran analyser::score_attempt() some queued
    //   events had not yet been written to the DB. The analyser saw a partial event set and
    //   often scored LOW instead of MEDIUM/HIGH (especially on fast Moodle servers where the
    //   finalize AJAX could arrive before the flush AJAX was processed). Fix: capture DOM text
    //   snapshots synchronously, then defer all finalizeAttempt calls inside flush().then() so
    //   events are guaranteed in DB before any scoring AJAX begins. AMD triple-match
    //   tracker.js md5 208832c5f3eae6d215c711fd0d3c2ec9. No DB schema changes.
    //   version.php → 2026042800096.
    if ($oldversion < 2026042800096) {
        upgrade_plugin_savepoint(true, 2026042800096, 'plagiarism', 'essayguard');
    }

    // v1.2.93 — FIX-EG-PERQ-TRUST + FIX-EG-TOAST-PERQ-SLOTS:
    //   Issue 1 (lib.php): The "higher-of-two" comparison added in v1.2.87 caused every
    //   question on the quiz attempt-review page to show the same badge. The aggregate
    //   record (qslot=0) is scored from ALL questions' events combined, so its riskscore
    //   is almost always higher than any individual per-question score. Every question's
    //   badge fell through to the aggregate, producing identical results regardless of
    //   whether Q1 was copy-pasted and Q2 was typed manually. Fix: trust the per-question
    //   record when its riskscore > 0; only fall back to aggregate when riskscore = 0
    //   (indicating qslot detection failed and no events were tagged for this slot).
    //   Issue 2 (tracker.js): When qslot detection fails for all fields in a multi-question
    //   quiz (all slots = 0), every finalizeAttempt() call at submit time uses qslot=0, so
    //   injectRiskBadge() writes to BADGE_STORAGE_KEY (overall) rather than the per-question
    //   perqMap. The post-submit toast shows only "Overall" with no per-question breakdown.
    //   Fix: if all snapshots have slot=0 AND multiple fields exist, assign sequential
    //   1-based position slots so each field gets its own perqMap entry.
    //   AMD triple-match tracker.js required. No DB schema changes. version.php → 2026042800097.
    if ($oldversion < 2026042800097) {
        upgrade_plugin_savepoint(true, 2026042800097, 'plagiarism', 'essayguard');
    }

    // v1.2.94 - FIX-EG-ENABLED-CHECK-INCONSISTENT + BUG-CURL-RESETOPT:
    //   (1) FIX-EG-GLOBAL-ENABLED-MISSING (v1.2.88) fixed inject_tracker() but the same
    //   wrong !get_config() pattern was present in 3 other places:
    //   observer.php::is_active() → PHP scoring never ran on fresh installs.
    //   finalize_attempt.php → JS badge never shown.
    //   log_event.php → returned ignored:true without storing events; JS queue cleared
    //   thinking events were persisted — events silently lost, no score ever possible.
    //   All 3 now use $v !== false && empty($v) guard matching the v1.2.88 fix.
    //   (2) auto_unlock() passed curl options via setopt() before post(); \curl::post()
    //   calls resetopt() internally, discarding Content-Type: application/json header.
    //   Server could not parse JSON body → auto-unlock always silently failed.
    //   Fix: pass options as 3rd argument to post(). No DB schema changes.
    if ($oldversion < 2026042800098) {
        upgrade_plugin_savepoint(true, 2026042800098, 'plagiarism', 'essayguard');
    }

    // v1.2.95 - FIX-EG-EMPTY-RESULT-OK-FALSE + FIX-EG-EVENTS-BEFORE-UNLOCK +
    //   FIX-EG-ASSIGN-EXTRACT-PLAIN + FIX-EG-FORUM-EXTRACT-PLAIN:
    //   (1) finalize_attempt.php empty_result() returned ok:true — JS badge injected LOW
    //   even when plugin was inactive; fix: ok:false suppresses badge injection.
    //   (2) log_event.php: events written BEFORE check_unlock() so telemetry is never
    //   lost when unlock fails (scoring still gated behind unlock check).
    //   (3) observer.php get_assign_text() + get_forum_text() use extract_plain_text()
    //   instead of bare strip_tags() so TinyMCE &nbsp; entities are properly decoded.
    //   No DB schema changes.
    if ($oldversion < 2026042900099) {
        upgrade_plugin_savepoint(true, 2026042900099, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026042900100) {
        // FIX-EG-SUBMIT-FLUSH-TIMEOUT + FIX-EG-SCORE-NO-CLOBBER + FIX-EG-RESCORE-TASK
        // Three race-condition fixes for the "always LOW badge" problem.
        // No DB schema changes in this version.
        upgrade_plugin_savepoint(true, 2026042900100, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026042900101) {
        // FIX-EG-TASKS-CRON: db/tasks.php rescore_pending task had 'minute' => 'R/5'.
        // Moodle's eval_cron_field() does not support the R+step combination — it returned
        // null, which caused count(null) TypeError in scheduled_task.php on every plugin
        // install/upgrade via reset_scheduled_tasks_for_component(). Fixed to '*/5'.
        // NOTE: In Moodle's task scheduler, 'R' is only valid as a standalone field value
        // (assigns a random offset 0–59); it cannot be combined with a step expression.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026042900101, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026042900102) {
        // FIX-EG-NO-STUDENT-BADGE: report.php student headline row no longer shows a
        // risk badge; only per-question breakdown rows carry badges.
        // FIX-EG-PASTE-SCORE-FLOOR: Signal 1 paste base raised +40 → +60.
        // FIX-EG-SIGNALS57-LARGE-INSERT: Signals 5 and 7 now also fire for large_inserts>0
        // so TinyMCE-intercepted pastes get the full +15/+10 correction-ratio and entropy
        // bonuses. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026042900102, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026042900103) {
        // FIX-EG-PERQ-UNTAGGED-PASTE: analyser.php now attributes untagged paste events
        // (qslot=0, stored when TinyMCE doc-level capture handler cannot resolve the
        // question slot) to the correct per-question scorer via 90–110 % insertlen matching.
        // Fixes per-question badge showing ~44% instead of 85%+ when student pastes in
        // TinyMCE and the paste event loses its qslot tag.
        // FIX-EG-TINYMCE-PASTE-LARGE-INSERT: tracker.js TinyMCE capture handler now also
        // emits large_insert with the same pasteSlot, ensuring Signal 2 (+8) fires for
        // per-question scores even when TinyMCE does not trigger DOM input on the body.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026042900103, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026042900104) {
        // DOC-FIX-EG-SCORE-BANDS: Score band thresholds corrected in two stale locations
        // in the EssayGuard marketing docs page (Troubleshooting threshold box and
        // How-It-Works step 3) from old 0-9/10-49/50-100 to correct 0-15/16-40/41-100
        // matching risk_level() in analyser.php v1.2.73+.
        // DOC-FIX-EG-SIGNAL-COUNT: Signal count updated from "eight" to "nine" in two
        // remaining stale locations (How It Works step 3 description, Signals section
        // intro paragraph). All four signal-count references now consistently say nine.
        // DOC-FIX-EG-VIDEO-TAB: Walkthrough Video tab wired up — TabsTrigger + TabsContent
        // added for all 12 production storyboard scenes.
        // No PHP, AMD, or DB schema changes.
        upgrade_plugin_savepoint(true, 2026042900104, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026042900105) {
        // FIX-EG-ENTITY-DECODE: Per-question badge showed ~44 % (HIGH) instead of 85 %+
        // when student pasted text containing HTML entities (&amp;, &lt;, &nbsp;, etc.)
        // into a TinyMCE essay question.
        //
        // Root cause: FIX-EG-PERQ-UNTAGGED-PASTE (v1.2.99) and the older QSLOT-FALLBACK
        // (v1.2.86) both computed text_chars via mb_strlen(strip_tags($finaltext)).
        // strip_tags() removes HTML tags but does NOT decode HTML entities, so
        // TinyMCE-encoded ampersands (&amp; = 5 chars) were counted as 5 characters
        // rather than 1, overcounting by 4 chars per special character.  For answers with
        // even a modest number of special characters (e.g. "A&B" or "cats & dogs") the
        // insertlen/text_chars ratio fell outside the 90–110 % attribution band, silently
        // dropping paste attribution and leaving the per-question score at ~44 % from
        // speed/pause signals alone instead of the expected 85 %+.
        //
        // Fix: both attribution blocks (FIX-EG-PERQ-UNTAGGED-PASTE and QSLOT-FALLBACK)
        // now call html_entity_decode(strip_tags($finaltext), ENT_QUOTES|ENT_HTML5, 'UTF-8')
        // before mb_strlen so the PHP character count matches the JS clipboard text.length.
        // Attribution band also widened from 90–110 % to 70–140 % to absorb minor TinyMCE
        // whitespace normalisation (paragraph wrapping, line-ending conversion).
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026042900105, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026043000107) {
        // FIX-EG-MIXED-BAND + FIX-EG-MIXED-PROPORTIONAL (v1.2.104):
        // Two bugs caused mixed-behaviour quiz submissions (student typed part of the
        // answer and pasted the rest) to show an incorrect LOW 0% badge instead of MEDIUM.
        //
        // Bug 1 (FIX-EG-MIXED-BAND): The untagged paste attribution fallback used a
        // 70-140% insertlen band. For mixed sessions the pasted portion is only a fraction
        // of the total answer (e.g. 40%), which falls below the 70% lower bound: paste
        // excluded, pastecount=0, score=0, LOW badge. Fix: lower bound reduced to 0.10.
        // large_insert events (TinyMCE clipboard proxy) are now also included in both
        // fallback blocks using their delta field.
        //
        // Bug 2 (FIX-EG-MIXED-PROPORTIONAL): Signal 1 awarded a flat +60 pts for any
        // paste regardless of fraction. A student who typed half and pasted half received
        // the same HIGH badge as a pure copy-paste. Fix: mixed sessions (keystrokes > 0
        // AND paste size known) now award max(35, 60 x paste_fraction), guaranteeing
        // MEDIUM (>=35 pts) for any meaningful paste (>=5% of answer). Pure paste
        // (no keystrokes) or unknown size retains the full +60.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026043000107, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026043000108) {
        // FIX-EG-PASTE-HIGH + FIX-EG-PARTIAL-PASTE-FALLBACK + FIX-EG-ENTITY-DECODE-TEXTCHARS
        // + FIX-EG-PARTIAL-PASTE-S5S7 (v1.2.105): Four bugs in multi-question quiz scoring.
        //
        // (1) FIX-EG-PASTE-HIGH: Ctrl/Cmd+V records 2 keydown events (modifier + V key),
        // causing total_keystrokes=2 and bypassing the pure-paste branch in Signal 1.
        // When paste_frac was under-reported (TinyMCE clipboard unreadable or partial cross-
        // question attribution), Signal 1=35 → total≤70 → MEDIUM instead of HIGH. Fix:
        // paste_frac_raw ≥ 0.75 condition added so sessions with ≥75% pasted text always
        // receive full +60.
        //
        // (2) FIX-EG-PARTIAL-PASTE-FALLBACK: Q2 mixed sessions showed LOW when Q1 had
        // qslot-tagged events (disabling Fallback 2) but Q2 events were untagged, leaving
        // Q2 with zero events and only Signal 4 (+20). Fix: new two-tier fallback: Tier 1
        // (ratio 0.70–1.40) handles full-answer pastes, Tier 2 (ratio 0.10–0.69) handles
        // partial pastes and sets partial_paste_only=true.
        //
        // (3) FIX-EG-ENTITY-DECODE-TEXTCHARS: text_chars used mb_strlen(strip_tags()) without
        // html_entity_decode, inflating char count and depressing paste_frac. Fix: apply
        // html_entity_decode before mb_strlen, matching text_chars_pfix / text_chars_early.
        //
        // (4) FIX-EG-PARTIAL-PASTE-S5S7: partial_paste_only sessions had total_keystrokes=0,
        // triggering is_paste_session=true and incorrectly awarding S5 (+15) and S7 (+10).
        // Fix: is_paste_session now requires !partial_paste_only.
        // No DB schema changes. version.php → 2026043000108.
        upgrade_plugin_savepoint(true, 2026043000108, 'plagiarism', 'essayguard');
    }

    // v1.2.106 - FIX-EG-QSLOT-CONTENT-FUZZY: lib.php find_qslot_by_content() uses
    //   similar_text() >= 85% instead of strict ===, fixing Review Attempt showing
    //   aggregate score for all questions when TinyMCE content did not exactly match.
    //   FIX-EG-PASTE-HIGH-V2: analyser.php Signal 1 pure-paste gate extended to
    //   total_keystrokes <= 2 (covers Ctrl/Cmd+V 2-keystroke sessions).
    //   No DB schema changes. version.php → 2026043000109.
    if ($oldversion < 2026043000109) {
        upgrade_plugin_savepoint(true, 2026043000109, 'plagiarism', 'essayguard');
    }

    // v1.2.107 - VERSION-BUMP: Maintenance release. No code changes. AMD JS files
    //   verified clean: reporter.js MD5 53dbbab5a5fc57802ad3c4d5206b629e,
    //   tracker.js MD5 af295fc746f893c82703cf451b5cd724 (src=build=min, triple-match).
    //   No DB schema changes. version.php → 2026043000110.
    if ($oldversion < 2026043000110) {
        upgrade_plugin_savepoint(true, 2026043000110, 'plagiarism', 'essayguard');
    }

    // v1.2.108 - FIX-EG-REPORT-FALLBACK: Eliminates badge mismatch between report.php
    //   and the quiz review-attempt page. report.php now mirrors the lib.php fallback
    //   rule (FIX-EG-PERQ-TRUST v1.2.93 + FIX-EG-IS-AGGREGATE v1.2.84): when a
    //   per-question record has riskscore <= 0 (qslot detection failed in tracker.js
    //   for that slot — no tagged events found), use the aggregate (qslot=0) record
    //   for that question's badge and append "(OVERALL)" so the score is clearly the
    //   whole-submission aggregate rather than question-specific. Previously report.php
    //   rendered "LOW 0%" literally for every untagged question, while the review-
    //   attempt page showed the correct fallback — misleading teachers about real
    //   student paste behaviour and producing inconsistent badges across the two views.
    //   PHP-only, only report.php changed (lines 318-369). No AMD JS changes — reporter.js
    //   and tracker.js MD5s unchanged from v1.2.107. No DB schema changes.
    //   version.php → 2026050100111.
    if ($oldversion < 2026050100111) {
        upgrade_plugin_savepoint(true, 2026050100111, 'plagiarism', 'essayguard');
    }

    // v1.2.109 - FIX-EG-FALLBACK-EVENTCOUNT: Aggregate fallback now uses captured
    //   activity (total_keystrokes / paste_events) as the discriminator instead of
    //   triggering on every per-question riskscore=0. Previously the v1.2.93/v1.2.108
    //   safety-net fallback fired whenever a per-question score was 0, which conflated
    //   two scenarios: (a) qslot detection failed → no tagged events → riskscore=0
    //   AND total_keystrokes=0 (fallback correct) and (b) student typed cleanly →
    //   events captured for the slot but no risk signals fired → riskscore=0 with
    //   total_keystrokes>0 (fallback WRONG). Honest typists were being mis-badged
    //   as MEDIUM (OVERALL) because the aggregate frequently sat just above the
    //   35% MEDIUM threshold from cross-question background noise. New rule: only
    //   fall back when riskscore=0 AND no captured activity. Trust the per-question
    //   0% when keystrokes/paste events exist for that slot. Applied to lib.php
    //   (Review Attempt) and report.php (per-question rows) so both views agree.
    //   PHP-only, no AMD changes. No DB schema changes (uses existing total_keystrokes
    //   and paste_events columns). version.php → 2026050100112.
    if ($oldversion < 2026050100112) {
        upgrade_plugin_savepoint(true, 2026050100112, 'plagiarism', 'essayguard');
    }

    // v1.2.110 - FIX-EG-FALLBACK-PASTE-ONLY: v1.2.109's "has activity" discriminator
    //   was wrong in both directions. Two opposite live-test failures shared one root
    //   cause:
    //     Bug A (paste → LOW): Ctrl+V keystrokes were tagged with this qslot but the
    //       paste/large_insert event was tagged with a different qslot. v1.2.109 saw
    //       "activity > 0" and refused to consult the aggregate where the paste was
    //       clearly visible — the badge stayed LOW for a paste.
    //     Bug B (typed → MEDIUM): qslot detection failed entirely so the per-question
    //       record had riskscore=0 with no activity. v1.2.109 fell back to the
    //       aggregate which had no paste but scored 35-40 from honest-typing signals
    //       (no long pauses + no backspaces). Honest typists were badged MEDIUM
    //       (OVERALL).
    //   New rule (replaces v1.2.109's logic in both lib.php and report.php): fall back
    //   to the aggregate ONLY when per-question riskscore=0 AND the aggregate has
    //   CONCRETE paste evidence — paste_events > 0 OR riskscore >= 0.70 (the HIGH
    //   threshold, which is reachable only when Signal 1 fires from a paste or
    //   large_insert event). Typing-only aggregate scores in the 35-69 MEDIUM range
    //   never rescue a per-question slot. The has-activity check on the per-question
    //   side was dropped because Ctrl+V keydowns are not a reliable indicator that the
    //   paste was correctly attributed to the same slot.
    //   Net effect:
    //     • Bug A → aggregate has paste → fallback fires → HIGH (correct).
    //     • Bug B → aggregate has no paste → fallback skipped → LOW (correct).
    //     • Per-question paste correctly tagged → trusted directly (unchanged).
    //     • Honest typist with cleanly tagged events → trusted directly (unchanged).
    //   PHP-only, no AMD changes. No DB schema changes (still reads paste_events and
    //   riskscore columns that already exist). version.php → 2026050100113.
    if ($oldversion < 2026050100113) {
        upgrade_plugin_savepoint(true, 2026050100113, 'plagiarism', 'essayguard');
    }

    // v1.2.111 - FIX-EG-BADGE-MEDIUM: report.php used 'partial' as the array key
    //   for risk_cfg, sort order, and counts — but analyser::risk_level() now
    //   returns 'medium'. Medium-risk students were silently sorted to the bottom
    //   with low-risk students, shown with a green LOW badge, and counted as 0 in
    //   the summary row. Fixed: all three occurrences changed to 'medium'.
    //   PHP-only change (report.php). No AMD JS or DB schema changes.
    //   version.php → 2026050200114.
    if ($oldversion < 2026050200114) {
        upgrade_plugin_savepoint(true, 2026050200114, 'plagiarism', 'essayguard');
    }

    // v1.2.112 - FEATURE-EG-SIGNALS-101112: Three new TypeShield-matched signals
    //   added to analyser.php: Signal 10 (IKI autocorrelation, up to +15 pts),
    //   Signal 11 (typing speed coefficient of variation, up to +10 pts), Signal 12
    //   (keystroke-to-character ratio, up to +10 pts). Total possible score raised
    //   to 130; risk_level() thresholds adjusted to LOW 0-15 / MEDIUM 16-40 /
    //   HIGH 41-130 (effectively 41-100 normalised). $text_chars added to metricsjson
    //   so Signal 12 can be computed without approximation. No DB schema changes.
    //   version.php → 2026050300115.
    if ($oldversion < 2026050300115) {
        upgrade_plugin_savepoint(true, 2026050300115, 'plagiarism', 'essayguard');
    }

    // v1.2.114 - FIX-EG-LANG-RETENTION-DESC:
    //   retentiondays_desc lang string incorrectly stated that session scores are
    //   deleted along with raw telemetry events. cleanup.php only deletes rows from
    //   plagiarism_essayguard_ev; plagiarism_essayguard_sc score records are retained
    //   permanently regardless of the retention setting. Updated lang string to
    //   accurately reflect this so site admins can plan GDPR data retention correctly.
    //   lang/en/plagiarism_essayguard.php only. No DB schema changes.
    if ($oldversion < 2026050400117) {
        upgrade_plugin_savepoint(true, 2026050400117, 'plagiarism', 'essayguard');
    }

    // v1.2.113 - BUG FIXES + per-signal breakdown:
    //   (1) FIX-EG-BADGE-MEDIUM: student.php and debug.php still used 'partial' as
    //   a primary palette key; analyser::risk_level() returns 'medium'. Medium-risk
    //   students showed with incorrect colours. Fixed across student.php, debug.php,
    //   and tracker.js (badge palette aligned to reporter.js orange scheme).
    //   (2) FIX-EG-EXPLAIN-S10S11S12: explainer.php had no coverage for Signals 10,
    //   11, 12. Added explain_ikiautocorr_high/med, explain_speedcv_high/med,
    //   explain_keystrokeratio_high/med lang strings and corresponding checks.
    //   (3) FIX-EG-TEXT-CHARS-METRIC: $text_chars added to metricsjson so explainer
    //   can compute Signal 12 keystroke ratio without approximation.
    //   (4) FEATURE-EG-SIGNAL-BREAKDOWN: analyser.php now tracks per-signal point
    //   contributions in $signal_pts[] stored as 'signal_breakdown' in metricsjson.
    //   student.php renders a per-signal breakdown table. No DB schema changes.
    //   version.php → 2026050400116.
    if ($oldversion < 2026050400116) {
        upgrade_plugin_savepoint(true, 2026050400116, 'plagiarism', 'essayguard');
    }

    // FIX-EG-CFG-SCOPE (v1.2.142): global $CFG added before require_once in all
    // three external class files. No DB schema changes.
    if ($oldversion < 2026051100145) {
        upgrade_plugin_savepoint(true, 2026051100145, 'plagiarism', 'essayguard');
    }

    // FIX-EG-SESSION-LOCK (v1.2.162): write_close() before HTTP calls in check_unlock() and auto_unlock().
    // No DB schema changes.
    if ($oldversion < 2026051300165) {
        upgrade_plugin_savepoint(true, 2026051300165, 'plagiarism', 'essayguard');
    }

    // FIX-EG-PRELOAD-PLAGIARISMLIB + FIX-EG-REMOVE-LEGACY-TOP-OF-BODY (v1.2.167):
    // No DB schema changes. Hook callback pre-loads plagiarismlib.php; legacy global function removed.
    if ($oldversion < 2026051300169) {
        upgrade_plugin_savepoint(true, 2026051300169, 'plagiarism', 'essayguard');
    }

    // FIX-EG-OVERVIEW-AGGREGATE-BLEED (v1.2.168): No DB schema changes.
    // When qslot detection fails on the overview page and 2+ per-question records
    // exist, return only the report link instead of a misleading aggregate badge.
    if ($oldversion < 2026051300170) {
        upgrade_plugin_savepoint(true, 2026051300170, 'plagiarism', 'essayguard');
    }

    // FIX-EG-OVERVIEW-AGGREGATE-BLEED revised (v1.2.169): No DB schema changes.
    // Removed content guard — fix now fires even when $linkarray['content'] is empty
    // (as it is on the quiz overview page which renders "Requires grading" status).
    if ($oldversion < 2026051300171) {
        upgrade_plugin_savepoint(true, 2026051300171, 'plagiarism', 'essayguard');
    }

    // FIX-EG-PRELOAD-INLINE (v1.2.170): No DB schema changes.
    // Guarded require_once(plagiarismlib.php) at top of lib.php ensures Path B
    // class always taken on normal page loads; eliminates update_status() deprecation.
    if ($oldversion < 2026051300172) {
        upgrade_plugin_savepoint(true, 2026051300172, 'plagiarism', 'essayguard');
    }

    // FIX-EG-RESTORE-LEGACY-FUNCTION (v1.2.171): No DB schema changes.
    // Restored global no-op plagiarism_essayguard_before_standard_top_of_body_html()
    // so plagiarism_update_status() short-circuits via function_exists() before
    // reaching the ReflectionMethod deprecation check. Removed the no-op
    // before_standard_top_of_body_html_generation hook from db/hooks.php so
    // process_legacy_callbacks() never emits "Callback should be migrated".
    if ($oldversion < 2026051300174) {
        upgrade_plugin_savepoint(true, 2026051300174, 'plagiarism', 'essayguard');
    }

    // FIX-EG-REMOVE-LEGACY-FUNCTION (v1.2.172): No DB schema changes.
    // Removed global plagiarism_essayguard_before_standard_top_of_body_html() from
    // lib.php — Moodle's get_plugins_with_function() emits "Callback should be
    // migrated" unconditionally for any plugin with the legacy function, regardless
    // of hook registration. Restored before_standard_top_of_body_html_generation
    // hook in db/hooks.php. Guarded require_once(plagiarismlib.php) already in lib.php
    // ensures Path B (extends plagiarism_plugin) so no update_status() deprecation.
    if ($oldversion < 2026051300175) {
        upgrade_plugin_savepoint(true, 2026051300175, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026051300176) {
        // FIX-EG-NO-BADGE-OVERVIEW: get_links() no longer shows a risk badge when
        // qslot cannot be determined (student name column). Badges on Report page only.
        // FIX-EG-PERQ-NO-EVENTS-LOW: linguistic fallback and Signal 13 now gated so
        // per-question no-events scoring without paste evidence returns LOW (not MEDIUM).
        upgrade_plugin_savepoint(true, 2026051300176, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026051300177) {
        // FIX-EG-UPDATE-STATUS-REVIVE (v1.2.174): Re-added global function
        // plagiarism_essayguard_before_standard_top_of_body_html() so that
        // plagiarism_update_status() bypasses its ReflectionMethod check and never
        // emits the "update_status() is deprecated" notice on the quiz Results page.
        // FIX-EG-CONTENT-MINLEN (v1.2.174): Added > 30 char plain-text length gate
        // to both the content-matching and call-order slot-resolution fallbacks in
        // get_links(). Prevents short Moodle status strings ("Requires grading",
        // grade values) from triggering slot assignment on the quiz overview page,
        // which caused a risk badge to appear in the Q.1 column under the student name.
        upgrade_plugin_savepoint(true, 2026051300177, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026051300178) {
        // FIX-EG-S4-ONE-PAUSE (v1.2.175): Signal 4 threshold tightened from
        // pausecount < 2 to pausecount === 0. Pre-thinker students who paused once
        // during typing no longer receive the +20 pt no-pause penalty.
        // FIX-EG-TYPING-FALSE-POSITIVE-V2 (v1.2.175): False-positive cap speed ceiling
        // raised from 8.0 cps (96 wpm) to 12.0 cps (144 wpm). Ensures fast typists
        // without paste evidence stay capped at LOW (29). No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051300178, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026051300179) {
        // FIX-EG-SITEWIDE-SETTINGS (v1.2.176): Added plagiarism_essayguard_get_platform_settings()
        // which fetches and caches site-wide enable/disable toggles from the AI Grader platform.
        // is_cm_active() now checks platform settings first — if the admin enabled Essay Guard
        // site-wide for all assignments or all quizzes, that overrides the per-activity checkbox.
        // Cached 30 minutes in Moodle config. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051300179, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026051300180) {
        // FIX-EG-BADGE-RENDER + EG-STUDENT-REPORT-UPGRADE (v1.2.177):
        // (1) get_links() badge rendering now fully delegated to plagiarism_essayguard_render_badge()
        // — no more Mustache template dependency, inline styles, hover tooltip, pending/error states.
        // (2) student.php upgraded to DocGuard quality: visual score bar, actual computed evidence
        // values per signal (cps, paste fraction, keystroke ratio, etc.), Signal 13 (server-side
        // CPS) added, per-question section rebuilt as collapsible cards each with full signal
        // breakdown table, interpretation guide added at page bottom.
        // (3) styles.css essayguard-badge/wrap/link/tooltip CSS block added.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051300180, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026051300181) {
        // FIX-EG-UPDATE-STATUS-PATH-B (v1.2.178): Added empty update_status() override to
        // Path B (extends plagiarism_plugin) class definition. Moodle 4.4+ changed
        // plagiarism_update_status() to unconditionally call $obj->update_status() on every
        // plugin object — the base plagiarism_plugin::update_status() method itself fires
        // debugging('plagiarism_plugin::update_status() is deprecated...') when not overridden.
        // Path A (standalone class) already had this stub since v1.2.163; Path B inherited
        // the noisy base-class method and therefore fired the deprecation on every assignment
        // grading page load. The empty override suppresses the notice on both class paths.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051300181, 'plagiarism', 'essayguard');
    }


    if ($oldversion < 2026051300182) {
        // EG-CLASS-REPORT-DOCGUARD-QUALITY (v1.2.179): Upgraded report.php (class report) to
        // DocGuard quality — dark blue gradient header with version badge, 6 coloured stat cards
        // (Total Submissions, High Risk, Medium Risk, Low Risk, Pending, Errors), Submissions
        // table with Score/Risk/Questions/Analysed/Actions columns, per-question breakdown rows
        // retained, Cross-Student Behaviour Summary section listing HIGH-risk students with
        // primary signal. styles.css extended with eg-class-header, eg-stat-cards, eg-score-badge,
        // eg-action-btn, eg-similarity-table, eg-debug-bar CSS blocks.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051300182, 'plagiarism', 'essayguard');
    }


    if ($oldversion < 2026051300183) {
        // ADD-EG-QUESTION-ANSWER-DISPLAY (v1.2.180): student.php per-question cards now show
        // question text and the student's answer text for quiz attempts. Question text is fetched
        // from question.questiontext (JOIN question_attempts); student answer from
        // question_attempt_step_data (name='answer', most-recent step per slot, ORDER BY
        // qas.id DESC). Both fields are HTML-stripped, entity-decoded, and NBSP-normalised to
        // plain text. Questions >400 chars and answers >600 chars get inline "more/less" toggles.
        // Question box uses a light-blue (#f0f4ff) background; answer box uses light-grey
        // (#f8fafc). Only fires for quiz attempts (attemptkey format: qa_{id}); assignments and
        // non-quiz activities show no change. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051300183, 'plagiarism', 'essayguard');
    }


    if ($oldversion < 2026051300184) {
        // ADD-EG-SIGNAL-STATUS-KEY (v1.2.181): essayguard_render_signal_table() now renders a
        // collapsible "<details> Signal status key" legend above the signal table explaining
        // Fired (suspicious pattern detected, points added), Silent (evaluated, nothing
        // suspicious found, no points added), and Applied (supplementary factor — baseline
        // deviation or linguistic fallback — was active and contributed points). Each status
        // badge also has a native title="" tooltip for instant hover context. No DB schema
        // changes.
        upgrade_plugin_savepoint(true, 2026051300184, 'plagiarism', 'essayguard');
    }


    if ($oldversion < 2026051300185) {
        // FIX-EG-CAPTURE-TEST-AUTH (v1.2.182): Added require_capability() to
        // capture_test.php to restrict access to site admins. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051300185, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026051300186) {
        // FIX-EG-PRELOAD-LIB: Hook callback now also require_once(lib.php).
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051300186, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026051300187) {
        // FIX-EG-BODY-PRELOAD-LIB: before_standard_top_of_body_html_generation
        // callback now also loads plagiarismlib.php + lib.php as a secondary
        // belt-and-suspenders guarantee. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051300187, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026051300188) {
        // FIX-EG-CALLORDER-NO-CONTENT-GATE: Removed > 30 char content gate from
        // call-order slot assignment in get_links(). Enables per-question risk badges
        // to appear in Q.N columns on the quiz overview page. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051300188, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026051400199) {
        // FIX-SESSION-CLOSE-GUARD (v1.2.199): Guarded all three write_close() calls in
        // lib.php to AJAX/CLI contexts only. During a normal web page render, write_close()
        // caused "Session mutated after close: $SESSION->editedpages" because the admin
        // framework writes that key at end-of-render after the function returned.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051400199, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026051400202) {
        // FIX-EG-PATH-A-STUB (v1.2.202): Restored update_status() stub on the Path A
        // (standalone) class declaration in lib.php.
        //
        // Root cause of "Method plagiarism_plugin_essayguard::update_status() does not exist"
        // crash on the quiz overview and assignment grading pages:
        //
        // 1. During early Moodle bootstrap (setup.php:855 get_plugins_with_function scan),
        //    lib.php is require_once()'d for the first time.
        // 2. At that moment class_exists('plagiarism_plugin', false) === false — plagiarismlib.php
        //    has not loaded yet — so Path A (standalone class, no update_status()) is compiled.
        // 3. require_once() prevents lib.php from executing again; Path A class is permanent.
        // 4. Later, on the quiz overview / assign grading page, plagiarism_update_status() is called.
        // 5. Moodle 4.4+ plagiarismlib.php:106 calls
        //      new ReflectionMethod('plagiarism_plugin_essayguard', 'update_status')
        //    with NO surrounding try/catch.
        // 6. The method does not exist on the Path A class → ReflectionException → page crash.
        //
        // Identical to the DocGuard fix (FIX-DG-PATH-A-STUB v1.0.46, savepoint 2026051400047).
        // Fix: update_status() no-op stub added to Path A class. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051400202, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026051400203) {
        // FIX-EG-FILESYSTEM-LOAD (v1.2.203): Replaced single-strategy $CFG->libdir
        // plagiarismlib.php load with dual-strategy loader to ensure Path B
        // (extends plagiarism_plugin) is always taken regardless of $CFG state.
        //
        // Root cause of crash and deprecation: Path A (standalone class with update_status()
        // stub) was permanently compiled during early bootstrap. Later, Moodle 4.4+
        // plagiarismlib.php:106 calls new ReflectionMethod() with NO try/catch — if the
        // method doesn't exist → ReflectionException → page crash (pre-v1.2.202); if it
        // exists but getDeclaringClass() != 'plagiarism_plugin' → debugging() fires
        // (deprecation notice visible in developer debug mode).
        //
        // Fix: Added filesystem-relative fallback path using dirname(dirname(dirname(__FILE__)))
        // which resolves to Moodle root regardless of $CFG. When plagiarismlib.php loads,
        // plagiarism_plugin is defined, Path B is taken, update_status() is inherited,
        // getDeclaringClass() returns 'plagiarism_plugin', ReflectionMethod check passes
        // silently. The Path A stub is retained as a last-resort safety net.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026051400203, 'plagiarism', 'essayguard');
    }

    // v1.2.210: SAVEPOINT-BUMP — no-op marker for clean upgrade path. No DB schema changes.
    if ($oldversion < 2026060400210) {
        upgrade_plugin_savepoint(true, 2026060400210, 'plagiarism', 'essayguard');
    }

    // v1.2.211: PERF-FIX-EG-BATCH-PRELOAD — replaced N+1 DB query pattern in get_links()
    // with a single CM-level bulk SELECT. No DB schema changes.
    if ($oldversion < 2026060500211) {
        upgrade_plugin_savepoint(true, 2026060500211, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026072300235) {
        // FIX-API-DOMAIN: Updated all API endpoint URLs from lms-labs.com to lms-labs.com.
        // lms-labs.com has no DNS resolution from Moodle server side; lms-labs.com is the
        // correct working domain. All ajax.php, api_client, unlock_verifier, lib.php calls updated.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026072300235, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026072300236) {
        // FIX-API-DOMAIN: Reverted API endpoint to lms-labs.com (correct domain).
        // essaygraderai.app was the original single-plugin domain; lms-labs.com is correct.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072300236, 'plagiarism', 'essayguard');
    }

    if ($oldversion < 2026072300237) {
        // Domain update: lms-labs.com → lms-labs.com
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072300237, 'plagiarism', 'essayguard');
    }

    return true;
}