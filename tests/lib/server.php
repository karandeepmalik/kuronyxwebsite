<?php
// Spins up a throwaway PHP built-in server (php -S) against public_html/, backed by
// a fresh disposable SQLite database, so tests exercise the real endpoints exactly as
// a browser would — no mocking of routing, sessions, or PDO.
//
// This temporarily overwrites public_html/includes/db-config.php (the gitignored,
// machine-specific config) for the duration of the run and restores whatever was
// there before on teardown, so it never disturbs a developer's own local-dev setup.
final class TestEnv {
    public string $root;
    public string $tmpDir;
    public string $dbPath;
    public string $storageDir;
    public string $baseUrl;
    public string $cookieJarStaff;
    public string $cookieJarAnon;
    public string $logPath = '';
    public string $dryRunEmailsPath;
    public array $shared = [];
    // True when the suite runs against a throwaway MySQL/MariaDB database instead of SQLite — set
    // KURONYX_TEST_MYSQL="host=127.0.0.1;port=3306;user=root;pass=secret" (the user needs CREATE/DROP
    // DATABASE). The suite creates its own kuronyx_test_* database and drops it afterwards.
    public bool $isMysql = false;
    public string $iniPath = '';
    public array $mysql = [];
    public string $mysqlDbName = '';

    private int $port;
    private string $configPath;
    private ?string $configBackup = null;
    private $proc = null;
    private bool $tornDown = false;

    public function __construct(string $projectRoot, int $port) {
        $this->root       = $projectRoot;
        $this->port       = $port;
        $this->tmpDir     = sys_get_temp_dir() . '/kuronyx-tests-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->dbPath     = $this->tmpDir . '/test.sqlite';
        $this->storageDir = $this->tmpDir . '/private_storage';
        $this->baseUrl    = "http://127.0.0.1:{$port}";
        $this->cookieJarStaff = $this->tmpDir . '/cookies-staff.txt';
        $this->cookieJarAnon  = $this->tmpDir . '/cookies-anon.txt';
        $this->dryRunEmailsPath = $this->tmpDir . '/dry-run-emails.jsonl';
        $this->configPath = $projectRoot . '/public_html/includes/db-config.php';
        $dsn = (string) getenv('KURONYX_TEST_MYSQL');
        if ($dsn !== '') {
            parse_str(str_replace(';', '&', $dsn), $this->mysql);
            $this->mysql += ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => ''];
            $this->isMysql = true;
            $this->mysqlDbName = 'kuronyx_test_' . bin2hex(random_bytes(4));
        }

