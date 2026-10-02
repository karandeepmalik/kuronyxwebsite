<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session-cleanup.php';

// Off-webroot, private session-file directory — mirrors private_storage_path()'s own
// fallback pattern. PHP's default session.save_path is the shared system temp directory,
// which on shared hosting can be used by other accounts on the same box; an explicit,
// dedicated folder keeps session files out of a location nothing else has a reason to
// read. Falls back to a local folder (still outside public_html) only when
// SESSION_SAVE_PATH hasn't been configured, so local development doesn't hard-fail.
function gs_session_storage_path(): string {
    if (defined('SESSION_SAVE_PATH') && SESSION_SAVE_PATH !== '') {
        return rtrim(SESSION_SAVE_PATH, '/');
    }
    $fallback = dirname(__DIR__, 2) . '/session_fallback';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0750, true);
    }
    return realpath($fallback) ?: $fallback;
}

// Three separate session cookies, one per kind of visitor, instead of one shared cookie that
// could carry staff, vet and anonymous-visitor state at once (which needed manual "clear the
// other identity" code at every login, and handed a staff-named session id to every anonymous
// visitor). Each area has its own cookie and session file:
//   staff  - everything under /admin/
//   vet    - the veterinarian portal pages (login, activation, password reset, portal/)
//   public - everything else (intake forms, newsletter, request-received, ...)
const GS_SESSION_NAMES    = ['staff' => 'kuronyx_staff_sid', 'vet' => 'kuronyx_vet_sid', 'public' => 'kuronyx_sid'];
const GS_SESSION_LIFETIME = 4 * 3600;

function gs_session_area(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strncmp($script, '/admin/', 7) === 0) return 'staff';
    if (preg_match('#^/for-veterinarians/(portal/.*|login\.php|activate\.php|forgot-password\.php|reset-password\.php)$#', $script)) return 'vet';
    return 'public';
}

// True if this request carries (or has already started) the given area's session - lets
// current_staff()/current_vet() answer "nobody" without creating a session file for visitors
// who never had one.
function gs_session_exists(string $area): bool {
    $name = GS_SESSION_NAMES[$area];
    return isset($_COOKIE[$name]) || (session_status() === PHP_SESSION_ACTIVE && session_name() === $name);
}

// Starts (or switches to) the session for $area - by default the one for the page being
// served. Only download.php, which serves both staff and vets, ever asks for a different one.
function gs_session_start(?string $area = null): void {
    $name = GS_SESSION_NAMES[$area ?? gs_session_area()];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() === $name) return;
        session_write_close(); // switching areas: save the current one first
    }
    // $_SERVER['HTTPS'] is set by the webserver terminating TLS directly, but Hostinger
    // (like most shared hosts) may front the app with a proxy/CDN that terminates TLS
    // itself and forwards plain HTTP to PHP — in which case HTTPS never gets set even
    // though the visitor is genuinely on https://. X-Forwarded-Proto is the standard
    // header such a proxy sets to carry the original scheme; trusting it is safe here
    // specifically because the only consequence of a spoofed value is the Secure flag
    // being set on a cookie issued over an actually-plain connection (strictly more
    // cautious, never less), not an access-control decision.
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    // Explicit idle timeout — PHP's default session.gc_maxlifetime (1440s / 24min) means
    // an idle tab (someone writing a long dispatch article, reviewing a long case) can
    // have its session file garbage-collected before they submit, silently losing
    // whatever they typed. 4 hours comfortably covers a working session without session
    // files accumulating forever (gc_probability/gc_divisor still apply as normal).
    ini_set('session.gc_maxlifetime', (string) GS_SESSION_LIFETIME);
    $savePath = gs_session_storage_path();
    if (is_dir($savePath) && is_writable($savePath)) {
        session_save_path($savePath);
        // PHP's own session garbage collection is often switched off for a custom save_path
        // (Debian-style builds rely on a system cron that only cleans the default folder), and
        // every visit to a public form creates a session file - so clean up ourselves.
        if (random_int(1, 100) === 1) {
            gs_session_cleanup($savePath, GS_SESSION_LIFETIME + 600);
        }
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name($name);
    session_start();
}

