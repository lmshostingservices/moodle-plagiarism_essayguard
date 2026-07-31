<?php

namespace plagiarism_essayguard\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\core_userlist_provider;
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
 * @copyright  2026 EssayGraderAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements metadata_provider, plugin_provider, core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('plagiarism_essayguard_ev', [
            'userid'      => 'privacy:metadata:essayguard_events:userid',
            'cmid'        => 'privacy:metadata:essayguard_events:cmid',
            'contextid'   => 'privacy:metadata:essayguard_events:contextid',
            'attemptkey'  => 'privacy:metadata:essayguard_events:attemptkey',
            'eventname'   => 'privacy:metadata:essayguard_events:eventname',
            'eventtime'   => 'privacy:metadata:essayguard_events:eventtime',
            'payloadjson' => 'privacy:metadata:essayguard_events:payloadjson',
        ], 'privacy:metadata:essayguard_events');

        $collection->add_database_table('plagiarism_essayguard_sc', [
            'userid'       => 'privacy:metadata:essayguard_scores:userid',
            'cmid'         => 'privacy:metadata:essayguard_scores:cmid',
            'attemptkey'   => 'privacy:metadata:essayguard_scores:attemptkey',
            'riskscore'    => 'privacy:metadata:essayguard_scores:riskscore',
            'risklevel'    => 'privacy:metadata:essayguard_scores:risklevel',
            'metricsjson'  => 'privacy:metadata:essayguard_scores:metricsjson',
            'timemodified' => 'privacy:metadata:essayguard_scores:timemodified',
        ], 'privacy:metadata:essayguard_scores');

        $collection->add_database_table('plagiarism_essayguard_fp', [
            'userid'       => 'privacy:metadata:essayguard_fingerprint:userid',
            'samplecount'  => 'privacy:metadata:essayguard_fingerprint:samplecount',
        ], 'privacy:metadata:essayguard_fingerprint');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        $sql = "SELECT DISTINCT contextid
                  FROM {plagiarism_essayguard_ev}
                 WHERE userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);
        return $contextlist;
    }

    /**
     * Get the list of users who have data within a specific context.
     * Required by core_userlist_provider.
     *
     * @param userlist $userlist The userlist to add users to.
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
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            // Export score records for this context.
            $scores = $DB->get_records('plagiarism_essayguard_sc', [
                'contextid' => $context->id,
                'userid'    => $userid,
            ]);

            foreach ($scores as $sc) {
                $data = [
                    'attemptkey'       => $sc->attemptkey,
                    'risklevel'        => $sc->risklevel,
                    'riskscore'        => (float)$sc->riskscore,
                    'total_keystrokes' => (int)$sc->total_keystrokes,
                    'paste_events'     => (int)$sc->paste_events,
                    'average_wpm'      => (float)$sc->average_wpm,
                    'timemodified'     => \core_privacy\local\request\transform::datetime($sc->timemodified),
                ];
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'plagiarism_essayguard'), 'scores', $sc->attemptkey],
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
                    'first_event' => \core_privacy\local\request\transform::datetime((int)$row->first_event),
                    'last_event'  => \core_privacy\local\request\transform::datetime((int)$row->last_event),
                ];
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'plagiarism_essayguard'), 'telemetry', $row->attemptkey],
                    (object)$data
                );
            }
        }

        // Export fingerprint (global to user, not per-context).
        $fp = $DB->get_record('plagiarism_essayguard_fp', ['userid' => $userid]);
        if ($fp) {
            $context_system = \context_system::instance();
            writer::with_context($context_system)->export_data(
                [get_string('pluginname', 'plagiarism_essayguard'), 'fingerprint'],
                (object)[
                    'samplecount'               => (int)$fp->samplecount,
                    'baseline_status'           => $fp->baseline_status,
                    'baseline_wpm'              => (float)$fp->baseline_wpm,
                    'baseline_backspace_ratio'  => (float)$fp->baseline_backspace_ratio,
                    'baseline_sentence_variance'=> (float)$fp->baseline_sentence_variance,
                    'timemodified'              => \core_privacy\local\request\transform::datetime($fp->timemodified),
                ]
            );
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        $DB->delete_records('plagiarism_essayguard_ev', ['contextid' => $context->id]);
        $DB->delete_records('plagiarism_essayguard_sc', ['contextid' => $context->id]);
        // Fingerprint table is per-user (not per-context) — not deleted here.
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $DB->delete_records('plagiarism_essayguard_ev', ['contextid' => $context->id, 'userid' => $userid]);
            $DB->delete_records('plagiarism_essayguard_sc', ['contextid' => $context->id, 'userid' => $userid]);
        }
        // Delete the user's fingerprint record completely.
        $DB->delete_records('plagiarism_essayguard_fp', ['userid' => $userid]);
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
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

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
        list($insql2, $inparams2) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('plagiarism_essayguard_fp', "userid {$insql2}", $inparams2);
    }
}
