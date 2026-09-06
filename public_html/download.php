<?php
// Authenticated document streaming endpoint. Documents are never web-accessible
// directly — every fetch goes through here so access can be gated and audited.
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/storage-path.php';

$staff = require_login();

$kind  = $_GET['kind'] ?? '';
$docId = (int) ($_GET['doc_id'] ?? 0);

if (!in_array($kind, ['gs_request', 'vet_application'], true) || $docId <= 0) {
    http_response_code(400);
    exit('Invalid request.');
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

audit('document_accessed', $entityType, $docId, ['owner_id' => $ownerId]);

header('Content-Type: ' . $doc['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($doc['original_filename']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
