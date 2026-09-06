<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
$staff = require_role(['admin']);

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 100;
$offset  = ($page - 1) * $perPage;

$stmt = db()->prepare(
    'SELECT a.*, s.name AS staff_name FROM audit_log a LEFT JOIN staff_users s ON s.id = a.actor_id AND a.actor_type = \'staff\'
     ORDER BY a.created_at DESC LIMIT ? OFFSET ?'
);
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$pageTitle     = 'Audit Log — Kuronyx Admin';
$robotsNoindex = true;
$backHref      = '/admin/gs-requests/';
$backLabel     = 'GS-441524 requests';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · Audit log</p>
    <h1 class="doc-title">Audit Log</h1>

    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Details</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="5">No audit entries yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['created_at'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['staff_name'] ?? ucfirst($r['actor_type']), ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['action'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['entity_type'], ENT_QUOTES) ?><?= $r['entity_id'] ? ' #' . (int) $r['entity_id'] : '' ?></td>
            <td style="font-family:var(--mono); font-size:0.6875rem; color:var(--paper-4);"><?= htmlspecialchars($r['metadata_json'] ?? '', ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <div style="display:flex; gap:1rem; margin-top:1.5rem;">
      <?php if ($page > 1): ?><a class="btn-secondary" href="?page=<?= $page - 1 ?>">Newer</a><?php endif; ?>
      <?php if (count($rows) === $perPage): ?><a class="btn-secondary" href="?page=<?= $page + 1 ?>">Older</a><?php endif; ?>
    </div>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
