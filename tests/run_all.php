<?php
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
