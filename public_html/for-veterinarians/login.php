<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

if (current_vet()) {
    header('Location: /for-veterinarians/portal/');
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
        // See admin/login.php - non-ASCII/malformed emails share one lockout bucket, no lookup.
        $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $identifier = 'vet:' . ($validEmail ? strtolower($email) : '(invalid-email)');
        $ip = client_ip();

        if ($email === '' || $password === '') {
            $errors['_form'] = 'Enter your email and password.';
        } elseif (rate_limited('login_ip:vet:' . $ip, LOGIN_IP_MAX_ATTEMPTS, LOGIN_LOCKOUT_MINUTES, false)) {
            // See admin/login.php — per-IP cap ahead of the per-account lockout, which on
            // its own can't see one source spraying across many different vet accounts.
            $errors['_form'] = 'Too many requests from this connection. Please try again later.';
        } else {
            // reserve_login_attempt() atomically checks the lockout count and reserves this
            // attempt as a failure in one transaction — see auth.php. A plain is_locked_out()
            // pre-check followed by a separate reserve/record call would let concurrent
            // requests all pass the check before any of them had recorded anything.
            $attemptId = reserve_login_attempt(login_identifier($identifier, $ip));

            if ($attemptId === null) {
                $errors['_form'] = 'Too many failed attempts. Please try again in ' . LOGIN_LOCKOUT_MINUTES . ' minutes.';
            } else {
                $vet = false;
                if ($validEmail) {
                    $stmt = db()->prepare(
                        "SELECT va.*, a.full_name FROM vet_accounts va
                         JOIN vet_applications a ON a.id = va.vet_application_id
                         WHERE va.email = ? LIMIT 1"
                    );
                    $stmt->execute([$email]);
                    $vet = $stmt->fetch();
                }

                if ($vet && $vet['status'] === 'active' && $vet['password_hash'] && password_verify($password, $vet['password_hash'])) {
                    mark_login_attempt_succeeded($attemptId);
                    gs_session_start();
                    session_regenerate_id(true);
                    // pw_fingerprint lets require_vet_login() detect a password reset and boot
                    // this session even though nothing else about the identity changed.
                    $_SESSION['vet'] = ['id' => (int) $vet['id'], 'email' => $vet['email'], 'full_name' => $vet['full_name'], 'vet_application_id' => (int) $vet['vet_application_id'], 'pw_fingerprint' => password_fingerprint($vet['password_hash'])];
                    audit('vet_login', 'vet_account', (int) $vet['id'], [], 'vet', (int) $vet['id']);
                    header('Location: /for-veterinarians/portal/');
                    exit;
                }

                if ($vet && !($vet['status'] === 'active' && $vet['password_hash'])) {
                    // Skipped password_verify() above (inactive / not yet activated) - same timing fix as admin/login.php.
                    password_verify($password, DUMMY_PASSWORD_HASH);
                }

                if ($vet) {
                    // See admin/login.php — only a real account's failed attempt is worth a
                    // permanent audit_log entry.
                    audit('vet_login_failed', 'vet_account', (int) $vet['id'], [], 'public');
                } else {
                    // See admin/login.php — equalizes response time against the
                    // password_verify() call above so a nonexistent email can't be told
                    // apart from a real one purely by how long the response takes.
                    password_verify($password, DUMMY_PASSWORD_HASH);
                }
                // Deliberately the same message whether the account doesn't exist, hasn't
                // been activated, is suspended, or the password was simply wrong —
                // distinguishing any of those would confirm an email has a real account.
                $errors['_form'] = 'Invalid email or password.';
            }
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
    <!-- Static, always-shown (not tied to whatever email was just submitted) so it can't
         be used to tell a real-but-pending account apart from a nonexistent one — the
         login error above is now deliberately the same message in both cases. -->
    <p style="margin-top:1rem; font-size:0.8125rem; color:var(--paper-3);">
      Recently approved and haven't set a password yet? Check your email for the activation link sent when your application was approved.
    </p>
    <p style="margin-top:1rem; font-size:0.8125rem; color:var(--paper-3);">
      Don't have an account yet? <a href="/for-veterinarians/apply" style="color:var(--paper); border-bottom:1px solid var(--paper-3);">Apply for a Veterinary Account</a>.
    </p>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
