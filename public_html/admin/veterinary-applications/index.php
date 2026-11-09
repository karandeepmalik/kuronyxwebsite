<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
$staff = require_login();

require_once __DIR__ . '/../../includes/case-statuses.php';
$statuses = VET_APPLICATION_STATUSES; // the single shared list - see includes/case-statuses.php

// Only a known status is accepted; a typo (?status=aproved) is ignored and reported in a note, instead of
// silently matching nothing and showing an empty list with the dropdown reading "All".
$ignoredFilters = [];
$status = list_filter('status', $statuses, $ignoredFilters);
$where  = [];
$params = [];
if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }

$sql = 'SELECT id, full_name, clinic_name, registration_number, status, created_at FROM vet_applications';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$page = list_page();
// id DESC breaks ties: created_at only has 1-second resolution, so rows from one burst share
// a timestamp and, without a unique tiebreaker, LIMIT/OFFSET can repeat or skip them across pages.
$sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . (LIST_PAGE_SIZE + 1) . ' OFFSET ' . (($page - 1) * LIST_PAGE_SIZE);

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$hasMore = count($rows) > LIST_PAGE_SIZE;
$rows = array_slice($rows, 0, LIST_PAGE_SIZE);

$pageTitle     = 'Veterinary Applications - Kuronyx Admin';
$robotsNoindex = true;
$backHref      = '/admin/gs-requests/';
$backLabel     = 'GS-441524 requests';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · <?= htmlspecialchars($staff['name'], ENT_QUOTES) ?></p>
    <h1 class="doc-title">Veterinary Applications</h1>
    <?php $activeAdminNav = 'vet-applications'; require __DIR__ . '/../../includes/admin-nav.php'; ?>

    <?= list_ignored_filters_notice($ignoredFilters) ?>

    <form method="GET" class="filter-bar">
      <label class="field">
        <span class="lbl">Status</span>
        <select name="status">
          <option value="">All</option>
          <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= status_label($s) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn-secondary">Filter</button>
    </form>

    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>ID</th><th>Applicant</th><th>Clinic</th><th>Registration #</th><th>Status</th><th>Submitted</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6">No applications match these filters.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><a href="/admin/veterinary-applications/view.php?id=<?= (int) $r['id'] ?>">VA-<?= (int) $r['id'] ?></a></td>
            <td><?= htmlspecialchars($r['full_name'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['clinic_name'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['registration_number'], ENT_QUOTES) ?></td>
            <td><span class="status-pill status-<?= htmlspecialchars($r['status'], ENT_QUOTES) ?>"><?= htmlspecialchars(status_label($r['status']), ENT_QUOTES) ?></span></td>
            <td><?= htmlspecialchars(fmt_time($r['created_at']), ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= list_pager($page, $hasMore) ?>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
