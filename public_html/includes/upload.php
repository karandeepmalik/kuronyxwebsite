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

const PDF_ACTIVE_CONTENT_PATTERN = '#/(JavaScript|JS|Launch|EmbeddedFiles?|RichMedia)(?![A-Za-z])#';
// Total bytes of decompressed stream data inspected per PDF - a bound against zip bombs.
const PDF_SCAN_MAX_INFLATED_BYTES = 32 * 1024 * 1024;

// Heuristic check (not a virus scan) for PDFs carrying scripts, launch actions or embedded
// files. Returns 'clean', 'active' or 'unscannable' (encrypted: the content can't be read).
// A plain text search misses the two usual ways of hiding those names, so this also
//   - decodes #xx escapes inside PDF names (/J#53 is /JS), and
//   - inflates FlateDecode streams, since PDF 1.5 object streams ("/ObjStm") hold ordinary
//     objects, including action dictionaries, compressed.
function pdf_active_content_verdict(string $pdf): string {
    $normalize = fn(string $s): string => preg_replace_callback(
        '/#([0-9A-Fa-f]{2})/', fn($m) => chr(hexdec($m[1])), $s
    );
    if (preg_match('#/Encrypt(?![A-Za-z])#', $pdf)) {
        return 'unscannable';
    }
    if (preg_match(PDF_ACTIVE_CONTENT_PATTERN, $normalize($pdf))) {
        return 'active';
    }
    // Only object streams are inflated: that is where compressed action dictionaries live.
    // Inflating image/font data too would risk false hits on random bytes that happen to
    // spell "/JS" (a large image is millions of positions).
    $budget = PDF_SCAN_MAX_INFLATED_BYTES;
    $pos = 0;
    while (($end = strpos($pdf, 'endstream', $pos)) !== false) {
        $start = strrpos($pdf, 'stream', $end - strlen($pdf));
        $pos   = $end + 9;
        if ($start === false) continue;
        $dictFrom = max(0, $start - 400);
        $dict     = substr($pdf, $dictFrom, $start - $dictFrom);
        if (!preg_match('#/ObjStm(?![A-Za-z])#', $dict)) continue;
        $dataStart = $start + 6;
        if (($pdf[$dataStart] ?? '') === "\r") $dataStart++;
        if (($pdf[$dataStart] ?? '') === "\n") $dataStart++;
        if ($budget <= 0) return 'unscannable';
        $inflated = @gzuncompress(substr($pdf, $dataStart, $end - $dataStart), $budget);
        if ($inflated === false) return 'unscannable'; // an object stream we can't read: refuse rather than guess
        $budget -= strlen($inflated);
        if (preg_match(PDF_ACTIVE_CONTENT_PATTERN, $normalize($inflated))) {
            return 'active';
        }
    }
    return 'clean';
}

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

    // A prescription is a scan or a plain document. A PDF carrying JavaScript, launch actions or
    // embedded files is not that, and staff open these on their own computers - refuse it. This is a
    // cheap heuristic (it can be dodged by obfuscating the names), not a virus scan.
    if ($mime === 'application/pdf') {
        $verdict = pdf_active_content_verdict((string) file_get_contents($file['tmp_name'], false, null, 0, MAX_UPLOAD_BYTES));
        if ($verdict === 'unscannable') {
            throw new UploadException('This PDF is encrypted, so it cannot be checked and cannot be accepted. Please upload an unprotected PDF, or a photo of the document.');
        }
        if ($verdict === 'active') {
            throw new UploadException('This PDF contains scripts or embedded files and cannot be accepted. Please upload a plain PDF, or a photo of the document.');
        }
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

// Deletes a file previously written by store_uploaded_file(), used to clean up uploads
// that already landed on disk when the DB transaction meant to reference them then fails
// and rolls back — otherwise the file is orphaned on disk forever with nothing in the DB
// pointing to it. Silently no-ops if the file isn't at that path (it may already have been
// relocated, or never existed), so callers can try every plausible location unconditionally.
function delete_uploaded_file(string $storedFilename, string $subfolder): void {
    $path = private_storage_path() . '/' . trim($subfolder, '/') . '/' . $storedFilename;
    if (is_file($path) && !unlink($path)) {
        error_log("[GS-441524] Could not delete orphaned upload {$path}");
    }
}
