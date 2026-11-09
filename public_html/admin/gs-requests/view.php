<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/mailer.php';
$staff = require_login();

$id = (int) ($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM gs_requests WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$case = $stmt->fetch();
if (!$case) {
    http_response_code(404);
    exit('Case not found.');
}

require_once __DIR__ . '/../../includes/case-statuses.php';
$statuses = GS_CASE_STATUSES; // the single shared list - see includes/case-statuses.php
$flash = null;
// What staff typed into the email composer, kept when a send fails validation (or fails to
// go out) so the form re-renders with their draft instead of wiping it back to the blank template.
$draft = null;
// Column limit for free-text notes (TEXT = 65,535 bytes; 10,000 chars stays under that even at
// 4 bytes/char). Over it MySQL throws and the visitor sees a bare 500.
const NOTE_MAX_CHARS = 10000;

// Post/Redirect/Get: a SUCCESSFUL action (status, case details, assignment, note, email sent, "no email needed")
// ends with a redirect to a plain GET of this page, and its message travels in the session. Without it the
// browser stayed on the POST, so F5 or a double-click re-sent it: "Add note" had no lock version and no
// one-time token, so it saved the note twice, and a refresh after a status change re-sent a stale lock
// version and showed a confusing "changed by someone else". The message is removed from the session the
// moment it is read, so it shows once and a reload does not show it again.
if (isset($_SESSION['case_flash'][$id])) {
    $flash = $_SESSION['case_flash'][$id];
    unset($_SESSION['case_flash'][$id]);
}

// What was typed into each form, kept when its submit FAILS (validation, a lock conflict, an expired session) so
// the form re-renders with it instead of with the freshly re-fetched row - without this, anything typed and not
// saved was wiped on every error (a long note over the limit came back as "too long" plus an empty box). Keyed by
// action; only set for the form that failed, so a successful save still shows the saved values.
$keepFields = [
    'update_final' => ['final_formulation', 'final_concentration', 'final_quantity', 'final_price', 'courier', 'tracking_number', 'dispatch_date', 'closure_reason'],
    'change_status' => ['new_status', 'status_note'],
    'assign_staff' => ['assigned_staff_id'],
    'add_note' => ['content'],
];
$typed = [];
// A form's value: what was typed if that form just failed, else what is saved.
// (By reference: $typed is filled in after this closure is created, and an arrow function would copy it empty.)
$tv = function (string $action, string $field, $saved) use (&$typed) {
    return isset($typed[$action]) ? $typed[$action][$field] : ($saved ?? '');
};

// Returned when the row changed since the form was loaded (another staff member saved, or a status change
// landed). 'conflict' marks it so the details of WHAT changed are added once the row has been re-read below.
$conflictFlash = ['type' => 'err', 'conflict' => true, 'text' => 'This case was changed by someone else since you loaded this page. What you typed is kept below: check it against what is saved now, then save again to overwrite it, or reload the page to discard it.'];

// Erasure (data-protection.php) blanks the personal data and the case is then kept only as a record. So it is
// READ-ONLY: every form is hidden below and every POST is refused here on the server, otherwise staff could
// type personal details straight back into an erased case (an internal note, the status note, the closure
// reason or tracking number that erasure had just blanked), or email an address that no longer exists.
$isErased = !empty($case['erased_at']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'err', 'text' => 'Your session expired. Please try again.'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($isErased) {
            $flash = ['type' => 'err', 'text' => 'This case has been erased, so it is read-only: nothing can be added, changed or sent. Its status history is kept.'];
        } elseif ($action === 'change_status') {
            $newStatus = $_POST['new_status'] ?? '';
            $note      = trim($_POST['status_note'] ?? '');
            $expectedLockVersion = (int) ($_POST['expected_lock_version'] ?? -1);
            if (!in_array($newStatus, $statuses, true)) {
                $flash = ['type' => 'err', 'text' => 'Invalid status.'];
            } elseif (mb_strlen($note) > NOTE_MAX_CHARS) {
                $flash = ['type' => 'err', 'text' => 'The status note is too long (max ' . NOTE_MAX_CHARS . ' characters).'];
            } else {
                $flash = run_serialized_transaction($pdo, function (PDO $pdo) use ($id, $newStatus, $note, $staff, $expectedLockVersion, &$case, $conflictFlash) {
                    // Re-reads status (for the history row's previous_status) and
                    // lock_version from inside the transaction, not the $case snapshot
                    // taken at the top of the script — two concurrent status changes on the
                    // same case would otherwise both compute "from" against the same stale
                    // value, recording a wrong previous_status in history for whichever
                    // commits second (and, without the lock_version check below, that
                    // second one would also just silently overwrite the first's change — a
                    // double-click has exactly this shape too).
                    $current = $pdo->prepare(
                        'SELECT status, lock_version FROM gs_requests WHERE id = ?' . locking_read_suffix()
                    );
                    $current->execute([$id]);
                    $current = $current->fetch();
                    if (!$current) {
                        return ['type' => 'err', 'text' => 'Case not found.'];
                    }
                    if ((int) $current['lock_version'] !== $expectedLockVersion) {
                        return $conflictFlash;
                    }

                    $pdo->prepare('UPDATE gs_requests SET status = ?, lock_version = lock_version + 1 WHERE id = ?')->execute([$newStatus, $id]);
                    $pdo->prepare('INSERT INTO case_status_history (gs_request_id, previous_status, new_status, changed_by, note) VALUES (?,?,?,?,?)')
                        ->execute([$id, $current['status'], $newStatus, $staff['id'], $note ?: null]);
                    // Audited *inside* the transaction, before it commits — auditing
                    // afterward meant that if audit()'s own INSERT ever failed, the catch
                    // block's rollBack() would itself throw ("no active transaction",
                    // since the status change had already committed by then), surfacing as
                    // an opaque 500 for a change that had actually succeeded.
                    audit('case_status_changed', 'gs_request', $id, ['from' => $current['status'], 'to' => $newStatus]);
                    $case['status'] = $newStatus;
                    return ['type' => 'ok', 'text' => 'Status updated. Nothing is emailed automatically - use "Send an email" below if the owner needs to be told.'];
                });
            }
        } elseif ($action === 'add_note') {
            $content = trim($_POST['content'] ?? '');
            if ($content === '') {
                $flash = ['type' => 'err', 'text' => 'Note cannot be empty.'];
            } elseif (mb_strlen($content) > NOTE_MAX_CHARS) {
                $flash = ['type' => 'err', 'text' => 'The note is too long (max ' . NOTE_MAX_CHARS . ' characters).'];
            } else {
                $pdo->prepare('INSERT INTO case_internal_notes (gs_request_id, staff_id, content) VALUES (?,?,?)')
                    ->execute([$id, $staff['id'], $content]);
                audit('note_added', 'gs_request', $id, []);
                $flash = ['type' => 'ok', 'text' => 'Note added.'];
            }
        } elseif ($action === 'update_final') {
            $final = [
                'final_formulation'   => trim($_POST['final_formulation'] ?? '') ?: null,
                'final_concentration' => trim($_POST['final_concentration'] ?? '') ?: null,
                'final_quantity'      => trim($_POST['final_quantity'] ?? '') ?: null,
                'final_price'         => trim($_POST['final_price'] ?? '') !== '' ? (float) $_POST['final_price'] : null,
                'courier'             => trim($_POST['courier'] ?? '') ?: null,
                'tracking_number'     => trim($_POST['tracking_number'] ?? '') ?: null,
                'dispatch_date'       => trim($_POST['dispatch_date'] ?? '') ?: null,
                'closure_reason'      => trim($_POST['closure_reason'] ?? '') ?: null,
            ];
            // Validated before touching the DB — previously a non-numeric price silently became
            // 0, a negative or absurdly large one reached a DECIMAL(10,2) column (a 500, or
            // INF for 1e999), and an invalid dispatch_date or over-long text field threw from
            // the UPDATE itself, losing everything else typed into this form. A validation error
            // is just a message now; the typed values are kept and re-shown (see $typed below).
            $finalError = null;
            $rawPrice = trim($_POST['final_price'] ?? '');
            if ($rawPrice !== '') {
                if (!is_numeric($rawPrice) || (float) $rawPrice < 0 || (float) $rawPrice > 99999999.99) {
                    $finalError = 'Price must be a number between 0 and 99,999,999.99.';
                }
            }
            if ($finalError === null && $final['dispatch_date'] !== null) {
                $dd = DateTime::createFromFormat('Y-m-d', $final['dispatch_date']);
                if (!$dd || $dd->format('Y-m-d') !== $final['dispatch_date']) {
                    $finalError = 'Dispatch date must be a valid date.';
                }
            }
            if ($finalError === null && field_length_errors($final, [
                'final_formulation' => 100, 'final_concentration' => 100, 'final_quantity' => 100,
                'courier' => 100, 'tracking_number' => 100, 'closure_reason' => 20000,
            ])) {
                $finalError = 'One of the fields is too long.';
            }
            // closure_reason is a TEXT column: 65,535 bytes, which fewer than 20,000 characters can exceed.
            if ($finalError === null && text_byte_errors($final, ['closure_reason'])) {
                $finalError = 'The closure reason is too long.';
            }
            if ($finalError !== null) {
                $flash = ['type' => 'err', 'text' => $finalError];
            } else {
            // Conditioned on lock_version still matching what this form was loaded with —
            // a blind UPDATE here would let two staff editing the same case's formulation
            // at once silently overwrite each other with no warning (last write wins,
            // first staff member's edits just vanish). updated_at was tried first but
            // isn't safe for this: it only has second-level precision, so two edits
            // within the same second would look identical and the race wouldn't actually
            // be caught — lock_version is bumped by exactly 1 on every such write instead,
            // so it can't collide regardless of timing. rowCount() of 0 means the row
            // changed underneath this submission (any write to it bumps lock_version,
            // including a status change, not just another update_final), so that staff
            // member needs to reload and see what changed before retrying.
            $stmt = $pdo->prepare(
                'UPDATE gs_requests SET final_formulation=?, final_concentration=?, final_quantity=?, final_price=?,
                 courier=?, tracking_number=?, dispatch_date=?, closure_reason=?, lock_version=lock_version+1
                 WHERE id=? AND lock_version=?'
            );
            $stmt->execute([...array_values($final), $id, (int) ($_POST['expected_lock_version'] ?? -1)]);
            if ($stmt->rowCount() === 0) {
                $flash = $conflictFlash;
            } else {
                audit('formulation_recorded', 'gs_request', $id, ['fields' => array_keys(array_filter($final, fn($v) => $v !== null))]);
                $flash = ['type' => 'ok', 'text' => 'Case details updated.'];
            }
            }
        } elseif ($action === 'assign_staff') {
            $assignedId   = (int) ($_POST['assigned_staff_id'] ?? 0) ?: null;
            $currentId    = (int) ($case['assigned_staff_id'] ?? 0) ?: null;
            $expectedLock = (int) ($_POST['expected_lock_version'] ?? -1);
            // Who the form names, and whether they can be assigned: an id that doesn't exist used to hit the
            // foreign key and surface as a 500, and a deactivated account (which can no longer sign in to do
            // anything with the case) must not be picked - only an existing, active staff member is a valid target.
            $target = null;
            if ($assignedId !== null) {
                $lookup = $pdo->prepare('SELECT name, active FROM staff_users WHERE id = ?');
                $lookup->execute([$assignedId]);
                $target = $lookup->fetch() ?: null;
            }
            $targetUsable = $assignedId === null || ($target !== null && (int) $target['active'] === 1);

            if ($assignedId === $currentId && $expectedLock === (int) $case['lock_version']) {
                // Saved without changing anything (and nobody else touched the case since the page loaded), so
                // there is nothing to write: no version bump, no audit entry. It must not be refused just
                // because the current assignee has since been deactivated - but then say so, because that
                // person can't work the case any more and someone else should take it.
                $flash = $targetUsable
                    ? ['type' => 'ok', 'text' => 'Assignment unchanged.']
                    : ['type' => 'err', 'text' => 'Assignment unchanged. ' . ($target['name'] ?? 'The current assignee') . ' is deactivated, so choose someone else to take this case.'];
            } elseif (!$targetUsable) {
                $flash = ['type' => 'err', 'text' => 'Choose an active staff member.'];
            } else {
                // Same optimistic-lock pattern as update_final above.
                $stmt = $pdo->prepare('UPDATE gs_requests SET assigned_staff_id = ?, lock_version = lock_version + 1 WHERE id = ? AND lock_version = ?');
                $stmt->execute([$assignedId, $id, $expectedLock]);
                if ($stmt->rowCount() === 0) {
                    $flash = $conflictFlash;
                } else {
                    audit('case_assigned', 'gs_request', $id, ['assigned_staff_id' => $assignedId]);
                    $flash = ['type' => 'ok', 'text' => 'Assignment updated.'];
                }
            }
        } elseif ($action === 'send_email') {
            $senderEmail = trim($_POST['sender'] ?? '');
            $recipient   = trim($_POST['recipient'] ?? '');
            $subject     = trim($_POST['subject'] ?? '');
            $body        = trim($_POST['body'] ?? '');
            // Recipient is restricted to email addresses this case's own requester
            // typed in at submission (owner/vet) — never an arbitrary address — and
            // sender to Brevo-verified addresses only. mailer.php enforces the sender
            // restriction again server-side regardless of what this form allows.
            $allowedRecipients = array_filter([$case['owner_email'], $case['vet_email']]);
            $draft = ['sender' => $senderEmail, 'recipient' => $recipient, 'subject' => $subject, 'body' => $body];
            if (!consume_one_time_token('send_email:' . $id)) {
                // Not kept as a draft: this is most likely a resubmission of a send that already went out.
                $draft = null;
                // A double-click or an F5 resubmitting the same POST has exactly this
                // shape — the token from that original form render was already consumed
                // by whichever submission got here first, so a second one can't also send.
                $flash = ['type' => 'err', 'text' => 'This form was already submitted (or the page is stale) - reload and try again if you still need to send it.'];
            } elseif (!array_key_exists($senderEmail, verified_senders())) {
                $flash = ['type' => 'err', 'text' => 'Select a valid sender address.'];
            } elseif (!in_array($recipient, $allowedRecipients, true)) {
                $flash = ['type' => 'err', 'text' => 'Recipient must be an email address on file for this case.'];
            } elseif ($subject === '' || $body === '') {
                $flash = ['type' => 'err', 'text' => 'Subject and message body are required.'];
            } elseif (($ph = unresolved_placeholder($subject . "
" . $body)) !== null) {
                $flash = ['type' => 'err', 'text' => "This message still contains an unfilled placeholder: {$ph} - replace it before sending."];
            } elseif (mb_strlen($subject) > 255) {
                $flash = ['type' => 'err', 'text' => 'Subject must be 255 characters or fewer.'];
            } elseif (mb_strlen($body) > 60000 || strlen($body) > TEXT_MAX_BYTES) { // case_emails.body is TEXT (bytes)
                $flash = ['type' => 'err', 'text' => 'Message body is too long.'];
            } else {
                $recipientName = $recipient === $case['owner_email'] ? $case['owner_full_name'] : ($recipient === $case['vet_email'] ? ($case['vet_name'] ?? '') : '');
                $result = send_case_email($pdo, $id, $staff['id'], $recipient, $recipientName, $subject, $body, $senderEmail);
                if ($result === 'sent') $draft = null; // a failed or unconfirmed send keeps the draft so it can be retried, once it is known to be safe
                audit(['sent' => 'email_sent', 'unknown' => 'email_unconfirmed'][$result] ?? 'email_failed', 'gs_request', $id, ['recipient' => $recipient, 'sender' => $senderEmail]);
                // 'unknown' = no answer came back, so the email MAY have gone out: say so, never "failed" (staff would click Send again)
                $flash = $result === 'sent'
                    ? ['type' => 'ok', 'text' => 'Email sent.']
                    : ($result === 'unknown'
                        ? ['type' => 'err', 'text' => mail_unconfirmed_text($recipient) . ' The attempt has been logged below.']
                        : ['type' => 'err', 'text' => 'Email failed to send. The attempt has been logged below.']);
            }
        } elseif ($action === 'no_email_needed') {
            // Same one-time token as send_email (both buttons live in one form), so a double-click
            // can't write two audit entries.
            if (!consume_one_time_token('send_email:' . $id)) {
                $flash = ['type' => 'err', 'text' => 'This form was already submitted (or the page is stale) - reload if you still need to.'];
            } else {
                audit('email_not_needed', 'gs_request', $id, ['status' => $case['status']]);
                $flash = ['type' => 'ok', 'text' => 'Noted - no email needed for this update.'];
            }
        }
    }

    // Only a success is redirected. An error or refusal (empty or over-long note, a lock conflict, a stale
    // page, a failed send, an expired session) is shown on this response, because the page must keep what
    // was typed and the email draft; repeating a refused request just refuses it again.
    if ($flash && $flash['type'] === 'ok' && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['case_flash'][$id] = $flash;
        header('Location: /admin/gs-requests/view.php?id=' . $id);
        exit;
    }

    // A form that failed (validation, a lock conflict, an expired session) keeps what was typed: the forms
    // below fill from $typed instead of the saved row. Without this the re-fetch below wiped it all.
    $keptAction = $_POST['action'] ?? '';
    if ($flash && $flash['type'] === 'err' && !$isErased && isset($keepFields[$keptAction])) {
        foreach ($keepFields[$keptAction] as $field) {
            $typed[$keptAction][$field] = (string) ($_POST[$field] ?? '');
        }
    }

    // Re-fetch rather than patching individual fields in-memory above — any of the
    // actions above (including change_status, which also touches this row) bumps
    // lock_version, and the forms below embed it as the optimistic-lock token for
    // the next submission. A stale in-memory value here would make the very next
    // legitimate edit fail with a false "someone else changed this".
    $stmt = $pdo->prepare('SELECT * FROM gs_requests WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $case = $stmt->fetch() ?: $case;

    // A lock conflict keeps the typed values AND hands the form the NEW lock_version (above), so saving again
    // would overwrite what the other person saved. Say exactly what that would do, so overwriting is a choice
    // made with the facts. For the case-details form, every field where what was typed differs from what is saved
    // now, in two short lists: fields that hold saved data (saving REPLACES it - this includes fields the other
    // person changed that this person never touched) and fields that are empty now (saving only ADDS to them).
    // Printed as lists under the sentence ($flash['lists']), not run into it: with eight fields it was a wall.
    if ($flash && !empty($flash['conflict']) && isset($typed[$keptAction])) {
        $shown = fn (string $v): string => '“' . mb_strimwidth($v, 0, 60, '…') . '”';
        if ($keptAction === 'update_final') {
            $labels = [
                'final_formulation' => 'Final formulation', 'final_concentration' => 'Concentration', 'final_quantity' => 'Quantity',
                'final_price' => 'Price', 'courier' => 'Courier', 'tracking_number' => 'Tracking number',
                'dispatch_date' => 'Dispatch date', 'closure_reason' => 'Closure reason',
            ];
            $replace = [];
            $add = [];
            foreach ($labels as $field => $label) {
                $mine  = trim($typed['update_final'][$field]);
                $saved = trim((string) ($case[$field] ?? ''));
                // 1200 and 1200.00 are the same price.
                $same = ($field === 'final_price' && is_numeric($mine) && is_numeric($saved)) ? abs((float) $mine - (float) $saved) < 0.005 : $mine === $saved;
                if ($same) continue;
                if ($saved === '') {
                    $add[] = "{$label}: {$shown($mine)}";
                } else {
                    $replace[] = $mine === '' ? "{$label}: {$shown($saved)} would be cleared" : "{$label}: {$shown($saved)} would become {$shown($mine)}";
                }
            }
            if ($replace) $flash['lists'][] = ['heading' => 'Saving again would replace what is saved now:', 'items' => $replace];
            if ($add)     $flash['lists'][] = ['heading' => $replace ? 'and would fill in these empty fields:' : 'Saving again would fill in these empty fields:', 'items' => $add];
            if (!$replace && !$add) {
                $flash['text'] .= ' Nothing you typed differs from what is saved now, so saving again will not overwrite anything.';
            }
        } elseif ($keptAction === 'change_status') {
            $flash['text'] .= ' The status is now "' . status_label($case['status']) . '".';
        } elseif ($keptAction === 'assign_staff') {
            $who = $pdo->prepare('SELECT name FROM staff_users WHERE id = ?');
            $who->execute([(int) ($case['assigned_staff_id'] ?? 0)]);
            $flash['text'] .= ' The case is now ' . (($name = $who->fetchColumn()) !== false && $case['assigned_staff_id'] ? 'assigned to ' . $name : 'unassigned') . '.';
        }
    }
}

$documents = $pdo->prepare('SELECT * FROM gs_request_documents WHERE gs_request_id = ? ORDER BY created_at ASC');
$documents->execute([$id]);
$documents = $documents->fetchAll();

$notes = $pdo->prepare('SELECT n.*, s.name AS staff_name FROM case_internal_notes n JOIN staff_users s ON s.id = n.staff_id WHERE n.gs_request_id = ? ORDER BY n.created_at DESC');
$notes->execute([$id]);
$notes = $notes->fetchAll();

$history = $pdo->prepare('SELECT h.*, s.name AS staff_name FROM case_status_history h LEFT JOIN staff_users s ON s.id = h.changed_by WHERE h.gs_request_id = ? ORDER BY h.created_at DESC');
$history->execute([$id]);
$history = $history->fetchAll();

$emails = $pdo->prepare('SELECT e.*, s.name AS staff_name FROM case_emails e LEFT JOIN staff_users s ON s.id = e.staff_id WHERE e.gs_request_id = ? ORDER BY e.sent_at DESC');
$emails->execute([$id]);
$emails = $emails->fetchAll();

$allStaff = $pdo->prepare('SELECT id, name, active FROM staff_users WHERE active = 1 OR id = ? ORDER BY name ASC');
$allStaff->execute([(int) ($case['assigned_staff_id'] ?? 0)]);
$allStaff = $allStaff->fetchAll();

// Predefined email templates, one per owner-facing status plus a blank/custom option.
// Rebuilt fresh on every render from the case's current final-formulation/fulfilment
// fields, so a template already reflects whatever's been recorded above — staff still
// review and edit before sending (formulation, price, payment link, etc. are never
// sent without a human reading them first).
$ref = "Reference: GS-{$id}";
$formulationLine = $case['final_formulation']
    ? 'Formulation: ' . $case['final_formulation']
        . ($case['final_concentration'] ? ' (' . $case['final_concentration'] . ')' : '')
        . ($case['final_quantity'] ? ', Qty: ' . $case['final_quantity'] : '')
    : 'Formulation: [confirm final formulation, strength & quantity]';
$priceLine = $case['final_price'] !== null && $case['final_price'] !== ''
    ? 'Price: Rs. ' . number_format((float) $case['final_price'], 2)
    : 'Price: [confirm final price]';
$paymentLine   = 'Payment link: [insert payment link]';
$trackingLine  = 'Courier: ' . ($case['courier'] ?: '[courier name]') . "\nTracking: " . ($case['tracking_number'] ?: '[tracking number]');

$emailTemplates = [
    'blank' => [
        'label'   => '- Blank / custom -',
        'subject' => "Update on your GS-441524 request (GS-{$id})",
        'body'    => '',
    ],
    'awaiting_information' => [
        'label'   => 'Awaiting Information',
        'subject' => "Additional information needed - GS-{$id}",
        'body'    => "We need some additional information before we can proceed with your request. Our team will be in touch with specifics.\n\n{$ref}",
    ],
    'approved' => [
        'label'   => 'Approved / Confirmed',
        'subject' => "Your GS-441524 request has been approved (GS-{$id})",
        'body'    => "Your GS-441524 request has been approved and is moving forward.\n\n{$formulationLine}\n{$priceLine}\n{$paymentLine}\n\n{$ref}",
    ],
    'ready_for_dispatch' => [
        'label'   => 'Ready for Dispatch',
        'subject' => "Your order is being prepared for dispatch (GS-{$id})",
        'body'    => "Your order has been compounded and is being prepared for dispatch.\n\n{$formulationLine}\n{$priceLine}\n{$paymentLine}\n\n{$ref}",
    ],
    'dispatched' => [
        'label'   => 'Dispatched',
        'subject' => "Your order has been dispatched (GS-{$id})",
        'body'    => "Your order has been dispatched.\n\n{$trackingLine}\n\n{$ref}",
    ],
    'finished' => [
        'label'   => 'Finished',
        'subject' => "Your order is complete (GS-{$id})",
        'body'    => "Your order has been completed.\n\n{$ref}",
    ],
    'rejected' => [
        'label'   => 'Rejected',
        'subject' => "Update on your GS-441524 request (GS-{$id})",
        'body'    => "Your GS-441524 request could not be approved.\n\n{$ref}",
    ],
    'cancelled' => [
        'label'   => 'Cancelled',
        'subject' => "Your GS-441524 request has been cancelled (GS-{$id})",
        'body'    => "Your GS-441524 request has been cancelled.\n\n{$ref}",
    ],
];

$pageTitle     = "GS-{$id} - Kuronyx Admin";
$robotsNoindex = true;
$backHref      = '/admin/gs-requests/';
$backLabel     = 'All requests';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · GS-441524 case</p>
    <h1 class="doc-title">GS-<?= $id ?></h1>
    <?php if ($isErased): ?>
      <div class="alert">Personal data for this case was erased on <?= htmlspecialchars(fmt_time($case['erased_at']), ENT_QUOTES) ?>. Names, contact details, documents, emails and notes are gone; only the case record and its status history remain. The case is read-only.</div>
    <?php endif; ?>
    <p class="doc-meta">
      <?= $case['source'] === 'cat_owner' ? 'Cat owner submission' : 'Veterinarian submission' ?><span class="sep">·</span>
      <span class="status-pill status-<?= htmlspecialchars($case['status'], ENT_QUOTES) ?>"><?= htmlspecialchars(status_label($case['status']), ENT_QUOTES) ?></span><span class="sep">·</span>
      Created <?= htmlspecialchars(fmt_time($case['created_at']), ENT_QUOTES) ?>
    </p>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : '' ?>">
        <?= htmlspecialchars($flash['text'], ENT_QUOTES) ?>
        <?php foreach ($flash['lists'] ?? [] as $list): ?>
          <p class="alert-list-heading"><?= htmlspecialchars($list['heading'], ENT_QUOTES) ?></p>
          <ul class="alert-list">
            <?php foreach ($list['items'] as $item): ?><li><?= htmlspecialchars($item, ENT_QUOTES) ?></li><?php endforeach; ?>
          </ul>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="card-panel">
      <h2>Owner</h2>
      <div class="kv-grid">
        <div class="k">Name</div><div class="v"><?= htmlspecialchars($case['owner_full_name'], ENT_QUOTES) ?></div>
        <div class="k">Email</div><div class="v"><?= htmlspecialchars($case['owner_email'], ENT_QUOTES) ?></div>
        <div class="k">Phone</div><div class="v"><?= htmlspecialchars($case['owner_phone'], ENT_QUOTES) ?></div>
        <div class="k">Address</div><div class="v"><?= htmlspecialchars($case['owner_address'], ENT_QUOTES) ?>, <?= htmlspecialchars($case['owner_city'], ENT_QUOTES) ?>, <?= htmlspecialchars($case['owner_state'], ENT_QUOTES) ?> <?= htmlspecialchars($case['owner_pin'], ENT_QUOTES) ?>, <?= htmlspecialchars($case['owner_country'], ENT_QUOTES) ?></div>
      </div>
    </div>

    <div class="card-panel">
      <h2>Treating veterinarian</h2>
      <div class="kv-grid">
        <div class="k">Name</div><div class="v"><?= htmlspecialchars($case['vet_name'] ?? '-', ENT_QUOTES) ?></div>
        <div class="k">Clinic</div><div class="v"><?= htmlspecialchars($case['vet_clinic'] ?? '-', ENT_QUOTES) ?></div>
        <div class="k">Email</div><div class="v"><?= htmlspecialchars($case['vet_email'] ?? '-', ENT_QUOTES) ?></div>
        <div class="k">Phone</div><div class="v"><?= htmlspecialchars($case['vet_phone'] ?? '-', ENT_QUOTES) ?></div>
        <div class="k">Registration</div><div class="v"><?= htmlspecialchars($case['vet_registration_info'] ?? '-', ENT_QUOTES) ?></div>
      </div>
    </div>

    <div class="card-panel">
      <h2>Patient</h2>
      <div class="kv-grid">
        <div class="k">Name</div><div class="v"><?= htmlspecialchars($case['patient_name'], ENT_QUOTES) ?></div>
        <div class="k">Species / breed</div><div class="v"><?= htmlspecialchars($case['patient_species'], ENT_QUOTES) ?> · <?= htmlspecialchars($case['patient_breed'] ?? '-', ENT_QUOTES) ?></div>
        <div class="k">Sex</div><div class="v"><?= htmlspecialchars(ucfirst($case['patient_sex']), ENT_QUOTES) ?></div>
        <div class="k">DOB</div><div class="v"><?= htmlspecialchars($case['patient_dob'] ?? 'Unknown', ENT_QUOTES) ?></div>
        <div class="k">Weight</div><div class="v"><?= $case['patient_weight_kg'] !== null ? htmlspecialchars($case['patient_weight_kg'], ENT_QUOTES) . ' kg' : '-' ?></div>
        <div class="k">Neutered / spayed</div><div class="v"><?= $case['patient_neutered'] === null ? '-' : ($case['patient_neutered'] ? 'Yes' : 'No') ?></div>
        <div class="k">Microchip</div><div class="v"><?= htmlspecialchars($case['patient_microchip'] ?? '-', ENT_QUOTES) ?></div>
        <div class="k">Requested formulation</div><div class="v"><?= ucfirst($case['requested_formulation']) ?></div>
      </div>
      <?php if ($case['clinical_notes']): ?>
        <p style="margin-top:1rem; font-size:0.8125rem; color:var(--paper-2); white-space:pre-wrap;"><?= htmlspecialchars($case['clinical_notes'], ENT_QUOTES) ?></p>
      <?php endif; ?>
    </div>

    <div class="card-panel">
      <h2>Documents</h2>
      <?php if (!$documents): ?>
        <p style="font-size:0.8125rem; color:var(--paper-3);">No documents on file.</p>
      <?php endif; ?>
      <?php foreach ($documents as $d): ?>
        <div class="note-item">
          <span class="status-pill" style="margin-right:0.6rem;"><?= $d['doc_type'] === 'prescription' ? 'Prescription' : 'Supporting' ?></span>
          <a href="/download.php?kind=gs_request&doc_id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener noreferrer" style="color:var(--paper); border-bottom:1px solid var(--paper-3);"><?= htmlspecialchars($d['original_filename'], ENT_QUOTES) ?></a>
          <span style="color:var(--paper-4); font-size:0.75rem;"> · <?= htmlspecialchars(fmt_time($d['created_at']), ENT_QUOTES) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card-panel">
      <h2>Final formulation &amp; fulfilment (staff-only)</h2>
      <?php if ($isErased): ?>
        <div class="kv-grid">
          <div class="k">Final formulation</div><div class="v"><?= htmlspecialchars($case['final_formulation'] ?? '-', ENT_QUOTES) ?></div>
          <div class="k">Concentration / strength</div><div class="v"><?= htmlspecialchars($case['final_concentration'] ?? '-', ENT_QUOTES) ?></div>
          <div class="k">Quantity</div><div class="v"><?= htmlspecialchars($case['final_quantity'] ?? '-', ENT_QUOTES) ?></div>
          <div class="k">Price (INR)</div><div class="v"><?= $case['final_price'] !== null && $case['final_price'] !== '' ? htmlspecialchars(number_format((float) $case['final_price'], 2), ENT_QUOTES) : '-' ?></div>
          <div class="k">Courier</div><div class="v"><?= htmlspecialchars($case['courier'] ?? '-', ENT_QUOTES) ?></div>
          <div class="k">Dispatch date</div><div class="v"><?= htmlspecialchars($case['dispatch_date'] ?? '-', ENT_QUOTES) ?></div>
        </div>
      <?php else: ?>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_final">
        <input type="hidden" name="expected_lock_version" value="<?= (int) $case['lock_version'] ?>">
        <div class="field-row three">
          <label class="field"><span class="lbl">Final formulation</span><input type="text" name="final_formulation" value="<?= htmlspecialchars((string) $tv('update_final', 'final_formulation', $case['final_formulation']), ENT_QUOTES) ?>"></label>
          <label class="field"><span class="lbl">Concentration / strength</span><input type="text" name="final_concentration" value="<?= htmlspecialchars((string) $tv('update_final', 'final_concentration', $case['final_concentration']), ENT_QUOTES) ?>"></label>
          <label class="field"><span class="lbl">Quantity</span><input type="text" name="final_quantity" value="<?= htmlspecialchars((string) $tv('update_final', 'final_quantity', $case['final_quantity']), ENT_QUOTES) ?>"></label>
        </div>
        <div class="field-row three">
          <label class="field"><span class="lbl">Price (INR)</span><input type="number" step="0.01" name="final_price" value="<?= htmlspecialchars((string) $tv('update_final', 'final_price', $case['final_price']), ENT_QUOTES) ?>"></label>
          <label class="field"><span class="lbl">Courier</span><input type="text" name="courier" value="<?= htmlspecialchars((string) $tv('update_final', 'courier', $case['courier']), ENT_QUOTES) ?>"></label>
          <label class="field"><span class="lbl">Tracking number</span><input type="text" name="tracking_number" value="<?= htmlspecialchars((string) $tv('update_final', 'tracking_number', $case['tracking_number']), ENT_QUOTES) ?>"></label>
        </div>
        <div class="field-row two">
          <label class="field"><span class="lbl">Dispatch date</span><input type="date" name="dispatch_date" value="<?= htmlspecialchars((string) $tv('update_final', 'dispatch_date', $case['dispatch_date']), ENT_QUOTES) ?>"></label>
          <label class="field"><span class="lbl">Closure reason (if closing)</span><input type="text" name="closure_reason" value="<?= htmlspecialchars((string) $tv('update_final', 'closure_reason', $case['closure_reason']), ENT_QUOTES) ?>"></label>
        </div>
        <button type="submit" class="btn-secondary">Save Case Details</button>
      </form>
      <?php endif; ?>
    </div>

    <div class="card-panel">
      <?php if ($isErased): ?>
        <h2>Status history</h2>
      <?php else: ?>
      <h2>Status</h2>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_status">
        <input type="hidden" name="expected_lock_version" value="<?= (int) $case['lock_version'] ?>">
        <div class="field-row two">
          <label class="field">
            <span class="lbl">New status</span>
            <select name="new_status">
              <?php $chosenStatus = $tv('change_status', 'new_status', $case['status']); if (!in_array($chosenStatus, $statuses, true)) $chosenStatus = $case['status']; ?>
              <?php foreach ($statuses as $s): ?>
                <option value="<?= $s ?>" <?= $chosenStatus === $s ? 'selected' : '' ?>><?= status_label($s) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field">
            <span class="lbl">Note (optional)</span>
            <input type="text" name="status_note" value="<?= htmlspecialchars((string) $tv('change_status', 'status_note', ''), ENT_QUOTES) ?>" placeholder="e.g. Formulation discussed and confirmed by treating veterinarian">
          </label>
        </div>
        <button type="submit" class="btn-primary">Update Status</button>
      </form>

      <h2 style="margin-top:2rem;">History</h2>
      <?php endif; ?>
      <?php foreach ($history as $h): ?>
        <div class="note-item">
          <div class="note-meta"><?= htmlspecialchars($h['staff_name'] ?? 'System', ENT_QUOTES) ?> · <?= htmlspecialchars(fmt_time($h['created_at']), ENT_QUOTES) ?></div>
          <div class="note-body"><?= $h['previous_status'] ? status_label($h['previous_status']) . ' → ' : '' ?><?= status_label($h['new_status']) ?><?= $h['note'] ? ' - ' . htmlspecialchars($h['note'], ENT_QUOTES) : '' ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card-panel">
      <h2>Assigned staff</h2>
      <?php if ($isErased): ?>
        <?php $assignedName = 'Unassigned'; foreach ($allStaff as $s) { if ((int) $s['id'] === (int) $case['assigned_staff_id']) $assignedName = $s['name']; } ?>
        <div class="kv-grid"><div class="k">Assigned to</div><div class="v"><?= htmlspecialchars($assignedName, ENT_QUOTES) ?></div></div>
      <?php else: ?>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_staff">
        <input type="hidden" name="expected_lock_version" value="<?= (int) $case['lock_version'] ?>">
        <div class="field-row two">
          <label class="field">
            <span class="lbl">Assigned to</span>
            <select name="assigned_staff_id">
              <option value="">Unassigned</option>
              <?php foreach ($allStaff as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= (int) $tv('assign_staff', 'assigned_staff_id', $case['assigned_staff_id']) === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name'], ENT_QUOTES) ?><?= (int) $s['active'] === 1 ? '' : ' (deactivated)' ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit" class="btn-secondary" style="align-self:end;">Save</button>
        </div>
      </form>
      <?php endif; ?>
    </div>

    <?php if (!$isErased): ?>
    <div class="card-panel">
      <h2>Internal notes (staff only)</h2>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_note">
        <label class="field">
          <textarea name="content" rows="3" placeholder="e.g. Called veterinarian, requested updated prescription"><?= htmlspecialchars((string) $tv('add_note', 'content', ''), ENT_QUOTES) ?></textarea>
        </label>
        <button type="submit" class="btn-secondary">Add Note</button>
      </form>
      <?php foreach ($notes as $n): ?>
        <div class="note-item">
          <div class="note-meta"><?= htmlspecialchars($n['staff_name'], ENT_QUOTES) ?> · <?= htmlspecialchars(fmt_time($n['created_at']), ENT_QUOTES) ?></div>
          <div class="note-body"><?= htmlspecialchars($n['content'], ENT_QUOTES) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card-panel">
      <h2>Send an email</h2>
      <p class="field-hint" style="margin-bottom:1rem;">Nothing is ever sent automatically. Pick a template, review and edit the formulation, price and payment link, then send it yourself - or mark that no email is needed.</p>
      <form method="POST">
        <?= csrf_field() ?>
        <?= one_time_field('send_email:' . $id) ?>
        <div class="field-row two">
          <label class="field">
            <span class="lbl">Template</span>
            <select id="email-template" data-templates="<?= htmlspecialchars(json_encode($emailTemplates), ENT_QUOTES) ?>">
              <?php foreach ($emailTemplates as $key => $t): ?>
                <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>"><?= htmlspecialchars($t['label'], ENT_QUOTES) ?></option>
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
              <?php if ($case['owner_email']): ?><option value="<?= htmlspecialchars($case['owner_email'], ENT_QUOTES) ?>" <?= ($draft['recipient'] ?? '') === $case['owner_email'] ? 'selected' : '' ?>>Owner - <?= htmlspecialchars($case['owner_email'], ENT_QUOTES) ?></option><?php endif; ?>
              <?php if ($case['vet_email']): ?><option value="<?= htmlspecialchars($case['vet_email'], ENT_QUOTES) ?>" <?= ($draft['recipient'] ?? '') === $case['vet_email'] ? 'selected' : '' ?>>Veterinarian - <?= htmlspecialchars($case['vet_email'], ENT_QUOTES) ?></option><?php endif; ?>
            </select>
          </label>
          <label class="field">
            <span class="lbl">Subject</span>
            <input type="text" id="email-subject" name="subject" value="<?= htmlspecialchars($draft['subject'] ?? $emailTemplates['blank']['subject'], ENT_QUOTES) ?>">
          </label>
        </div>
        <label class="field">
          <span class="lbl">Message</span>
          <textarea id="email-body" name="body" rows="8" placeholder="Select a template above, or write a custom message…"><?= htmlspecialchars($draft['body'] ?? $emailTemplates['blank']['body'], ENT_QUOTES) ?></textarea>
        </label>
        <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:1rem;">
          <button type="submit" name="action" value="send_email" class="btn-primary">Send Email</button>
          <button type="submit" name="action" value="no_email_needed" class="btn-secondary">No Email Needed</button>
        </div>
      </form>
      <script src="/js/admin-email-compose.js" defer></script>

      <h2 style="margin-top:2rem;">Communication history</h2>
      <?php if (!$emails): ?>
        <p style="font-size:0.8125rem; color:var(--paper-3);">No emails sent for this case yet.</p>
      <?php endif; ?>
      <?php foreach ($emails as $e): ?>
        <div class="note-item">
          <div class="note-meta">
            <?= htmlspecialchars($e['staff_name'] ?? 'System', ENT_QUOTES) ?> · <?= htmlspecialchars($e['sender'] ?? SENDER_EMAIL, ENT_QUOTES) ?> → <?= htmlspecialchars($e['recipient'], ENT_QUOTES) ?> · <?= htmlspecialchars(fmt_time($e['sent_at']), ENT_QUOTES) ?>
            <span class="status-pill <?= $e['delivery_status'] === 'sent' ? 'status-approved' : ($e['delivery_status'] === 'unknown' ? '' : 'status-rejected') ?>" style="margin-left:0.5rem;"><?= htmlspecialchars($e['delivery_status'] === 'unknown' ? 'Unconfirmed' : ucfirst($e['delivery_status'] ?? 'unknown'), ENT_QUOTES) ?></span>
          </div>
          <?php if ($e['delivery_status'] === 'unknown'): ?>
            <div class="note-meta">May have been sent: check Brevo's transactional email logs for this address before sending it again.</div>
          <?php endif; ?>
          <div class="note-body"><strong><?= htmlspecialchars($e['subject'], ENT_QUOTES) ?></strong><br><?= nl2br(htmlspecialchars($e['body'], ENT_QUOTES)) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
