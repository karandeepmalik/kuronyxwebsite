<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

require_once __DIR__ . '/db.php';

class MailException extends \RuntimeException {}

// The admin email templates deliberately contain bracketed fill-in-the-blank markers
// ([insert payment link], [confirm final price], [courier name], [tracking number],
// [click "Generate Activation Link"...]) that staff are meant to replace before sending.
// Nothing stopped a message going out to a customer with one still in it verbatim. Returns
// the first such leftover marker found in $text, or null.
function unresolved_placeholder(string $text): ?string {
    if (preg_match('/\[(?:insert|confirm|courier name|tracking number|click )[^\]]*\]/i', $text, $m)) {
        return $m[0];
    }
    return null;
}

// The sender addresses staff are allowed to send as — must match what's actually
// verified in Brevo (Settings → Senders & IP). Falls back to the single legacy
// SENDER_EMAIL/SENDER_NAME pair if an environment's db-config.php predates this list.
function verified_senders(): array {
    return defined('BREVO_VERIFIED_SENDERS') ? BREVO_VERIFIED_SENDERS : [SENDER_EMAIL => SENDER_NAME];
}

// Sends a plain-text transactional email via Brevo (no template — used by the admin
// email composer). Returns the Brevo message id on success, or a synthetic dry-run id
// in EMAIL_DRY_RUN mode (the test suite sets this — see tests/lib/server.php — so
// tests never hit the real API). $fromEmail/$fromName default to SENDER_EMAIL/
// SENDER_NAME but must be one of verified_senders() when overridden — this is a
// server-side backstop behind the admin UI's sender dropdown, not just a UI restriction.
function send_transactional_email(string $toEmail, string $toName, string $subject, string $textBody, ?string $fromEmail = null, ?string $fromName = null): string {
    $fromEmail = $fromEmail ?? SENDER_EMAIL;
    $senders   = verified_senders();
    if (!array_key_exists($fromEmail, $senders)) {
        throw new MailException("Sender address {$fromEmail} is not a verified Brevo sender.");
    }
    $fromName = $fromName ?? $senders[$fromEmail];

    if (defined('EMAIL_DRY_RUN') && EMAIL_DRY_RUN) {
        // EMAIL_DRY_RUN_LOG is only ever defined by the test harness (tests/lib/server.php)
        // so it can read back what would have been sent — e.g. to pull an activation link
        // out of an email body without a real inbox.
        if (defined('EMAIL_DRY_RUN_LOG') && EMAIL_DRY_RUN_LOG) {
            file_put_contents(EMAIL_DRY_RUN_LOG, json_encode(['to' => $toEmail, 'from' => $fromEmail, 'subject' => $subject, 'body' => $textBody]) . "\n", FILE_APPEND | LOCK_EX);
        }
        return 'dry-run-' . bin2hex(random_bytes(8));
    }

    $payload = json_encode([
        'to'          => [['email' => $toEmail, 'name' => $toName]],
        'sender'      => ['email' => $fromEmail, 'name' => $fromName],
        'subject'     => $subject,
        'textContent' => $textBody,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 201) {
        throw new MailException("Brevo email send failed (HTTP {$httpCode}): {$response}");
    }
    $data = json_decode($response, true);
    return $data['messageId'] ?? '';
}

// Sends an email tied to a GS-441524 case and logs the attempt to case_emails
// regardless of outcome, so a failed send is still visible in the case's
// communication history rather than silently disappearing. $senderEmail defaults to
// SENDER_EMAIL (see send_transactional_email) but is recorded either way, so the
// history always shows which verified address a message actually went out from.
//
// Write-ahead: the log row is inserted as 'sending' *before* the network call, then
// updated to 'sent'/'failed' afterward — not inserted only once the outcome is known. The
// old order (send, then log) meant that if the send succeeded but the log INSERT then
// failed for any reason (e.g. a subject too long for its column), a real email would have
// gone out with no record of it at all, and the failed INSERT would itself surface as an
// opaque error for what was actually a successful send. Recording the attempt first means
// a log entry always exists from the moment a send is attempted, whatever happens next —
// and a row stuck on 'sending' is itself diagnostic (the log INSERT worked but something
// after it didn't), rather than a silent gap.
function send_case_email(PDO $pdo, int $gsRequestId, ?int $staffId, string $recipient, string $recipientName, string $subject, string $body, ?string $senderEmail = null): bool {
    $senderEmail = $senderEmail ?? SENDER_EMAIL;
    $stmt = $pdo->prepare(
        "INSERT INTO case_emails (gs_request_id, staff_id, recipient, sender, subject, body, delivery_status) VALUES (?,?,?,?,?,?,'sending')"
    );
    $stmt->execute([$gsRequestId, $staffId, $recipient, $senderEmail, $subject, $body]);
    $logId = (int) $pdo->lastInsertId();

    try {
        $messageId = send_transactional_email($recipient, $recipientName, $subject, $body, $senderEmail);
    } catch (Throwable $e) {
        // Any failure *before or during* the send (not just MailException: a curl or runtime
        // error too) means nothing went out.
        error_log("[mailer] case email to {$recipient} (GS-{$gsRequestId}) failed: " . $e->getMessage());
        try {
            $pdo->prepare("UPDATE case_emails SET delivery_status = 'failed' WHERE id = ?")->execute([$logId]);
        } catch (Throwable $logError) {
            error_log("[mailer] could not mark case_emails #{$logId} failed: " . $logError->getMessage());
        }
        return false;
    }

    // The email has gone out. Failing to record that must not surface as an error (staff
    // would see a 500 and re-send, mailing the owner twice) - the row stays 'sending' and
    // the problem is logged instead.
    try {
        $pdo->prepare("UPDATE case_emails SET delivery_status = 'sent', brevo_message_id = ? WHERE id = ?")->execute([$messageId, $logId]);
    } catch (Throwable $e) {
        error_log("[mailer] case email #{$logId} to {$recipient} WAS SENT but its log row could not be updated: " . $e->getMessage());
    }
    return true;
}
