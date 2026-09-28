<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

// This page creates the first admin account with no login required, so an empty
// staff_users table alone is not enough of a gate — this URL is permanent and
// predictable, and staff_users could in principle become empty again later.
// SETUP_TOKEN must be set in db-config.php (a long random value, not committed)
// and is checked before anything else runs, on both GET and POST.
if (!defined('SETUP_TOKEN') || SETUP_TOKEN === '' || !hash_equals(SETUP_TOKEN, $_REQUEST['token'] ?? '')) {
    http_response_code(403);
    exit('Forbidden.');
}

// Must run before any HTML output so the session cookie ships with the first
// response headers — csrf_field() alone (called later, inside the template)
// is too late and silently breaks CSRF verification on every submission.
csrf_token();

$existingCount = (int) db()->query('SELECT COUNT(*) FROM staff_users')->fetchColumn();
$errors = [];

if ($existingCount > 0) {
    $pageTitle     = 'Setup — Kuronyx Admin';
    $robotsNoindex = true;
    $backHref      = '/admin/login.php';
    $backLabel     = 'Go to sign in';
    require __DIR__ . '/../includes/layout-header.php';
    ?>
        <p class="doc-eyebrow">Admin · Setup</p>
        <h1 class="doc-title">Setup already completed</h1>
        <p class="lead">A staff account already exists. Go to <a href="/admin/login.php">Sign In</a> instead.</p>
    <?php
    require __DIR__ . '/../includes/layout-footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors['_form'] = 'Your session expired. Please try again.';
    } else {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');

        if ($name === '') $errors['name'] = 'Required';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address';
        if (strlen($password) < 12) $errors['password'] = 'Use at least 12 characters';
        if ($password !== $confirm) $errors['password_confirm'] = 'Passwords do not match';

        if (empty($errors)) {
            $pdo = db();
            // Re-check under the same request to avoid a race between two setup submissions.
            $count = (int) $pdo->query('SELECT COUNT(*) FROM staff_users')->fetchColumn();
            if ($count > 0) {
                $errors['_form'] = 'Setup has already been completed.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO staff_users (name, email, password_hash, role) VALUES (?,?,?,\'admin\')');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                audit('staff_account_created', 'staff_user', (int) $pdo->lastInsertId(), ['via' => 'setup'], 'system');
                header('Location: /admin/login.php?setup=done');
                exit;
            }
        }
    }
}

$pageTitle     = 'Setup — Kuronyx Admin';
$robotsNoindex = true;
$backHref      = '/';
$backLabel     = 'Back to site';
require __DIR__ . '/../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · One-time setup</p>
    <h1 class="doc-title">Create the first admin account</h1>
    <p class="lead">This page only works once, while no staff accounts exist yet.</p>

    <?php if (!empty($errors['_form'])): ?>
      <div class="alert"><?= htmlspecialchars($errors['_form'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token'] ?? '', ENT_QUOTES) ?>">
      <label class="field <?= isset($errors['name']) ? 'has-error' : '' ?>">
        <span class="lbl">Name</span>
        <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES) ?>" required>
      </label>
      <label class="field <?= isset($errors['email']) ? 'has-error' : '' ?>">
        <span class="lbl">Email</span>
        <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>" required>
      </label>
      <label class="field <?= isset($errors['password']) ? 'has-error' : '' ?>">
        <span class="lbl">Password (min. 12 characters)</span>
        <input type="password" name="password" required minlength="12">
      </label>
      <label class="field <?= isset($errors['password_confirm']) ? 'has-error' : '' ?>">
        <span class="lbl">Confirm password</span>
        <input type="password" name="password_confirm" required minlength="12">
      </label>
      <button type="submit" class="btn-primary" style="margin-top:1rem;">Create Admin Account</button>
    </form>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
