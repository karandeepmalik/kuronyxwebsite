<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
$staff = require_login();

$rows = db()->query(
    'SELECT d.id, d.title, d.slug, d.status, d.published_at, d.updated_at, s.name AS author_name
     FROM dispatches d LEFT JOIN staff_users s ON s.id = d.author_staff_id
     ORDER BY d.updated_at DESC LIMIT 200'
)->fetchAll();

$pageTitle     = 'Dispatches — Kuronyx Admin';
$robotsNoindex = true;
$activeNav     = 'dispatches';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · <?= htmlspecialchars($staff['name'], ENT_QUOTES) ?></p>
    <h1 class="doc-title">Dispatches</h1>
    <?php $activeAdminNav = 'dispatches'; require __DIR__ . '/../../includes/admin-nav.php'; ?>

    <a href="/admin/dispatches/edit.php" class="btn-primary" style="margin-bottom:1.5rem; display:inline-flex;">New Dispatch</a>

    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Title</th><th>Status</th><th>Author</th><th>Published</th><th>Updated</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="5">No dispatches yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><a href="/admin/dispatches/edit.php?id=<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['title'], ENT_QUOTES) ?></a></td>
            <td><span class="status-pill <?= $r['status'] === 'published' ? 'status-approved' : '' ?>"><?= ucfirst($r['status']) ?></span></td>
            <td><?= htmlspecialchars($r['author_name'] ?? '—', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars(fmt_time($r['published_at'] ?? null), ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars(fmt_time($r['updated_at']), ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
