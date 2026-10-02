<?php
// ONE-TIME first-admin creation page. DELIBERATELY NOT under public_html/, so the deploy workflow
// never ships it. To create the first admin on a server:
//   1. Make sure SETUP_TOKEN (a long random value) is set in that server's db-config.php.
//   2. Upload this file to public_html/admin/setup.php by hand (File Manager / FTP).
//   3. Open /admin/setup.php?token=<SETUP_TOKEN>, create the account.
//   4. Delete public_html/admin/setup.php AND SETUP_TOKEN from the server again.
// (public_html/admin/setup.php is gitignored, so a stray copy can't be committed.) The tests copy
// this file there for the duration of a run.
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

// This page creates the first admin account with no login required, so an empty
// staff_users table alone is not enough of a gate — this URL is permanent and
// predictable, and staff_users could in principle become empty again later.
// SETUP_TOKEN must be set in db-config.php (a long random value, not committed)
// and is checked before anything else runs, on both GET and POST.
if (!defined('SETUP_TOKEN') || SETUP_TOKEN === '' || !hash_equals(SETUP_TOKEN, (string) ($_GET['token'] ?? $_POST['token'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden.');
}

// Must run before any HTML output so the session cookie ships with the first
// response headers — csrf_field() alone (called later, inside the template)
// is too late and silently breaks CSRF verification on every submission.
csrf_token();

// setup_lock (not staff_users) is the authoritative "has setup happened" signal — see
// the POST handler below for why. Checking it here too (not just staff_users) means this
// message stays correct even in the edge case the file's own original comment already
// worried about: staff_users becoming empty again later wouldn't reopen this page.
$alreadySetUp = (int) db()->query('SELECT COUNT(*) FROM setup_lock')->fetchColumn() > 0
    || (int) db()->query('SELECT COUNT(*) FROM staff_users')->fetchColumn() > 0;
$errors = [];

if ($alreadySetUp) {
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
        elseif (mb_strlen($name) > 150) $errors['name'] = 'Must be 150 characters or fewer';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address';
        elseif (mb_strlen($email) > 190) $errors['email'] = 'Must be 190 characters or fewer';
        if (strlen($password) < 12) $errors['password'] = 'Use at least 12 characters';
        elseif (strlen($password) > PASSWORD_MAX_BYTES) $errors['password'] = 'Use at most 72 characters';
        if ($password !== $confirm) $errors['password_confirm'] = 'Passwords do not match';

        if (empty($errors)) {
            $pdo = db();
            // Claiming this fixed-PRIMARY-KEY row and creating the admin account happen in
            // one transaction — previously two separate statements, which meant that if the
            // staff_users insert failed for *any* reason (a length the length checks above
            // didn't catch, a transient DB error), the setup_lock row had already committed
            // on its own, permanently locking this page with no admin ever created and no
            // way back in except editing the database by hand. Now either both commit
            // together or neither does, so a failed attempt can always just be retried.
            // Claiming the fixed-PRIMARY-KEY setup_lock row is what actually makes this
            // concurrency-safe: a PRIMARY KEY violation is always atomic and mutually
            // exclusive at the database level. A plain "is staff_users empty?" check (even
            // as one SQL statement) isn't enough — under MySQL's default non-locking reads,
            // two concurrent requests can both see an empty table and both proceed.
            try {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO setup_lock (id) VALUES (1)')->execute();

                // Belt-and-braces alongside the lock above: if an already-provisioned
                // database was migrated to add setup_lock without also seeding it (see that
                // migration's own comment), this stops a second admin being created regardless.
                if ((int) $pdo->query('SELECT COUNT(*) FROM staff_users')->fetchColumn() > 0) {
                    $pdo->rollBack();
                    $errors['_form'] = 'Setup has already been completed.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO staff_users (name, email, password_hash, role) VALUES (?,?,?,\'admin\')');
                    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                    $newId = (int) $pdo->lastInsertId();
                    $pdo->commit();
                    audit('staff_account_created', 'staff_user', $newId, ['via' => 'setup'], 'system');
                    header('Location: /admin/login.php?setup=done');
                    exit;
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                if ($e->getCode() === '23000') {
                    // The setup_lock row already existed — a concurrent request got there
                    // first. Nothing was left half-done, since the whole attempt (including
                    // the lock claim) just rolled back together.
                    $errors['_form'] = 'Setup has already been completed.';
                } else {
                    // A genuine, unexpected failure — surface it properly rather than
                    // mislabeling every possible error as "already completed", which would
                    // hide a real problem (and the rollback above means it's still safe to
                    // just try again).
                    throw $e;
                }
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
