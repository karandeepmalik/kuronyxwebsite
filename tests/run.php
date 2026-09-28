<?php
// Integration test runner. No framework, no Composer (none is installed and the
// project otherwise has no build step) — just curl against a real `php -S` instance
// backed by a disposable SQLite database, exercising the actual page scripts.
//
// Usage:  php tests/run.php
declare(strict_types=1);

// The default php.ini on this machine has neither curl nor pdo_sqlite enabled
// (only local-dev/php.ini, used for the built-in server, does). Re-exec under an
// ini that has both, so `php tests/run.php` works with no extra flags needed.
if (!extension_loaded('curl') || !extension_loaded('pdo_sqlite')) {
    $extDir = null;
    $localIni = __DIR__ . '/../local-dev/php.ini';
    if (is_file($localIni)) {
        foreach (file($localIni) as $line) {
            if (preg_match('/^extension_dir\s*=\s*"?([^"]+)"?/', trim($line), $m)) { $extDir = $m[1]; break; }
        }
    }
    $extDir = $extDir ?? ini_get('extension_dir');
    $bootIni = tempnam(sys_get_temp_dir(), 'kuronyx-boot-') . '.ini';
    file_put_contents($bootIni, "extension_dir=\"{$extDir}\"\nextension=curl\nextension=pdo_sqlite\nextension=fileinfo\nextension=mbstring\n");
    $proc = proc_open(['php', '-c', $bootIni, __FILE__], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    $code = proc_close($proc);
    @unlink($bootIni);
    exit($code);
}

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/server.php';

$root = dirname(__DIR__);
$port = 8974;
$env  = new TestEnv($root, $port);

register_shutdown_function(fn() => $env->tearDown());

echo "Kuronyx test suite — starting local server on {$env->baseUrl}\n";
$env->setUp();
echo "Server up. DB: {$env->dbPath}\n\n";

$caseFiles = glob(__DIR__ . '/cases/*.php');
sort($caseFiles);
foreach ($caseFiles as $file) {
    echo '== ' . basename($file) . " ==\n";
    (require $file)($env);
    echo "\n";
}

$exitCode = print_summary();
if ($exitCode !== 0) {
    echo "\nServer log ({$env->logPath}):\n" . @file_get_contents($env->logPath) . "\n";
}

$env->tearDown();
exit($exitCode);
