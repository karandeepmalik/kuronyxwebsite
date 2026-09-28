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
    $active = db()->prepare('SELECT active FROM staff_users WHERE id = ?');
    $active->execute([$staff['id']]);
    if ((int) $active->fetchColumn() !== 1) {
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
    $status = db()->prepare('SELECT status FROM vet_accounts WHERE id = ?');
    $status->execute([$vet['id']]);
    if ($status->fetchColumn() !== 'active') {
        unset($_SESSION['vet']);
        header('Location: /for-veterinarians/login.php');
        exit;
    }
    return $vet;
}

const LOGIN_MAX_ATTEMPTS      = 8;
const LOGIN_LOCKOUT_MINUTES   = 15;

function is_locked_out(string $identifier): bool {
    // Compute the cutoff in PHP and bind it as a plain value rather than relying on
    // MySQL-specific date arithmetic (NOW()/INTERVAL) — keeps this portable and avoids
    // a dialect-specific query failing outright on another engine.
    $cutoff = gmdate('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_MINUTES * 60);
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE identifier = ? AND succeeded = 0 AND created_at > ?'
    );
    $stmt->execute([$identifier, $cutoff]);
    return (int) $stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
}

function record_login_attempt(string $identifier, bool $succeeded): void {
    $stmt = db()->prepare('INSERT INTO login_attempts (identifier, succeeded) VALUES (?, ?)');
    $stmt->execute([$identifier, $succeeded ? 1 : 0]);
}

// Generic per-bucket rate limiter (e.g. bucket = "cat_owner_submit:1.2.3.4") for public,
// unauthenticated endpoints that would otherwise let a script flood storage, the DB, or
// (for the Brevo-calling endpoints) someone else's inbox with no real limit. Same shape
// as the login-attempts lockout above, generalized to any caller.
function rate_limited(string $bucket, int $maxHits, int $windowMinutes): bool {
    $cutoff = gmdate('Y-m-d H:i:s', time() - $windowMinutes * 60);
    $stmt = db()->prepare('SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ? AND created_at > ?');
    $stmt->execute([$bucket, $cutoff]);
    return (int) $stmt->fetchColumn() >= $maxHits;
}

function record_rate_limit_hit(string $bucket): void {
    db()->prepare('INSERT INTO rate_limit_hits (bucket) VALUES (?)')->execute([$bucket]);
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

// Sets the new password and burns the token so a link can only ever be used once.
function complete_password_reset(string $table, int $id, string $newPassword): void {
    password_reset_condition($table);
    db()->prepare("UPDATE {$table} SET password_hash = ?, password_reset_token_hash = NULL, password_reset_expires_at = NULL WHERE id = ?")
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);
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
