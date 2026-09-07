<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace plagiarism_essayguard\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\user_preference_provider;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\writer;

/**
 * Privacy provider for plagiarism_essayguard.
 *
 * BUG-EG-PRIVACY-USERLIST fix (v1.2.11): The previous delete_data_for_users()
 * method carried an approved_contextlist type-hint instead of the correct
 * approved_userlist, and the interface core_userlist_provider was not declared.
 * This caused a PHP TypeError if Moodle's privacy framework ever invoked the
 * method, and prevented bulk per-context user deletion from working correctly.
 * Fix: declare core_userlist_provider, add get_users_in_context(), and correct
 * delete_data_for_users() to accept approved_userlist.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements core_userlist_provider, metadata_provider, plugin_provider, user_preference_provider {
    /**
     * Declare everything Essay Guard stores or transmits about a user.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection The same collection, with Essay Guard's items added.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'plagiarism_essayguard_ev',
            [
                'userid'      => 'privacy:metadata:essayguard_events:userid',
                'cmid'        => 'privacy:metadata:essayguard_events:cmid',
                'contextid'   => 'privacy:metadata:essayguard_events:contextid',
                'attemptkey'  => 'privacy:metadata:essayguard_events:attemptkey',
                'eventname'   => 'privacy:metadata:essayguard_events:eventname',
                'eventtime'   => 'privacy:metadata:essayguard_events:eventtime',
                'payloadjson' => 'privacy:metadata:essayguard_events:payloadjson',
                // V1.2.229 FIX-EG-EV-METADATA-TIMECREATED: the one _ev column still missing
                // from the declaration. It is the column the cleanup task prunes on, so it is
                // also the column that decides how long the keystroke stream is kept.
                'timecreated' => 'privacy:metadata:essayguard_events:timecreated',
                ],
            'privacy:metadata:essayguard_events'
        );

        // V1.2.219: The _sc declaration listed 7 of its 30 columns and the _fp
        // declaration 2 of 13, while export_user_data() below exports columns that were
        // never declared at all (total_keystrokes, paste_events, average_wpm,
        // baseline_wpm, baseline_backspace_ratio, baseline_sentence_variance...).
        // get_metadata() is the statement a site publishes to its data subjects about
        // what it holds; understating it by 23 columns is not a technicality when those
        // columns are a keystroke-level behavioural profile of a named student. Every
        // stored column is now declared.
        $collection->add_database_table(
            'plagiarism_essayguard_sc',
            [
                'userid'               => 'privacy:metadata:essayguard_scores:userid',
                'cmid'                 => 'privacy:metadata:essayguard_scores:cmid',
                'contextid'            => 'privacy:metadata:essayguard_scores:contextid',
                'attemptkey'           => 'privacy:metadata:essayguard_scores:attemptkey',
                'qslot'                => 'privacy:metadata:essayguard_scores:qslot',
                'riskscore'            => 'privacy:metadata:essayguard_scores:riskscore',
                'risklevel'            => 'privacy:metadata:essayguard_scores:risklevel',
                'metricsjson'          => 'privacy:metadata:essayguard_scores:metricsjson',
                'explanationsjson'     => 'privacy:metadata:essayguard_scores:explanationsjson',
                'timemodified'         => 'privacy:metadata:essayguard_scores:timemodified',
                'typing_time'          => 'privacy:metadata:essayguard_scores:typing_time',
                'idle_time'            => 'privacy:metadata:essayguard_scores:idle_time',
                'total_keystrokes'     => 'privacy:metadata:essayguard_scores:total_keystrokes',
                'paste_events'         => 'privacy:metadata:essayguard_scores:paste_events',
                'backspace_count'      => 'privacy:metadata:essayguard_scores:backspace_count',
                'delete_count'         => 'privacy:metadata:essayguard_scores:delete_count',
                'cursor_moves'         => 'privacy:metadata:essayguard_scores:cursor_moves',
                'average_wpm'          => 'privacy:metadata:essayguard_scores:average_wpm',
                'wpm_std_dev'          => 'privacy:metadata:essayguard_scores:wpm_std_dev',
                'interkey_mean'        => 'privacy:metadata:essayguard_scores:interkey_mean',
                'interkey_std_dev'     => 'privacy:metadata:essayguard_scores:interkey_std_dev',
                'pause_count'          => 'privacy:metadata:essayguard_scores:pause_count',
                'pause_mean'           => 'privacy:metadata:essayguard_scores:pause_mean',
                'pause_std_dev'        => 'privacy:metadata:essayguard_scores:pause_std_dev',
                'burst_count'          => 'privacy:metadata:essayguard_scores:burst_count',
                'burst_mean'           => 'privacy:metadata:essayguard_scores:burst_mean',
                'burst_std_dev'        => 'privacy:metadata:essayguard_scores:burst_std_dev',
                'sentence_variance'    => 'privacy:metadata:essayguard_scores:sentence_variance',
                'vocab_diversity'      => 'privacy:metadata:essayguard_scores:vocab_diversity',
                'rare_word_ratio'      => 'privacy:metadata:essayguard_scores:rare_word_ratio',
                'thinking_pause_score' => 'privacy:metadata:essayguard_scores:thinking_pause_score',
                'entropy_score'        => 'privacy:metadata:essayguard_scores:entropy_score',
                'baseline_deviation'   => 'privacy:metadata:essayguard_scores:baseline_deviation',
                'baseline_status'      => 'privacy:metadata:essayguard_scores:baseline_status',
                ],
            'privacy:metadata:essayguard_scores'
        );

        $collection->add_database_table(
            'plagiarism_essayguard_fp',
            [
                'userid'                     => 'privacy:metadata:essayguard_fingerprint:userid',
                'samplecount'                => 'privacy:metadata:essayguard_fingerprint:samplecount',
                'baseline_wpm'               => 'privacy:metadata:essayguard_fingerprint:baseline_wpm',
                'baseline_pause_mean'        => 'privacy:metadata:essayguard_fingerprint:baseline_pause_mean',
                'baseline_backspace_ratio'   => 'privacy:metadata:essayguard_fingerprint:baseline_backspace_ratio',
                'baseline_burst_mean'        => 'privacy:metadata:essayguard_fingerprint:baseline_burst_mean',
                'baseline_sentence_variance' => 'privacy:metadata:essayguard_fingerprint:baseline_sentence_variance',
                'baseline_vocab_diversity'   => 'privacy:metadata:essayguard_fingerprint:baseline_vocab_diversity',
                'baseline_entropy'           => 'privacy:metadata:essayguard_fingerprint:baseline_entropy',
                'baseline_interkey_mean'     => 'privacy:metadata:essayguard_fingerprint:baseline_interkey_mean',
                'baseline_status'            => 'privacy:metadata:essayguard_fingerprint:baseline_status',
                'timemodified'               => 'privacy:metadata:essayguard_fingerprint:timemodified',
                ],
            'privacy:metadata:essayguard_fingerprint'
        );

        // V1.2.219: The plugin makes three outbound HTTPS calls to lms-labs.com
        // (licence verify, auto-unlock, platform settings) carrying the site ID and API
        // key. No student text is sent — but an external transmission that was not
        // declared here at all is exactly the sort of undisclosed third-party data flow
        // the metadata collection exists to surface, and the README claimed no external
        // call was made. Declared now.
        $collection->add_external_location_link(
            'lms_labs_licence',
            [
                'siteid' => 'privacy:metadata:lms_labs_licence:siteid',
                'apikey' => 'privacy:metadata:lms_labs_licence:apikey',
                ],
            'privacy:metadata:lms_labs_licence'
        );

        // V1.2.219: inject_tracker() writes essayguard_ak_<cmid>, and log_event.php
        // writes essayguard_lastscore_<cmid>. Neither was declared, exported, or deleted.
        $collection->add_user_preference(
            'essayguard_ak_',
            'privacy:metadata:preference:essayguard_ak'
        );
        $collection->add_user_preference(
            'essayguard_lastscore_',
            'privacy:metadata:preference:essayguard_lastscore'
        );

        return $collection;
    }

    /**
     * v1.2.219: Export the user preferences declared above. Required by
     * core_userlist_provider consumers and by Moodle's privacy self-test — an undeclared,
     * unexported preference is a silent data holding.
     *
     * @param int $userid The id of the user whose preferences are being exported.
     * @return void
     */
    public static function export_user_preferences(int $userid): void {
        global $DB;

        $prefs = $DB->get_records_select(
            'user_preferences',
            "userid = :userid AND (" . $DB->sql_like('name', ':akname') . " OR " . $DB->sql_like('name', ':lsname') . ")",
            [
                'userid' => $userid,
                'akname' => 'essayguard_ak_%',
                'lsname' => 'essayguard_lastscore_%',
            ]
        );

        foreach ($prefs as $pref) {
            // V1.2.229 FIX-EG-PRIVACY-PREF-DESCRIPTION: both preferences were exported
            // with the attempt-key description, so a student's export explained the
            // scoring-throttle marker as "the typing session key for this activity".
            // Two different holdings must not be described as the same holding.
            $descriptionkey = (strpos($pref->name, 'essayguard_lastscore_') === 0)
                ? 'privacy:metadata:preference:essayguard_lastscore'
                : 'privacy:metadata:preference:essayguard_ak';
            writer::export_user_preference(
                'plagiarism_essayguard',
                $pref->name,
                $pref->value,
                get_string($descriptionkey, 'plagiarism_essayguard')
            );
        }
    }

    /**
     * v1.2.219: THIS QUERIED ONLY THE EVENTS TABLE, WHICH IS THE ONE TABLE THAT GETS
     * PRUNED.
     *
     * The cleanup task deletes from _ev after retentiondays (default 90) and explicitly
     * KEEPS _sc forever ("Score records are retained permanently"). So 91 days after a
     * submission this method returned ZERO contexts for that student — and Moodle's
     * privacy framework asks nothing further about a user with no contexts. Their risk
     * scores, behavioural metrics and explanations were therefore never exported on a
     * subject-access request and never erased on a right-to-erasure request, while
     * remaining fully readable by teachers in the class report. That is precisely the
     * failure mode GDPR Article 17 exists to prevent, and it was guaranteed to happen to
     * every student eventually.
     *
     * Fixed by UNIONing the score table. The fingerprint table (_fp) has no contextid —
     * it is one global row per user — so it is surfaced at system context, which is where
     * export_user_data() already writes it and where delete_data_for_user() removes it.
     *
     * @param int $userid The user to look up.
     * @return contextlist The contexts holding their events or scores, plus the system
     *                     context when they still have a writing baseline.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();

        $sql = "SELECT contextid
                  FROM {plagiarism_essayguard_ev}
                 WHERE userid = :evuserid
                 UNION
                SELECT contextid
                  FROM {plagiarism_essayguard_sc}
                 WHERE userid = :scuserid";
        $contextlist->add_from_sql($sql, ['evuserid' => $userid, 'scuserid' => $userid]);

        // Fingerprint rows are per-user and context-free; attach them to system context
        // so a user whose events and scores have all been pruned but who still has a
        // baseline is not invisible to the framework.
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {plagiarism_essayguard_fp} fp ON fp.userid = :fpuserid
                 WHERE c.contextlevel = :syslevel";
        $contextlist->add_from_sql(
            $sql,
            [
                'fpuserid' => $userid,
                'syslevel' => CONTEXT_SYSTEM,
                ]
        );

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a specific context.
     * Required by core_userlist_provider.
     *
     * @param userlist $userlist The userlist to add users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        $sql = "SELECT DISTINCT userid
                  FROM {plagiarism_essayguard_ev}
                 WHERE contextid = :contextid";
        $userlist->add_from_sql('userid', $sql, ['contextid' => $context->id]);

        $sql = "SELECT DISTINCT userid
                  FROM {plagiarism_essayguard_sc}
                 WHERE contextid = :contextid";
        $userlist->add_from_sql('userid', $sql, ['contextid' => $context->id]);

        // V1.2.219: Fingerprint rows carry no contextid, so they can only be enumerated
        // at system context. Without this a site-wide erasure run at system context
        // never saw the users whose baselines it should be deleting.
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $sql = "SELECT DISTINCT userid FROM {plagiarism_essayguard_fp}";
            $userlist->add_from_sql('userid', $sql, []);
        }
    }

    /**
     * Export this user's risk scores, telemetry summary and writing baseline.
     *
     * Raw event payloads are not exported; the telemetry is summarised as per-session
     * event counts and first and last event times.
     *
     * @param approved_contextlist $contextlist The approved contexts to export from.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            // Export score records for this context.
            $scores = $DB->get_records(
                'plagiarism_essayguard_sc',
                [
                    'contextid' => $context->id,
                    'userid'    => $userid,
                    ]
            );

            foreach ($scores as $sc) {
                /*
                 * V1.2.229 FIX-EG-PRIVACY-EXPORT-UNDERSTATED: this exported 7 of the 34
                 * columns get_metadata() declares. The 27 it dropped are the whole point
                 * of the record - every behavioural signal (pause and burst statistics,
                 * inter-keystroke timing, entropy, backspace and delete counts), the
                 * baseline deviation, the derived metrics JSON, and explanationsjson,
                 * which is the plain-English case AGAINST the student that a teacher
                 * reads in the report.
                 *
                 * A subject access request is supposed to return the data held, not a
                 * summary of it, and a student contesting an academic-integrity finding
                 * is exactly the person who needs the reasoning. Declaring 34 columns and
                 * exporting 7 is worse than declaring 7: it tells the data subject the
                 * data exists and then does not give it to them.
                 *
                 * Exported straight from the record now, so a column added to the table
                 * in future cannot silently fall out of the export again.
                 */
                $data = (array)$sc;
                unset($data['id'], $data['userid'], $data['contextid'], $data['cmid']);
                $data['riskscore']    = (float)$sc->riskscore;
                $data['timemodified'] = \core_privacy\local\request\transform::datetime($sc->timemodified);
                writer::with_context(
                    $context)->export_data(
                        [
                        get_string('pluginname', 'plagiarism_essayguard'),
                        get_string('privacy:export:scores', 'plagiarism_essayguard'),
                        $sc->attemptkey,
                        ],
                    (object)$data
                );
            }

            // Export raw telemetry event counts (not raw payloads) for this context.
            $counts = $DB->get_records_sql(
                "SELECT attemptkey, COUNT(id) AS eventcount, MIN(eventtime) AS first_event, MAX(eventtime) AS last_event
                   FROM {plagiarism_essayguard_ev}
                  WHERE contextid = :contextid AND userid = :userid
               GROUP BY attemptkey",
                ['contextid' => $context->id, 'userid' => $userid]
            );

            foreach ($counts as $row) {
                $data = [
                    'attemptkey'  => $row->attemptkey,
                    'event_count' => (int)$row->eventcount,
                    // V1.2.229 FIX-EG-PRIVACY-EVENTTIME-MS: see event_time_to_seconds().
                    'first_event' => \core_privacy\local\request\transform::datetime(
                        self::event_time_to_seconds((int)$row->first_event)
                    ),
                    'last_event'  => \core_privacy\local\request\transform::datetime(
                        self::event_time_to_seconds((int)$row->last_event)
                    ),
                ];
                writer::with_context(
                    $context)->export_data(
                        [
                        get_string('pluginname', 'plagiarism_essayguard'),
                        get_string('privacy:export:telemetry', 'plagiarism_essayguard'),
                        $row->attemptkey,
                        ],
                    (object)$data
                );
            }
        }

        // Export fingerprint (global to user, not per-context).
        $fp = $DB->get_record('plagiarism_essayguard_fp', ['userid' => $userid]);
        if ($fp) {
            $contextsystem = \context_system::instance();
            // V1.2.229 FIX-EG-PRIVACY-EXPORT-UNDERSTATED: five of the eight declared
            // baseline columns (pause mean, burst mean, vocabulary diversity, entropy,
            // inter-keystroke mean) were declared and never exported. The baseline is the
            // longest-lived record this plugin keeps - it survives the retention prune
            // that clears the raw telemetry - so it is the one a data subject is most
            // likely to ask about.
            $fpdata = (array)$fp;
            unset($fpdata['id'], $fpdata['userid']);
            $fpdata['timemodified'] = \core_privacy\local\request\transform::datetime($fp->timemodified);
            writer::with_context(
                $contextsystem)->export_data(
                    [
                    get_string('pluginname', 'plagiarism_essayguard'),
                    get_string('privacy:export:fingerprint', 'plagiarism_essayguard'),
                    ],
                (object)$fpdata
            );
        }
    }

    /**
     * V1.2.229 FIX-EG-PRIVACY-EVENTTIME-MS: plagiarism_essayguard_ev.eventtime holds
     * MILLISECONDS, not seconds.
     *
     * tracker.js stamps every event with Date.now(), log_event.php stores that value
     * verbatim, and analyser.php reads it back as milliseconds throughout - its pause
     * thresholds are literally 500, 2000 and 10000 ms. The export handed the raw value to
     * transform::datetime(), which reads a UNIX timestamp in seconds. A 2026 keystroke
     * therefore came back to the data subject dated somewhere around the year 55,000.
     *
     * Every telemetry summary in every Essay Guard subject access request ever produced
     * carried those dates, and they are the only times in the export a student could use
     * to check the record actually describes the session they think it does.
     *
     * Converted here rather than in the table, because the millisecond resolution is what
     * the analyser needs and the stored data is not wrong - only its interpretation was.
     * Values that are already plausible seconds are passed through unchanged so a row
     * written by anything other than tracker.js is not divided by a thousand.
     *
     * @param int $eventtime The raw eventtime column value.
     * @return int A UNIX timestamp in seconds.
     */
    private static function event_time_to_seconds(int $eventtime): int {
        // 100000000000 is 5138-11-16 in seconds and 1973-03-03 in milliseconds: any
        // real telemetry timestamp above it is milliseconds, any below it is seconds.
        return $eventtime > 100000000000 ? intdiv($eventtime, 1000) : $eventtime;
    }

    /**
     * Delete every user's Essay Guard events and scores in one context.
     *
     * Writing baselines are per-user rather than per-context and are not touched here.
     *
     * @param \context $context The context being purged.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        $DB->delete_records('plagiarism_essayguard_ev', ['contextid' => $context->id]);
        $DB->delete_records('plagiarism_essayguard_sc', ['contextid' => $context->id]);
        // V1.2.229 FIX-EG-PRIVACY-CONTEXT-PREFS: the two preferences this plugin writes
        // are named per course module (essayguard_ak_<cmid>, essayguard_lastscore_<cmid>),
        // so purging a module context has to take them with it. Without this, purging an
        // activity left every student's typing-session key for that activity behind in
        // user_preferences, pointing at data that no longer exists.
        if ($context->contextlevel == CONTEXT_MODULE) {
            self::delete_preferences_for_cmid((int)$context->instanceid);
        }
        // Fingerprint table is per-user (not per-context) — not deleted here.
    }

    /**
     * V1.2.229: Remove both essayguard_* preferences belonging to one course module,
     * for every user who has them.
     *
     * @param int $cmid The course module whose preferences are being cleared.
     * @return void
     */
    private static function delete_preferences_for_cmid(int $cmid): void {
        global $DB;

        if ($cmid <= 0) {
            return;
        }
        $DB->delete_records_select(
            'user_preferences',
            'name = :akname OR name = :lsname',
            [
                'akname' => 'essayguard_ak_' . $cmid,
                'lsname' => 'essayguard_lastscore_' . $cmid,
            ]
        );
    }

    /**
     * Delete one user's Essay Guard data in the approved contexts, and their baseline.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete from.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $DB->delete_records('plagiarism_essayguard_ev', ['contextid' => $context->id, 'userid' => $userid]);
            $DB->delete_records('plagiarism_essayguard_sc', ['contextid' => $context->id, 'userid' => $userid]);
        }
        // Delete the user's fingerprint record completely.
        $DB->delete_records('plagiarism_essayguard_fp', ['userid' => $userid]);

        // V1.2.219: Also erase the undeclared user preferences this plugin writes
        // (essayguard_ak_<cmid>, essayguard_lastscore_<cmid>). Before this, a completed
        // erasure request left the student's attempt keys sitting in user_preferences.
        self::delete_preferences_for_users($userid);
    }

    /**
     * v1.2.219: Remove the essayguard_* user preferences for one or more users.
     *
     * @param int|array $userids A single user id, or an array of user ids, to clear
     *                            the essayguard_* preferences for.
     * @return void
     */
    private static function delete_preferences_for_users($userids): void {
        global $DB;

        $userids = is_array($userids) ? $userids : [$userids];
        if (empty($userids)) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $DB->delete_records_select(
            'user_preferences',
            "userid {$insql} AND ("
                . $DB->sql_like('name', ':akname') . " OR "
                . $DB->sql_like('name', ':lsname') . ")",
            array_merge(
                $inparams,
                [
                    'akname' => 'essayguard_ak_%',
                    'lsname' => 'essayguard_lastscore_%',
                    ]
            )
        );
    }

    /**
     * Delete multiple users' data within a specific context.
     * Required by core_userlist_provider.
     *
     * BUG-EG-PRIVACY-USERLIST fix: previously this method had the wrong
     * parameter type (approved_contextlist instead of approved_userlist),
     * causing a PHP TypeError when Moodle's privacy framework invokes it
     * for bulk per-context user deletion.
     *
     * @param approved_userlist $userlist The approved list of users to delete data for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $DB->delete_records_select(
            'plagiarism_essayguard_ev',
            "contextid = :contextid AND userid {$insql}",
            array_merge(['contextid' => $context->id], $inparams)
        );

        $DB->delete_records_select(
            'plagiarism_essayguard_sc',
            "contextid = :contextid AND userid {$insql}",
            array_merge(['contextid' => $context->id], $inparams)
        );

        // Fingerprints are global per-user — delete for all approved users.
        [$insql2, $inparams2] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('plagiarism_essayguard_fp', "userid {$insql2}", $inparams2);

        // V1.2.219: and the user preferences — see delete_preferences_for_users().
        self::delete_preferences_for_users($userids);
    }
}
