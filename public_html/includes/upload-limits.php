<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

const MAX_UPLOAD_BYTES = 10 * 1024 * 1024; // 10MB

// "10 MB", "23.4 MB": for the limit printed next to the upload fields and in the refusal messages (single source: MAX_UPLOAD_BYTES).
function upload_size_text(int $bytes): string {
    $mb = round($bytes / 1048576, 1);
    return ($mb == floor($mb) ? number_format($mb, 0) : number_format($mb, 1)) . ' MB';
}

// PHP's own shorthand ("2M", "1024M", "1G", "512K", "8388608") as bytes; 0 when it cannot be read or means "no limit".
function upload_ini_bytes(string $value): int {
    if (!preg_match('/^\s*(\d+)\s*([KMG]?)B?\s*$/i', $value, $m)) return 0;
    $multiplier = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($m[2])];
    return (int) $m[1] * $multiplier;
}

// The limit that REALLY applies to one file: ours (MAX_UPLOAD_BYTES) or the server's upload_max_filesize, whichever is
// smaller. PHP refuses a bigger file BEFORE our code looks at it (a Karandeep review comment: the PHP default is only
// 2M, so on a host that keeps it a 5 MB prescription photo never reached our size message), so the forms print THIS number
// and the browser checks against it, instead of promising 10 MB the server would not take. $iniValue is for the tests.
function upload_limit_bytes(?string $iniValue = null): int {
    $server = upload_ini_bytes($iniValue ?? (string) ini_get('upload_max_filesize'));
    return $server > 0 ? min(MAX_UPLOAD_BYTES, $server) : MAX_UPLOAD_BYTES;
}

// What a person is told for a PHP upload error code (anything but UPLOAD_ERR_OK / UPLOAD_ERR_NO_FILE). The size codes say
// the real limit; the others used to be one vague "Upload failed. Please try again.".
function upload_error_message(int $code, ?string $iniValue = null): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'That file is larger than this server accepts (' . upload_size_text(upload_limit_bytes($iniValue)) . '). Please choose a smaller file, or a compressed copy.';
        case UPLOAD_ERR_PARTIAL:
            return 'The upload was interrupted before it finished. Please try again.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return "We couldn't save the file because of a problem on our side. Please try again later, or contact ops@kuronyx.in.";
        default:
            return 'Upload failed. Please try again.';
    }
}