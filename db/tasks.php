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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
    // Use '*/5' for every-5-minutes; use 'R' only as a standalone value.
    [
        'classname' => 'plagiarism_essayguard\\task\\rescore_pending',
        'blocking'   => 0,
        'minute'     => '*/5',
        'hour'       => '*',
        'day'        => '*',
        'dayofweek'  => '*',
        'month'      => '*',
    ],
    // V1.2.219: The licence-verify and platform-settings HTTPS calls to lms-labs.com
    // used to happen inline on whichever unlucky page request found the 30-minute cache
    // expired — including quiz submission and every grading-page render. They now happen
    // here, off the request path, and the request path reads the cache only.
    // Every 15 minutes so the 30-minute cache is always refreshed before it goes stale.
    [
        'classname'  => 'plagiarism_essayguard\\task\\refresh_licence',
        'blocking'   => 0,
        'minute'     => '*/15',
        'hour'       => '*',
        'day'        => '*',
        'dayofweek'  => '*',
        'month'      => '*',
    ],
];
