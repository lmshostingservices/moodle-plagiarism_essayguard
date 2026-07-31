<?php

namespace plagiarism_essayguard\task;

defined('MOODLE_INTERNAL') || die();

class cleanup extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('cleanup_task', 'plagiarism_essayguard');
    }

    public function execute() {
        global $DB;

        $cfg = (array) get_config('plagiarism_essayguard');
        $retentiondays = (int) ($cfg['retentiondays'] ?? 90);

        if ($retentiondays <= 0) {
            mtrace('Essay Guard cleanup: retention set to 0 — pruning disabled.');
            return;
        }

        $cutoff = time() - ($retentiondays * DAYSECS);

        // Only prune raw telemetry events — these grow large and contain redundant
        // keystroke-level data once scoring is complete.
        // Score records (plagiarism_essayguard_sc) are kept permanently so
        // teachers can review historical risk assessments and compare across
        // submissions. The score table is small (one row per attempt).
        $evdeleted = $DB->count_records_select(
            'plagiarism_essayguard_ev',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );
        $DB->delete_records_select(
            'plagiarism_essayguard_ev',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );

        mtrace("Essay Guard cleanup: deleted {$evdeleted} telemetry event(s) older than {$retentiondays} days. Score records are retained.");
    }
}