function csrf_token(): string {
    gs_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_verify(): bool {
    gs_session_start();
    $token = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

// One-time tokens for actions with a genuine external side effect that must not fire
// twice from the same form render (an email actually going out, a new case/application
// being created) — a double-click or an F5 resubmitting the last POST has exactly this
// shape. Distinct from csrf_token()/csrf_field(): that one stays the same for the whole
// session and is checked but never consumed, so it can't by itself distinguish "this is a
// resubmission of the same form" from "this is a fresh legitimate request from the same
// logged-in session" — this one is generated fresh per form render and deleted the moment
// it's checked, whichever way that check goes, so it can only ever be used once. PHP's
// own session file locking (see gs_session_start()) already serializes concurrent
// requests from the *same* session, so there's no extra race to close here beyond that.
//
// A small set of live tokens is kept per key (not just the latest one): rendering the same form
// twice - the same case open in two tabs, or the Back button re-showing an older render - used
// to overwrite the first render's token, so the older tab's submit failed as "already
// submitted". Each render's token is still single-use; only the oldest are dropped once more
// than OTT_MAX_PER_KEY renders are outstanding.
const OTT_MAX_PER_KEY = 8;

function one_time_token(string $key): string {
    gs_session_start();
    $token = bin2hex(random_bytes(16));
    $live = $_SESSION['ott'][$key] ?? [];
    if (!is_array($live)) $live = [];
    $live[] = $token;
    $_SESSION['ott'][$key] = array_slice($live, -OTT_MAX_PER_KEY);
    return $token;
}

function one_time_field(string $key): string {
    return '<input type="hidden" name="ott" value="' . htmlspecialchars(one_time_token($key), ENT_QUOTES) . '">';
}

function consume_one_time_token(string $key): bool {
    gs_session_start();
    $submitted = $_POST['ott'] ?? '';
    $live = $_SESSION['ott'][$key] ?? [];
    if (!is_string($submitted) || $submitted === '' || !is_array($live)) return false;
    foreach ($live as $i => $expected) {
        if (is_string($expected) && hash_equals($expected, $submitted)) {
            unset($live[$i]); // consumed - a second submit of the same render finds nothing
            $_SESSION['ott'][$key] = array_values($live);
            return true;
        }
    }
    return false;
}

// A fingerprint of an account's current password_hash, stored in the session at login
// (see admin/login.php / for-veterinarians/login.php) and re-checked on every request by
// require_login()/require_vet_login()/download.php. Since password_hash() always produces
// a different string even for the same plaintext (random salt), this changes on every
// successful password change — so comparing it is equivalent to a session-revocation
// counter, without needing a schema migration to add one. It's a hash of a hash, not the
// hash itself, so it reveals nothing useful even if a session were somehow read.
function password_fingerprint(string $passwordHash): string {
    return hash('sha256', $passwordHash);
}

function password_fingerprint_matches(array $sessionIdentity, ?string $currentPasswordHash): bool {
    return $currentPasswordHash !== null
        && isset($sessionIdentity['pw_fingerprint'])
        && hash_equals(password_fingerprint($currentPasswordHash), $sessionIdentity['pw_fingerprint']);
}

function current_staff(): ?array {
    if (!gs_session_exists('staff')) return null;
    gs_session_start('staff');
    return $_SESSION['staff'] ?? null;
}

function require_login(): array {
    $staff = current_staff();
    if (!$staff) {
        header('Location: /admin/login.php');
        exit;
    }
    // Re-checked against the DB (not just the session) on every request so
    // deactivating a staff account takes effect immediately, not on next login.
    // Also re-checks the session's password fingerprint (see admin/login.php) against
    // the current password_hash — without this, a password reset would only stop
    // future logins; any session issued before the reset (including one an attacker
    // had stolen) would keep working indefinitely, defeating the point of resetting it.
    $row = db()->prepare('SELECT active, password_hash FROM staff_users WHERE id = ?');
    $row->execute([$staff['id']]);
    $row = $row->fetch();
    if (!$row || (int) $row['active'] !== 1 || !password_fingerprint_matches($staff, $row['password_hash'])) {
        unset($_SESSION['staff']);
        header('Location: /admin/login.php');
        exit;
    }
    return $staff;
}

function require_role(array $roles): array {
    $staff = require_login();
    if (!in_array($staff['role'], $roles, true)) {
        http_response_code(403);
        exit('Forbidden.');
    }
    return $staff;
}

// --- Veterinarian portal session (separate from staff, same session cookie) ---

function current_vet(): ?array {
    if (!gs_session_exists('vet')) return null;
    gs_session_start('vet');
    return $_SESSION['vet'] ?? null;
}

function require_vet_login(): array {
    $vet = current_vet();
    if (!$vet) {
        header('Location: /for-veterinarians/login.php');
        exit;
    }
    // Re-checks the linked application's own status here too, not just the account's —
    // the admin review actions (approve/reject/suspend) update vet_applications and
    // vet_accounts as two statements, and even wrapped in a transaction, a vet_accounts
    // row could still in principle end up active while its application isn't approved
    // (e.g. restored from an older backup, or a future action that forgets to touch the
    // account). Checking both here, on every request, means portal access doesn't depend
    // on every status-changing action having correctly kept the two in sync.
    $row = db()->prepare(
        'SELECT va.status, va.password_hash, a.status AS application_status
         FROM vet_accounts va JOIN vet_applications a ON a.id = va.vet_application_id
         WHERE va.id = ?'
    );
    $row->execute([$vet['id']]);
    $row = $row->fetch();
    if (!$row || $row['status'] !== 'active' || $row['application_status'] !== 'approved' || !password_fingerprint_matches($vet, $row['password_hash'])) {
        unset($_SESSION['vet']);
        header('Location: /for-veterinarians/login.php');
        exit;
    }
    return $vet;
}

// The IP address every rate limiter/lockout in this app keys on. Defaults to
// REMOTE_ADDR — correct only when PHP is actually the first hop that sees each visitor's
// real connection. If Hostinger fronts the app with a CDN or reverse proxy, REMOTE_ADDR
// would instead be that proxy's own address for every single visitor, collapsing every
// per-IP limit in the app into one shared, site-wide limit — and, separately, many
// genuine visitors in India can already share one IP behind carrier-grade NAT even
// without a proxy in the mix. This can't be determined from the codebase alone; it needs
// checking directly against production (e.g. a temporary debug page printing
// $_SERVER['REMOTE_ADDR'] and $_SERVER['HTTP_X_FORWARDED_FOR'], deleted again
// afterward — same pattern already used elsewhere in this project for one-off production
// checks). Once confirmed, define TRUST_PROXY_IP_HEADER => true in db-config.php to use
// X-Forwarded-For instead — never do this without confirming a proxy actually sits in
// front, since X-Forwarded-For is otherwise an ordinary, attacker-controllable header.
// Even with a proxy, the LEFT-most entry is whatever the client itself sent: a proxy
// appends the address it saw to the right, so a client sending "X-Forwarded-For: 1.2.3.4"
// arrives as "1.2.3.4, <real ip>". The right-most entry is the one our own proxy added, so
// that is the one used (assumes exactly one trusted proxy). An unparseable value falls
// back to REMOTE_ADDR.
function client_ip(): string {
    if (defined('TRUST_PROXY_IP_HEADER') && TRUST_PROXY_IP_HEADER && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidate = trim((string) end($forwarded));
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return $candidate;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

const LOGIN_MAX_ATTEMPTS      = 8;
const LOGIN_LOCKOUT_MINUTES   = 15;
// Per-IP cap, checked before any per-account work — the per-email lockout above has no
// visibility into "one source spraying short password lists across many different
// accounts" (each account's own counter looks fine in isolation), so it needs a separate,
// IP-keyed check. Deliberately generous: many genuine visitors can share one IP behind
// carrier-grade NAT (common in India) or a hosting provider's own proxy, and this must
// not lock out a shared office/clinic network over a few mistyped passwords.
const LOGIN_IP_MAX_ATTEMPTS   = 30;
// A real bcrypt hash (of an arbitrary fixed string, not a real password) used to run a
// dummy password_verify() when no account matches the submitted email — password_verify()
// against a real account takes a measurable, roughly constant amount of time (bcrypt's
// whole point), while skipping it entirely for a nonexistent account returns near-
// instantly; that timing gap is enough to let an attacker probe which emails have
// accounts without ever seeing a different error message. Running this dummy comparison
// on the "no such account" path costs the same bcrypt work and closes the gap.
const DUMMY_PASSWORD_HASH = '$2y$10$FUGDtHtzaK/.BMns.HYlqeS1jGBB8GjdLcxZbsDpae8Cdky5.khgC';

function db_driver(): string {
    return defined('DB_DRIVER') ? DB_DRIVER : 'mysql';
}

// Starts a transaction that actually serializes concurrent callers racing the same
// count-then-insert window, instead of leaving the SELECT and the INSERT as two
// independent autocommit statements a concurrent request can interleave with.
// - SQLite only ever allows one writer for the *whole database* at a time once a write
//   transaction is open. Issuing "BEGIN IMMEDIATE" (rather than PDO's own
//   beginTransaction(), which starts SQLite's default *deferred* transaction — no lock
//   until the first write) takes that write lock immediately, before the SELECT below
//   even runs, so every statement until COMMIT is serialized against every other writer.
// - MySQL/InnoDB has no database-wide write lock, so the SELECT in the caller must use
//   "FOR UPDATE" on the same (identifier/bucket, created_at) range the INSERT lands in.
//   Under InnoDB's default REPEATABLE READ isolation, a locking read on an indexed range
//   takes a *gap lock* over that range even when nothing currently matches it — so a
//   concurrent transaction's own locking read for the same identifier/bucket blocks until
//   this one commits or rolls back, instead of reading a stale pre-insert count.
// Raw SQL (not PDO's beginTransaction()/commit()/rollBack()) is used throughout so this
// never gets silently downgraded to a plain "BEGIN" by the PDO SQLite driver. Callers of
// this (reserve_login_attempt(), rate_limited()) must not themselves be called from
// inside another open transaction — none of this app's call sites do that today.
function begin_serialized_window_transaction(PDO $pdo): void {
    $pdo->exec(db_driver() === 'sqlite' ? 'BEGIN IMMEDIATE' : 'START TRANSACTION');
}

// Appends "FOR UPDATE" on MySQL only — SQLite doesn't support the clause at all (and
// doesn't need it: begin_serialized_window_transaction() already serializes it there).
function locking_read_suffix(): string {
    return db_driver() === 'sqlite' ? '' : ' FOR UPDATE';
}

// True for a PDOException that represents transient contention between two transactions
// (deadlock or lock-wait timeout) rather than a real error — the database's own mechanism
// for breaking a circular wait, not a sign anything is broken. This is a well-documented,
// expected InnoDB outcome specifically for "SELECT ... FOR UPDATE on a range with no
// matching rows, then INSERT into that range" done concurrently by two sessions (exactly
// reserve_login_attempt()/rate_limited()'s own shape): InnoDB gap locks are mutually
// compatible between transactions (by design — see the MySQL manual's own notes on gap
// locks), so two concurrent callers can both acquire a gap lock over the same empty range
// and then both block on each other's subsequent INSERT, at which point InnoDB's deadlock
// detector kills one of the two with error 1213 (SQLSTATE 40001). The loser's transaction
// is fully rolled back and simply needs to run again from the start — see
// run_serialized_transaction() below, which does that automatically.
function is_transient_contention_error(PDOException $e): bool {
    if ($e->getCode() === '40001') return true; // MySQL 1213 deadlock
    $message = $e->getMessage();
    return str_contains($message, 'Lock wait timeout') // MySQL 1205
        || str_contains($message, 'database is locked'); // SQLite SQLITE_BUSY
}

// Runs $work(PDO) inside one begin_serialized_window_transaction(), committing on success
// and automatically retrying (with a small jittered backoff) if the database reports
// transient contention — see is_transient_contention_error(). $work must perform its own
// statements against the given connection and return whatever the caller needs; throwing
// anything other than a transient-contention PDOException rolls back and propagates
// immediately, with no retry.
function run_serialized_transaction(PDO $pdo, callable $work, int $maxAttempts = 6) {
    // ROLLBACK that can't mask the error being handled: if the transaction is already gone
    // (BEGIN itself failed, or the engine aborted it) a second ROLLBACK throws, which would
    // replace the real exception with "no transaction is active".
    $rollback = function () use ($pdo): void {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
    };
    for ($attempt = 1; ; $attempt++) {
        // BEGIN is inside the try: on SQLite, BEGIN IMMEDIATE is exactly where "database is
        // locked" is raised, so it has to be retried like any other contention.
        try {
            begin_serialized_window_transaction($pdo);
            $result = $work($pdo);
            $pdo->exec('COMMIT');
            return $result;
        } catch (PDOException $e) {
            $rollback();
            if ($attempt < $maxAttempts && is_transient_contention_error($e)) {
                usleep(random_int(5000, 20000) * $attempt);
                continue;
            }
            throw $e;
        } catch (Throwable $e) {
            $rollback();
            throw $e;
        }
    }
}

// Deletes rows older than $retentionSeconds from $table's $column, outside of — and only
// ever called after — any serialized window transaction. Run inside that transaction (as
// this used to be, for both login_attempts and rate_limit_hits), an unindexed range DELETE
// scanning/locking every row in the table would hold the transaction's lock for however
// long that scan takes, stalling or deadlocking every other concurrent caller of the same
// bucket/identifier for no reason related to their own request. $column must be indexed on
// its own (not just as the second part of a composite index, which an unqualified range
// scan like this can't use) — see the migration that added idx_login_attempts_created_at /
// idx_rate_limit_created_at. Batched via LIMIT on MySQL to bound how long any single
// DELETE holds its own (much shorter-lived, single-statement) lock; SQLite's bundled
// PDO driver isn't built with LIMIT-on-DELETE support, so it just deletes the whole
// expired range in one go — fine given dev/test datasets are tiny.
function cleanup_old_rows(PDO $pdo, string $table, string $column, int $retentionSeconds): void {
    $cutoff = gmdate('Y-m-d H:i:s', time() - $retentionSeconds);
    $sql = "DELETE FROM {$table} WHERE {$column} < ?";
    if (db_driver() !== 'sqlite') {
        $sql .= ' LIMIT 500';
    }
    $pdo->prepare($sql)->execute([$cutoff]);
}

// Atomically checks whether $identifier is currently locked out and, if not, reserves
// this attempt as a failure — both inside one serialized transaction (see
// begin_serialized_window_transaction()), so the count-then-insert can't be interleaved
// by a concurrent request the way two separate statements could be. Returns null when
// locked out (nothing reserved); otherwise the new login_attempts row id, which the
// caller must pass to mark_login_attempt_succeeded() if the password check then succeeds
// — reserving as a failure *before* running the slow, bcrypt-based password check (rather
// than only recording afterward) keeps the window a concurrent burst could race through
// down to this one DB round trip, not the time it takes to verify a password.
function reserve_login_attempt(string $identifier): ?int {
    $pdo = db();
    $result = run_serialized_transaction($pdo, function (PDO $pdo) use ($identifier) {
        $cutoff = gmdate('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_MINUTES * 60);
        // Selects the actual matching row ids (not SELECT COUNT(*)) and counts them in
        // PHP. An aggregate FOR UPDATE on MySQL is not reliably documented to take the
        // same next-key/gap locks over the scanned range as a row-returning locking read
        // does — selecting and locking the real rows removes that ambiguity entirely,
        // rather than depending on it.
        $stmt = $pdo->prepare(
            'SELECT id FROM login_attempts WHERE identifier = ? AND succeeded = 0 AND created_at > ?'
            . locking_read_suffix()
        );
        $stmt->execute([$identifier, $cutoff]);
        if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) >= LOGIN_MAX_ATTEMPTS) {
            return null;
        }
        $pdo->prepare('INSERT INTO login_attempts (identifier, succeeded) VALUES (?, 0)')->execute([$identifier]);
        return (int) $pdo->lastInsertId();
    });
    // Nothing ever deleted old rows before this, so the table grew forever even though
    // the count above only ever looks at the last LOGIN_LOCKOUT_MINUTES. Opportunistic
    // cleanup (~1 in 50 calls) keeps it bounded, run outside the transaction above — see
    // cleanup_old_rows(). 24h is comfortably past the lockout window.
    if (random_int(1, 50) === 1) {
        cleanup_old_rows($pdo, 'login_attempts', 'created_at', 86400);
    }
    return $result;
}

// The key a login lockout counts failures against: the account AND the visitor's IP, not the
// account alone. Keyed on the email only, anyone anywhere could send 8 wrong passwords and
// lock the real owner (the admin, say) out for 15 minutes while they themselves kept their
// own IP free. Keyed on email+IP, an attacker can only ever lock out their own address.
// Guessing one account's password from many IPs at once is still bounded by the 12-character
// minimum password and by the per-IP login cap (LOGIN_IP_MAX_ATTEMPTS). $base is the
// lower-cased email, with a "vet:" prefix for veterinarian accounts. Truncated so the whole
// key always fits login_attempts.identifier (VARCHAR(190)).
function login_identifier(string $base, string $ip): string {
    return substr($base, 0, 120) . '|' . substr($ip, 0, 45);
}

// Clears every IP's failed-attempt rows for one account — used after a password reset, so a
// locked-out owner who proves they own the mailbox can sign in straight away. SUBSTR (not
// LIKE) so an email containing % or _ can't act as a wildcard.
function clear_login_attempts(string $base): void {
    $prefix = substr($base, 0, 120) . '|';
    db()->prepare('DELETE FROM login_attempts WHERE identifier = ? OR SUBSTR(identifier, 1, ?) = ?')
        ->execute([substr($base, 0, 120), strlen($prefix), $prefix]);
}

function mark_login_attempt_succeeded(int $attemptId): void {
    db()->prepare('UPDATE login_attempts SET succeeded = 1 WHERE id = ?')->execute([$attemptId]);
}

// Generic per-bucket rate limiter (e.g. bucket = "cat_owner_submit:1.2.3.4") for public,
// unauthenticated endpoints that would otherwise let a script flood storage, the DB, or
// (for the Brevo-calling endpoints) someone else's inbox with no real limit. Same shape,
// and the same atomic count-then-insert transaction, as the login lockout above.
// $recordBlocked: by default a blocked attempt is still recorded, so retrying immediately
// can't reset the bucket. The login per-IP cap passes false: otherwise someone hammering the
// form keeps the window full indefinitely, and the block only ever ends once they stop —
// with false, the block lifts $windowMinutes after the last *counted* attempt.
function rate_limited(string $bucket, int $maxHits, int $windowMinutes, bool $recordBlocked = true): bool {
    $pdo = db();
    $exceeded = run_serialized_transaction($pdo, function (PDO $pdo) use ($bucket, $maxHits, $windowMinutes, $recordBlocked) {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $windowMinutes * 60);
        // Selects the actual matching row ids (not SELECT COUNT(*)) and counts them in
        // PHP — see reserve_login_attempt() above for why an aggregate FOR UPDATE isn't
        // relied on here.
        $stmt = $pdo->prepare(
            'SELECT id FROM rate_limit_hits WHERE bucket = ? AND created_at > ?' . locking_read_suffix()
        );
        $stmt->execute([$bucket, $cutoff]);
        $exceeded = count($stmt->fetchAll(PDO::FETCH_COLUMN)) >= $maxHits;

        // Recorded regardless of outcome by default (see $recordBlocked above).
        if (!$exceeded || $recordBlocked) {
            $pdo->prepare('INSERT INTO rate_limit_hits (bucket) VALUES (?)')->execute([$bucket]);
        }
        return $exceeded;
    });
    // See reserve_login_attempt() above — cleanup runs outside the serialized transaction
    // so an unindexed-for-this-purpose range scan can't hold up concurrent callers.
    if (random_int(1, 50) === 1) {
        cleanup_old_rows($pdo, 'rate_limit_hits', 'created_at', 86400);
    }
    return $exceeded;
}

// Like rate_limited(), but for forms where only a *completed* submission should use up the
// allowance: the hit is reserved atomically up front (so a burst can't all slip past the count),
// and the caller hands it back with rate_limit_release() when the submission is bounced for
// ordinary field errors - otherwise someone correcting a typo, or several people behind one
// clinic / carrier-grade-NAT address, burn the whole budget without submitting anything.
// Returns the reserved row id, or null when the bucket is full (a blocked attempt is not
// recorded, so the block lifts $windowMinutes after the last counted attempt).
function rate_limit_reserve(string $bucket, int $maxHits, int $windowMinutes): ?int {
    $pdo = db();
    $id = run_serialized_transaction($pdo, function (PDO $pdo) use ($bucket, $maxHits, $windowMinutes) {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $windowMinutes * 60);
        $stmt = $pdo->prepare(
            'SELECT id FROM rate_limit_hits WHERE bucket = ? AND created_at > ?' . locking_read_suffix()
        );
        $stmt->execute([$bucket, $cutoff]);
        if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) >= $maxHits) {
            return null;
        }
        $pdo->prepare('INSERT INTO rate_limit_hits (bucket) VALUES (?)')->execute([$bucket]);
        return (int) $pdo->lastInsertId();
    });
    if (random_int(1, 50) === 1) {
        cleanup_old_rows($pdo, 'rate_limit_hits', 'created_at', 86400);
    }
    return $id;
}

