<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/mailer.php';
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

$flash = null;
// Set only when this request just (re)generated a usable activation link, so the
// template built further down can embed the real URL instead of a placeholder.
$activationUrl = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'err', 'text' => 'Your session expired. Please try again.'];
    } else {
        $action = $_POST['action'] ?? '';
        $noteText = trim($_POST['note'] ?? '');

        $applyNote = function (string $prefix) use ($pdo, $id, $staff, $noteText, &$app) {
            $stamp = fmt_time(gmdate('Y-m-d H:i:s'));
            $entry = "[{$stamp} · {$staff['name']}] {$prefix}" . ($noteText !== '' ? ": {$noteText}" : '.');
            $updated = trim(($app['internal_notes'] ?? '') . "\n" . $entry);
            $pdo->prepare('UPDATE vet_applications SET internal_notes = ? WHERE id = ?')->execute([$updated, $id]);
            $app['internal_notes'] = $updated;
        };

        if ($action === 'approve') {
            $pdo->prepare('UPDATE vet_applications SET status = \'approved\', reviewed_by = ?, reviewed_at = ? WHERE id = ?')->execute([$staff['id'], gmdate('Y-m-d H:i:s'), $id]);
            $applyNote('Approved');
            audit('application_approved', 'vet_application', $id, []);
            $app['status'] = 'approved';

            // Provision (or reuse) the portal account this application unlocks.
            $existing = $pdo->prepare('SELECT id FROM vet_accounts WHERE vet_application_id = ?');
            $existing->execute([$id]);
            $accountId = $existing->fetchColumn();

            if ($accountId === false) {
                $emailTaken = $pdo->prepare('SELECT id FROM vet_accounts WHERE email = ?');
                $emailTaken->execute([$app['professional_email']]);
                if ($emailTaken->fetchColumn() !== false) {
                    $applyNote('Approved, but a portal account already exists for this email address — no new account created');
                    audit('vet_account_conflict', 'vet_application', $id, ['email' => $app['professional_email']]);
                    $flash = ['type' => 'ok', 'text' => 'Application approved. Note: a portal account for this email already exists.'];
                } else {
                    $rawToken = bin2hex(random_bytes(32));
                    $pdo->prepare(
                        'INSERT INTO vet_accounts (vet_application_id, email, status, activation_token_hash, activation_expires_at)
                         VALUES (?, ?, \'pending_activation\', ?, ?)'
                    )->execute([$id, $app['professional_email'], hash('sha256', $rawToken), gmdate('Y-m-d H:i:s', time() + 7 * 86400)]);
                    audit('vet_account_created', 'vet_application', $id, []);
                    $activationUrl = site_url() . '/for-veterinarians/activate.php?token=' . $rawToken;
                    $flash = ['type' => 'ok', 'text' => 'Application approved and a portal account has been created. Use "Send an email" below to send the activation link — nothing is emailed automatically.'];
                }
            } else {
                $flash = ['type' => 'ok', 'text' => 'Application approved.'];
            }
        } elseif ($action === 'reject') {
            $pdo->prepare('UPDATE vet_applications SET status = \'rejected\', reviewed_by = ?, reviewed_at = ? WHERE id = ?')->execute([$staff['id'], gmdate('Y-m-d H:i:s'), $id]);
            $applyNote('Rejected');
            audit('application_rejected', 'vet_application', $id, []);
            $flash = ['type' => 'ok', 'text' => 'Application rejected. Use "Send an email" below if the applicant needs to be told.'];
            $app['status'] = 'rejected';
        } elseif ($action === 'request_info') {
            $pdo->prepare('UPDATE vet_applications SET status = \'under_review\' WHERE id = ?')->execute([$id]);
            $applyNote('Requested more information');
            audit('application_reviewed', 'vet_application', $id, ['outcome' => 'more_info_requested']);
            $flash = ['type' => 'ok', 'text' => 'Marked as needing more information. Use "Send an email" below if the applicant needs to be told.'];
            $app['status'] = 'under_review';
        } elseif ($action === 'suspend') {
            $pdo->prepare('UPDATE vet_applications SET status = \'suspended\' WHERE id = ?')->execute([$id]);
            // Also lock out the portal account, if one was ever provisioned — otherwise
            // suspending the application would leave an already-activated vet still
            // able to sign in and submit requests.
            $pdo->prepare('UPDATE vet_accounts SET status = \'suspended\' WHERE vet_application_id = ?')->execute([$id]);
            $applyNote('Suspended');
            audit('application_reviewed', 'vet_application', $id, ['outcome' => 'suspended']);
            $flash = ['type' => 'ok', 'text' => 'Account suspended. Use "Send an email" below if the applicant needs to be told.'];
            $app['status'] = 'suspended';
        } elseif ($action === 'generate_activation_link') {
            $account = $pdo->prepare('SELECT id, status FROM vet_accounts WHERE vet_application_id = ?');
            $account->execute([$id]);
            $account = $account->fetch();
            if (!$account) {
                $flash = ['type' => 'err', 'text' => 'No portal account exists for this application yet — approve it first.'];
            } elseif ($account['status'] === 'active') {
                $flash = ['type' => 'err', 'text' => 'This vet has already activated their account — no link needed.'];
            } else {
                $rawToken = bin2hex(random_bytes(32));
                $pdo->prepare('UPDATE vet_accounts SET activation_token_hash = ?, activation_expires_at = ? WHERE id = ?')
                    ->execute([hash('sha256', $rawToken), gmdate('Y-m-d H:i:s', time() + 7 * 86400), $account['id']]);
                audit('activation_link_generated', 'vet_application', $id, []);
                $activationUrl = site_url() . '/for-veterinarians/activate.php?token=' . $rawToken;
                $flash = ['type' => 'ok', 'text' => 'A fresh activation link has been generated below (any previous link no longer works) — review and send it.'];
            }
        } elseif ($action === 'send_email') {
            $senderEmail = trim($_POST['sender'] ?? '');
            $recipient   = trim($_POST['recipient'] ?? '');
            $subject     = trim($_POST['subject'] ?? '');
            $body        = trim($_POST['body'] ?? '');
            $allowedRecipients = array_filter([$app['professional_email'], $app['clinic_email'] ?? null]);
            if (!array_key_exists($senderEmail, verified_senders())) {
                $flash = ['type' => 'err', 'text' => 'Select a valid sender address.'];
            } elseif (!in_array($recipient, $allowedRecipients, true)) {
                $flash = ['type' => 'err', 'text' => 'Recipient must be an email address on file for this application.'];
            } elseif ($subject === '' || $body === '') {
                $flash = ['type' => 'err', 'text' => 'Subject and message body are required.'];
            } else {
                $sent = false;
                try {
                    send_transactional_email($recipient, $app['full_name'], $subject, $body, $senderEmail);
                    $sent = true;
                } catch (MailException $e) {
                    $sent = false;
                }
                // vet_applications has no case_emails-style log table of its own (that's
                // scoped to gs_requests) — outcomes are appended to internal_notes instead,
                // consistent with how every other review action here is recorded.
                $applyNote($sent
                    ? "Emailed applicant ({$senderEmail} → {$recipient}) — Subject: {$subject}"
                    : "Email to applicant FAILED to send ({$senderEmail} → {$recipient}) — Subject: {$subject}");
                audit($sent ? 'email_sent' : 'email_failed', 'vet_application', $id, ['sender' => $senderEmail, 'recipient' => $recipient]);
                $flash = $sent
                    ? ['type' => 'ok', 'text' => 'Email sent.']
                    : ['type' => 'err', 'text' => 'Email failed to send. The attempt has been logged in the notes below.'];
            }
        } elseif ($action === 'no_email_needed') {
            audit('email_not_needed', 'vet_application', $id, []);
            $applyNote('Marked — no email needed');
            $flash = ['type' => 'ok', 'text' => 'Noted — no email needed.'];
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
        'label'   => '— Blank / custom —',
        'subject' => '',
        'body'    => '',
    ],
    'approved' => [
        'label'   => 'Approved (with activation link)',
        'subject' => 'Your Kuronyx veterinary account is approved',
        'body'    => "Your veterinary account application has been approved.\n\nSet your password to activate your portal account (link valid for 7 days):\n"
            . ($activationUrl ?: '[click "Generate Activation Link" below, then re-open this template]')
            . "\n\n{$ref}",
    ],
    'rejected' => [
        'label'   => 'Rejected',
        'subject' => 'Your Kuronyx veterinary account application',
        'body'    => "Your veterinary account application could not be approved at this time.\n\n{$ref}\n\nIf you have questions, please contact ops@kuronyx.in.",
    ],
    'request_info' => [
        'label'   => 'Request More Information',
        'subject' => 'Additional information needed — Kuronyx veterinary account application',
        'body'    => "We need some additional information before we can proceed with your veterinary account application.\n\n{$ref}\n\nOur team will follow up shortly with specifics.",
    ],
    'suspended' => [
        'label'   => 'Suspended',
        'subject' => 'Your Kuronyx veterinary account',
        'body'    => "Your veterinary account has been suspended.\n\n{$ref}\n\nIf you have questions, please contact ops@kuronyx.in.",
    ],
];
$defaultTemplateKey = $activationUrl ? 'approved' : 'blank';

$pageTitle     = "VA-{$id} — Kuronyx Admin";
$robotsNoindex = true;
$backHref      = '/admin/veterinary-applications/';
$backLabel     = 'All applications';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · Veterinary application</p>
    <h1 class="doc-title">VA-<?= $id ?> · <?= htmlspecialchars($app['full_name'], ENT_QUOTES) ?></h1>
    <p class="doc-meta">
      <span class="status-pill status-<?= htmlspecialchars($app['status'], ENT_QUOTES) ?>"><?= ucwords($app['status']) ?></span><span class="sep">·</span>
      Submitted <?= htmlspecialchars(fmt_time($app['created_at']), ENT_QUOTES) ?>
    </p>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : '' ?>"><?= htmlspecialchars($flash['text'], ENT_QUOTES) ?></div>
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
        <div class="k">Email</div><div class="v"><?= htmlspecialchars($app['clinic_email'] ?? '—', ENT_QUOTES) ?></div>
        <div class="k">Website</div><div class="v"><?= htmlspecialchars($app['clinic_website'] ?? '—', ENT_QUOTES) ?></div>
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

    <div class="card-panel">
      <h2>Review actions</h2>
      <form method="POST">
        <?= csrf_field() ?>
        <label class="field">
          <span class="lbl">Note (recorded with every action below — internal only, never shown to the applicant)</span>
          <textarea name="note" rows="2"></textarea>
        </label>
        <p class="field-hint" style="margin:1rem 0;">Nothing is emailed automatically here — use "Send an email" below afterward if the applicant needs to be told.</p>
        <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:1rem;">
          <button type="submit" name="action" value="approve" class="btn-primary">Approve</button>
          <button type="submit" name="action" value="reject" class="btn-secondary">Reject</button>
          <button type="submit" name="action" value="request_info" class="btn-secondary">Request More Information</button>
          <button type="submit" name="action" value="suspend" class="btn-secondary">Suspend</button>
          <button type="submit" name="action" value="add_note" class="btn-secondary">Add Note Only</button>
        </div>
      </form>
    </div>

    <div class="card-panel">
      <h2>Send an email</h2>
      <p class="field-hint" style="margin-bottom:1rem;">Pick a template, review and edit it, then send it yourself — or mark that no email is needed. The "Approved" template needs a fresh activation link generated first.</p>
      <form method="POST">
        <?= csrf_field() ?>
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
                <option value="<?= htmlspecialchars($email, ENT_QUOTES) ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?> &lt;<?= htmlspecialchars($email, ENT_QUOTES) ?>&gt;</option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="field-row two">
          <label class="field">
            <span class="lbl">Recipient</span>
            <select name="recipient">
              <option value="<?= htmlspecialchars($app['professional_email'], ENT_QUOTES) ?>">Veterinarian — <?= htmlspecialchars($app['professional_email'], ENT_QUOTES) ?></option>
              <?php if (!empty($app['clinic_email'])): ?><option value="<?= htmlspecialchars($app['clinic_email'], ENT_QUOTES) ?>">Clinic — <?= htmlspecialchars($app['clinic_email'], ENT_QUOTES) ?></option><?php endif; ?>
            </select>
          </label>
          <label class="field">
            <span class="lbl">Subject</span>
            <input type="text" id="email-subject" name="subject" value="<?= htmlspecialchars($emailTemplates[$defaultTemplateKey]['subject'], ENT_QUOTES) ?>">
          </label>
        </div>
        <label class="field">
          <span class="lbl">Message</span>
          <textarea id="email-body" name="body" rows="8" placeholder="Select a template above, or write a custom message…"><?= htmlspecialchars($emailTemplates[$defaultTemplateKey]['body'], ENT_QUOTES) ?></textarea>
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
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
