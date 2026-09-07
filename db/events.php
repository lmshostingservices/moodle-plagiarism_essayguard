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
 * Essay Guard event observers.
 *
 * These observers are the PRIMARY trigger for finalising a student attempt.
 * When a student submits an assignment, quiz, or forum post, Moodle fires the
 * relevant event. The observer looks up the latest attemptkey for that user
 * and course module from the telemetry events table, extracts the final
 * submitted text, and calls analyser::score_attempt() directly.
 *
 * This is the approach recommended by the integration notes: server-side
 * event observers are reliable because they fire even if the browser is
 * closed immediately after submit. The JavaScript finalize call in
 * tracker.js is a secondary/redundant path for low-latency use cases.
 *
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [

    // Assignment submission — fires when a student submits any assign activity.
    [
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback'  => 'plagiarism_essayguard\observer::on_assessable_submitted',
        'priority'  => 0,
        'internal'  => false,
    ],

    // Quiz attempt submission — fires when a student submits a quiz attempt.
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback'  => 'plagiarism_essayguard\observer::on_quiz_attempt_submitted',
        'priority'  => 0,
        'internal'  => false,
    ],

    // Forum assessable upload — fires for graded forum posts.
    [
        'eventname' => '\mod_forum\event\assessable_uploaded',
        'callback'  => 'plagiarism_essayguard\observer::on_assessable_submitted',
        'priority'  => 0,
        'internal'  => false,
    ],

    // V1.2.229 FIX-EG-ORPHAN-ON-CM-DELETE: purge this plugin's rows when the activity
    // they describe is deleted. Without this the rows outlive their context and become
    // permanently unreachable by the privacy framework — see observer for the detail.
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => 'plagiarism_essayguard\observer::on_course_module_deleted',
        'priority'  => 0,
        'internal'  => false,
    ],
];
