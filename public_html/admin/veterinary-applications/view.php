<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/case-statuses.php';
require_once __DIR__ . '/../../includes/form-errors.php';
$staff = require_role(['admin', 'pharmacy_staff']);

$id  = (int) ($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM vet_applications WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$app = $stmt->fetch();
if (!$app) {
    http_response_code(404);
    exit('Application not found.');
}

// An erased application (includes/data-protection.php erase_vet_application()) is a record, not a working
// application: every action is refused on the server and the forms are not shown, so personal details cannot
// be typed back in (a review note, an email to an address that no longer exists) and a rejected or suspended
// application cannot be approved again.
$isErased = !empty($app['erased_at']);

// The account that already uses this application's professional email, and the application it belongs to
// (null when there is none, or when it is this application's own). One account belongs to exactly one application
// and the address is unique across accounts, so when it is taken this application can never get its own account.
$emailAccountOwner = function () use ($pdo, $id, $app): ?array {
    $q = $pdo->prepare(
        'SELECT a.id, a.status FROM vet_accounts va JOIN vet_applications a ON a.id = va.vet_application_id
          WHERE va.email = ? AND va.vet_application_id <> ? LIMIT 1'
    );
    $q->execute([$app['professional_email'], $id]);
    return $q->fetch() ?: null;
};
// The message after approving when no account could be made because the address already has one. A WARNING, not
// the green success it used to be: the vet cannot use the portal through this application, so staff must follow up.
$noAccountWarning = function () use ($emailAccountOwner): array {
    $owner = $emailAccountOwner();
    return ['type' => 'warn', 'text' => 'Application approved, but no portal account was created: an account for this email already belongs to '
        . ($owner ? 'VA-' . (int) $owner['id'] . ' (' . status_label($owner['status']) . ')' : 'another application')
        . '. See the warning below for what to check.'];
};

$flash = null;
// Field-level errors (see includes/form-errors.php): only the review note has one - the banner above the page says it
// in full, and the note box, far down the page, shows it too so it is clear where to type.
$errors = [];
// Set only when this request just (re)generated a usable activation link, so the
// template built further down can embed the real URL instead of a placeholder.
$activationUrl = null;
// What staff typed into the email composer, kept when a send fails validation (or fails to go
// out) so the form re-renders with their draft instead of wiping it back to the template.
$draft = null;
// The line the "Approved" template shows until a link has been generated. Generating swaps it (or an old, now dead
// link) for the new link inside whatever message the staff member has typed - see generate_activation_link below.
$activationPlaceholder = '[click "Generate Activation Link" below, then re-open this template]';

// The outcome of the previous action, shown once (Post/Redirect/Get - see the redirect after the POST block below).
// $activationUrl travels with it: the raw activation link is never stored, so it exists only in the response that
// made it, and a redirect would otherwise lose it. It is removed from the session the moment it is read here, so it
// is shown once and a refresh does not show it again.
if (isset($_SESSION['vet_review_flash'][$id])) {
    $carried = $_SESSION['vet_review_flash'][$id];
    unset($_SESSION['vet_review_flash'][$id]);
    $flash = $carried['flash'] ?? null;
    $activationUrl = $carried['activation_url'] ?? null;
    $draft = $carried['draft'] ?? null; // the email message that was being written when Generate was clicked
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'err', 'text' => 'Your session expired. Please try again.'];
    } else {
        $action = $_POST['action'] ?? '';
        $noteText = trim($_POST['note'] ?? '');

        $applyNote = function (string $prefix) use ($pdo, $id, $staff, $noteText, &$app) {
            $stamp = fmt_time(gmdate('Y-m-d H:i:s'));
            $entry = "[{$stamp} · {$staff['name']}] {$prefix}" . ($noteText !== '' ? ": {$noteText}" : '.');
            // Appended via a single atomic UPDATE — the database's own concatenation
            // (CONCAT on MySQL, || on SQLite, which has no CONCAT()) against whatever
            // internal_notes actually holds right now — rather than reading the current
            // text into PHP, appending, and writing the whole thing back. The latter lets
            // two concurrent review actions on the same application (two staff members, or
            // a double-click) both start from the same stale snapshot read at the top of
            // this script, so the second write silently discards whatever the first had
            // just appended. The separator is passed as a bound parameter, not embedded in
            // the SQL text — neither engine reliably treats a literal \n inside a SQL
            // string as an actual newline.
            $sql = db_driver() === 'sqlite'
                ? "UPDATE vet_applications SET internal_notes = CASE WHEN internal_notes IS NULL OR internal_notes = '' THEN ? ELSE internal_notes || ? || ? END WHERE id = ?"
                : "UPDATE vet_applications SET internal_notes = CASE WHEN internal_notes IS NULL OR internal_notes = '' THEN ? ELSE CONCAT(internal_notes, ?, ?) END WHERE id = ?";
            $pdo->prepare($sql)->execute([$entry, "\n", $entry, $id]);
            $app['internal_notes'] = trim(($app['internal_notes'] ?? '') . "\n" . $entry);
        };

        // Approve / reject / suspend / request-info change the application's status, so two things are checked
        // (rules in VET_APPLICATION_ACTIONS, case-statuses.php):
        //  1. The status the form was LOADED with (expected_status) must still be the status now. Without this, a
        //     stale tab quietly undid someone else's decision: open an application as Pending, have it rejected
        //     elsewhere, click Approve from the old tab, and it approved with no warning and no sight of the
        //     rejection. A double-click is the same shape (the second click finds it already changed).
        //  2. The action must make sense from the status it is in: suspend only takes a working (approved) account
        //     out of use, and approving what is approved or rejecting what is rejected does nothing.
        // This pre-check reads the status fresh at the start of the request; the UPDATE inside each transaction is
        // conditional on that status again (see $statusRaced), so two requests landing at once can't both win.
        $expectedStatus = (string) ($_POST['expected_status'] ?? '');
        if (!in_array($expectedStatus, VET_APPLICATION_STATUSES, true)) $expectedStatus = '';
        $reviewRefusal = function (string $action) use (&$app, $expectedStatus): ?string {
            $current = $app['status'];
            if ($expectedStatus !== $current) {
                return $expectedStatus === ''
                    ? 'This page is out of date, so nothing was changed. Reload it and decide again.'
                    : 'This application is now ' . status_label($current) . ' (it was ' . status_label($expectedStatus) . ' when you opened this page), so nothing was changed. Reload to see what happened, then decide again.';
            }
            if (!in_array($current, VET_APPLICATION_ACTIONS[$action]['from'], true)) {
                return match ($action) {
                    'suspend'      => 'Only an approved application can be suspended. This one is ' . status_label($current) . '.',
                    'request_info' => $current === 'under_review'
                        ? 'This application is already Under Review.'
                        : 'Cannot request more information on an application that has already been approved, rejected, or suspended.',
                    default        => 'This application is already ' . status_label($current) . '.',
                };
            }
            return null;
        };
        // Thrown inside a transaction when the conditional status UPDATE matched nothing: the status changed
        // between the pre-check above and this write. Caught next to each rollback.
        $statusRaced = ['type' => 'err', 'text' => 'Someone changed this application while you were saving, so nothing was changed. Reload to see what happened, then decide again.'];

        if ($isErased) {
            $flash = ['type' => 'err', 'text' => 'This application has been erased, so it is read-only: nothing can be approved, changed, noted or sent.'];
        } elseif (isset(VET_APPLICATION_ACTIONS[$action]) && ($refusal = $reviewRefusal($action)) !== null) {
            $flash = ['type' => 'err', 'text' => $refusal];
        } elseif ((VET_APPLICATION_ACTIONS[$action]['note'] ?? false) && $noteText === '') {
            // Approving, rejecting and suspending decide who may send us prescriptions, so each must leave a record of
            // what was checked or why. The checklist says to write it; this makes sure it is there (a blank or
            // whitespace-only box does not count).
            $errors['note'] = match ($action) {
                'approve' => 'Required: write what you checked.',
                'reject'  => 'Required: write why you are rejecting.',
                default   => 'Required: write why you are suspending.',
            };
            $flash = ['type' => 'err', 'text' => match ($action) {
                'approve' => 'Write what you checked before approving (for example: certificate checked against the council register; clinic called on a number found independently and confirmed). Nothing was changed.',
                'reject'  => 'Write why you are rejecting this application, so there is a record of the reason. Nothing was changed.',
                default   => 'Write why you are suspending this account, so there is a record of the reason. Nothing was changed.',
            }];
        } elseif ($action === 'approve') {
            // The application's own status and its linked portal account's status must
            // land together — doing these as separate autocommit statements let a
            // concurrent review action (e.g. another tab rejecting this same application)
            // interleave between them and leave the two out of sync (application rejected,
            // account still active, or vice versa). require_vet_login() below also checks
            // the application is still approved on every request as a second layer, but
            // the underlying rows should still be consistent with each other.
            $pdo->beginTransaction();
            try {
                $moved = $pdo->prepare('UPDATE vet_applications SET status = \'approved\', reviewed_by = ?, reviewed_at = ? WHERE id = ? AND status = ?');
                $moved->execute([$staff['id'], gmdate('Y-m-d H:i:s'), $id, $app['status']]);
                if ($moved->rowCount() === 0) throw new DomainException('status changed');
                $applyNote('Approved');
                audit('application_approved', 'vet_application', $id, []);
                $app['status'] = 'approved';

                // Provision (or reuse) the portal account this application unlocks.
                $existing = $pdo->prepare('SELECT id, status, password_hash FROM vet_accounts WHERE vet_application_id = ?');
                $existing->execute([$id]);
                $existing = $existing->fetch();

                if ($existing === false) {
                    $emailTaken = $pdo->prepare('SELECT id FROM vet_accounts WHERE email = ?');
                    $emailTaken->execute([$app['professional_email']]);
                    if ($emailTaken->fetchColumn() !== false) {
                        $applyNote('Approved, but a portal account already exists for this email address - no new account created');
                        audit('vet_account_conflict', 'vet_application', $id, ['email' => $app['professional_email']]);
                        $flash = $noAccountWarning();
                    } else {
                        // vet_accounts.vet_application_id is UNIQUE, so two staff clicking
                        // "Approve" on the same application at once can both reach this
                        // branch (each sees "no existing account" from their own
                        // transaction) and race to INSERT — the DB constraint guarantees
                        // only one account is ever created, but without this catch the
                        // loser's INSERT throws and falls through to the generic error
                        // page instead of a clean message.
                        try {
                            $rawToken = bin2hex(random_bytes(32));
                            $pdo->prepare(
                                'INSERT INTO vet_accounts (vet_application_id, email, status, activation_token_hash, activation_expires_at)
                                 VALUES (?, ?, \'pending_activation\', ?, ?)'
                            )->execute([$id, $app['professional_email'], hash('sha256', $rawToken), gmdate('Y-m-d H:i:s', time() + 7 * 86400)]);
                            audit('vet_account_created', 'vet_application', $id, []);
                            $activationUrl = site_url() . '/for-veterinarians/activate.php?token=' . $rawToken;
                            $flash = ['type' => 'ok', 'text' => 'Application approved and a portal account has been created. Use "Send an email" below to send the activation link - nothing is emailed automatically.'];
                        } catch (PDOException $e) {
                            // vet_accounts has TWO unique columns, and each means something different - so
                            // say which one collided instead of assuming the first.
                            if (is_unique_violation($e, 'vet_accounts', 'vet_application_id')) {
                                $flash = ['type' => 'ok', 'text' => 'Application approved. A portal account for this vet was just created by another request - reload this page to see its activation link.'];
                            } elseif (is_unique_violation($e, 'vet_accounts', 'email')) {
                                // The same outcome as the "email already has an account" check above, reached
                                // through a race: another request created an account for this address between
                                // that check and this INSERT. No account was made for this application.
                                $applyNote('Approved, but a portal account already exists for this email address - no new account created');
                                audit('vet_account_conflict', 'vet_application', $id, ['email' => $app['professional_email']]);
                                $flash = $noAccountWarning();
                            } else {
                                throw $e;
                            }
                        }
                    }
                } elseif ($existing['status'] === 'suspended' && $existing['password_hash'] !== null) {
                    // Was previously activated (has a real password already), then rejected or
                    // suspended — re-approving should actually restore working access, not just
                    // relabel the application while leaving the vet locked out.
                    $pdo->prepare("UPDATE vet_accounts SET status = 'active' WHERE id = ?")->execute([$existing['id']]);
                    $applyNote('Existing portal account reactivated (was suspended, already had a password)');
                    audit('vet_account_reactivated', 'vet_application', $id, []);
                    $flash = ['type' => 'ok', 'text' => 'Application approved and the existing portal account has been reactivated - the vet can sign in with their existing password.'];
                } elseif ($existing['status'] === 'suspended') {
                    // Was rejected/suspended before ever being activated — any earlier token is
                    // long cleared/expired, so this needs a fresh one, same as a brand-new account.
                    $rawToken = bin2hex(random_bytes(32));
                    $pdo->prepare(
                        "UPDATE vet_accounts SET status = 'pending_activation', activation_token_hash = ?, activation_expires_at = ? WHERE id = ?"
                    )->execute([hash('sha256', $rawToken), gmdate('Y-m-d H:i:s', time() + 7 * 86400), $existing['id']]);
                    $applyNote('Existing (never-activated) portal account reset back to pending activation with a fresh link');
                    audit('vet_account_reactivated', 'vet_application', $id, []);
                    $activationUrl = site_url() . '/for-veterinarians/activate.php?token=' . $rawToken;
                    $flash = ['type' => 'ok', 'text' => 'Application approved. A fresh activation link has been generated below - any previous one was invalidated when this application was rejected or suspended.'];
                } else {
                    $flash = ['type' => 'ok', 'text' => 'Application approved.'];
                }
                $pdo->commit();
            } catch (DomainException $e) {
                $pdo->rollBack();
                $flash = $statusRaced;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } elseif ($action === 'reject') {
            // See 'approve' above — the application and account status updates must
            // commit together, not as two separate autocommit statements.
            $pdo->beginTransaction();
            try {
                $moved = $pdo->prepare('UPDATE vet_applications SET status = \'rejected\', reviewed_by = ?, reviewed_at = ? WHERE id = ? AND status = ?');
                $moved->execute([$staff['id'], gmdate('Y-m-d H:i:s'), $id, $app['status']]);
                if ($moved->rowCount() === 0) throw new DomainException('status changed');
                // An application can be rejected after already being approved (e.g. a decision
                // gets reversed) — without this, a previously-provisioned vet_accounts row would
                // stay active/pending_activation and the vet could still sign in or activate
                // despite the application now showing rejected. Same statement 'suspend' below
                // already uses, and it's safe to run unconditionally even if no account exists.
                $pdo->prepare('UPDATE vet_accounts SET status = \'suspended\' WHERE vet_application_id = ?')->execute([$id]);
                $applyNote('Rejected');
                audit('application_rejected', 'vet_application', $id, []);
                $pdo->commit();
            } catch (DomainException $e) {
                $pdo->rollBack();
                $flash = $statusRaced;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $flash = ['type' => 'ok', 'text' => 'Application rejected. Use "Send an email" below if the applicant needs to be told.'];
            $app['status'] = 'rejected';
        } elseif ($action === 'request_info') {
            // Only valid from the application's own early-review states (pending / under review): calling this
            // on an *approved* application would move it to 'under_review' without touching its linked
            // vet_accounts row, leaving the account marked 'active' while the application is not. The shared
            // pre-check above refuses every other status; the conditional UPDATE guards the race.
            $pdo->beginTransaction();
            try {
                $moved = $pdo->prepare('UPDATE vet_applications SET status = \'under_review\' WHERE id = ? AND status = ?');
                $moved->execute([$id, $app['status']]);
                if ($moved->rowCount() === 0) throw new DomainException('status changed');
                $applyNote('Requested more information');
                audit('application_reviewed', 'vet_application', $id, ['outcome' => 'more_info_requested']);
                $pdo->commit();
                $flash = ['type' => 'ok', 'text' => 'Marked as needing more information. Use "Send an email" below if the applicant needs to be told.'];
                $app['status'] = 'under_review';
            } catch (DomainException $e) {
                $pdo->rollBack();
                $flash = $statusRaced;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } elseif ($action === 'suspend') {
            // See 'approve' above — the application and account status updates must
            // commit together, not as two separate autocommit statements.
            $pdo->beginTransaction();
            try {
                $moved = $pdo->prepare('UPDATE vet_applications SET status = \'suspended\' WHERE id = ? AND status = ?');
                $moved->execute([$id, $app['status']]);
                if ($moved->rowCount() === 0) throw new DomainException('status changed');
                // Also lock out the portal account, if one was ever provisioned — otherwise
                // suspending the application would leave an already-activated vet still
                // able to sign in and submit requests.
                $pdo->prepare('UPDATE vet_accounts SET status = \'suspended\' WHERE vet_application_id = ?')->execute([$id]);
                $applyNote('Suspended');
                audit('application_reviewed', 'vet_application', $id, ['outcome' => 'suspended']);
                $pdo->commit();
            } catch (DomainException $e) {
                $pdo->rollBack();
                $flash = $statusRaced;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $flash = ['type' => 'ok', 'text' => 'Account suspended. Use "Send an email" below if the applicant needs to be told.'];
            $app['status'] = 'suspended';
        } elseif ($action === 'generate_activation_link') {
            // This button shares a form with the email composer, so the message being written is posted along with
            // it. Generating used to ignore it and the page came back with the default template: a custom message
            // was gone. Keep it (also when Generate is refused), exactly as a failed send keeps its draft.
            $typedDraft = [
                'sender'    => trim($_POST['sender'] ?? ''),
                'recipient' => trim($_POST['recipient'] ?? ''),
                'subject'   => trim($_POST['subject'] ?? ''),
                'body'      => trim($_POST['body'] ?? ''),
            ];
            $draft = ($typedDraft['subject'] !== '' || $typedDraft['body'] !== '') ? $typedDraft : null;
            $account = $pdo->prepare('SELECT id, status FROM vet_accounts WHERE vet_application_id = ?');
            $account->execute([$id]);
            $account = $account->fetch();
            if (!$account) {
                if ($app['status'] === 'approved') {
                    $owner = $emailAccountOwner();
                    $flash = ['type' => 'err', 'text' => 'This application is approved but has no portal account, so there is no link to generate'
                        . ($owner ? ': an account for this email already belongs to VA-' . (int) $owner['id'] . ' (' . status_label($owner['status']) . '). See the warning above.' : '. See the warning above.')];
                } else {
                    $flash = ['type' => 'err', 'text' => 'No portal account exists for this application yet - approve it first.'];
                }
            } elseif ($app['status'] !== 'approved') {
                // activate.php independently requires the linked application to still be
                // 'approved', so a link minted while the application is rejected/suspended
                // can never work even though this would otherwise happily hand staff a URL
                // that looks usable. Block it at the source instead of relying on the vet
                // account's own status alone (which 'approve' may not have touched yet).
                $flash = ['type' => 'err', 'text' => 'This application is not currently approved - no working activation link can be generated.'];
            } elseif ($account['status'] === 'active') {
                $flash = ['type' => 'err', 'text' => 'This vet has already activated their account - no link needed.'];
            } elseif ($account['status'] !== 'pending_activation') {
                $flash = ['type' => 'err', 'text' => 'This portal account is not awaiting activation - resolve its status first.'];
            } else {
                $rawToken = bin2hex(random_bytes(32));
                $pdo->prepare('UPDATE vet_accounts SET activation_token_hash = ?, activation_expires_at = ? WHERE id = ?')
                    ->execute([hash('sha256', $rawToken), gmdate('Y-m-d H:i:s', time() + 7 * 86400), $account['id']]);
                audit('activation_link_generated', 'vet_application', $id, []);
                $activationUrl = site_url() . '/for-veterinarians/activate.php?token=' . $rawToken;
                if ($draft !== null) {
                    // Generating just killed the previous link, and sending refuses a message holding a dead one, so
                    // put the new link where the old one (or the template's placeholder line) was. A message with
                    // neither is left alone: the page then shows the new link in a box to paste where wanted.
                    $newUrl = $activationUrl;
                    $draft['body'] = str_replace($activationPlaceholder, $newUrl, $draft['body']);
                    $draft['body'] = preg_replace_callback('#https?://[^\s]+/for-veterinarians/activate\.php\?token=[a-f0-9]+#', fn () => $newUrl, $draft['body']);
                }
                $flash = ['type' => 'ok', 'text' => 'A fresh activation link has been generated below (any previous link no longer works) - review and send it.'];
            }
        } elseif ($action === 'send_email') {
            $senderEmail = trim($_POST['sender'] ?? '');
            $recipient   = trim($_POST['recipient'] ?? '');
            $subject     = trim($_POST['subject'] ?? '');
            $body        = trim($_POST['body'] ?? '');
            $allowedRecipients = array_filter([$app['professional_email'], $app['clinic_email'] ?? null]);
            // "Generate Activation Link" always overwrites the account's previous token —
            // if two staff generate one around the same time (or the same staff regenerates
            // in another tab), whichever link is embedded in THIS message may already have
            // been silently invalidated by a newer one before this send actually happens.
            // Re-checking against the account's current token at send time, not just when
            // this message was drafted, means a dead link can't go out unnoticed.
            $embeddedTokenStale = false;
            $embeddedActivationLink = false;
            if (preg_match('#/for-veterinarians/activate\.php\?token=([a-f0-9]+)#', $body, $m)) {
                $embeddedActivationLink = true;
                $stillLive = $pdo->prepare(
                    "SELECT 1 FROM vet_accounts WHERE vet_application_id = ? AND activation_token_hash = ? AND status = 'pending_activation'"
                );
                $stillLive->execute([$id, hash('sha256', $m[1])]);
                $embeddedTokenStale = !$stillLive->fetchColumn();
            }

            $draft = ['sender' => $senderEmail, 'recipient' => $recipient, 'subject' => $subject, 'body' => $body];
            if (!consume_one_time_token('send_email:vet:' . $id)) {
                // Not kept as a draft: most likely a resubmission of a send that already went out.
                $draft = null;
                // A double-click or an F5 resubmitting the same POST has exactly this
                // shape — see gs-requests/view.php's identical guard for the same reason.
                $flash = ['type' => 'err', 'text' => 'This form was already submitted (or the page is stale) - reload and try again if you still need to send it.'];
            } elseif (!array_key_exists($senderEmail, verified_senders())) {
                $flash = ['type' => 'err', 'text' => 'Select a valid sender address.'];
            } elseif (!in_array($recipient, $allowedRecipients, true)) {
                $flash = ['type' => 'err', 'text' => 'Recipient must be an email address on file for this application.'];
            } elseif ($subject === '' || $body === '') {
                $flash = ['type' => 'err', 'text' => 'Subject and message body are required.'];
            } elseif (($ph = unresolved_placeholder($subject . "
" . $body)) !== null) {
                $flash = ['type' => 'err', 'text' => "This message still contains an unfilled placeholder: {$ph} - replace it before sending."];
            } elseif (mb_strlen($subject) > 255) {
                $flash = ['type' => 'err', 'text' => 'Subject must be 255 characters or fewer.'];
            } elseif (mb_strlen($body) > 60000 || strlen($body) > TEXT_MAX_BYTES) {
                $flash = ['type' => 'err', 'text' => 'Message body is too long.'];
            } elseif ($embeddedActivationLink && $recipient !== $app['professional_email']) {
                // The activation link lets whoever opens it set the account's password. It may only go to the
                // address the account was created for - the clinic address is unverified.
                $flash = ['type' => 'err', 'text' => 'An activation link can only be sent to the veterinarian\'s own professional email address.'];
            } elseif ($embeddedTokenStale) {
                $flash = ['type' => 'err', 'text' => 'This message contains an activation link that is no longer valid - a newer one has since been generated. Generate a fresh link and try again.'];
            } else {
                $result = 'failed';
                try {
                    send_transactional_email($recipient, $app['full_name'], $subject, $body, $senderEmail);
                    $result = 'sent';
                } catch (MailException $e) {
                    error_log("[mailer] application email (VA-{$id}) failed or unconfirmed: " . log_safe($e->getMessage()));
                    // 'unknown': the request went out but no answer came back, so the email MAY have been sent (see mail_send_outcome())
                    $result = $e->outcome === 'unknown' ? 'unknown' : 'failed';
                }
                $sent = $result === 'sent';
                // vet_applications has no case_emails-style log table of its own (that's
                // scoped to gs_requests) — outcomes are appended to internal_notes instead,
                // consistent with how every other review action here is recorded.
                if ($sent) $draft = null; // a failed send keeps the draft so it can be retried
                $applyNote($sent
                    ? "Emailed applicant ({$senderEmail} → {$recipient}) - Subject: {$subject}"
                    : ($result === 'unknown'
                        ? "Email to applicant NOT CONFIRMED - it may have been sent, check before resending ({$senderEmail} → {$recipient}) - Subject: {$subject}"
                        : "Email to applicant FAILED to send ({$senderEmail} → {$recipient}) - Subject: {$subject}"));
                audit(['sent' => 'email_sent', 'unknown' => 'email_unconfirmed'][$result] ?? 'email_failed', 'vet_application', $id, ['sender' => $senderEmail, 'recipient' => $recipient]);
                $flash = $sent
                    ? ['type' => 'ok', 'text' => 'Email sent.']
                    : ($result === 'unknown'
                        ? ['type' => 'err', 'text' => mail_unconfirmed_text($recipient) . ' The attempt has been logged in the notes below.']
                        : ['type' => 'err', 'text' => 'Email failed to send. The attempt has been logged in the notes below.']);
            }
        } elseif ($action === 'no_email_needed') {
            // Same one-time token as send_email (both buttons live in one form), so a double-click
            // can't append the note and write the audit entry twice.
            if (!consume_one_time_token('send_email:vet:' . $id)) {
                $flash = ['type' => 'err', 'text' => 'This form was already submitted (or the page is stale) - reload if you still need to.'];
            } else {
                audit('email_not_needed', 'vet_application', $id, []);
                $applyNote('Marked - no email needed');
                $flash = ['type' => 'ok', 'text' => 'Noted - no email needed.'];
            }
        } elseif ($action === 'add_note') {
            if ($noteText === '') {
                $flash = ['type' => 'err', 'text' => 'Note cannot be empty.'];
            } else {
                $applyNote('Note');
                audit('note_added', 'vet_application', $id, []);
                $flash = ['type' => 'ok', 'text' => 'Note added.'];
            }
        }
    }
}

// Post/Redirect/Get. Answering a POST with the result page itself leaves the browser sitting on a POST, so a refresh
// RE-SENT it: Add Note appended the note again, Approve/Reject added another entry (or a confusing "now Approved"
// refusal), and Generate Activation Link made a new token that killed the link just copied. So a successful action
// answers with a redirect to a plain GET of this page and the message is shown once from the session (above).
// Refusals and errors are NOT redirected: the page must keep what was typed (the review note, the email draft), and
// repeating a refused request just refuses it again.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flash && in_array($flash['type'], ['ok', 'warn'], true) && session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['vet_review_flash'][$id] = ['flash' => $flash, 'activation_url' => $activationUrl, 'draft' => $draft];
    header('Location: /admin/veterinary-applications/view.php?id=' . $id);
    exit;
}

// The review note, kept when an action is refused (a stale page, a status rule) so a note that took a while to
// write is not lost; a successful action starts with an empty box again.
$keptNote = ($flash && $flash['type'] === 'err' && !$isErased) ? (string) ($_POST['note'] ?? '') : '';

// An APPROVED application with no portal account of its own (because the email address already has one, which
// belongs to another application): the vet cannot use the portal through it. The message right after approving is
// shown once, so this stays visible every time the page is opened until staff have sorted it out.
$noAccountBanner = null;
if ($app['status'] === 'approved' && !$isErased) {
    $ownAccount = $pdo->prepare('SELECT COUNT(*) FROM vet_accounts WHERE vet_application_id = ?');
    $ownAccount->execute([$id]);
    if ((int) $ownAccount->fetchColumn() === 0) {
        $noAccountBanner = ['owner' => $emailAccountOwner()];
    }
}

$documents = $pdo->prepare('SELECT * FROM vet_application_documents WHERE application_id = ? ORDER BY created_at ASC');
$documents->execute([$id]);
$documents = $documents->fetchAll();

// Predefined email templates, one per review outcome plus a blank/custom option. The
// "Approved" template embeds a real activation link only when this render just
// generated one (via the approve or "Generate Activation Link" action) — the raw
// token is never persisted, so it can only ever appear right after it's created.
$ref = "Reference: VA-{$id}";
$emailTemplates = [
    'blank' => [
        'label'   => '- Blank / custom -',
        'subject' => '',
        'body'    => '',
    ],
    'approved' => [
        'label'   => 'Approved (with activation link)',
        'subject' => 'Your Kuronyx veterinary account is approved',
        'body'    => "Your veterinary account application has been approved.\n\nSet your password to activate your portal account (link valid for 7 days):\n"
            . ($activationUrl ?: $activationPlaceholder)
            . "\n\n{$ref}",
    ],
    'rejected' => [
        'label'   => 'Rejected',
        'subject' => 'Your Kuronyx veterinary account application',
        'body'    => "Your veterinary account application could not be approved at this time.\n\n{$ref}\n\nIf you have questions, please contact ops@kuronyx.in.",
    ],
    'request_info' => [
        'label'   => 'Request More Information',
        'subject' => 'Additional information needed - Kuronyx veterinary account application',
        'body'    => "We need some additional information before we can proceed with your veterinary account application.\n\n{$ref}\n\nOur team will follow up shortly with specifics.",
    ],
    'suspended' => [
        'label'   => 'Suspended',
        'subject' => 'Your Kuronyx veterinary account',
        'body'    => "Your veterinary account has been suspended.\n\n{$ref}\n\nIf you have questions, please contact ops@kuronyx.in.",
    ],
];
$defaultTemplateKey = ($activationUrl && $draft === null) ? 'approved' : 'blank'; // a kept draft is the staff member's own message

$pageTitle     = "VA-{$id} - Kuronyx Admin";
$robotsNoindex = true;
$backHref      = '/admin/veterinary-applications/';
$backLabel     = 'All applications';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · Veterinary application</p>
    <h1 class="doc-title">VA-<?= $id ?> · <?= htmlspecialchars($app['full_name'], ENT_QUOTES) ?></h1>
    <p class="doc-meta">
      <span class="status-pill status-<?= htmlspecialchars($app['status'], ENT_QUOTES) ?>"><?= htmlspecialchars(status_label($app['status']), ENT_QUOTES) ?></span><span class="sep">·</span>
      Submitted <?= htmlspecialchars(fmt_time($app['created_at']), ENT_QUOTES) ?>
    </p>

    <?php if ($flash): ?>
      <div class="alert <?= ['ok' => 'alert-ok', 'warn' => 'alert-warn'][$flash['type']] ?? '' ?>"><?= htmlspecialchars($flash['text'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <?php if ($isErased): ?>
      <div class="alert">Personal data for this application was erased on <?= htmlspecialchars(fmt_time($app['erased_at']), ENT_QUOTES) ?>. The applicant and clinic details, uploaded documents and review notes are gone; only the application number and its status remain. The application is read-only.</div>
    <?php endif; ?>

    <?php if ($noAccountBanner !== null): $owner = $noAccountBanner['owner']; ?>
      <div class="alert alert-warn">
        <strong>No portal account for this application.</strong>
        <?php if ($owner): ?>
          It is approved, but an account for this email address already belongs to
          <a href="/admin/veterinary-applications/view.php?id=<?= (int) $owner['id'] ?>">VA-<?= (int) $owner['id'] ?></a>
          (<?= htmlspecialchars(status_label($owner['status']), ENT_QUOTES) ?>), and an account can belong to only one application. The vet cannot use the portal
          through this application. Check <a href="/admin/veterinary-applications/view.php?id=<?= (int) $owner['id'] ?>">VA-<?= (int) $owner['id'] ?></a>:
          it may be the one to re-approve (re-approving a rejected or suspended application restores its account), or this may be a duplicate to reject.
        <?php else: ?>
          It is approved, but no account exists for it, so the vet cannot sign in through it. Reject it and approve it again to create the account.
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card-panel">
      <h2>Veterinarian</h2>
      <div class="kv-grid">
        <div class="k">Full name</div><div class="v"><?= htmlspecialchars($app['full_name'], ENT_QUOTES) ?></div>
        <div class="k">Email</div><div class="v"><?= htmlspecialchars($app['professional_email'], ENT_QUOTES) ?></div>
        <div class="k">Mobile</div><div class="v"><?= htmlspecialchars($app['mobile'], ENT_QUOTES) ?></div>
        <div class="k">Registration #</div><div class="v"><?= htmlspecialchars($app['registration_number'], ENT_QUOTES) ?></div>
        <div class="k">Registration state</div><div class="v"><?= htmlspecialchars($app['registration_state'], ENT_QUOTES) ?>, <?= htmlspecialchars($app['registration_country'], ENT_QUOTES) ?></div>
        <div class="k">Qualification</div><div class="v"><?= htmlspecialchars($app['qualification'], ENT_QUOTES) ?> (<?= (int) $app['year_qualified'] ?>)</div>
        <div class="k">Practice type</div><div class="v"><?= htmlspecialchars($app['practice_type'], ENT_QUOTES) ?></div>
      </div>
    </div>

    <div class="card-panel">
      <h2>Clinic</h2>
      <div class="kv-grid">
        <div class="k">Name</div><div class="v"><?= htmlspecialchars($app['clinic_name'], ENT_QUOTES) ?></div>
        <div class="k">Address</div><div class="v"><?= htmlspecialchars($app['clinic_address'], ENT_QUOTES) ?>, <?= htmlspecialchars($app['clinic_city'], ENT_QUOTES) ?>, <?= htmlspecialchars($app['clinic_state'], ENT_QUOTES) ?> <?= htmlspecialchars($app['clinic_pin'], ENT_QUOTES) ?></div>
        <div class="k">Phone</div><div class="v"><?= htmlspecialchars($app['clinic_phone'], ENT_QUOTES) ?></div>
        <div class="k">Email</div><div class="v"><?= htmlspecialchars($app['clinic_email'] ?? '-', ENT_QUOTES) ?></div>
        <div class="k">Website</div><div class="v"><?= htmlspecialchars($app['clinic_website'] ?? '-', ENT_QUOTES) ?></div>
      </div>
    </div>

    <div class="card-panel">
      <h2>Documents</h2>
      <?php if (!$documents): ?>
        <p style="font-size:0.8125rem; color:var(--paper-3);">No documents uploaded.</p>
      <?php endif; ?>
      <?php foreach ($documents as $d): ?>
        <div class="note-item">
          <a href="/download.php?kind=vet_application&doc_id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener noreferrer" style="color:var(--paper); border-bottom:1px solid var(--paper-3);"><?= htmlspecialchars($d['original_filename'], ENT_QUOTES) ?></a>
          <span style="color:var(--paper-4); font-size:0.75rem;"> · <?= htmlspecialchars(fmt_time($d['created_at']), ENT_QUOTES) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!$isErased): ?>
    <div class="card-panel">
      <h2>Before you approve</h2>
      <p class="field-hint" style="margin-bottom:0.75rem;">Approval unlocks the portal for whoever owns the email address on this application, so verify first:</p>
      <ol style="margin-left:1.25rem; font-size:0.8125rem; color:var(--paper-2); line-height:1.8;">
        <li>Open the registration certificate above: name, number and issuing council match the form.</li>
        <li>Look the registration up on the State Veterinary Council register (or confirm with the council office).</li>
        <li>Phone the clinic on a number you found independently, and confirm they applied and that the email address is theirs.</li>
        <li>Check the applications list for another application with the same registration number or email.</li>
      </ol>
      <p class="field-hint" style="margin-top:0.75rem;">Write what you checked in the note below. Full checklist: <code>docs/vet-approval-checklist.md</code> in the repository.</p>
    </div>

    <div class="card-panel">
      <h2>Review actions</h2>
      <form method="POST">
        <?= csrf_field() ?>
        <?php // The status this page was loaded with: the server refuses the action if the status has changed since. ?>
        <input type="hidden" name="expected_status" value="<?= htmlspecialchars($app['status'], ENT_QUOTES) ?>">
        <label class="<?= field_class($errors, 'note') ?>">
          <span class="lbl">Note (recorded with every action below - internal only, never shown to the applicant). Required for Approve, Reject and Suspend.</span>
          <textarea name="note" rows="2" placeholder="e.g. Certificate MH-VET-1234 checked against the MSVC register 02 Oct; called the clinic on 020-xxxxxxx, confirmed."<?= field_error_attrs($errors, 'note') ?><?= $errors ? ' autofocus' : '' ?>><?= htmlspecialchars($keptNote, ENT_QUOTES) ?></textarea>
          <?= field_error_html($errors, 'note') ?>
        </label>
        <p class="field-hint" style="margin:1rem 0;">Nothing is emailed automatically here - use "Send an email" below afterward if the applicant needs to be told.</p>
        <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:1rem;">
          <?php // Only the actions that make sense from this status (VET_APPLICATION_ACTIONS); the server enforces the same rules. ?>
          <?php if (in_array($app['status'], VET_APPLICATION_ACTIONS['approve']['from'], true)): ?><button type="submit" name="action" value="approve" class="btn-primary">Approve</button><?php endif; ?>
          <?php if (in_array($app['status'], VET_APPLICATION_ACTIONS['reject']['from'], true)): ?><button type="submit" name="action" value="reject" class="btn-secondary">Reject</button><?php endif; ?>
          <?php if (in_array($app['status'], VET_APPLICATION_ACTIONS['request_info']['from'], true)): ?><button type="submit" name="action" value="request_info" class="btn-secondary">Request More Information</button><?php endif; ?>
          <?php if (in_array($app['status'], VET_APPLICATION_ACTIONS['suspend']['from'], true)): ?><button type="submit" name="action" value="suspend" class="btn-secondary">Suspend</button><?php endif; ?>
          <button type="submit" name="action" value="add_note" class="btn-secondary">Add Note Only</button>
        </div>
      </form>
    </div>

    <div class="card-panel">
      <h2>Send an email</h2>
      <p class="field-hint" style="margin-bottom:1rem;">Pick a template, review and edit it, then send it yourself - or mark that no email is needed. The "Approved" template needs a fresh activation link generated first.</p>
      <?php if ($activationUrl && $draft !== null && strpos($draft['body'], $activationUrl) === false): ?>
        <div class="alert alert-ok">New activation link (shown once, valid 7 days; any earlier link no longer works): <code><?= htmlspecialchars($activationUrl, ENT_QUOTES) ?></code><br>It is not in your message. Paste it where you want it.</div>
      <?php endif; ?>
      <form method="POST">
        <?= csrf_field() ?>
        <?= one_time_field('send_email:vet:' . $id) ?>
        <div class="field-row two">
          <label class="field">
            <span class="lbl">Template</span>
            <select id="email-template" data-templates="<?= htmlspecialchars(json_encode($emailTemplates), ENT_QUOTES) ?>">
              <?php foreach ($emailTemplates as $key => $t): ?>
                <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>" <?= $key === $defaultTemplateKey ? 'selected' : '' ?>><?= htmlspecialchars($t['label'], ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field">
            <span class="lbl">Sender</span>
            <select name="sender">
              <?php foreach (verified_senders() as $email => $name): ?>
                <option value="<?= htmlspecialchars($email, ENT_QUOTES) ?>" <?= ($draft['sender'] ?? '') === $email ? 'selected' : '' ?>><?= htmlspecialchars($name, ENT_QUOTES) ?> &lt;<?= htmlspecialchars($email, ENT_QUOTES) ?>&gt;</option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="field-row two">
          <label class="field">
            <span class="lbl">Recipient</span>
            <select name="recipient">
              <option value="<?= htmlspecialchars($app['professional_email'], ENT_QUOTES) ?>">Veterinarian - <?= htmlspecialchars($app['professional_email'], ENT_QUOTES) ?></option>
              <?php if (!empty($app['clinic_email'])): ?><option value="<?= htmlspecialchars($app['clinic_email'], ENT_QUOTES) ?>" <?= ($draft['recipient'] ?? '') === $app['clinic_email'] ? 'selected' : '' ?>>Clinic - <?= htmlspecialchars($app['clinic_email'], ENT_QUOTES) ?></option><?php endif; ?>
            </select>
          </label>
          <label class="field">
            <span class="lbl">Subject</span>
            <input type="text" id="email-subject" name="subject" value="<?= htmlspecialchars($draft['subject'] ?? $emailTemplates[$defaultTemplateKey]['subject'], ENT_QUOTES) ?>">
          </label>
        </div>
        <label class="field">
          <span class="lbl">Message</span>
          <textarea id="email-body" name="body" rows="8" placeholder="Select a template above, or write a custom message…"><?= htmlspecialchars($draft['body'] ?? $emailTemplates[$defaultTemplateKey]['body'], ENT_QUOTES) ?></textarea>
        </label>
        <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:1rem;">
          <button type="submit" name="action" value="generate_activation_link" class="btn-secondary">Generate Activation Link</button>
          <button type="submit" name="action" value="send_email" class="btn-primary">Send Email</button>
          <button type="submit" name="action" value="no_email_needed" class="btn-secondary">No Email Needed</button>
        </div>
      </form>
      <script src="/js/admin-email-compose.js" defer></script>
    </div>

    <div class="card-panel">
      <h2>Internal notes &amp; review history (staff only)</h2>
      <pre style="white-space:pre-wrap; font-family:var(--mono); font-size:0.75rem; color:var(--paper-2); line-height:1.7;"><?= htmlspecialchars($app['internal_notes'] ?? 'No notes yet.', ENT_QUOTES) ?></pre>
    </div>
    <?php endif; ?>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
