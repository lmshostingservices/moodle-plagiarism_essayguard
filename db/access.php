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

$capabilities = [
    'plagiarism/essayguard:viewreport' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // V1.2.224 FIX-EG-RESCORE-READCAP: rescore.php was gated on :viewreport alone, a
    // capability declared 'read'. That page writes rows to plagiarism_essayguard_sc in
    // bulk and consumes vendor API calls, so a role granted read-only reporting access
    // could trigger both. A write action needs a write-captype capability of its own.
    //
    // Defaulted to the same archetypes as :viewreport so no existing site loses the
    // ability - an editing teacher or manager who could rescore yesterday still can -
    // while a site that has granted :viewreport to a non-editing role (a tutor, an
    // external examiner) now gets the read-only behaviour it was asking for.
    'plagiarism/essayguard:rescore' => [
        'riskbitmask' => RISK_PERSONAL | RISK_SPAM,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
