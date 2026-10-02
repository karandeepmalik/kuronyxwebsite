<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

if (current_staff()) {
    header('Location: /admin/gs-requests/');
    exit;
}

// Must run before any HTML output so this area's session cookie ships with the first response
// headers (current_staff()/current_vet() above no longer start a session for a visitor who has none).
csrf_token();

$errors = [];
$emailOld = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors['_form'] = 'Your session expired. Please try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $emailOld = $email;
        // Anything that isn't a plain ASCII email can't be a real account, but MySQL's
        // accent-insensitive collation would still match it to one - each spelling would then
        // get its own lockout bucket. Such input shares one bucket and skips the lookup.
        $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $identifier = $validEmail ? strtolower($email) : '(invalid-email)';
        $ip = client_ip();

        if ($email === '' || $password === '') {
            $errors['_form'] = 'Enter your email and password.';
        } elseif (rate_limited('login_ip:' . $ip, LOGIN_IP_MAX_ATTEMPTS, LOGIN_LOCKOUT_MINUTES, false)) {
            // Per-IP cap, checked before any per-account work — the per-email lockout
            // below has no visibility into one source spraying attempts across many
            // different accounts (each account's own counter looks fine in isolation).
            // See LOGIN_IP_MAX_ATTEMPTS in auth.php for why this is deliberately generous.
            $errors['_form'] = 'Too many requests from this connection. Please try again later.';
        } else {
            // reserve_login_attempt() atomically checks the lockout count and reserves
            // this attempt as a failure in one transaction — see auth.php. A plain
            // is_locked_out() pre-check followed by a separate reserve/record call would
            // let concurrent requests all pass the check before any of them had recorded
            // anything.
            $attemptId = reserve_login_attempt(login_identifier($identifier, $ip));

            if ($attemptId === null) {
                $errors['_form'] = 'Too many failed attempts. Please try again in ' . LOGIN_LOCKOUT_MINUTES . ' minutes.';
            } else {
                $staff = false;
                if ($validEmail) {
                    $stmt = db()->prepare('SELECT * FROM staff_users WHERE email = ? LIMIT 1');
                    $stmt->execute([$email]);
                    $staff = $stmt->fetch();
                }

                if ($staff && (int) $staff['active'] === 1 && password_verify($password, $staff['password_hash'])) {
                    mark_login_attempt_succeeded($attemptId);
                    gs_session_start();
                    session_regenerate_id(true);
                    // pw_fingerprint lets require_login() detect a password reset and boot this
                    // session even though nothing else about the identity changed — see auth.php.
                    $_SESSION['staff'] = ['id' => (int) $staff['id'], 'name' => $staff['name'], 'email' => $staff['email'], 'role' => $staff['role'], 'pw_fingerprint' => password_fingerprint($staff['password_hash'])];
                    audit('staff_login', 'staff_user', (int) $staff['id'], []);
                    header('Location: /admin/gs-requests/');
                    exit;
                }

                if ($staff && (int) $staff['active'] !== 1) {
                    // A deactivated account skipped password_verify() above (short-circuit), so
                    // run the same bcrypt work here or its response time gives it away.
                    password_verify($password, DUMMY_PASSWORD_HASH);
                }

                if ($staff) {
                    // Only a real, known account's failed attempt is worth an audit-log
                    // entry — auditing every guess against a nonexistent email would let a
                    // scripted attacker grow this permanent record without bound (unlike
                    // login_attempts, which has its own opportunistic cleanup purely for
                    // lockout bookkeeping, audit_log is kept forever by design, so it
                    // shouldn't absorb bot noise in the first place).
                    audit('staff_login_failed', 'staff_user', (int) $staff['id'], [], 'public');
                } else {
                    // Equalizes response time against the "account exists, wrong password"
                    // branch above, which calls password_verify() (a deliberately slow,
                    // constant-ish-time bcrypt comparison) — without this, a nonexistent
                    // email returns near-instantly while a real one takes measurably
                    // longer, letting an attacker probe which emails have accounts purely
                    // from response time, even with an identical error message.
                    password_verify($password, DUMMY_PASSWORD_HASH);
                }
                // Deliberately the same message whether the account doesn't exist, is
                // deactivated, or the password was simply wrong — distinguishing
                // "deactivated" from "invalid" would otherwise confirm an email belongs to
                // a real (if disabled) account.
                $errors['_form'] = 'Invalid email or password.';
            }
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
