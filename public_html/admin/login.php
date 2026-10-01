<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

if (current_staff()) {
    header('Location: /admin/gs-requests/');
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
        $identifier = strtolower($email);

        if ($email === '' || $password === '') {
            $errors['_form'] = 'Enter your email and password.';
        } elseif (is_locked_out($identifier)) {
            $errors['_form'] = 'Too many failed attempts. Please try again in ' . LOGIN_LOCKOUT_MINUTES . ' minutes.';
        } else {
            // Reserved as a failure before the (slow) password check runs, then flipped to
            // success below if it is one — see reserve_login_attempt() in auth.php.
            $attemptId = reserve_login_attempt($identifier);
            $stmt = db()->prepare('SELECT * FROM staff_users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $staff = $stmt->fetch();

            if ($staff && (int) $staff['active'] === 1 && password_verify($password, $staff['password_hash'])) {
                mark_login_attempt_succeeded($attemptId);
                gs_session_start();
                session_regenerate_id(true);
                // session_regenerate_id() carries the existing $_SESSION array forward —
                // clear any lingering vet-portal identity so one browser can't hold both
                // a staff and a vet session at once under the shared session cookie.
                unset($_SESSION['vet']);
                // pw_fingerprint lets require_login() detect a password reset and boot this
                // session even though nothing else about the identity changed — see auth.php.
                $_SESSION['staff'] = ['id' => (int) $staff['id'], 'name' => $staff['name'], 'email' => $staff['email'], 'role' => $staff['role'], 'pw_fingerprint' => password_fingerprint($staff['password_hash'])];
                audit('staff_login', 'staff_user', (int) $staff['id'], []);
                header('Location: /admin/gs-requests/');
                exit;
            }

            audit('staff_login_failed', 'staff_user', null, ['email_attempted' => $identifier], 'public');
            $errors['_form'] = ($staff && (int) $staff['active'] !== 1)
                ? 'This account has been deactivated. Contact an administrator.'
                : 'Invalid email or password.';
        }
    }
}

$pageTitle     = 'Staff Sign In — Kuronyx Admin';
$robotsNoindex = true;
$backHref      = '/';
$backLabel     = 'Back to site';
require __DIR__ . '/../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · Staff sign in</p>
    <h1 class="doc-title">Sign in</h1>

    <?php if (!empty($_GET['setup']) && $_GET['setup'] === 'done'): ?>
      <div class="alert alert-ok">Admin account created. Sign in below.</div>
    <?php endif; ?>
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
      <a href="/admin/forgot-password.php" style="color:var(--paper); border-bottom:1px solid var(--paper-3);">Forgot your password?</a>
    </p>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