function rate_limit_release(?int $hitId): void {
    if ($hitId === null) return;
    db()->prepare('DELETE FROM rate_limit_hits WHERE id = ?')->execute([$hitId]);
}

// --- Self-service password reset (staff/admin via staff_users, vets via vet_accounts) ---

const PASSWORD_RESET_TTL_MINUTES = 60;

// Base URL used inside emailed links. Deliberately NOT taken from the request's Host
// header — a forged Host would otherwise let an attacker make the site email a victim a
// reset link pointing at the attacker's domain. Set SITE_URL in db-config.php for
// non-production environments (e.g. http://localhost:8000).
function site_url(): string {
    return rtrim(defined('SITE_URL') ? SITE_URL : 'https://kuronyx.in', '/');
}

// The request's Origin if it is one of this site's own origins, else null. Used by the JSON
// endpoints in php/ (called by fetch() from our own pages). Production is kuronyx.in and
// www.kuronyx.in; localhost is NOT accepted in production — a dev environment gets its own
// origin by setting SITE_URL (e.g. http://localhost:8000) in db-config.php.
function allowed_site_origin(): ?string {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = ['https://kuronyx.in', 'https://www.kuronyx.in', site_url()];
    return in_array($origin, $allowed, true) ? $origin : null;
}

// Only these two tables can ever be reset; $table is interpolated into SQL below, so it is
// checked against this fixed list rather than trusted.
function password_reset_condition(string $table): string {
    if ($table === 'staff_users') return 'active = 1';
    if ($table === 'vet_accounts') return "status = 'active'";
    throw new InvalidArgumentException('Unsupported table for password reset.');
}

