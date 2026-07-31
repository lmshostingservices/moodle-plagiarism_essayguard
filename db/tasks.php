<?php

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'plagiarism_essayguard\\task\\cleanup',
        'blocking'   => 0,
        'minute'     => 'R',
        'hour'       => '3',
        'day'        => '*',
        'dayofweek'  => '*',
        'month'      => '*',
    ],
    // FIX-EG-RESCORE-TASK (v1.2.96): Re-score attempts where events arrived after
    // the observer had already written riskscore=0. Runs every 5 minutes.
    // NOTE: Moodle's eval_cron_field() does NOT support 'R/5' (random+step).
    //       Use '*/5' for every-5-minutes; use 'R' only as a standalone value.
    [
        'classname' => 'plagiarism_essayguard\\task\\rescore_pending',
        'blocking'   => 0,
        'minute'     => '*/5',
        'hour'       => '*',
        'day'        => '*',
        'dayofweek'  => '*',
        'month'      => '*',
    ],
];
