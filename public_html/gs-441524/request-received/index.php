<?php
require __DIR__ . '/../../includes/auth.php';
gs_session_start();

$kind = $_SESSION['gs_request_received'] ?? null;
unset($_SESSION['gs_request_received']);

if (!$kind) {
    header('Location: /gs-441524');
    exit;
}

$pageTitle       = 'Request Received — GS-441524 | Kuronyx Sciences';
$pageDescription = 'Your GS-441524 request has been received and will be reviewed by the Kuronyx team.';
$robotsNoindex   = true;
$backHref        = '/gs-441524';
$backLabel       = 'Back to GS-441524';
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">GS-441524 · Request received</p>
    <h1 class="doc-title">Thank you. Your request has been received.</h1>
    <p class="doc-meta">Kuronyx Sciences<span class="sep">·</span>Under review</p>

    <?php if ($kind === 'vet_application'): ?>
      <p class="lead">
        Your veterinary account application has been received. Your account is currently under review by Kuronyx.
        You will receive an email once your application has been reviewed.
      </p>
    <?php else: ?>
      <p class="lead">
        Our team will review the prescription and information submitted. A member of the Kuronyx team will contact
        you by email regarding the next steps.
      </p>
    <?php endif; ?>

    <div class="notice">
      <span class="notice-label">What happens next</span>
      <p>
        This confirmation does not mean the request has been approved, priced, or scheduled for compounding.
        Every request is reviewed individually before any formulation or pricing decision is made.
      </p>
    </div>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