// Generates and stores a one-time reset token (only its SHA-256 hash is persisted) and
// returns the raw token, which exists only in the emailed link.
function issue_password_reset(string $table, int $id): string {
    password_reset_condition($table);
    $raw = bin2hex(random_bytes(32));
    db()->prepare("UPDATE {$table} SET password_reset_token_hash = ?, password_reset_expires_at = ? WHERE id = ?")
        ->execute([hash('sha256', $raw), gmdate('Y-m-d H:i:s', time() + PASSWORD_RESET_TTL_MINUTES * 60), $id]);
    return $raw;
}

// Returns the still-active account a valid, unexpired reset token belongs to, else null.
function find_password_reset_account(string $table, string $rawToken): ?array {
    if ($rawToken === '') return null;
    $cond = password_reset_condition($table);
    $stmt = db()->prepare(
        "SELECT * FROM {$table} WHERE password_reset_token_hash = ? AND password_reset_expires_at > ? AND {$cond} LIMIT 1"
    );
    $stmt->execute([hash('sha256', $rawToken), gmdate('Y-m-d H:i:s')]);
    return $stmt->fetch() ?: null;
}

// Sets the new password and burns the token so a link can only ever be used once. The
// WHERE clause re-checks the token hash/expiry as part of the same atomic UPDATE rather
// than trusting an earlier, separate find_password_reset_account() call — otherwise two
// concurrent submissions of the same link could both pass that earlier check and both
// succeed. Whichever UPDATE actually runs first wins (rowCount() 1); the second finds
// 0 matching rows, since the first already cleared the hash. Returns whether this call
// was the one that won.
function complete_password_reset(string $table, int $id, string $rawToken, string $newPassword): bool {
    $cond = password_reset_condition($table);
    $stmt = db()->prepare(
        "UPDATE {$table} SET password_hash = ?, password_reset_token_hash = NULL, password_reset_expires_at = NULL
         WHERE id = ? AND password_reset_token_hash = ? AND password_reset_expires_at > ? AND {$cond}"
    );
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id, hash('sha256', $rawToken), gmdate('Y-m-d H:i:s')]);
    return $stmt->rowCount() > 0;
}

