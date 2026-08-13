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
 * Essay Guard v1.1.0 — Full Test Suite Runner
 *
 * Executes all four test modules and reports a combined summary.
 *
 * Usage:
 *   php moodle-plugin/plagiarism_essayguard/tests/run_all.php
 *
 * Or from inside the tests/ directory:
 *   php run_all.php
 * @package    plagiarism_essayguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

$dir   = __DIR__;
$start = microtime(true);

// Each test file runs its own TestRunner::summary() at the end.
// We capture exit codes via output buffering + ob_start trick isn't needed
// since TestRunner::get_failed() is static — we just run them in sequence.

$suites = [
    'Linguistic'   => $dir . '/test_linguistic.php',
    'Analyser'     => $dir . '/test_analyser.php',
    'Fingerprint'  => $dir . '/test_fingerprint.php',
    'Explainer'    => $dir . '/test_explainer.php',
];

// Print header
echo "\n";
echo "\033[1m\033[35m";
echo "╔══════════════════════════════════════════════════════╗\n";
echo "║     Essay Guard v1.2.113 — PHP Test Suite            ║\n";
echo "║     Standalone — no Moodle installation required     ║\n";
echo "╚══════════════════════════════════════════════════════╝\n";
echo "\033[0m\n";

foreach ($suites as $name => $file) {
    if (!file_exists($file)) {
        echo "\033[31m  ✗ Missing test file: {$file}\033[0m\n";
        continue;
    }
    require $file;
}

$elapsed = round(microtime(true) - $start, 3);
echo "\n\033[1m\033[35m  Total runtime: {$elapsed}s\033[0m\n\n";
