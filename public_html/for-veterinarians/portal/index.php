<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
$vet = require_vet_login();

$stmt = db()->prepare(
    'SELECT id, patient_name, requested_formulation, status, created_at, updated_at
     FROM gs_requests WHERE vet_account_id = ? ORDER BY created_at DESC'
);
$stmt->execute([$vet['id']]);
$rows = $stmt->fetchAll();

$pageTitle     = 'Veterinary Portal — Kuronyx Sciences';
$robotsNoindex = true;
$activeNav     = 'for-veterinarians';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Veterinary portal · <?= htmlspecialchars($vet['full_name'], ENT_QUOTES) ?></p>
    <h1 class="doc-title">Your GS-441524 requests</h1>
    <p class="lead" style="margin-bottom:1.5rem;">
      <a href="/for-veterinarians/portal/logout.php" style="color:var(--paper); border-bottom:1px solid var(--paper-3);">Sign out</a>
    </p>

    <a href="/for-veterinarians/portal/new-request.php" class="btn-primary" style="margin-bottom:1.75rem; display:inline-flex;">New Request</a>

    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>ID</th><th>Patient</th><th>Formulation</th><th>Status</th><th>Submitted</th><th>Updated</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6">You haven't submitted any requests yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><a href="/for-veterinarians/portal/view.php?id=<?= (int) $r['id'] ?>">GS-<?= (int) $r['id'] ?></a></td>
            <td><?= htmlspecialchars($r['patient_name'], ENT_QUOTES) ?></td>
            <td><?= ucfirst($r['requested_formulation']) ?></td>
            <td><span class="status-pill status-<?= htmlspecialchars($r['status'], ENT_QUOTES) ?>"><?= ucwords(str_replace('_', ' ', $r['status'])) ?></span></td>
            <td><?= htmlspecialchars(fmt_time($r['created_at']), ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars(fmt_time($r['updated_at']), ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
