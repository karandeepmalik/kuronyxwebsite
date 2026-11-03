<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
// POST + CSRF only - see admin/logout.php. A request that is not a valid sign-out used to bounce back to the portal with no
// word, so the vet was still signed in and could not tell (a Karandeep review comment: on a shared clinic computer the next
// person would see their patients' cases). Now, if someone IS signed in, they get a plain page that says so and a fresh
// Log out button; a page visit alone still signs nobody out, so a link or image on another site cannot do it.
$vet = current_vet();
$validSignOut = $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify();
if (!$validSignOut) {
    if (!$vet) {
        header('Location: /for-veterinarians/login.php'); // nobody signed in: nothing to log out of
        exit;
    }
    send_private_page_headers();
    $failedAttempt = $_SERVER['REQUEST_METHOD'] === 'POST';
    $pageTitle     = 'Log out | Kuronyx';
    $robotsNoindex = true;
    $activeNav     = 'for-veterinarians';
    $backHref      = '/for-veterinarians/portal/';
    $backLabel     = 'Back to your requests';
    require __DIR__ . '/../../includes/layout-header.php';
    ?>
    <p class="doc-eyebrow">Veterinary portal</p>
    <h1 class="doc-title">Log out</h1>
    <?php if ($failedAttempt): ?>
      <div class="alert">We couldn't log you out, so your session is still open. This usually happens when the page was left open from an earlier visit. Please try again.</div>
    <?php else: ?>
      <p class="lead">You are still logged in. Use the button to log out.</p>
    <?php endif; ?>
    <form method="POST" action="/for-veterinarians/portal/logout.php">
      <?= csrf_field() ?>
      <button type="submit" class="btn-primary">Log out</button>
    </form>
    <?php
    require __DIR__ . '/../../includes/layout-footer.php';
    exit;
}
if ($vet) {
    audit('vet_logout', 'vet_account', $vet['id'], [], 'vet', (int) $vet['id']);
}
sign_out_and_clear_browser_cache(); // was: unset($_SESSION['vet']) only - the session and its cookie stayed behind
header('Location: /for-veterinarians/login.php');
exit;
