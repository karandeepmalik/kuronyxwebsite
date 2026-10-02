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
        elseif (mb_strlen($name) > 150) $errors['name'] = 'Must be 150 characters or fewer';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address';
        elseif (mb_strlen($email) > 190) $errors['email'] = 'Must be 190 characters or fewer';
        if (strlen($password) < 12) $errors['password'] = 'Use at least 12 characters';
        elseif (strlen($password) > PASSWORD_MAX_BYTES) $errors['password'] = 'Use at most 72 characters';
        if ($password !== $confirm) $errors['password_confirm'] = 'Passwords do not match';

        if (empty($errors)) {
            $pdo = db();
            // Inserting directly and catching the UNIQUE violation (rather than checking
            // for an existing row first, then inserting) closes the race where two admins
            // submit the same email at once: a separate check-then-insert lets both pass
            // the check before either commits, so the loser would otherwise hit a raw,
            // uncaught duplicate-key error instead of this friendly message. The INSERT
            // and the UNIQUE constraint check happen as a single atomic operation in the
            // database, so there's no window between "is this taken" and "take it".
            try {
                $stmt = $pdo->prepare('INSERT INTO staff_users (name, email, password_hash, role) VALUES (?,?,?,?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
                audit('staff_account_created', 'staff_user', (int) $pdo->lastInsertId(), ['role' => $role, 'via' => 'admin_ui']);
                header('Location: /admin/staff/?created=1');
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $errors['email'] = 'An account with this email already exists';
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
