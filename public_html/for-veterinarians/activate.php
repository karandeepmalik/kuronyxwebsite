<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

csrf_token();

$pdo = db();
$rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$done = false;

$account = null;
if ($rawToken !== '') {
    $stmt = $pdo->prepare(
        "SELECT va.*, a.full_name FROM vet_accounts va
         JOIN vet_applications a ON a.id = va.vet_application_id
         WHERE va.activation_token_hash = ? AND va.status = 'pending_activation' AND va.activation_expires_at > ?
         LIMIT 1"
    );
    $stmt->execute([hash('sha256', $rawToken), gmdate('Y-m-d H:i:s')]);
    $account = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $account) {
    if (!csrf_verify()) {
        $errors['_form'] = 'Your session expired. Please reload this page and try again.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');
        if (strlen($password) < 12) $errors['password'] = 'Use at least 12 characters';
        if ($password !== $confirm) $errors['password_confirm'] = 'Passwords do not match';

        if (empty($errors)) {
            $pdo->prepare("UPDATE vet_accounts SET password_hash = ?, status = 'active', activation_token_hash = NULL, activation_expires_at = NULL WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $account['id']]);
            audit('vet_account_activated', 'vet_account', (int) $account['id'], [], 'public');
            $done = true;
        }
    }
}

$pageTitle     = 'Activate Your Veterinary Account — Kuronyx Sciences';
$robotsNoindex = true;
$activeNav     = 'for-veterinarians';
require __DIR__ . '/../includes/layout-header.php';
?>
    <p class="doc-eyebrow">For veterinarians · Account activation</p>
    <h1 class="doc-title">Activate your veterinary account</h1>

    <?php if ($done): ?>
        <div class="alert alert-ok">Your account is active. <a href="/for-veterinarians/login.php" style="color:var(--paper); border-bottom:1px solid var(--paper-3);">Sign in</a> to continue.</div>
    <?php elseif (!$account): ?>
        <div class="alert">This activation link is invalid or has expired. Please contact <a href="mailto:ops@kuronyx.in" style="color:#ffb3b3;">ops@kuronyx.in</a> for a new one.</div>
    <?php else: ?>
        <p class="lead">Welcome, <?= htmlspecialchars($account['full_name'], ENT_QUOTES) ?>. Set a password to activate your Kuronyx veterinary portal account (<?= htmlspecialchars($account['email'], ENT_QUOTES) ?>).</p>

        <?php if (!empty($errors['_form'])): ?>
          <div class="alert"><?= htmlspecialchars($errors['_form'], ENT_QUOTES) ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= htmlspecialchars($rawToken, ENT_QUOTES) ?>">
          <label class="field <?= isset($errors['password']) ? 'has-error' : '' ?>">
            <span class="lbl">Password (min. 12 characters)</span>
            <input type="password" name="password" required minlength="12">
          </label>
          <label class="field <?= isset($errors['password_confirm']) ? 'has-error' : '' ?>">
            <span class="lbl">Confirm password</span>
            <input type="password" name="password_confirm" required minlength="12">
          </label>
          <button type="submit" class="btn-primary" style="margin-top:1rem;">Activate Account</button>
        </form>
    <?php endif; ?>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
