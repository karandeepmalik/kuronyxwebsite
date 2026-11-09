<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

// Text that is about to go to the server's error log, with email addresses taken out (and cut to $max characters).
// The error log sits OUTSIDE the data-erasure process, so an address written there outlives the erasure of the case it
// belongs to (a Karandeep review comment). The lines name the case and the log row instead (GS-123, case_emails #45),
// which is just as useful for debugging; this is for what we do not control - an exception message, or whatever Brevo
// sent back, which can echo the address ("email address 'x@y' is invalid"). Use it on EVERY error_log() line that
// carries an exception message or an API response (tests/cases/70c_log_safe.php fails if one does not).
function log_safe(string $text, int $max = 500): string {
    $clean = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', '[email]', $text);
    $clean = $clean ?? '[unreadable text]';
    return mb_strlen($clean) > $max ? mb_substr($clean, 0, $max) . '...[cut]' : $clean;
}