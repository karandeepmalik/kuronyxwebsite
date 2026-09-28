<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/mailer.php';

if (current_vet()) {
    header('Location: /for-veterinarians/portal/');
    exit;
}

csrf_token();

$errors = [];
$sent   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors['_form'] = 'Your session expired. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $ipBucket = 'pw_reset_ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        if (rate_limited($ipBucket, 15, 60)) {
            $errors['_form'] = 'Too many requests from this connection. Please try again later.';
        } else {
            record_rate_limit_hit($ipBucket);

            // See admin/forgot-password.php — same per-address cap and the same
            // always-identical confirmation, so nothing here reveals which emails have accounts.
            // Only activated (status = active) accounts get a reset; a pending one still
            // needs its original activation link, and a suspended one must stay locked out.
            $emailBucket = 'pw_reset_email:vet:' . substr(strtolower($email), 0, 150);
            if ($email !== '' && !rate_limited($emailBucket, 3, 60)) {
                record_rate_limit_hit($emailBucket);
                $stmt = db()->prepare(
                    "SELECT va.id, va.email, a.full_name FROM vet_accounts va
                     JOIN vet_applications a ON a.id = va.vet_application_id
                     WHERE va.email = ? AND va.status = 'active' LIMIT 1"
                );
                $stmt->execute([$email]);
                $account = $stmt->fetch();
                if ($account) {
                    $token = issue_password_reset('vet_accounts', (int) $account['id']);
                    audit('password_reset_requested', 'vet_account', (int) $account['id'], [], 'public');
                    try {
                        send_transactional_email(
                            $account['email'], $account['full_name'],
                            'Reset your Kuronyx veterinary portal password',
                            "We received a request to reset the password for your Kuronyx veterinary portal account.\n\n"
                            . 'Set a new password (link valid for ' . PASSWORD_RESET_TTL_MINUTES . " minutes, single use):\n"
                            . site_url() . '/for-veterinarians/reset-password.php?token=' . $token . "\n\n"
                            . "If you didn't request this, you can ignore this email — your password stays unchanged."
                        );
                    } catch (MailException $e) {
                        error_log('[password-reset] vet email failed: ' . $e->getMessage());
                    }
                }
            }
            $sent = true;
        }
    }
}

$pageTitle     = 'Forgot Password — Kuronyx Sciences';
$robotsNoindex = true;
$activeNav     = 'for-veterinarians';
require __DIR__ . '/../includes/layout-header.php';
?>
    <p class="doc-eyebrow">For veterinarians · Portal sign in</p>
    <h1 class="doc-title">Forgot your password?</h1>

    <?php if (!empty($errors['_form'])): ?>
      <div class="alert"><?= htmlspecialchars($errors['_form'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <?php if ($sent): ?>
      <div class="alert alert-ok">If a veterinary portal account exists for that email address, a password reset link is on its way. It expires in <?= PASSWORD_RESET_TTL_MINUTES ?> minutes and can be used once.</div>
      <p style="margin-top:1.5rem; font-size:0.8125rem; color:var(--paper-3);"><a href="/for-veterinarians/login.php" style="color:var(--paper); border-bottom:1px solid var(--paper-3);">Back to sign in</a></p>
    <?php else: ?>
      <p class="lead">Enter the email address of your veterinary portal account and we'll send you a link to set a new password.</p>
      <form method="POST" novalidate>
        <?= csrf_field() ?>
        <label class="field">
          <span class="lbl">Email</span>
          <input type="email" name="email" required autofocus>
        </label>
        <button type="submit" class="btn-primary" style="margin-top:1rem;">Send Reset Link</button>
      </form>
      <p style="margin-top:2rem; font-size:0.8125rem; color:var(--paper-3);"><a href="/for-veterinarians/login.php" style="color:var(--paper); border-bottom:1px solid var(--paper-3);">Back to sign in</a></p>
    <?php endif; ?>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
