<?php
/**
 * Essay Guard Test Bootstrap
 *
 * Defines all Moodle stubs needed to load and run the service classes
 * without a real Moodle installation. Load this file first in every test.
 *
 * @package plagiarism_essayguard
 */

// ── Moodle internal guard ───────────────────────────────────────────────────
define('MOODLE_INTERNAL', true);

// ── Colour constants for terminal output ───────────────────────────────────
define('CLR_RESET',  "\033[0m");
define('CLR_GREEN',  "\033[32m");
define('CLR_RED',    "\033[31m");
define('CLR_YELLOW', "\033[33m");
define('CLR_CYAN',   "\033[36m");
define('CLR_BOLD',   "\033[1m");

// ── Moodle stub: get_config ─────────────────────────────────────────────────
function get_config(string $plugin, ?string $key = null) {
    static $config = [
        'plagiarism_essayguard' => [
            'enabled'       => '1',
            'minchars'      => '120',
            'maxburstchars' => '150',
            'captureinterval' => '5000',
            'allowpaste'    => '1',
        ],
    ];
    if ($key !== null) {
        return $config[$plugin][$key] ?? false;
    }
    return (object)($config[$plugin] ?? []);
}

// ── Moodle stub: get_string ──────────────────────────────────────────────────
// Returns the string key so explainer tests can assert on key names
function get_string(string $identifier, string $component = '', $a = null): string {
    if ($a !== null) {
        $suffix = is_array($a) ? json_encode($a) : (string)$a;
        return "[{$identifier}:{$suffix}]";
    }
    return "[{$identifier}]";
}

// ── Mock Database ────────────────────────────────────────────────────────────
class MockDB {
    private array $events      = [];
    private ?object $fingerprint = null;
    private array $scores      = [];

    public function load_events(array $events): void {
        $this->events = $events;
    }

    public function load_fingerprint(?array $fp): void {
        $this->fingerprint = $fp !== null ? (object)$fp : null;
    }

    public function get_records(string $table, array $filters = [], string $sort = ''): array {
        if ($table === 'plagiarism_essayguard_ev') {
            $result = [];
            foreach ($this->events as $i => $ev) {
                $obj = (object)$ev;
                $obj->id = $i + 1;
                $result[$i + 1] = $obj;
            }
            return $result;
        }
        return [];
    }

    public function get_record(string $table, array $filters = []): object|false {
        if ($table === 'plagiarism_essayguard_fp') {
            return $this->fingerprint ?? false;
        }
        if ($table === 'plagiarism_essayguard_sc') {
            return false;
        }
        return false;
    }

    public function insert_record(string $table, object $record): int {
        return 1;
    }

    public function update_record(string $table, object $record): bool {
        return true;
    }
}

// ── Global $DB ───────────────────────────────────────────────────────────────
$DB = new MockDB();

// ── Autoload service classes ─────────────────────────────────────────────────
$base = __DIR__ . '/../classes/local/service';
require_once $base . '/linguistic.php';
require_once $base . '/fingerprint.php';
require_once $base . '/explainer.php';
require_once $base . '/analyser.php';

// ── Test framework ───────────────────────────────────────────────────────────
class TestRunner {
    private static int $passed  = 0;
    private static int $failed  = 0;
    private static int $total   = 0;
    private static string $suite = '';

    public static function suite(string $name): void {
        self::$suite = $name;
        echo CLR_BOLD . CLR_CYAN . "\n══════════════════════════════════════════\n";
        echo "  {$name}\n";
        echo "══════════════════════════════════════════" . CLR_RESET . "\n";
    }

    public static function assert(string $label, bool $condition, string $detail = ''): void {
        self::$total++;
        if ($condition) {
            self::$passed++;
            echo CLR_GREEN . "  ✓ " . CLR_RESET . $label . "\n";
        } else {
            self::$failed++;
            echo CLR_RED . "  ✗ " . CLR_RESET . $label;
            if ($detail !== '') {
                echo CLR_YELLOW . "  → {$detail}" . CLR_RESET;
            }
            echo "\n";
        }
    }

