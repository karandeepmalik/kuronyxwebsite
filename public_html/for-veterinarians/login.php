<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

if (current_vet()) {
    header('Location: /for-veterinarians/portal/');
    exit;
}

$errors = [];
$emailOld = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors['_form'] = 'Your session expired. Please try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $emailOld = $email;
        $identifier = 'vet:' . strtolower($email);

        if ($email === '' || $password === '') {
            $errors['_form'] = 'Enter your email and password.';
        } elseif (is_locked_out($identifier)) {
            $errors['_form'] = 'Too many failed attempts. Please try again in ' . LOGIN_LOCKOUT_MINUTES . ' minutes.';
        } else {
            // Reserved as a failure before the (slow) password check runs, then flipped to
            // success below if it is one — see reserve_login_attempt() in auth.php.
            $attemptId = reserve_login_attempt($identifier);
            $stmt = db()->prepare(
                "SELECT va.*, a.full_name FROM vet_accounts va
                 JOIN vet_applications a ON a.id = va.vet_application_id
                 WHERE va.email = ? LIMIT 1"
            );
            $stmt->execute([$email]);
            $vet = $stmt->fetch();

            if ($vet && $vet['status'] === 'active' && $vet['password_hash'] && password_verify($password, $vet['password_hash'])) {
                mark_login_attempt_succeeded($attemptId);
                gs_session_start();
                session_regenerate_id(true);
                // See admin/login.php — session_regenerate_id() carries $_SESSION forward,
                // so clear any lingering staff identity rather than holding both at once.
                unset($_SESSION['staff']);
                // pw_fingerprint lets require_vet_login() detect a password reset and boot
                // this session even though nothing else about the identity changed.
                $_SESSION['vet'] = ['id' => (int) $vet['id'], 'email' => $vet['email'], 'full_name' => $vet['full_name'], 'vet_application_id' => (int) $vet['vet_application_id'], 'pw_fingerprint' => password_fingerprint($vet['password_hash'])];
                audit('vet_login', 'vet_account', (int) $vet['id'], [], 'public');
                header('Location: /for-veterinarians/portal/');
                exit;
            }

            audit('vet_login_failed', 'vet_account', null, ['email_attempted' => $identifier], 'public');
            $errors['_form'] = ($vet && $vet['status'] === 'pending_activation')
                ? 'This account has not been activated yet — check your email for the activation link.'
                : 'Invalid email or password.';
        }
    }
}

$pageTitle       = 'Veterinarian Sign In — Kuronyx Sciences';
$pageDescription = 'Sign in to your Kuronyx veterinary account to submit and track GS-441524 requests.';
$robotsNoindex   = true;
$activeNav       = 'for-veterinarians';
require __DIR__ . '/../includes/layout-header.php';
?>
    <p class="doc-eyebrow">For veterinarians · Portal sign in</p>
    <h1 class="doc-title">Sign in</h1>

    <?php if (!empty($errors['_form'])): ?>
      <div class="alert"><?= htmlspecialchars($errors['_form'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <?= csrf_field() ?>
      <label class="field">
        <span class="lbl">Email</span>
        <input type="email" name="email" value="<?= htmlspecialchars($emailOld, ENT_QUOTES) ?>" required autofocus>
      </label>
      <label class="field">
        <span class="lbl">Password</span>
        <input type="password" name="password" required>
      </label>
      <button type="submit" class="btn-primary" style="margin-top:1rem;">Sign In</button>
    </form>

    <p style="margin-top:2rem; font-size:0.8125rem; color:var(--paper-3);">
      <a href="/for-veterinarians/forgot-password.php" style="color:var(--paper); border-bottom:1px solid var(--paper-3);">Forgot your password?</a>
    </p>
    <p style="margin-top:1rem; font-size:0.8125rem; color:var(--paper-3);">
      Don't have an account yet? <a href="/for-veterinarians/apply" style="color:var(--paper); border-bottom:1px solid var(--paper-3);">Apply for a Veterinary Account</a>.
    </p>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
