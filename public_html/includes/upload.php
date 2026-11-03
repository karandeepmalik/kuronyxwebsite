<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

require_once __DIR__ . '/storage-path.php';
require_once __DIR__ . '/upload-limits.php'; // MAX_UPLOAD_BYTES, upload_size_text(), upload_limit_bytes(), upload_error_message()

const ALLOWED_UPLOAD_MIME = [
    'application/pdf' => 'pdf',
    'image/jpeg'       => 'jpg',
    'image/png'        => 'png',
];
class UploadException extends \RuntimeException {}

const PDF_ACTIVE_CONTENT_PATTERN = '#/(JavaScript|JS|Launch|EmbeddedFiles?|RichMedia)(?![A-Za-z])#';
// Total bytes of decompressed stream data inspected per PDF - a bound against zip bombs.
const PDF_SCAN_MAX_INFLATED_BYTES = 32 * 1024 * 1024;

// Inflates one zlib stream, but STOPS as soon as the output passes $max bytes (returns null), and returns null for data that is
// not valid or not complete. gzuncompress($data, $max) was used here before and its limit does not hold: PHP returned a 34 MB
// stream at a 32 MB limit (found in a review follow-up), so a zip bomb was only ever stopped by memory_limit (a fatal error).
// This feeds the compressed bytes in small pieces and checks the size after each, so memory stays near $max plus a few MB
// (4 KB of input unpacks to at most about 4 MB). Checked 2026-11-02: a stream of 1000 MB unpacked is refused in ~0.2 s at ~47 MB.
function pdf_inflate_capped(string $data, int $max): ?string {
    $ctx = @inflate_init(ZLIB_ENCODING_DEFLATE);
    if ($ctx === false) return null;
    $out = '';
    foreach (str_split($data, 4096) as $chunk) {
        $piece = @inflate_add($ctx, $chunk, ZLIB_SYNC_FLUSH);
        if ($piece === false) return null;
        $out .= $piece;
        if (strlen($out) > $max) return null;
        if (inflate_get_status($ctx) === ZLIB_STREAM_END) break; // anything after the end (the newline before "endstream") is ignored
    }
    return inflate_get_status($ctx) === ZLIB_STREAM_END ? $out : null; // a truncated stream is not readable either
}

// Heuristic check (not a virus scan) for PDFs carrying scripts, launch actions or embedded
// files. Returns 'clean', 'active', 'encrypted' (the content is locked) or 'unscannable' (we could not read it ourselves:
// object streams bigger than the inflate budget, or a compression we cannot decode). Encrypted and unscannable used to be
// ONE verdict and one message, so a normal but large or unusual scanner PDF was told it was encrypted (a Karandeep review comment).
// A plain text search misses the two usual ways of hiding those names, so this also
//   - decodes #xx escapes inside PDF names (/J#53 is /JS), and
//   - inflates FlateDecode streams, since PDF 1.5 object streams ("/ObjStm") hold ordinary
//     objects, including action dictionaries, compressed.
function pdf_active_content_verdict(string $pdf): string {
    $normalize = fn(string $s): string => preg_replace_callback(
        '/#([0-9A-Fa-f]{2})/', fn($m) => chr(hexdec($m[1])), $s
    );
    if (preg_match('#/Encrypt(?![A-Za-z])#', $pdf)) {
        return 'encrypted';
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
        $inflated = pdf_inflate_capped(substr($pdf, $dataStart, $end - $dataStart), $budget);
        if ($inflated === null) return 'unscannable'; // unreadable, truncated, or bigger than what is left of the budget: refuse rather than guess
        $budget -= strlen($inflated);
        if (preg_match(PDF_ACTIVE_CONTENT_PATTERN, $normalize($inflated))) {
            return 'active';
        }
    }
    return 'clean';
}

// Did the person choose a file for this field? Answered from PHP's upload ERROR code, never from the file's name:
// empty($_FILES[x]['name']) is true for a file literally called "0" (PHP's empty() treats the string "0" as empty), so a
// document with that name was reported missing (required fields) or silently skipped (optional ones). A browser that
// was given no file sends error UPLOAD_ERR_NO_FILE; a crafted array-valued field counts as chosen so that
// store_uploaded_file() can refuse it as invalid.
function upload_was_chosen(string $field): bool {
    $error = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
    return is_array($error) || (int) $error !== UPLOAD_ERR_NO_FILE;
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
        // The size codes name the real limit and the others say what happened (see upload_error_message()); the ones that mean
        // OUR server could not store the file are also logged, because the visitor cannot fix them.
        if (in_array($file['error'], [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)) {
            error_log('[upload] PHP could not store an uploaded file (upload error ' . (int) $file['error'] . '): check upload_tmp_dir, disk space and extensions.');
        }
        throw new UploadException(upload_error_message((int) $file['error']));
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new UploadException('Invalid upload.');
    }
    if ($file['size'] <= 0) {
        throw new UploadException('That file is empty. Please choose a different file.');
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        throw new UploadException('That file is ' . upload_size_text((int) $file['size']) . ' - the limit is ' . upload_size_text(MAX_UPLOAD_BYTES) . ' per file. Please choose a smaller file, or a compressed copy.');
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
        if ($verdict === 'encrypted') {
            throw new UploadException('This PDF is encrypted, so it cannot be checked and cannot be accepted. Please upload an unprotected PDF, or a photo of the document.');
        }
        if ($verdict === 'unscannable') {
            // Not encrypted: we just could not read it ourselves (very large, or an unusual scanner format). Say so honestly.
            throw new UploadException("We couldn't check this PDF automatically, so we can't accept it. Please upload a photo or scan of the document (JPG or PNG) instead.");
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
