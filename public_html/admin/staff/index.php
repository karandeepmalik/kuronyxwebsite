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
        $targetId  = (int) ($_POST['staff_id'] ?? 0);
        // The button itself carries the explicit intended end state (0 or 1), set from
        // whatever the row's status actually was when this page was rendered — not a
        // "1 - active" flip computed from a value read earlier in this same request. A
        // flip has no memory of *intent*: if two admins both click "Deactivate" on the
        // same person at nearly the same moment, each flip just inverts whatever it finds,
        // so the second one silently reactivates the account the first had just turned
        // off. An explicit target value makes a duplicate click a safe no-op instead.
        $setActive = (int) ($_POST['set_active'] ?? -1);

        if ($targetId === (int) $staff['id']) {
            $flash = ['type' => 'err', 'text' => 'You cannot deactivate your own account.'];
        } elseif ($setActive !== 0 && $setActive !== 1) {
            $flash = ['type' => 'err', 'text' => 'Invalid request.'];
        } else {
            $flash = run_serialized_transaction($pdo, function (PDO $pdo) use ($targetId, $setActive) {
                $target = $pdo->prepare(
                    'SELECT id, role, active FROM staff_users WHERE id = ?' . locking_read_suffix()
                );
                $target->execute([$targetId]);
                $target = $target->fetch();

                if (!$target) {
                    return ['type' => 'err', 'text' => 'Staff account not found.'];
                }
                if ((int) $target['active'] === $setActive) {
                    // Already in the desired state — a second "Deactivate" click after
                    // someone else's has already landed, say. Not an error.
                    return ['type' => 'ok', 'text' => $setActive
                        ? 'Account is already active.'
                        : 'Account is already deactivated.'];
                }
                if ($setActive === 0 && $target['role'] === 'admin') {
                    // Deactivating an admin — refuse if it would leave none. Two admins
                    // deactivating *each other* at the same moment would otherwise both
                    // individually look safe (each still sees the other as currently
                    // active right up until this check) and leave zero admins able to
                    // sign in, with setup_lock already claimed so admin/setup.php can't
                    // be used to recover — the only way back in would be editing the
                    // database by hand. Locking every other active-admin row here (the
                    // FOR UPDATE suffix on a MySQL target) means a concurrent deactivation
                    // of a *different* admin can't also pass this same count check before
                    // this one commits.
                    $others = $pdo->prepare(
                        "SELECT COUNT(*) FROM staff_users WHERE role = 'admin' AND active = 1 AND id != ?"
                        . locking_read_suffix()
                    );
                    $others->execute([$targetId]);
                    if ((int) $others->fetchColumn() === 0) {
                        return ['type' => 'err', 'text' => 'Cannot deactivate the last active admin account.'];
                    }
                }

                $pdo->prepare('UPDATE staff_users SET active = ? WHERE id = ?')->execute([$setActive, $targetId]);
                audit($setActive ? 'staff_reactivated' : 'staff_deactivated', 'staff_user', $targetId, []);
                return ['type' => 'ok', 'text' => $setActive
                    ? 'Account reactivated.'
                    : 'Account deactivated — they will be signed out on their next request.'];
            });
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
                  <input type="hidden" name="set_active" value="<?= (int) $r['active'] === 1 ? 0 : 1 ?>">
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
