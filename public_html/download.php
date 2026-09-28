<?php
// Authenticated document streaming endpoint. Documents are never web-accessible
// directly — every fetch goes through here so access can be gated and audited.
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/storage-path.php';

// Staff can see any document; a signed-in veterinarian can only see documents on
// their own GS-441524 requests (never another vet's, and never application docs —
// those are staff-only, reviewed as part of the account application itself).
$staff = current_staff();
$vet   = current_vet();
if ($vet) {
    // current_vet() only reads the session — unlike require_vet_login(), it doesn't
    // re-check the account is still active, so a suspended vet's still-open session
    // could otherwise keep downloading their own documents. Re-check here too, since
    // this page never calls require_vet_login() (that would wrongly redirect staff).
    $vetStatus = db()->prepare('SELECT status FROM vet_accounts WHERE id = ?');
    $vetStatus->execute([$vet['id']]);
    if ($vetStatus->fetchColumn() !== 'active') {
        unset($_SESSION['vet']);
        $vet = null;
    }
}
if (!$staff && !$vet) {
    header('Location: /admin/login.php');
    exit;
}

$kind  = $_GET['kind'] ?? '';
$docId = (int) ($_GET['doc_id'] ?? 0);

if (!in_array($kind, ['gs_request', 'vet_application'], true) || $docId <= 0) {
    http_response_code(400);
    exit('Invalid request.');
}
if (!$staff && $kind !== 'gs_request') {
    http_response_code(403);
    exit('Forbidden.');
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
        http_response_code(403);
        exit('Forbidden.');
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

audit('document_accessed', $entityType, $docId, ['owner_id' => $ownerId, 'vet_account_id' => $vet['id'] ?? null], $staff ? 'staff' : 'public');

header('Content-Type: ' . $doc['mime_type']);
header('Content-Length: ' . filesize($path));
$safeFilename = str_replace(['"', "\r", "\n"], '', basename($doc['original_filename']));
header('Content-Disposition: inline; filename="' . $safeFilename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
