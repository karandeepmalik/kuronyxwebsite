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

// Set during POST handling below; if non-null once the page has fully rendered, the
// actual email send happens at the very end of this script — see the bottom of the file.
$deferredEmail = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors['_form'] = 'Your session expired. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $ipBucket = 'pw_reset_ip:' . client_ip();

        if (rate_limited($ipBucket, 15, 60)) {
            $errors['_form'] = 'Too many requests from this connection. Please try again later.';
        } else {
            // Also capped per address so this can't be used to flood someone's inbox with
            // reset emails. The visitor sees the same confirmation either way — whether an
            // account exists, is inactive, or was throttled is never revealed.
            $emailBucket = 'pw_reset_email:staff:' . substr(strtolower($email), 0, 150);
            if ($email !== '' && !rate_limited($emailBucket, 3, 60)) {
                $stmt = db()->prepare('SELECT id, name, email FROM staff_users WHERE email = ? AND active = 1 LIMIT 1');
                $stmt->execute([$email]);
                $account = $stmt->fetch();
                if ($account) {
                    $token = issue_password_reset('staff_users', (int) $account['id']);
                    audit('password_reset_requested', 'staff_user', (int) $account['id'], [], 'public');
                    $deferredEmail = [
                        'to' => $account['email'], 'name' => $account['name'],
                        'subject' => 'Reset your Kuronyx staff password',
                        'body' => "We received a request to reset the password for your Kuronyx staff account.\n\n"
                            . 'Set a new password (link valid for ' . PASSWORD_RESET_TTL_MINUTES . " minutes, single use):\n"
                            . site_url() . '/admin/reset-password.php?token=' . $token . "\n\n"
                            . "If you didn't request this, you can ignore this email — your password stays unchanged.",
                    ];
                } else {
                    // Do the same kind of work the real-account branch above does (a token write
                    // against the accounts table) so a nonexistent address takes about as long as a
                    // real one. This used to compute a bcrypt hash here instead, which the real branch
                    // never does - so unknown addresses were the *slower* ones, the opposite of the
                    // intent. id = 0 never matches a row, so nothing is changed or audited. The slow
                    // part, the Brevo call, is deferred until after the response (see end of file).
                    db()->prepare("UPDATE staff_users SET password_reset_token_hash = ?, password_reset_expires_at = ? WHERE id = 0")
                        ->execute([hash('sha256', bin2hex(random_bytes(32))), gmdate('Y-m-d H:i:s', time() + PASSWORD_RESET_TTL_MINUTES * 60)]);
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
<?php
require __DIR__ . '/../includes/layout-footer.php';

// The actual (slow, network-bound) email send happens only now, after the full page
// above has already been handed to the browser — see the $deferredEmail comment near the
// top of this file for why. fastcgi_finish_request() (PHP-FPM) or litespeed_finish_request() (LiteSpeed) closes the
// connection to the client while this script keeps running server-side; under any other
// SAPI (e.g. the built-in dev server used locally/in tests) it doesn't exist, so this
// just runs synchronously in that order instead, same as before.
// Also releases the session file lock before the slow part — without this, another tab
// or request from the same visitor would block until the deferred send below finishes,
// even though their own response has already gone out via fastcgi_finish_request() above.
session_write_close();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    // Hostinger runs LiteSpeed (SAPI "litespeed"), which has its own equivalent.
    litespeed_finish_request();
}
if ($deferredEmail !== null) {
    try {
        send_transactional_email($deferredEmail['to'], $deferredEmail['name'], $deferredEmail['subject'], $deferredEmail['body']);
    } catch (MailException $e) {
        error_log('[password-reset] staff email failed: ' . $e->getMessage());
    }
}

