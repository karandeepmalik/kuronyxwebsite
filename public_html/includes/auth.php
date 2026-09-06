<?php
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

const LOGIN_MAX_ATTEMPTS      = 8;
const LOGIN_LOCKOUT_MINUTES   = 15;

function is_locked_out(string $identifier): bool {
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE identifier = ? AND succeeded = 0 AND created_at > (NOW() - INTERVAL ? MINUTE)'
    );
    $stmt->execute([$identifier, LOGIN_LOCKOUT_MINUTES]);
    return (int) $stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
}

function record_login_attempt(string $identifier, bool $succeeded): void {
    $stmt = db()->prepare('INSERT INTO login_attempts (identifier, succeeded) VALUES (?, ?)');
    $stmt->execute([$identifier, $succeeded ? 1 : 0]);
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
