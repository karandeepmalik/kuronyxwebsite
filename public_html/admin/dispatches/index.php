<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/journal.php';
// Admin-only, like the audit log, data erasure and staff pages: publishing and deleting public site content
// is an admin decision (the only roles are admin and pharmacy_staff - there is no separate editor role).
$staff = require_role(['admin']);

// The outcome of a delete (set by edit.php, which redirects here), shown once.
$flash = null;
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['dispatch_flash'])) {
    $flash = $_SESSION['dispatch_flash'];
    unset($_SESSION['dispatch_flash']);
}

// Paged (Newer / Older, like the audit log and the case lists). It used to stop silently at 200 rows: past
// that, the oldest dispatches vanished from the admin and could no longer be found to edit. One extra row
// is fetched to know whether an "Older" page exists. id DESC breaks ties between rows with the same
// updated_at (one-second resolution), so a row can't repeat or go missing across pages.
$page = list_page();
$rows = db()->query(
    'SELECT d.id, d.title, d.slug, d.issue_number, d.status, d.published_at, d.updated_at, s.name AS author_name
     FROM dispatches d LEFT JOIN staff_users s ON s.id = d.author_staff_id
     ORDER BY d.updated_at DESC, d.id DESC LIMIT ' . (LIST_PAGE_SIZE + 1) . ' OFFSET ' . (($page - 1) * LIST_PAGE_SIZE)
)->fetchAll();
$hasMore = count($rows) > LIST_PAGE_SIZE;
$rows    = array_slice($rows, 0, LIST_PAGE_SIZE);

$pageTitle     = 'Journal | Kuronyx admin';
$robotsNoindex = true;
$activeNav     = 'dispatches';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · <?= htmlspecialchars($staff['name'], ENT_QUOTES) ?></p>
    <h1 class="doc-title">Journal</h1>
    <?php $activeAdminNav = 'dispatches'; require __DIR__ . '/../../includes/admin-nav.php'; ?>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : '' ?>"><?= htmlspecialchars($flash['text'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <a href="/admin/dispatches/edit.php" class="btn-primary page-action">New issue</a>

    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Title</th><th>Issue</th><th>Status</th><th>Created by</th><th>Published</th><th>Updated</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6"><?= $page > 1 ? 'There are no issues on this page.' : 'No issues yet.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            // published_at is kept when an issue is taken down (so republishing doesn't re-date it), which would
            // otherwise show a "Draft" with a publication date beside it. A draft that HAS been published is
            // "Unpublished"; one that never was stays "Draft".
            $statusLabel = $r['status'] === 'published' ? 'Published' : (($r['published_at'] ?? null) !== null ? 'Unpublished' : 'Draft');
        ?>
          <tr>
            <td>
              <a href="/admin/dispatches/edit.php?id=<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['title'], ENT_QUOTES) ?></a>
              <?php if ($r['status'] === 'published'): // only a published issue has a public page; a draft's address is a 404 ?>
                <a class="live-link" href="/journal/view.php?slug=<?= urlencode($r['slug']) ?>" target="_blank" rel="noopener" aria-label="View &quot;<?= htmlspecialchars($r['title'], ENT_QUOTES) ?>&quot; on the live site (opens in a new tab)">View live ↗</a>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars(journal_issue_label($r['issue_number'] === null ? null : (int) $r['issue_number']) ?: '-', ENT_QUOTES) ?></td>
            <td><span class="status-pill <?= $r['status'] === 'published' ? 'status-published' : '' ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES) ?></span></td>
            <td><?= htmlspecialchars($r['author_name'] ?? '-', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars(fmt_time($r['published_at'] ?? null), ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars(fmt_time($r['updated_at']), ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= list_pager($page, $hasMore) ?>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
