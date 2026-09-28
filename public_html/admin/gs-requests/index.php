<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
$staff = require_login();

$status      = $_GET['status'] ?? '';
$source      = $_GET['source'] ?? '';
$formulation = $_GET['formulation'] ?? '';
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
$sql .= ' ORDER BY created_at DESC LIMIT 200';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$statuses = ['submitted','under_review','awaiting_information','communication_in_progress','formulation_discussion','approved','compounding','ready_for_dispatch','dispatched','finished','closed','cancelled','rejected'];

$pageTitle     = 'GS-441524 Requests — Kuronyx Admin';
$robotsNoindex = true;
$backHref      = '/admin/gs-requests/';
$backLabel     = 'Admin';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · <?= htmlspecialchars($staff['name'], ENT_QUOTES) ?> (<?= htmlspecialchars($staff['role'], ENT_QUOTES) ?>)</p>
    <h1 class="doc-title">GS-441524 Requests</h1>
    <?php $activeAdminNav = 'gs-requests'; require __DIR__ . '/../../includes/admin-nav.php'; ?>

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
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
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
            <td><span class="status-pill status-<?= htmlspecialchars($r['status'], ENT_QUOTES) ?>"><?= ucwords(str_replace('_', ' ', $r['status'])) ?></span></td>
            <td><?= htmlspecialchars(fmt_time($r['created_at']), ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars(fmt_time($r['updated_at']), ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