// Returns the keys of $fields whose value is longer than the matching entry in $limits (a
// column's VARCHAR size). Every public form used to hand over-long input straight to the
// INSERT, where MySQL would either throw (a 500, with the visitor's whole submission and
// uploads lost) or, in non-strict mode, silently truncate it. Checking up front turns that
// into an ordinary highlighted-field error.
function field_length_errors(array $fields, array $limits): array {
    $bad = [];
    foreach ($limits as $key => $max) {
        if (isset($fields[$key]) && is_string($fields[$key]) && mb_strlen($fields[$key]) > $max) {
            $bad[] = $key;
        }
    }
    return $bad;
}

// MySQL TEXT columns hold 65,535 *bytes*, not characters, so a character cap alone isn't enough:
// Hindi is 3 bytes per character and emoji 4. Returns the keys of $keys whose value in $fields is
// longer than $maxBytes measured in bytes, so over-long free text is a field error rather than a
// failed INSERT.
const TEXT_MAX_BYTES = 65535;

function text_byte_errors(array $fields, array $keys, int $maxBytes = TEXT_MAX_BYTES): array {
    $bad = [];
    foreach ($keys as $key) {
        if (isset($fields[$key]) && is_string($fields[$key]) && strlen($fields[$key]) > $maxBytes) {
            $bad[] = $key;
        }
    }
    return $bad;
}

