<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

// What the result of a call to Brevo's send endpoint tells us about whether the email went out. There are THREE
// answers, not two (a Karandeep review comment): a slow answer or a dropped connection after the request was sent
// used to be recorded as "failed" - and the email may well have gone out, so staff clicked Send again and the owner
// got it twice.
//   'sent'     - Brevo answered 201 (accepted).
//   'not_sent' - we KNOW nothing went out: Brevo answered with an error (4xx/5xx: it refused the message), or we never
//                got as far as sending the request (could not resolve the name, connect, or finish the TLS handshake).
//   'unknown'  - the request went out but no usable answer came back (timeout, connection dropped, an odd 2xx/3xx):
//                the message MAY have been accepted. Never treat this as a failure to retry blindly.
// $handshakeDone is "the TLS handshake with Brevo completed" (curl's APPCONNECT time > 0): only after it can the
// request have reached them.
function mail_send_outcome(int $httpCode, bool $handshakeDone): string {
    if ($httpCode === 201) return 'sent';
    if ($httpCode >= 400 && $httpCode <= 599) return 'not_sent';
    if ($httpCode === 0 && !$handshakeDone) return 'not_sent';
    return 'unknown';
}

// What staff are told when the outcome is 'unknown' (case page, veterinary application page): honest about the doubt,
// and says how to settle it before sending again.
function mail_unconfirmed_text(string $recipient): string {
    return "We couldn't confirm whether this email was sent - it may have gone out. Before sending it again, check Brevo's "
        . "transactional email logs (search for {$recipient}) or ask them.";
}