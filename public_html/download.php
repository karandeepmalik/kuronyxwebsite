<?php
// Authenticated document streaming endpoint. Documents are never web-accessible
// directly — every fetch goes through here so access can be gated and audited.
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/storage-path.php';

// The staff and vet identities live in separate sessions (separate cookies, see auth.php), so
// current_staff() then current_vet() each switch to their own. audit() can therefore not infer
// the acting staff member from whichever session happens to be active — it is passed explicitly.
// Staff can see any document; a signed-in veterinarian can only see documents on
// their own GS-441524 requests (never another vet's, and never application docs —
// those are staff-only, reviewed as part of the account application itself).
$staff = current_staff();
$vet   = current_vet();
// Neither current_staff() nor current_vet() re-checks the account is still active —
// unlike require_login()/require_vet_login(), they only read the session. This page
// can't just call those (require_login() would wrongly redirect a signed-in vet, and
// vice versa), so both re-checks are done here instead, matching what those functions
// already do: a deactivated staff member's or suspended vet's still-open session
// should stop working immediately, not just on the next portal/admin page they visit.
// Also re-checks the password fingerprint (see auth.php) so a session issued before a
// password reset — including one an attacker had stolen — stops working here too, the
// same as it now does via require_login()/require_vet_login() elsewhere.
if ($staff) {
    $staffRow = db()->prepare('SELECT active, password_hash FROM staff_users WHERE id = ?');
    $staffRow->execute([$staff['id']]);
    $staffRow = $staffRow->fetch();
    if (!$staffRow || (int) $staffRow['active'] !== 1 || !password_fingerprint_matches($staff, $staffRow['password_hash'])) {
        $staff = null; // not unset from the session: it may not be the active one (see below)
    }
}
if ($vet) {
    // Also re-checks the linked application's own status — see require_vet_login() in
    // auth.php for why this duplicates that check instead of just the account's status.
    $vetRow = db()->prepare(
        'SELECT va.status, va.password_hash, a.status AS application_status
         FROM vet_accounts va JOIN vet_applications a ON a.id = va.vet_application_id
         WHERE va.id = ?'
    );
    $vetRow->execute([$vet['id']]);
    $vetRow = $vetRow->fetch();
    if (!$vetRow || $vetRow['status'] !== 'active' || $vetRow['application_status'] !== 'approved' || !password_fingerprint_matches($vet, $vetRow['password_hash'])) {
        $vet = null;
    }
}
if (!$staff && !$vet) {
    // Send the visitor to the sign-in page for the identity they were using: someone holding
    // only a (now stale) vet session cookie belongs at the vet login, not the staff one.
    $vetOnly = gs_session_exists('vet') && !gs_session_exists('staff');
    header('Location: ' . ($vetOnly ? '/for-veterinarians/login.php' : '/admin/login.php'));
    exit;
}

$kind  = $_GET['kind'] ?? '';
$docId = (int) ($_GET['doc_id'] ?? 0);

// Denied attempts are worth a record as much as successful ones — a signed-in vet probing
// document ids that aren't theirs is exactly what an access log is for.
function deny_document_access(int $status, string $message, string $kind, int $docId, $vet, $staff): void {
    audit('document_access_denied', $kind === 'vet_application' ? 'vet_application_document' : 'gs_request_document', $docId > 0 ? $docId : null,
        ['reason' => $message, 'vet_account_id' => $vet['id'] ?? null], $staff ? 'staff' : ($vet ? 'vet' : 'public'), $staff['id'] ?? $vet['id'] ?? null);
    http_response_code($status);
    exit($message);
}

if (!in_array($kind, ['gs_request', 'vet_application'], true) || $docId <= 0) {
    http_response_code(400);
    exit('Invalid request.');
}
if (!$staff && $kind !== 'gs_request') {
    deny_document_access(403, 'Forbidden.', $kind, $docId, $vet, $staff);
}

if ($kind === 'gs_request') {
    $stmt = db()->prepare('SELECT * FROM gs_request_documents WHERE id = ? LIMIT 1');
    $subfolderPrefix = 'gs-requests';
    $entityType = 'gs_request_document';
} else {
    $stmt = db()->prepare('SELECT * FROM vet_application_documents WHERE id = ? LIMIT 1');
    $subfolderPrefix = 'vet-applications';
    $entityType = 'vet_application_document';
}
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    exit('Not found.');
}

if (!$staff && $kind === 'gs_request') {
    $owner = db()->prepare('SELECT vet_account_id FROM gs_requests WHERE id = ?');
    $owner->execute([(int) $doc['gs_request_id']]);
    if ((int) $owner->fetchColumn() !== (int) $vet['id']) {
        deny_document_access(403, 'Forbidden.', $kind, $docId, $vet, $staff);
    }
}

// Documents are filed under a "pending" bucket until the owning case/application id is known
// at upload time, then left there — Phase 1 has no re-filing step, so check both locations.
$ownerColumn = $kind === 'gs_request' ? 'gs_request_id' : 'application_id';
$ownerId     = (int) $doc[$ownerColumn];
$candidates  = [
    private_storage_path() . "/{$subfolderPrefix}/{$ownerId}/{$doc['stored_filename']}",
    private_storage_path() . "/{$subfolderPrefix}/pending/{$doc['stored_filename']}",
];

$path = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $path = $candidate;
        break;
    }
}
if (!$path) {
    http_response_code(404);
    exit('File not found.');
}

audit('document_accessed', $entityType, $docId, ['owner_id' => $ownerId, 'vet_account_id' => $vet['id'] ?? null], $staff ? 'staff' : 'vet', $staff['id'] ?? $vet['id'] ?? null);

header('Content-Type: ' . $doc['mime_type']);
header('Content-Length: ' . filesize($path));
$safeFilename = str_replace(['"', "\r", "\n", ';', '\\', '/'], '', basename($doc['original_filename']));
// ASCII fallback for old clients, plus the RFC 5987 filename* form so non-ASCII names survive.
$asciiFilename = preg_replace('/[^A-Za-z0-9._ -]/', '_', $safeFilename) ?: 'document';
// "attachment" rather than "inline": every document here was uploaded by an anonymous,
// unauthenticated visitor (the public intake forms) and then opened directly by staff —
// rendering it inline would run the browser's own PDF/image viewer against untrusted
// content on this authenticated origin, with the staff session's cookies in scope. A
// vulnerability in that viewer (pdf.js has had arbitrary-JS-execution bugs historically)
// would then execute in the context of an active admin/vet session rather than a
// sandboxed, cookieless download. Forcing a download avoids that entirely, at the cost of
// an extra click to open the file afterward.
header('Content-Disposition: attachment; filename="' . $asciiFilename . '"; filename*=UTF-8\'\'' . rawurlencode($safeFilename));
// Belt and braces: if a browser ever did render this response, it gets no scripts, no network and
// an opaque origin.
header("Content-Security-Policy: default-src 'none'; sandbox");
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
// Releases PHP's session file lock (held since gs_session_start()) before streaming
// potentially up to 10MB — without this, every other request from the same browser
// session (another tab, or just the next click) blocks until this download finishes,
// since PHP's default session handler holds an exclusive lock on the session file for the
// entire script lifetime. Nothing after this point touches $_SESSION.
session_write_close();
readfile($path);
exit;
