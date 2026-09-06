<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
$staff = require_login();

$status = $_GET['status'] ?? '';
$where  = [];
$params = [];
if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }

$sql = 'SELECT id, full_name, clinic_name, registration_number, status, created_at FROM vet_applications';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY created_at DESC LIMIT 200';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$statuses = ['pending','under_review','approved','rejected','suspended'];

$pageTitle     = 'Veterinary Applications — Kuronyx Admin';
$robotsNoindex = true;
$backHref      = '/admin/gs-requests/';
$backLabel     = 'GS-441524 requests';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · <?= htmlspecialchars($staff['name'], ENT_QUOTES) ?></p>
    <h1 class="doc-title">Veterinary Applications</h1>

    <form method="GET" class="filter-bar">
      <label class="field">
        <span class="lbl">Status</span>
        <select name="status">
          <option value="">All</option>
          <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucwords($s) ?></option>
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
            <td><span class="status-pill status-<?= htmlspecialchars($r['status'], ENT_QUOTES) ?>"><?= ucwords($r['status']) ?></span></td>
            <td><?= htmlspecialchars($r['created_at'], ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
