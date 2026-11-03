<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/form-errors.php';
$staff = require_role(['admin']);

csrf_token();

// The admin does NOT choose the new person's password. They used to, which meant the admin knew it (and sent it
// over WhatsApp or similar), and nothing made the new person change it. Now the account is created with a
// random password nobody knows, and the new person gets an emailed single-use link to set their own - the same
// machinery as "Forgot your password?" (issue_password_reset(), admin/reset-password.php), with a 7-day life.

$errors = [];
$old    = $_POST ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors['_form'] = 'Your session expired. Please try again.';
    } elseif (!consume_one_time_token('staff_invite')) {
        // Each render of the form carries a single-use token: a double-click or a refresh of the POST would
        // otherwise try to create (and email) the same person twice. Consumed before validation, as on the
        // other forms - a form re-rendered after an error gets a fresh one.
        $errors['_form'] = 'This form was already submitted (a double-click or a refresh), so nothing more was created and no second invitation was sent. Check the Staff Accounts list to see whether the first one went through.';
    } else {
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role  = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'pharmacy_staff';

        if ($name === '') $errors['name'] = 'Required';
        elseif (mb_strlen($name) > 150) $errors['name'] = 'Must be 150 characters or fewer';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address';
        elseif (mb_strlen($email) > 190) $errors['email'] = 'Must be 190 characters or fewer';

        if (empty($errors)) {
            $pdo = db();
            $newId = 0;
            $rawToken = '';
            // Inserting directly and catching the UNIQUE violation (rather than checking
            // for an existing row first, then inserting) closes the race where two admins
            // submit the same email at once: a separate check-then-insert lets both pass
            // the check before either commits, so the loser would otherwise hit a raw,
            // uncaught duplicate-key error instead of this friendly message. The INSERT
            // and the UNIQUE constraint check happen as a single atomic operation in the
            // database, so there's no window between "is this taken" and "take it".
            // The account, its invitation token and the audit entry commit together.
            try {
                $pdo->beginTransaction();
                // 32 random bytes nobody ever sees: the account cannot be signed in to until the invitation
                // link is used (and anything the form posted under "password" is ignored).
                $pdo->prepare('INSERT INTO staff_users (name, email, password_hash, role) VALUES (?,?,?,?)')
                    ->execute([$name, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $role]);
                $newId = (int) $pdo->lastInsertId();
                $rawToken = issue_password_reset('staff_users', $newId, STAFF_INVITE_TTL_MINUTES);
                audit('staff_account_created', 'staff_user', $newId, ['role' => $role, 'via' => 'invite']);
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                // Only a duplicate EMAIL means "this email is taken"; any other integrity error is a real
                // fault and must not be reported to the admin as a duplicate email.
                if (!is_unique_violation($e, 'staff_users', 'email')) {
                    throw $e;
                }
                $errors['email'] = 'An account with this email already exists';
                $newId = 0;
            }

            if ($newId > 0) {
                // The email goes out only after the account is safely saved. If it cannot be sent the account
                // still exists, and the person can get a link themselves with "Forgot your password?" on the
                // sign-in page - so the admin is never handed a link or password to pass on by hand.
                $roleLabel = $role === 'admin' ? 'an admin' : 'pharmacy staff';
                $sent = false;
                $unconfirmed = false;
                try {
                    send_transactional_email($email, $name, 'Your Kuronyx staff account',
                        "Hello {$name},\n\n"
                        . "{$staff['name']} has created a Kuronyx staff account for you ({$roleLabel}).\n\n"
                        . "Set your own password to activate it (single-use link, valid for 7 days):\n"
                        . site_url() . '/admin/reset-password.php?token=' . $rawToken . "\n\n"
                        . 'Then sign in at ' . site_url() . "/admin/login.php\n\n"
                        . "Nobody can sign in to this account until you use that link, and nobody else ever sees your password. "
                        . "If the link has expired, choose \"Forgot your password?\" on the sign-in page and we will send a new one.\n\n"
                        . "If you were not expecting this, you can ignore this email.");
                    $sent = true;
                } catch (Throwable $e) {
                    error_log('[staff-invite] email to a new staff account failed: ' . log_safe($e->getMessage()));
                    // No answer came back after the request went out: the invitation MAY have been sent (see mail_send_outcome()).
                    $unconfirmed = $e instanceof MailException && $e->outcome === 'unknown';
                }
                audit($sent ? 'staff_invite_sent' : ($unconfirmed ? 'staff_invite_unconfirmed' : 'staff_invite_failed'), 'staff_user', $newId, ['role' => $role]);

                $_SESSION['staff_flash'] = $sent
                    ? ['type' => 'ok', 'text' => "Staff account created for {$name}. We emailed an invitation to {$email}: the link lets them set their own password, works once and lasts 7 days. You never see their password."]
                    : ($unconfirmed
                        ? ['type' => 'err', 'text' => "Staff account created for {$name}, but we couldn't confirm the invitation email to {$email} was sent - it may have gone out. Check with them (or in Brevo's transactional email logs) first; if it did not arrive, they can open the sign-in page and choose \"Forgot your password?\" - that sends a fresh link to this address."]
                        : ['type' => 'err', 'text' => "Staff account created for {$name}, but the invitation email to {$email} could not be sent. Ask them to open the sign-in page and choose \"Forgot your password?\" - that sends a fresh link to this address. (Check the address is right; the technical reason is in the server log.)"]);
                header('Location: /admin/staff/');
                exit;
            }
        }
    }
}

$pageTitle     = 'New Staff Account - Kuronyx Admin';
$robotsNoindex = true;
$backHref      = '/admin/staff/';
$backLabel     = 'Staff accounts';
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · Staff accounts</p>
    <h1 class="doc-title">New Staff Account</h1>

    <?php if (!empty($errors['_form'])): ?>
      <div class="alert"><?= htmlspecialchars($errors['_form'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <p class="lead">You don't set a password. We email the new person a single-use link, valid for 7 days, so they choose their own - you never see it.</p>

    <form method="POST" novalidate>
      <?= csrf_field() ?>
      <?= one_time_field('staff_invite') ?>
      <label class="<?= field_class($errors, 'name') ?>">
        <span class="lbl">Name</span>
        <input type="text" name="name" value="<?= htmlspecialchars($old['name'] ?? '', ENT_QUOTES) ?>" required<?= field_error_attrs($errors, 'name') ?>>
        <?= field_error_html($errors, 'name') ?>
      </label>
      <label class="<?= field_class($errors, 'email') ?>">
        <span class="lbl">Email (the invitation is sent here - check it carefully)</span>
        <input type="email" name="email" value="<?= htmlspecialchars($old['email'] ?? '', ENT_QUOTES) ?>" required<?= field_error_attrs($errors, 'email') ?>>
        <?= field_error_html($errors, 'email') ?>
      </label>
      <label class="field">
        <span class="lbl">Role</span>
        <select name="role">
          <option value="pharmacy_staff" <?= ($old['role'] ?? '') === 'pharmacy_staff' ? 'selected' : '' ?>>Pharmacy staff</option>
          <option value="admin" <?= ($old['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
        </select>
      </label>
      <button type="submit" class="btn-primary" style="margin-top:1rem;">Create Account and Send Invitation</button>
    </form>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