// gs_requests.patient_weight_kg is DECIMAL(5,2), which holds at most 999.99. Returns the weight if
// $raw is a finite number in (0, 999.99], else null (is_numeric() alone accepts "1e999" = INF).
function parse_patient_weight(string $raw): ?float {
    if (!is_numeric($raw)) return null;
    $w = (float) $raw;
    return (is_finite($w) && $w > 0 && round($w, 2) <= 999.99) ? $w : null;
}

// bcrypt (PASSWORD_DEFAULT) only ever looks at the first 72 bytes of a password — anything
// past that is silently ignored, so a 100-character password would be accepted but only
// its first 72 bytes would actually be checked. Reject rather than silently weaken.
const PASSWORD_MAX_BYTES = 72;

// Pagination for admin list pages. These used to be a hard LIMIT 200 with no way past it —
// anything older than the newest 200 rows was simply unreachable unless a filter happened to
// narrow it down. $perPage + 1 rows are fetched so "is there a next page" costs no COUNT(*).
const LIST_PAGE_SIZE = 100;

function list_page(): int {
    return max(1, (int) ($_GET['page'] ?? 1));
}

function list_pager(int $page, bool $hasMore): string {
    if ($page <= 1 && !$hasMore) return '';
    $link = function (int $p, string $label) {
        $q = $_GET;
        $q['page'] = $p;
        return '<a href="?' . htmlspecialchars(http_build_query($q), ENT_QUOTES) . '" style="color:var(--paper); border-bottom:1px solid var(--paper-3); margin-right:1.25rem;">' . $label . '</a>';
    };
    $out = '<p style="margin-top:1.5rem; font-size:0.8125rem; color:var(--paper-3);">Page ' . $page . ' &nbsp; ';
    if ($page > 1) $out .= $link($page - 1, '&larr; Newer');
    if ($hasMore)  $out .= $link($page + 1, 'Older &rarr;');
    return $out . '</p>';
}

