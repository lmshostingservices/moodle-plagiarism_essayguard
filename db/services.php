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

/**
 * plagiarism_essayguard file.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

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
