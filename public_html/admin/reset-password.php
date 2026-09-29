<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

csrf_token();

$rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errors   = [];
$done     = false;
$account  = find_password_reset_account('staff_users', $rawToken);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $account) {
    if (!csrf_verify()) {
        $errors['_form'] = 'Your session expired. Please reload this page and try again.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');
        if (strlen($password) < 12) $errors['password'] = 'Use at least 12 characters';
        if ($password !== $confirm) $errors['password_confirm'] = 'Passwords do not match';

        if (empty($errors)) {
            if (complete_password_reset('staff_users', (int) $account['id'], $rawToken, $password)) {
                // A locked-out user resetting their password should be able to sign in straight away.
                db()->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([strtolower($account['email'])]);
                audit('password_reset_completed', 'staff_user', (int) $account['id'], [], 'public');
                $done = true;
            } else {
                $account = null; // someone else already used this link first
            }
        }
    }
}

$pageTitle     = 'Reset Password — Kuronyx Admin';
$robotsNoindex = true;
$backHref      = '/admin/login.php';
$backLabel     = 'Back to sign in';
require __DIR__ . '/../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · Staff sign in</p>
    <h1 class="doc-title">Set a new password</h1>

    <?php if ($done): ?>
      <div class="alert alert-ok">Your password has been changed. <a href="/admin/login.php" style="color:var(--paper); border-bottom:1px solid var(--paper-3);">Sign in</a> with the new one.</div>
    <?php elseif (!$account): ?>
      <div class="alert">This reset link is invalid or has expired. <a href="/admin/forgot-password.php" style="color:#ffb3b3;">Request a new one</a>.</div>
    <?php else: ?>
      <?php if (!empty($errors['_form'])): ?>
        <div class="alert"><?= htmlspecialchars($errors['_form'], ENT_QUOTES) ?></div>
      <?php endif; ?>
      <p class="lead">Choose a new password for <?= htmlspecialchars($account['email'], ENT_QUOTES) ?>.</p>
      <form method="POST" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($rawToken, ENT_QUOTES) ?>">
        <label class="field <?= isset($errors['password']) ? 'has-error' : '' ?>">
          <span class="lbl">New password (min. 12 characters)</span>
          <input type="password" name="password" required minlength="12" autofocus>
        </label>
        <label class="field <?= isset($errors['password_confirm']) ? 'has-error' : '' ?>">
          <span class="lbl">Confirm new password</span>
          <input type="password" name="password_confirm" required minlength="12">
        </label>
        <button type="submit" class="btn-primary" style="margin-top:1rem;">Change Password</button>
      </form>
    <?php endif; ?>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