// Cloudflare Turnstile (a free, privacy-friendly CAPTCHA). Entirely optional: with no
// TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY in db-config.php every function below is a
// no-op and the forms behave exactly as before. Create the keys at
// dash.cloudflare.com -> Turnstile (this does not require moving the site to Cloudflare).
function captcha_enabled(): bool {
    return defined('TURNSTILE_SITE_KEY') && TURNSTILE_SITE_KEY !== ''
        && defined('TURNSTILE_SECRET_KEY') && TURNSTILE_SECRET_KEY !== '';
}

function captcha_widget(): string {
    if (!captcha_enabled()) return '';
    return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'
         . '<div class="cf-turnstile" data-sitekey="' . htmlspecialchars(TURNSTILE_SITE_KEY, ENT_QUOTES) . '" style="margin:1.25rem 0;"></div>';
}

// Missing/invalid token -> false. If Cloudflare itself can't be reached the check fails
// OPEN (and logs): a verification outage must not stop a worried cat owner submitting a
// prescription request; the per-IP limit and daily upload cap still apply.
// $token is passed explicitly by the JSON endpoints (php/send-*.php); the regular HTML forms
// leave it null and it is read from the POSTed form field.
function captcha_verify(?string $token = null): bool {
    if (!captcha_enabled()) return true;
    $token = $token ?? ($_POST['cf-turnstile-response'] ?? '');
    if (!is_string($token) || $token === '') return false;
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['secret' => TURNSTILE_SECRET_KEY, 'response' => $token, 'remoteip' => client_ip()]),
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $code < 200 || $code >= 300) {
        error_log('[captcha] Turnstile verification unavailable (HTTP ' . $code . ') - failing open');
        return true;
    }
    $data = json_decode($response, true);
    return is_array($data) && !empty($data['success']);
}

// Records an entry in audit_log. Never pass prescription/document contents as $metadata.
// $actorType: 'staff' (a signed-in staff member), 'vet' (a veterinarian acting on their own account -
// pass their vet_accounts id as $actorId), 'public' (an anonymous visitor) or 'system'.
// The acting staff member is read from the staff session only when that is the session
// currently active (every /admin/ page has it active via require_login()); download.php, which
// has to juggle the staff and vet sessions, passes $actorId explicitly instead.
function audit(string $action, string $entityType, ?int $entityId, array $metadata = [], string $actorType = 'staff', ?int $actorId = null): void {
    if ($actorId === null && session_status() === PHP_SESSION_ACTIVE && session_name() === GS_SESSION_NAMES['staff']) {
        $actorId = $_SESSION['staff']['id'] ?? null;
    }
    $stmt = db()->prepare(
        'INSERT INTO audit_log (actor_type, actor_id, action, entity_type, entity_id, metadata_json)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $actorType,
        $actorId,
        $action,
        $entityType,
        $entityId,
        $metadata ? json_encode($metadata) : null,
    ]);
}