        mkdir($this->tmpDir, 0777, true);
        mkdir($this->storageDir, 0777, true);
        mkdir($this->tmpDir . '/sessions', 0777, true);
    }

    public function setUp(): void {
        // setup.php is kept out of public_html (see tools/setup.php) so it is never deployed; the
        // setup tests need it served, so it is copied in for the duration of the run.
        copy($this->root . '/tools/setup.php', $this->root . '/public_html/admin/setup.php');
        if (file_exists($this->configPath)) {
            $this->configBackup = file_get_contents($this->configPath);
        }
        $config = "<?php\n"
            . "if (basename(\$_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(403); exit('Forbidden.'); }\n"
            . ($this->isMysql
                ? "define('DB_DRIVER', 'mysql');\n"
                  . "define('DB_HOST', " . var_export($this->mysql['host'], true) . ");\n"
                  . "define('DB_PORT', " . (int) $this->mysql['port'] . ");\n"
                  . "define('DB_NAME', " . var_export($this->mysqlDbName, true) . ");\n"
                  . "define('DB_USER', " . var_export($this->mysql['user'], true) . ");\n"
                  . "define('DB_PASS', " . var_export($this->mysql['pass'], true) . ");\n"
                : "define('DB_DRIVER', 'sqlite');\n"
                  . "define('DB_HOST', '');\n"
                  . "define('DB_NAME', " . var_export($this->dbPath, true) . ");\n"
                  . "define('DB_USER', '');\n"
                  . "define('DB_PASS', '');\n")
            . "define('PRIVATE_STORAGE_PATH', " . var_export($this->storageDir, true) . ");\n"
            . "define('SETUP_TOKEN', 'test-setup-token');\n"
            . "define('BREVO_API_KEY', 'test-key-not-real');\n"
            . "define('SENDER_EMAIL', 'hello@kuronyx.in');\n"
            . "define('RECEIVER_EMAIL', 'ops@kuronyx.in');\n"
            . "define('SENDER_NAME', 'Kuronyx Sciences');\n"
            . "define('BREVO_TEMPLATE_ID', 1);\n"
            . "define('BREVO_ENQUIRY_TEMPLATE_ID', 3);\n"
            . "define('BREVO_LIST_ID', 7);\n"
            . "define('BREVO_VERIFIED_SENDERS', ['hello@kuronyx.in' => 'Kuronyx Sciences', 'ops@kuronyx.in' => 'Kuronyx Sciences Ops']);\n"
            . "define('BREVO_WEBHOOK_SECRET', 'test-webhook-secret-not-real');\n"
            . "define('EMAIL_DRY_RUN', true);\n"
            . "define('EMAIL_DRY_RUN_LOG', " . var_export($this->dryRunEmailsPath, true) . ");\n";
        file_put_contents($this->configPath, $config);

        if ($this->isMysql) {
            $this->createMysqlDatabase();
        } else {
            $pdo = new PDO('sqlite:' . $this->dbPath);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec(file_get_contents($this->root . '/db/schema.sqlite.sql'));
            $pdo = null;
        }

        $this->startServer();
    }

    private function mysqlServerPdo(?string $db = null): PDO {
        $dsn = 'mysql:host=' . $this->mysql['host'] . ';port=' . (int) $this->mysql['port'] . ($db !== null ? ';dbname=' . $db : '') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $this->mysql['user'], $this->mysql['pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    private function createMysqlDatabase(): void {
        require_once __DIR__ . '/schema.php';
        $server = $this->mysqlServerPdo();
        $server->exec('CREATE DATABASE ' . $this->mysqlDbName . ' CHARACTER SET utf8mb4');
        $db = $this->mysqlServerPdo($this->mysqlDbName);
        // The real production schema, statement by statement (comments stripped first so a
        // semicolon inside one can't split a statement).
        foreach (array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", schema_strip_comments(file_get_contents($this->root . '/db/schema.sql')))))) as $stmt) {
            $db->exec($stmt);
        }
    }

    private function extensionDir(): string {
        $localIni = $this->root . '/local-dev/php.ini';
        if (file_exists($localIni)) {
            foreach (file($localIni) as $line) {
                if (preg_match('/^extension_dir\s*=\s*"?([^"]+)"?/', trim($line), $m)) {
                    return $m[1];
                }
            }
        }
        return (string) ini_get('extension_dir');
    }

    private function startServer(): void {
        $iniPath = $this->tmpDir . '/php.ini';
        $ini = 'extension_dir = "' . $this->extensionDir() . "\"\n"
             . "extension=pdo_sqlite\n"
             . "extension=pdo_mysql\n"
             . "extension=fileinfo\n"
             . "extension=mbstring\n"
             . "display_errors = On\n"
             . "error_reporting = E_ALL\n"
             . 'session.save_path = "' . $this->tmpDir . "/sessions\"\n"
             . "file_uploads = On\n"
             . "upload_max_filesize = 12M\n"
             . "post_max_size = 12M\n";
        file_put_contents($iniPath, $ini);
        $this->iniPath = $iniPath;

        $this->logPath = $this->tmpDir . '/server.log';
        $cmd = ['php', '-c', $iniPath, '-S', "127.0.0.1:{$this->port}", '-t', $this->root . '/public_html'];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $this->logPath, 'w'], 2 => ['file', $this->logPath, 'w']];
        $pipes = [];
        // Passing the command as an array bypasses cmd.exe on Windows, so proc_terminate()
        // below signals the actual php.exe process instead of an orphaned wrapper shell.
        $this->proc = proc_open($cmd, $descriptors, $pipes, $this->root);
        if (!is_resource($this->proc)) {
            throw new RuntimeException('Failed to start the PHP built-in server');
        }

        $deadline = microtime(true) + 8;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.3);
            if ($conn) { fclose($conn); return; }
            usleep(150000);
        }
        throw new RuntimeException('PHP built-in server did not start in time. Log: ' . @file_get_contents($this->logPath));
    }

    public function pdo(): PDO {
        if ($this->isMysql) {
            return $this->mysqlServerPdo($this->mysqlDbName);
        }
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    // Returns the most recent dry-run email sent to $toEmail (['to','subject','body']),
    // or null if none was logged. Lets a test recover something like an activation
    // link out of an email body without a real inbox.
    public function lastDryRunEmailTo(string $toEmail): ?array {
        if (!is_file($this->dryRunEmailsPath)) return null;
        $lines = array_filter(explode("\n", file_get_contents($this->dryRunEmailsPath)));
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $entry = json_decode($lines[$i], true);
            if ($entry && $entry['to'] === $toEmail) return $entry;
        }
        return null;
    }

    // Runs a query and returns a single scalar value, closing the connection
    // immediately afterward. On Windows, a test's own SQLite handle left open
    // (e.g. a $stmt kept in scope) while an http_request() call goes out to the
    // separate `php -S` process can block that process's write with a file-lock
    // stall — use this instead of holding a PDO/statement object across a request.
    public function scalar(string $sql, array $params = []) {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        $stmt = null;
        $pdo = null;
        return $value;
    }

    public function tearDown(): void {
        if ($this->tornDown) return;
        $this->tornDown = true;

        if (is_resource($this->proc)) {
            $status = proc_get_status($this->proc);
            proc_terminate($this->proc);
            proc_close($this->proc);
            if (!empty($status['pid'])) {
                // Best-effort safety net: proc_terminate on Windows doesn't always
                // reach a php -S process started this way.
                @exec('taskkill /F /PID ' . (int) $status['pid'] . ' 2>NUL');
            }
        }

        @unlink($this->root . '/public_html/admin/setup.php');

        if ($this->configBackup !== null) {
            file_put_contents($this->configPath, $this->configBackup);
        } elseif (file_exists($this->configPath)) {
            unlink($this->configPath);
        }

        if ($this->isMysql) {
            try {
                $this->mysqlServerPdo()->exec('DROP DATABASE IF EXISTS ' . $this->mysqlDbName);
            } catch (Throwable $e) {
                echo "Could not drop {$this->mysqlDbName}: " . $e->getMessage() . "\n";
            }
        }

        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
