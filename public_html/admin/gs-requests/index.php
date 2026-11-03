<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
$staff = require_login();

require_once __DIR__ . '/../../includes/case-statuses.php';
$statuses = GS_CASE_STATUSES; // the single shared list - see includes/case-statuses.php

// Only known values are accepted for the three dropdown filters; anything else (a typo such as
// ?status=aproved) is ignored and reported in a note, instead of silently matching nothing.
$ignoredFilters = [];
$status      = list_filter('status', $statuses, $ignoredFilters);
$source      = list_filter('source', ['veterinarian', 'cat_owner'], $ignoredFilters);
$formulation = list_filter('formulation', ['injection', 'oral'], $ignoredFilters);
$q           = trim($_GET['q'] ?? '');

$where  = [];
$params = [];
if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }
if ($source !== '') { $where[] = 'source = ?'; $params[] = $source; }
if ($formulation !== '') { $where[] = 'requested_formulation = ?'; $params[] = $formulation; }
if ($q !== '') {
    $where[] = '(owner_full_name LIKE ? OR patient_name LIKE ? OR vet_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$sql = 'SELECT id, source, owner_full_name, patient_name, requested_formulation, status, created_at, updated_at FROM gs_requests';
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

$pageTitle     = 'GS-441524 Requests - Kuronyx Admin';
$robotsNoindex = true;
$backHref      = '/'; // this page IS the admin home (admin/index.php redirects here), so no Back button - '/' hides it
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · <?= htmlspecialchars($staff['name'], ENT_QUOTES) ?> (<?= htmlspecialchars($staff['role'], ENT_QUOTES) ?>)</p>
    <h1 class="doc-title">GS-441524 Requests</h1>
    <?php $activeAdminNav = 'gs-requests'; require __DIR__ . '/../../includes/admin-nav.php'; ?>

    <?= list_ignored_filters_notice($ignoredFilters) ?>

    <form method="GET" class="filter-bar">
      <label class="field">
        <span class="lbl">Search</span>
        <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="Owner, patient or vet name">
      </label>
      <label class="field">
        <span class="lbl">Status</span>
        <select name="status">
          <option value="">All</option>
          <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= status_label($s) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="lbl">Source</span>
        <select name="source">
          <option value="">All</option>
          <option value="veterinarian" <?= $source === 'veterinarian' ? 'selected' : '' ?>>Veterinarian</option>
          <option value="cat_owner" <?= $source === 'cat_owner' ? 'selected' : '' ?>>Cat owner</option>
        </select>
      </label>
      <label class="field">
        <span class="lbl">Formulation</span>
        <select name="formulation">
          <option value="">All</option>
          <option value="injection" <?= $formulation === 'injection' ? 'selected' : '' ?>>Injection</option>
          <option value="oral" <?= $formulation === 'oral' ? 'selected' : '' ?>>Oral</option>
        </select>
      </label>
      <button type="submit" class="btn-secondary">Filter</button>
    </form>

    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr>
          <th>ID</th><th>Source</th><th>Owner</th><th>Patient</th><th>Formulation</th><th>Status</th><th>Created</th><th>Updated</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8">No requests match these filters.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><a href="/admin/gs-requests/view.php?id=<?= (int) $r['id'] ?>">GS-<?= (int) $r['id'] ?></a></td>
            <td><?= $r['source'] === 'cat_owner' ? 'Cat owner' : 'Veterinarian' ?></td>
            <td><?= htmlspecialchars($r['owner_full_name'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['patient_name'], ENT_QUOTES) ?></td>
            <td><?= ucfirst($r['requested_formulation']) ?></td>
            <td><span class="status-pill status-<?= htmlspecialchars($r['status'], ENT_QUOTES) ?>"><?= htmlspecialchars(status_label($r['status']), ENT_QUOTES) ?></span></td>
            <td><?= htmlspecialchars(fmt_time($r['created_at']), ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars(fmt_time($r['updated_at']), ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= list_pager($page, $hasMore) ?>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
