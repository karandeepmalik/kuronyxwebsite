<?php
$GLOBALS['__results'] = [];

class SkipTest extends RuntimeException {}

function run_test(string $name, callable $fn): void {
    try {
        $fn();
        $GLOBALS['__results'][] = ['name' => $name, 'ok' => true];
        echo "  PASS  {$name}\n";
    } catch (SkipTest $e) {
        $GLOBALS['__results'][] = ['name' => $name, 'ok' => true];
        echo "  SKIP  {$name} ({$e->getMessage()})\n";
    } catch (Throwable $e) {
        $GLOBALS['__results'][] = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()];
        echo "  FAIL  {$name}\n        " . $e->getMessage() . "\n";
    }
}

// Call from inside a test closure to skip it (recorded as a pass, printed as SKIP).
function skip_test(string $reason): void {
    throw new SkipTest($reason);
}

function assert_true($cond, string $msg = 'Assertion failed'): void {
    if (!$cond) throw new RuntimeException($msg);
}

function assert_equal($expected, $actual, string $msg = ''): void {
    if ($expected != $actual) {
        throw new RuntimeException(($msg ?: 'Assertion failed') . ' — expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_contains(string $needle, string $haystack, string $msg = ''): void {
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException(($msg ?: 'Expected string not found') . " — looking for: {$needle}");
    }
}

function print_summary(): int {
    $pass = count(array_filter($GLOBALS['__results'], fn($r) => $r['ok']));
    $fail = count($GLOBALS['__results']) - $pass;
    echo "\n" . str_repeat('-', 60) . "\n";
    echo 'Total: ' . count($GLOBALS['__results']) . "   Passed: {$pass}   Failed: {$fail}\n";
    if ($fail > 0) {
        echo "\nFailures:\n";
        foreach ($GLOBALS['__results'] as $r) {
            if (!$r['ok']) echo "  - {$r['name']}: {$r['error']}\n";
        }
    }
    return $fail > 0 ? 1 : 0;
}
