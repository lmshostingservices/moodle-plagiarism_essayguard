<?php

defined('MOODLE_INTERNAL') || die();

$functions = [
    'plagiarism_essayguard_log_event' => [
        'classname'   => 'plagiarism_essayguard\\external\\log_event',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Logs buffered Essay Guard telemetry from the browser.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities'=> '',
    ],

    'plagiarism_essayguard_finalize_attempt' => [
        'classname'   => 'plagiarism_essayguard\\external\\finalize_attempt',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Finalizes an Essay Guard attempt: runs full linguistic + behavioural analysis, updates student fingerprint, and returns the complete authenticity report.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities'=> '',
    ],

    // v1.2.54 BUG-EG-NO-BADGE-OVERVIEW: Batch badge fetch for reporter.js
    // so the quiz grading overview table can show Essay Guard risk badges
    // without requiring individual attempt-review page visits.
    'plagiarism_essayguard_get_badges' => [
        'classname'   => 'plagiarism_essayguard\\external\\get_badges',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Returns Essay Guard risk badge data (risklevel, score) for one or more students in a given activity. Used by reporter.js to inject badges into the quiz grading overview table.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities'=> 'plagiarism/essayguard:viewreport',
    ],
];
