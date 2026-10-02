<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/data-protection.php';
$staff = require_role(['admin']);

$pdo   = db();
$flash = null;

// Permanently erases one case's personal data — see erase_gs_request(). Irreversible, so it takes
// the case id typed in full plus the word ERASE, on top of the usual CSRF check; the per-row
// buttons below fill both in and ask for a browser confirmation instead. Erasing is idempotent
// (a second attempt just reports "already erased"), so no one-time token is needed.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'err', 'text' => 'Your session expired. Please try again.'];
    } else {
        $caseId = (int) ($_POST['case_id'] ?? 0);
        if ($caseId <= 0) {
            $flash = ['type' => 'err', 'text' => 'Enter a valid case number.'];
        } elseif (($_POST['confirm'] ?? '') !== 'ERASE') {
            $flash = ['type' => 'err', 'text' => 'Type ERASE (in capitals) to confirm.'];
        } else {
            $res   = erase_gs_request($pdo, $caseId);
            $flash = ['type' => $res['ok'] ? 'ok' : 'err', 'text' => $res['message']];
        }
    }
}

csrf_token();
$candidates = erasure_candidates($pdo);

$pageTitle     = 'Data Erasure — Kuronyx Admin';
$robotsNoindex = true;
$backHref      = '/admin/gs-requests/';
$backLabel     = 'GS-441524 requests';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · Data erasure</p>
    <h1 class="doc-title">Data Erasure</h1>
    <?php $activeAdminNav = 'data-erasure'; require __DIR__ . '/../../includes/admin-nav.php'; ?>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : '' ?>"><?= htmlspecialchars($flash['text'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <div class="card-panel">
      <h2>Erase a case</h2>
      <p class="field-hint" style="margin-bottom:1rem;">
        For a deletion request under the Privacy Policy, or once a case is past its retention period. Permanently deletes the
        uploaded prescription and documents, the owner/patient/veterinarian details, consent records, logged emails and internal
        notes for that case. The case number, its status history and the formulation/price stay, with nothing that identifies a
        person. <strong>This cannot be undone.</strong> Only finished, closed, cancelled or rejected cases can be erased.
      </p>
      <form method="POST">
        <?= csrf_field() ?>
        <div class="field-row two">
          <label class="field">
            <span class="lbl">Case number (the number after GS-)</span>
            <input type="number" name="case_id" min="1" required>
          </label>
          <label class="field">
            <span class="lbl">Type ERASE to confirm</span>
            <input type="text" name="confirm" autocomplete="off" required>
          </label>
        </div>
        <button type="submit" class="btn-secondary" style="border-color:#ff8787; color:#ff8787;">Erase Case Data</button>
      </form>
    </div>

    <div class="card-panel">
      <h2>Past retention — for review</h2>
      <p class="field-hint" style="margin-bottom:1rem;">
        Finished/closed/cancelled/rejected cases not touched for <?= retention_months() ?> months (set <code>RETENTION_MONTHS</code> in
        db-config.php once you have confirmed how long prescription and dispensing records must legally be kept).
        Nothing here is deleted automatically.
      </p>
      <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Case</th><th>Status</th><th>Source</th><th>Last updated</th><th></th></tr></thead>
        <tbody>
          <?php if (!$candidates): ?>
            <tr><td colspan="5">No cases are past the retention period.</td></tr>
          <?php endif; ?>
          <?php foreach ($candidates as $c): ?>
            <tr>
              <td><a href="/admin/gs-requests/view.php?id=<?= (int) $c['id'] ?>">GS-<?= (int) $c['id'] ?></a></td>
              <td><span class="status-pill status-<?= htmlspecialchars($c['status'], ENT_QUOTES) ?>"><?= ucwords(str_replace('_', ' ', $c['status'])) ?></span></td>
              <td><?= $c['source'] === 'cat_owner' ? 'Cat owner' : 'Veterinarian' ?></td>
              <td><?= htmlspecialchars(fmt_time($c['updated_at']), ENT_QUOTES) ?></td>
              <td>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently erase all personal data and documents for GS-<?= (int) $c['id'] ?>? This cannot be undone.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="case_id" value="<?= (int) $c['id'] ?>">
                  <input type="hidden" name="confirm" value="ERASE">
                  <button type="submit" class="btn-secondary" style="padding:0.4rem 0.8rem; font-size:0.625rem;">Erase</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
