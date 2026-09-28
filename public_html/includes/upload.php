<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

require_once __DIR__ . '/storage-path.php';

const ALLOWED_UPLOAD_MIME = [
    'application/pdf' => 'pdf',
    'image/jpeg'       => 'jpg',
    'image/png'        => 'png',
];
const MAX_UPLOAD_BYTES = 10 * 1024 * 1024; // 10MB

class UploadException extends \RuntimeException {}

// Validates and stores one uploaded file (from $_FILES[$field]) off-webroot under
// private_storage_path()/$subfolder, with a random stored filename. Returns metadata
// to persist alongside the owning record (gs_request_documents / vet_application_documents).
function store_uploaded_file(array $file, string $subfolder): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new UploadException('Invalid upload.');
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        throw new UploadException('No file was uploaded.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new UploadException('Upload failed. Please try again.');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new UploadException('Invalid upload.');
    }
    if ($file['size'] <= 0 || $file['size'] > MAX_UPLOAD_BYTES) {
        throw new UploadException('File is empty or exceeds the 10MB limit.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!isset(ALLOWED_UPLOAD_MIME[$mime])) {
        throw new UploadException('Unsupported file type. Upload a PDF, JPG or PNG.');
    }

    $dir = private_storage_path() . '/' . trim($subfolder, '/');
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new UploadException('Could not create storage directory.');
    }

    $ext            = ALLOWED_UPLOAD_MIME[$mime];
    $storedFilename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination    = $dir . '/' . $storedFilename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new UploadException('Could not save the uploaded file.');
    }

    return [
        'stored_filename'   => $storedFilename,
        'original_filename' => basename($file['name']),
        'mime_type'         => $mime,
        'size_bytes'        => $file['size'],
    ];
}

// Moves a file uploaded via store_uploaded_file() out of a "pending" bucket into its
// owning case/application's own folder, once that record's id is known (the upload
// itself happens before the DB row exists, so it can't be filed correctly right away).
// Failing to relocate is not fatal — download.php still falls back to the pending
// bucket — so this only logs rather than throwing.
function relocate_uploaded_file(string $storedFilename, string $fromSubfolder, string $toSubfolder): void {
    $from = private_storage_path() . '/' . trim($fromSubfolder, '/') . '/' . $storedFilename;
    $toDir = private_storage_path() . '/' . trim($toSubfolder, '/');
    if (!is_file($from)) {
        return;
    }
    if (!is_dir($toDir) && !mkdir($toDir, 0750, true) && !is_dir($toDir)) {
        error_log("[GS-441524] Could not create storage directory {$toDir} while relocating {$storedFilename}");
        return;
    }
    if (!rename($from, $toDir . '/' . $storedFilename)) {
        error_log("[GS-441524] Could not relocate {$storedFilename} from {$fromSubfolder} to {$toSubfolder}");
    }
}
