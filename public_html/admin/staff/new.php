<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
$staff = require_role(['admin']);

csrf_token();

$errors = [];
$old    = $_POST ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors['_form'] = 'Your session expired. Please try again.';
    } else {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $role     = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'pharmacy_staff';
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');

        if ($name === '') $errors['name'] = 'Required';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address';
        if (strlen($password) < 12) $errors['password'] = 'Use at least 12 characters';
        if ($password !== $confirm) $errors['password_confirm'] = 'Passwords do not match';

        if (empty($errors)) {
            $pdo = db();
            $dupe = $pdo->prepare('SELECT id FROM staff_users WHERE email = ?');
            $dupe->execute([$email]);
            if ($dupe->fetch()) {
                $errors['email'] = 'An account with this email already exists';
            } else {
                $stmt = $pdo->prepare('INSERT INTO staff_users (name, email, password_hash, role) VALUES (?,?,?,?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
                audit('staff_account_created', 'staff_user', (int) $pdo->lastInsertId(), ['role' => $role, 'via' => 'admin_ui']);
                header('Location: /admin/staff/?created=1');
                exit;
            }
        }
    }
}

$pageTitle     = 'New Staff Account — Kuronyx Admin';
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

    <form method="POST" novalidate>
      <?= csrf_field() ?>
      <label class="field <?= isset($errors['name']) ? 'has-error' : '' ?>">
        <span class="lbl">Name</span>
        <input type="text" name="name" value="<?= htmlspecialchars($old['name'] ?? '', ENT_QUOTES) ?>" required>
      </label>
      <label class="field <?= isset($errors['email']) ? 'has-error' : '' ?>">
        <span class="lbl">Email</span>
        <input type="email" name="email" value="<?= htmlspecialchars($old['email'] ?? '', ENT_QUOTES) ?>" required>
      </label>
      <label class="field">
        <span class="lbl">Role</span>
        <select name="role">
          <option value="pharmacy_staff" <?= ($old['role'] ?? '') === 'pharmacy_staff' ? 'selected' : '' ?>>Pharmacy staff</option>
          <option value="admin" <?= ($old['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
        </select>
      </label>
      <label class="field <?= isset($errors['password']) ? 'has-error' : '' ?>">
        <span class="lbl">Password (min. 12 characters)</span>
        <input type="password" name="password" required minlength="12">
      </label>
      <label class="field <?= isset($errors['password_confirm']) ? 'has-error' : '' ?>">
        <span class="lbl">Confirm password</span>
        <input type="password" name="password_confirm" required minlength="12">
      </label>
      <button type="submit" class="btn-primary" style="margin-top:1rem;">Create Staff Account</button>
    </form>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
