<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
$staff = require_role(['admin']);

$page    = list_page(); // same page parsing (and cap) as every other list
$perPage = 100;
$offset  = ($page - 1) * $perPage;

// actor_name is the name saved on the row when it was written. Rows from before that column
// existed have none, so those fall back to the account's current name (staff_users, or the vet's
// application for a vet account), and finally to "Staff #12" / "Vet #5" - the id is stored even
// when the name can't be found. 'public' rows have no account behind them (an anonymous visitor).
$stmt = db()->prepare(
    'SELECT a.*, s.name AS staff_name, vapp.full_name AS vet_name
     FROM audit_log a
     LEFT JOIN staff_users s ON s.id = a.actor_id AND a.actor_type = \'staff\'
     LEFT JOIN vet_accounts va ON va.id = a.actor_id AND a.actor_type = \'vet\'
     LEFT JOIN vet_applications vapp ON vapp.id = va.vet_application_id
     ORDER BY a.created_at DESC, a.id DESC LIMIT ? OFFSET ?'
);
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$pageTitle     = 'Audit Log - Kuronyx Admin';
$robotsNoindex = true;
$backHref      = '/admin/gs-requests/';
$backLabel     = 'GS-441524 requests';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · Audit log</p>
    <h1 class="doc-title">Audit Log</h1>
    <?php $activeAdminNav = 'audit-log'; require __DIR__ . '/../../includes/admin-nav.php'; ?>

    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Details</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="5">No audit entries yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars(fmt_time($r['created_at']), ENT_QUOTES) ?></td>
            <?php
              $actor = $r['actor_name'] ?? $r['staff_name'] ?? $r['vet_name'] ?? null;
              if ($actor === null) {
                  $actor = ucfirst($r['actor_type']) . ($r['actor_id'] ? ' #' . (int) $r['actor_id'] : '');
              }
            ?>
            <td><?= htmlspecialchars($actor, ENT_QUOTES) ?></td>
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
