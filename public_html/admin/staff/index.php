<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
$staff = require_role(['admin']);

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'err', 'text' => 'Your session expired. Please try again.'];
    } else {
        $targetId = (int) ($_POST['staff_id'] ?? 0);
        if ($targetId === (int) $staff['id']) {
            $flash = ['type' => 'err', 'text' => 'You cannot deactivate your own account.'];
        } else {
            $current = $pdo->prepare('SELECT active FROM staff_users WHERE id = ?');
            $current->execute([$targetId]);
            $currentActive = $current->fetchColumn();
            if ($currentActive === false) {
                $flash = ['type' => 'err', 'text' => 'Staff account not found.'];
            } else {
                $newActive = (int) $currentActive === 1 ? 0 : 1;
                $pdo->prepare('UPDATE staff_users SET active = ? WHERE id = ?')->execute([$newActive, $targetId]);
                audit($newActive ? 'staff_reactivated' : 'staff_deactivated', 'staff_user', $targetId, []);
                $flash = ['type' => 'ok', 'text' => $newActive ? 'Account reactivated.' : 'Account deactivated — they will be signed out on their next request.'];
            }
        }
    }
}

$rows = $pdo->query('SELECT id, name, email, role, active, created_at FROM staff_users ORDER BY created_at ASC')->fetchAll();

$pageTitle     = 'Staff Accounts — Kuronyx Admin';
$robotsNoindex = true;
$backHref      = '/admin/gs-requests/';
$backLabel     = 'GS-441524 requests';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · Staff accounts</p>
    <h1 class="doc-title">Staff Accounts</h1>
    <?php $activeAdminNav = 'staff'; require __DIR__ . '/../../includes/admin-nav.php'; ?>

    <?php if (!empty($_GET['created'])): ?>
      <div class="alert alert-ok">Staff account created.</div>
    <?php endif; ?>
    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : '' ?>"><?= htmlspecialchars($flash['text'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <a href="/admin/staff/new.php" class="btn-primary" style="margin-bottom:1.5rem; display:inline-flex;">New Staff Account</a>

    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['name'], ENT_QUOTES) ?><?= (int) $r['id'] === (int) $staff['id'] ? ' (you)' : '' ?></td>
            <td><?= htmlspecialchars($r['email'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $r['role'])), ENT_QUOTES) ?></td>
            <td><span class="status-pill <?= (int) $r['active'] === 1 ? 'status-approved' : 'status-rejected' ?>"><?= (int) $r['active'] === 1 ? 'Active' : 'Deactivated' ?></span></td>
            <td><?= htmlspecialchars(fmt_time($r['created_at']), ENT_QUOTES) ?></td>
            <td>
              <?php if ((int) $r['id'] !== (int) $staff['id']): ?>
                <form method="POST" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="staff_id" value="<?= (int) $r['id'] ?>">
                  <button type="submit" class="btn-secondary" style="padding:0.4rem 0.8rem; font-size:0.625rem;"><?= (int) $r['active'] === 1 ? 'Deactivate' : 'Reactivate' ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
