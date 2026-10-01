<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

require_once __DIR__ . '/db.php';

function gs_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('kuronyx_staff_sid');
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
    gs_session_start();
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
    gs_session_start();
    return $_SESSION['vet'] ?? null;
}

function require_vet_login(): array {
    $vet = current_vet();
    if (!$vet) {
        header('Location: /for-veterinarians/login.php');
        exit;
    }
    $row = db()->prepare('SELECT status, password_hash FROM vet_accounts WHERE id = ?');
    $row->execute([$vet['id']]);
    $row = $row->fetch();
    if (!$row || $row['status'] !== 'active' || !password_fingerprint_matches($vet, $row['password_hash'])) {
        unset($_SESSION['vet']);
        header('Location: /for-veterinarians/login.php');
        exit;
    }
    return $vet;
}

const LOGIN_MAX_ATTEMPTS      = 8;
const LOGIN_LOCKOUT_MINUTES   = 15;

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
    begin_serialized_window_transaction($pdo);
    try {
        $cutoff = gmdate('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_MINUTES * 60);
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND succeeded = 0 AND created_at > ?'
            . locking_read_suffix()
        );
        $stmt->execute([$identifier, $cutoff]);
        if ((int) $stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS) {
            $pdo->exec('COMMIT');
            return null;
        }
        $pdo->prepare('INSERT INTO login_attempts (identifier, succeeded) VALUES (?, 0)')->execute([$identifier]);
        $id = (int) $pdo->lastInsertId();
        $pdo->exec('COMMIT');
        return $id;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function mark_login_attempt_succeeded(int $attemptId): void {
    db()->prepare('UPDATE login_attempts SET succeeded = 1 WHERE id = ?')->execute([$attemptId]);
}

// Generic per-bucket rate limiter (e.g. bucket = "cat_owner_submit:1.2.3.4") for public,
// unauthenticated endpoints that would otherwise let a script flood storage, the DB, or
// (for the Brevo-calling endpoints) someone else's inbox with no real limit. Same shape,
// and the same atomic count-then-insert transaction, as the login lockout above.
function rate_limited(string $bucket, int $maxHits, int $windowMinutes): bool {
    $pdo = db();
    begin_serialized_window_transaction($pdo);
    try {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $windowMinutes * 60);
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ? AND created_at > ?' . locking_read_suffix()
        );
        $stmt->execute([$bucket, $cutoff]);
        $exceeded = (int) $stmt->fetchColumn() >= $maxHits;

        // Recorded regardless of outcome, including a blocked attempt, so retrying
        // immediately after being blocked can't reset the bucket.
        $pdo->prepare('INSERT INTO rate_limit_hits (bucket) VALUES (?)')->execute([$bucket]);

        // Nothing ever deleted old rows before this, so the table grew forever even though
        // the count above only ever looks at the last $windowMinutes. Opportunistic
        // cleanup (~1 in 50 calls, so this doesn't add a DELETE to every single request)
        // keeps it bounded. 24h is comfortably past the longest window checked anywhere in
        // this app.
        if (random_int(1, 50) === 1) {
            $oldCutoff = gmdate('Y-m-d H:i:s', time() - 86400);
            $pdo->prepare('DELETE FROM rate_limit_hits WHERE created_at < ?')->execute([$oldCutoff]);
        }
        $pdo->exec('COMMIT');
        return $exceeded;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
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

// Records an entry in audit_log. Never pass prescription/document contents as $metadata.
function audit(string $action, string $entityType, ?int $entityId, array $metadata = [], string $actorType = 'staff'): void {
    $staff = current_staff();
    $stmt = db()->prepare(
        'INSERT INTO audit_log (actor_type, actor_id, action, entity_type, entity_id, metadata_json)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $actorType,
        $staff['id'] ?? null,
        $action,
        $entityType,
        $entityId,
        $metadata ? json_encode($metadata) : null,
    ]);
}