    public static function assert_equals(string $label, mixed $expected, mixed $actual): void {
        $ok     = $expected === $actual;
        $detail = $ok ? '' : "expected " . var_export($expected, true) . ", got " . var_export($actual, true);
        self::assert($label, $ok, $detail);
    }

    public static function assert_range(string $label, float $min, float $max, float $actual): void {
        $ok     = $actual >= $min && $actual <= $max;
        $detail = $ok ? '' : "expected {$min}–{$max}, got {$actual}";
        self::assert($label, $ok, $detail);
    }

    public static function assert_gte(string $label, float $min, float $actual): void {
        $ok     = $actual >= $min;
        $detail = $ok ? '' : "expected ≥ {$min}, got {$actual}";
        self::assert($label, $ok, $detail);
    }

    public static function assert_lte(string $label, float $max, float $actual): void {
        $ok     = $actual <= $max;
        $detail = $ok ? '' : "expected ≤ {$max}, got {$actual}";
        self::assert($label, $ok, $detail);
    }

    public static function assert_contains(string $label, string $needle, array $haystack): void {
        $found  = false;
        foreach ($haystack as $item) {
            if (str_contains($item, $needle)) {
                $found = true;
                break;
            }
        }
        $detail = $found ? '' : "'{$needle}' not found in [" . implode(', ', array_map(fn($s) => "'{$s}'", $haystack)) . "]";
        self::assert($label, $found, $detail);
    }

    public static function summary(): void {
        $failed = self::$failed;
        $passed = self::$passed;
        $total  = self::$total;
        echo CLR_BOLD . "\n══════════════════════════════════════════\n";
        echo "  RESULTS: {$passed}/{$total} passed";
        if ($failed > 0) {
            echo CLR_RED . "  ({$failed} failed)" . CLR_RESET . CLR_BOLD;
        }
        echo "\n══════════════════════════════════════════" . CLR_RESET . "\n\n";
    }

    public static function get_failed(): int {
        return self::$failed;
    }
}

// ── Event builder helpers ─────────────────────────────────────────────────────

/**
 * Build a keydown event object.
 * @param int $time   Event time in ms
 * @param int $ikd    Inter-key delay in ms (time since previous keydown)
 */
function ev_keydown(int $time, int $ikd = 0): array {
    return [
        'userid'      => 1,
        'cmid'        => 1,
        'attemptkey'  => 'test',
        'eventname'   => 'keydown',
        'eventtime'   => $time,
        'payloadjson' => json_encode(['ikd' => $ikd]),
    ];
}

function ev_backspace(int $time, int $ikd = 0): array {
    return [
        'userid'      => 1,
        'cmid'        => 1,
        'attemptkey'  => 'test',
        'eventname'   => 'backspace',
        'eventtime'   => $time,
        'payloadjson' => json_encode(['ikd' => $ikd]),
    ];
}

function ev_paste(int $time, int $insertlen = 200): array {
    return [
        'userid'      => 1,
        'cmid'        => 1,
        'attemptkey'  => 'test',
        'eventname'   => 'paste',
        'eventtime'   => $time,
        'payloadjson' => json_encode(['insertlen' => $insertlen]),
    ];
}

function ev_drop_paste(int $time, int $insertlen = 200): array {
    return [
        'userid'      => 1,
        'cmid'        => 1,
        'attemptkey'  => 'test',
        'eventname'   => 'drop_paste',
        'eventtime'   => $time,
        'payloadjson' => json_encode(['insertlen' => $insertlen]),
    ];
}

function ev_input(int $time, int $addedchars = 1, int $insertlen = 1): array {
    return [
        'userid'      => 1,
        'cmid'        => 1,
        'attemptkey'  => 'test',
        'eventname'   => 'input',
        'eventtime'   => $time,
        'payloadjson' => json_encode(['addedchars' => $addedchars, 'insertlen' => $insertlen]),
    ];
}

