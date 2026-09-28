<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/mailer.php';

if (current_staff()) {
    header('Location: /admin/gs-requests/');
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

            // Also capped per address so this can't be used to flood someone's inbox with
            // reset emails. The visitor sees the same confirmation either way — whether an
            // account exists, is inactive, or was throttled is never revealed.
            $emailBucket = 'pw_reset_email:staff:' . substr(strtolower($email), 0, 150);
            if ($email !== '' && !rate_limited($emailBucket, 3, 60)) {
                record_rate_limit_hit($emailBucket);
                $stmt = db()->prepare('SELECT id, name, email FROM staff_users WHERE email = ? AND active = 1 LIMIT 1');
                $stmt->execute([$email]);
                $account = $stmt->fetch();
                if ($account) {
                    $token = issue_password_reset('staff_users', (int) $account['id']);
                    audit('password_reset_requested', 'staff_user', (int) $account['id'], [], 'public');
                    try {
                        send_transactional_email(
                            $account['email'], $account['name'],
                            'Reset your Kuronyx staff password',
                            "We received a request to reset the password for your Kuronyx staff account.\n\n"
                            . 'Set a new password (link valid for ' . PASSWORD_RESET_TTL_MINUTES . " minutes, single use):\n"
                            . site_url() . '/admin/reset-password.php?token=' . $token . "\n\n"
                            . "If you didn't request this, you can ignore this email — your password stays unchanged."
                        );
                    } catch (MailException $e) {
                        error_log('[password-reset] staff email failed: ' . $e->getMessage());
                    }
                }
            }
            $sent = true;
        }
    }
}

$pageTitle     = 'Forgot Password — Kuronyx Admin';
$robotsNoindex = true;
$backHref      = '/admin/login.php';
$backLabel     = 'Back to sign in';
require __DIR__ . '/../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · Staff sign in</p>
    <h1 class="doc-title">Forgot your password?</h1>

    <?php if (!empty($errors['_form'])): ?>
      <div class="alert"><?= htmlspecialchars($errors['_form'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <?php if ($sent): ?>
      <div class="alert alert-ok">If a staff account exists for that email address, a password reset link is on its way. It expires in <?= PASSWORD_RESET_TTL_MINUTES ?> minutes and can be used once.</div>
    <?php else: ?>
      <p class="lead">Enter your staff email address and we'll send you a link to set a new password.</p>
      <form method="POST" novalidate>
        <?= csrf_field() ?>
        <label class="field">
          <span class="lbl">Email</span>
          <input type="email" name="email" required autofocus>
        </label>
        <button type="submit" class="btn-primary" style="margin-top:1rem;">Send Reset Link</button>
      </form>
    <?php endif; ?>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
