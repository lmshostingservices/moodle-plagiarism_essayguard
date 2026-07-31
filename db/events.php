<?php
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
 * @copyright  2026 EssayGraderAI
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
];