function ev_wpm(int $time, int $wpm): array {
    return [
        'userid'      => 1,
        'cmid'        => 1,
        'attemptkey'  => 'test',
        'eventname'   => 'wpm_snapshot',
        'eventtime'   => $time,
        'payloadjson' => json_encode(['wpm' => $wpm]),
    ];
}

function ev_burst_end(int $time, int $wordcount): array {
    return [
        'userid'      => 1,
        'cmid'        => 1,
        'attemptkey'  => 'test',
        'eventname'   => 'burst_end',
        'eventtime'   => $time,
        'payloadjson' => json_encode(['wordcount' => $wordcount]),
    ];
}

function ev_selection(int $time, int $cursormoves = 1): array {
    return [
        'userid'      => 1,
        'cmid'        => 1,
        'attemptkey'  => 'test',
        'eventname'   => 'selection',
        'eventtime'   => $time,
        'payloadjson' => json_encode(['cursormoves' => $cursormoves]),
    ];
}

/**
 * Generate a realistic natural typing sequence.
 * Produces keydown events with varied IKD (80-400ms), periodic backspaces,
 * input events building up chars, and thinking pauses every ~500ms of type-time.
 *
 * @param int   $start       Start timestamp (ms)
 * @param int   $charcount   Number of characters to type
 * @param int   $backspaces  Number of backspace events to inject
 * @param int   $pauses      Number of thinking pauses (3-5s gaps) to inject
 * @param array $wpm_vals    WPM snapshots to emit
 */
function build_natural_typing(
    int $start,
    int $charcount = 220,
    int $backspaces = 18,
    int $pauses = 3,
    array $wpm_vals = [38, 42, 35, 40]
): array {
    $events  = [];
    $t       = $start;
    $total   = $charcount + $backspaces;
    $pause_every = (int)($total / ($pauses + 1));

    for ($i = 0; $i < $total; $i++) {
        // Inject a thinking pause every N keystrokes
        if ($pauses > 0 && $i > 0 && $i % $pause_every === 0) {
            $t += rand(3000, 5000);
            $pauses--;
        }

        // Vary IKD naturally: Gaussian-like via multiple rands
        $ikd = rand(80, 180) + rand(0, 120) + rand(-20, 60);
        $ikd = max(40, $ikd);
        $t  += $ikd;

        if ($backspaces > 0 && ($i % (int)($total / $backspaces) === 0)) {
            $events[] = ev_backspace($t, $ikd);
            $backspaces--;
        } else {
            $events[] = ev_keydown($t, $ikd);
        }
    }

    // One input event covering all chars
    $events[] = ev_input($t + 50, $charcount, 1);

    // WPM snapshots
    $wpm_interval = (int)(($t - $start) / (count($wpm_vals) + 1));
    foreach ($wpm_vals as $idx => $wpm) {
        $events[] = ev_wpm($start + $wpm_interval * ($idx + 1), $wpm);
    }

    return $events;
}

/**
 * Generate a robotic typing sequence — simulates re-typing a ChatGPT answer.
 * Very uniform IKD, almost no backspaces, no pauses.
 *
 * @param int $start     Start timestamp (ms)
 * @param int $charcount Characters to type
 * @param int $ikd_ms    Fixed IKD (ms) — typically 60-75ms
 */
function build_robotic_typing(
    int $start,
    int $charcount = 250,
    int $ikd_ms = 68
): array {
    $events = [];
    $t      = $start;

    // 248 keydowns + 2 backspaces
    for ($i = 0; $i < $charcount - 2; $i++) {
        $jitter = rand(-3, 3); // minimal variance
        $t += $ikd_ms + $jitter;
        $events[] = ev_keydown($t, $ikd_ms + $jitter);
    }
    // 2 backspaces near the end
    $t += $ikd_ms;
    $events[] = ev_backspace($t, $ikd_ms);
    $t += $ikd_ms;
    $events[] = ev_backspace($t, $ikd_ms);

    // Input event: total chars including corrections
    $events[] = ev_input($t + 30, $charcount, 1);

    return $events;
}
