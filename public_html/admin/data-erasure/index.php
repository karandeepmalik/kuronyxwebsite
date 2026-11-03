<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/data-protection.php';
require_once __DIR__ . '/../../includes/orphan-uploads.php';
$staff = require_role(['admin']);

$pdo   = db();
$flash = null;

// Permanently erases one case's personal data — see erase_gs_request(). Irreversible, so it takes
// the case id typed in full plus the word ERASE, on top of the usual CSRF check. That holds for
// both ways in: the form at the top, and the per-row Erase buttons, which open a dialog where the
// case number and ERASE have to be typed again (nothing is pre-filled or hidden, and there's no
// browser confirm() that can be switched off). The dialog also posts `expected_case_id` — the row
// that was clicked — and the server refuses if the number typed isn't that one, so a mistyped or
// wrong-row confirmation can't erase a different case. Erasing is idempotent (a second attempt
// just reports "already erased"), so no one-time token is needed.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'err', 'text' => 'Your session expired. Please try again.'];
    } elseif (($_POST['action'] ?? '') === 'delete_leftovers') {
        // Retry deleting files that an earlier erasure meant to remove but couldn't (see the
        // "Leftover files" panel). Nothing in the request names a file: the list is recomputed
        // from the erasure audit entries, so this can only ever touch those.
        $res   = delete_erasure_leftovers($pdo);
        $flash = ['type' => $res['failed'] ? 'err' : 'ok', 'text' => $res['message']];
    } elseif (($_POST['action'] ?? '') === 'check_orphans') {
        // Runs the same check that runs by itself now and then (see includes/orphan-uploads.php): files no case or
        // application points at, and that are over a day old, are MOVED to a holding folder - never deleted at once.
        $res = sweep_orphan_uploads($pdo, 'staff');
        if ($res === null) {
            $flash = ['type' => 'err', 'text' => 'Another check is already running (or the holding folder could not be created). Try again in a minute.'];
        } else {
            $parts = [count($res['held']) . ' unreferenced file(s) moved to the holding folder'];
            if ($res['more']) $parts[0] .= ' (stopped at ' . ORPHAN_SWEEP_MAX_FILES . ' - run the check again for the rest)';
            $parts[] = $res['referenced'] . ' old file(s) left alone because a record uses them';
            if ($res['purged'] > 0) $parts[] = $res['purged'] . ' file(s) deleted from the holding folder after ' . ORPHAN_HOLD_DAYS . ' days';
            $flash = ['type' => 'ok', 'text' => implode('; ', $parts) . '.'];
        }
    } elseif (($_POST['kind'] ?? '') === 'vet_application') {
        // Same safeguards as a case, for a veterinary application: the number typed in full plus ERASE, and
        // (from the dialog) expected_application_id, the row that was clicked. See erase_vet_application().
        $appId = (int) ($_POST['application_id'] ?? 0);
        $expectedAppId = isset($_POST['expected_application_id']) ? trim((string) $_POST['expected_application_id']) : null;
        if ($appId <= 0) {
            $flash = ['type' => 'err', 'text' => 'Enter a valid application number.'];
        } elseif ($expectedAppId !== null && trim((string) $_POST['application_id']) !== $expectedAppId) {
            $flash = ['type' => 'err', 'text' => 'The application number you typed does not match VA-' . (int) $expectedAppId . '. Nothing was erased.'];
        } elseif (trim((string) ($_POST['confirm'] ?? '')) !== 'ERASE') {
            $flash = ['type' => 'err', 'text' => 'Type ERASE (in capitals) to confirm.'];
        } else {
            $res   = erase_vet_application($pdo, $appId);
            $flash = ['type' => ($res['ok'] && empty($res['failed_files'])) ? 'ok' : 'err', 'text' => $res['message']];
        }
    } else {
        $caseId = (int) ($_POST['case_id'] ?? 0);
        $expectedCaseId = isset($_POST['expected_case_id']) ? trim((string) $_POST['expected_case_id']) : null;
        if ($caseId <= 0) {
            $flash = ['type' => 'err', 'text' => 'Enter a valid case number.'];
        } elseif ($expectedCaseId !== null && trim((string) $_POST['case_id']) !== $expectedCaseId) {
            $flash = ['type' => 'err', 'text' => 'The case number you typed does not match GS-' . (int) $expectedCaseId . '. Nothing was erased.'];
        } elseif (trim((string) ($_POST['confirm'] ?? '')) !== 'ERASE') { // trimmed so a stray space from copy-paste doesn't fail; still case-sensitive
            $flash = ['type' => 'err', 'text' => 'Type ERASE (in capitals) to confirm.'];
        } else {
            $res   = erase_gs_request($pdo, $caseId);
            // 'ok' means the database part succeeded; a file that couldn't be deleted is still shown as an error.
            $flash = ['type' => ($res['ok'] && empty($res['failed_files'])) ? 'ok' : 'err', 'text' => $res['message']];
        }
    }

    // Post/Redirect/Get: answering the POST with the result page itself would make a browser
    // refresh re-send the form (the "resubmit?" popup, then a confusing "already been erased"
    // for a case that was in fact just erased). So the outcome goes into the session and the
    // browser is redirected to a plain GET of this page, which shows it once (below). If there's
    // somehow no session to carry it, fall through and render it directly, as before.
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['data_erasure_flash'] = $flash;
        header('Location: /admin/data-erasure/');
        exit;
    }
}

// The result of the last POST, shown once: read it and clear it so a refresh doesn't repeat it.
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['data_erasure_flash'])) {
    $flash = $_SESSION['data_erasure_flash'];
    unset($_SESSION['data_erasure_flash']);
}

csrf_token();
$candidates    = erasure_candidates($pdo);
$vetCandidates = vet_erasure_candidates($pdo);
$leftovers     = erasure_leftover_files($pdo);
$holding       = orphan_holding_summary();

$extraHead = <<<HTML
<style>
  /* margin:auto is what centres a modal <dialog>; the site-wide reset zeroes margins, so say it explicitly. */
  dialog.erase-dialog{ margin:auto; background:var(--navy); color:var(--paper); border:1px solid var(--rule); padding:1.75rem; width:min(32rem, calc(100vw - 2rem)); max-height:calc(100vh - 2rem); overflow:auto; }
  dialog.erase-dialog::backdrop{ background:rgba(8,16,22,0.75); }
  .erase-dialog h2{ font-family:var(--mono); font-weight:500; font-size:0.75rem; letter-spacing:0.2em; text-transform:uppercase; color:var(--err); margin-bottom:0.75rem; }
  .erase-dialog .field{ margin-bottom:1rem; }
  .erase-dialog .field-hint{ margin-bottom:1.25rem; }
  .erase-dialog-actions{ display:flex; gap:0.75rem; justify-content:flex-end; margin-top:1.5rem; }
  .panel-actions{ margin-top:1.25rem; }
  .erase-dialog .btn-danger:disabled{ opacity:0.4; cursor:not-allowed; }
</style>
HTML;
$pageTitle     = 'Data Erasure - Kuronyx Admin';
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
      <p class="field-hint spaced">
        For a deletion request under the Privacy Policy, or once a case is past its retention period. Permanently deletes the
        uploaded prescription and documents, the owner/patient/veterinarian details, consent records, logged emails and internal
        notes for that case. The case number, its status history and the formulation/price stay, with nothing that identifies a
        person. The audit log keeps what happened and when, but not the details: email addresses, IP addresses and each status
        change's from/to are cleared (the status history still shows the statuses).
        <strong>This cannot be undone.</strong> Only finished, closed, cancelled or rejected cases can be erased.
      </p>
      <form method="POST">
        <?= csrf_field() ?>
        <div class="field-row two">
          <label class="field">
            <span class="lbl">Case number (the number after GS-)</span>
            <input type="number" name="case_id" id="erase-case-id" min="1" required>
          </label>
          <label class="field">
            <span class="lbl">Type ERASE to confirm</span>
            <input type="text" name="confirm" id="erase-confirm" autocomplete="off" required>
          </label>
        </div>
        <button type="submit" class="btn-secondary btn-danger">Erase Case Data</button>
      </form>
    </div>

    <div class="card-panel">
      <h2>Past retention - for review</h2>
      <p class="field-hint spaced">
        Finished/closed/cancelled/rejected cases that reached that status over <?= retention_months() ?> months ago, however recently they were edited since (set <code>RETENTION_MONTHS</code> in
        db-config.php once you have confirmed how long prescription and dispensing records must legally be kept).
        Nothing here is deleted automatically.
      </p>
      <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Case</th><th>Status</th><th>Source</th><th>Closed</th><th></th></tr></thead>
        <tbody>
          <?php if (!$candidates): ?>
            <tr><td colspan="5">No cases are past the retention period.</td></tr>
          <?php endif; ?>
          <?php foreach ($candidates as $c): ?>
            <tr>
              <td><a href="/admin/gs-requests/view.php?id=<?= (int) $c['id'] ?>">GS-<?= (int) $c['id'] ?></a></td>
              <td><span class="status-pill status-<?= htmlspecialchars($c['status'], ENT_QUOTES) ?>"><?= htmlspecialchars(status_label($c['status']), ENT_QUOTES) ?></span></td>
              <td><?= $c['source'] === 'cat_owner' ? 'Cat owner' : 'Veterinarian' ?></td>
              <td><?= htmlspecialchars(fmt_time($c['closed_at']), ENT_QUOTES) ?></td>
              <td>
                <button type="button" class="btn-secondary btn-sm" data-erase-case="<?= (int) $c['id'] ?>">Erase</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

    <div class="card-panel">
      <h2>Erase a veterinary application</h2>
      <p class="field-hint spaced">
        For a deletion request from an applicant or veterinarian, or once a rejected application is past its retention period.
        Permanently deletes the uploaded registration certificate, the applicant and clinic details and the staff review notes, and
        locks out and anonymises the portal account. The application number and its status stay. Cases the veterinarian submitted
        are <strong>not</strong> changed: a case is also the pharmacy's prescription record, and each case clears the prescriber's
        details when that case is erased. The audit log keeps what happened and when, without the details, and the veterinarian's
        name is cleared from their own audit entries. <strong>This cannot be undone.</strong> An approved application must be
        suspended first.
      </p>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="kind" value="vet_application">
        <div class="field-row two">
          <label class="field">
            <span class="lbl">Application number (the number after VA-)</span>
            <input type="number" name="application_id" id="erase-vet-application-id" min="1" required>
          </label>
          <label class="field">
            <span class="lbl">Type ERASE to confirm</span>
            <input type="text" name="confirm" id="erase-vet-confirm" autocomplete="off" required>
          </label>
        </div>
        <button type="submit" class="btn-secondary btn-danger">Erase Application Data</button>
      </form>
    </div>

    <div class="card-panel">
      <h2>Rejected applications past retention - for review</h2>
      <p class="field-hint spaced">
        Applications rejected over <?= vet_retention_months() ?> months ago. No law fixes a period for rejected applications; the rule is to
        keep personal data no longer than it is needed, and a decided application is mostly done with. Twelve months leaves room for an
        appeal or a re-application and for spotting someone who re-applies after a rejection. Set
        <code>VET_APPLICATION_RETENTION_MONTHS</code> in db-config.php to change it, and keep the Privacy Policy in step. Nothing here is
        deleted automatically.
      </p>
      <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Application</th><th>Status</th><th>Rejected</th><th></th></tr></thead>
        <tbody>
          <?php if (!$vetCandidates): ?>
            <tr><td colspan="4">No rejected applications are past the retention period.</td></tr>
          <?php endif; ?>
          <?php foreach ($vetCandidates as $v): ?>
            <tr>
              <td><a href="/admin/veterinary-applications/view.php?id=<?= (int) $v['id'] ?>">VA-<?= (int) $v['id'] ?></a></td>
              <td><span class="status-pill status-<?= htmlspecialchars($v['status'], ENT_QUOTES) ?>"><?= htmlspecialchars(status_label($v['status']), ENT_QUOTES) ?></span></td>
              <td><?= htmlspecialchars(fmt_time($v['decided_at']), ENT_QUOTES) ?></td>
              <td>
                <button type="button" class="btn-secondary btn-sm" data-erase-application="<?= (int) $v['id'] ?>">Erase</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

    <div class="card-panel">
      <h2>Leftover files from erasures</h2>
      <p class="field-hint spaced">
        Uploaded files that an erasure meant to delete but that are still on the server, because a delete failed or the server
        stopped partway through. Only files recorded by an erasure itself are ever listed or deleted &mdash; nothing else in
        storage is touched.
      </p>
      <?php if (!$leftovers): ?>
        <p class="field-hint">None. Every erased case's and application's uploaded files are gone from the server.</p>
      <?php else: ?>
        <div class="table-scroll">
        <table class="data-table">
          <thead><tr><th>Record</th><th>File</th><th>Where</th><th>Erased</th></tr></thead>
          <tbody>
            <?php foreach ($leftovers as $l): ?>
              <tr>
                <td><a href="<?= $l['kind'] === 'vet_application' ? '/admin/veterinary-applications/view.php?id=' : '/admin/gs-requests/view.php?id=' ?><?= (int) $l['id'] ?>"><?= htmlspecialchars($l['label'], ENT_QUOTES) ?></a></td>
                <td><code><?= htmlspecialchars($l['file'], ENT_QUOTES) ?></code></td>
                <td><code><?= htmlspecialchars($l['where'], ENT_QUOTES) ?></code></td>
                <td><?= htmlspecialchars(fmt_time($l['erased_at']), ENT_QUOTES) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <form method="POST" class="panel-actions">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_leftovers">
          <button type="submit" class="btn-secondary btn-danger">Delete leftover files</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card-panel">
      <h2>Uploads no record points at</h2>
      <p class="field-hint spaced">
        If a submission dies partway (a timeout, a restart), its uploaded files can be left on the server with no case or
        application attached, and nobody would ever see them. This check finds files that no record uses and that are over a day
        old, and <strong>moves</strong> them to a holding folder; they are deleted from there after <?= ORPHAN_HOLD_DAYS ?> days, so
        anything wrongly caught (for example after the database was restored from an older backup) can still be recovered.
        A file that a case or application does use is never touched. It runs by itself now and then; this button runs it now.
      </p>
      <p class="field-hint">
        <?= $holding['files'] === 0 ? 'The holding folder is empty.' : (int) $holding['files'] . ' file(s) are in the holding folder (the oldest from ' . htmlspecialchars((string) $holding['oldest'], ENT_QUOTES) . '), in <code>orphaned/</code> next to the case folders.' ?>
      </p>
      <form method="POST" class="panel-actions">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="check_orphans">
        <button type="submit" class="btn-secondary">Check for unreferenced uploads now</button>
      </form>
    </div>

    <!-- Opened by a row's Erase button. Nothing here is pre-filled: the case number and ERASE have to be
         typed, and the submit button stays disabled until both match (the server checks again). -->
    <dialog id="erase-dialog" class="erase-dialog" aria-labelledby="erase-dialog-title">
      <form method="POST" id="erase-dialog-form">
        <?= csrf_field() ?>
        <input type="hidden" name="expected_case_id" id="erase-expected" value="">
        <h2 id="erase-dialog-title">Erase <span id="erase-dialog-case">GS-</span>?</h2>
        <p class="field-hint">
          Permanently deletes the uploaded prescription and documents, the owner/patient/veterinarian details, consent
          records, logged emails and internal notes for this case. <strong>This cannot be undone.</strong>
        </p>
        <label class="field">
          <span class="lbl">Type the case number, <strong id="erase-dialog-number"></strong>, to confirm</span>
          <input type="text" name="case_id" id="erase-dialog-case-id" inputmode="numeric" autocomplete="off" required>
        </label>
        <label class="field">
          <span class="lbl">Type ERASE to confirm</span>
          <input type="text" name="confirm" id="erase-dialog-confirm" autocomplete="off" required>
        </label>
        <div class="erase-dialog-actions">
          <button type="button" class="btn-secondary" id="erase-dialog-cancel">Cancel</button>
          <button type="submit" class="btn-secondary btn-danger" id="erase-dialog-submit" disabled>Erase Case Data</button>
        </div>
      </form>
    </dialog>
    <!-- The same, for a veterinary application's row button. -->
    <dialog id="erase-vet-dialog" class="erase-dialog" aria-labelledby="erase-vet-dialog-title">
      <form method="POST" id="erase-vet-dialog-form">
        <?= csrf_field() ?>
        <input type="hidden" name="kind" value="vet_application">
        <input type="hidden" name="expected_application_id" id="erase-vet-expected" value="">
        <h2 id="erase-vet-dialog-title">Erase <span id="erase-vet-dialog-label">VA-</span>?</h2>
        <p class="field-hint">
          Permanently deletes the uploaded registration certificate, the applicant and clinic details and the staff review
          notes, and locks out and anonymises the portal account. <strong>This cannot be undone.</strong>
        </p>
        <label class="field">
          <span class="lbl">Type the application number, <strong id="erase-vet-dialog-number"></strong>, to confirm</span>
          <input type="text" name="application_id" id="erase-vet-dialog-id" inputmode="numeric" autocomplete="off" required>
        </label>
        <label class="field">
          <span class="lbl">Type ERASE to confirm</span>
          <input type="text" name="confirm" id="erase-vet-dialog-confirm" autocomplete="off" required>
        </label>
        <div class="erase-dialog-actions">
          <button type="button" class="btn-secondary" id="erase-vet-dialog-cancel">Cancel</button>
          <button type="submit" class="btn-secondary btn-danger" id="erase-vet-dialog-submit" disabled>Erase Application Data</button>
        </div>
      </form>
    </dialog>
    <script>
    // One wiring for both dialogs: a row's Erase button opens its dialog; the submit button stays disabled until
    // the number typed matches the row and ERASE is typed (the server checks again).
    (function () {
      function wire(c) {
        var dialog  = document.getElementById(c.dialog);
        var buttons = document.querySelectorAll('[' + c.button + ']');
        if (!dialog || !buttons.length) return;
        var form = document.getElementById(c.form);
        var expected = document.getElementById(c.expected);
        var idInput = document.getElementById(c.idInput);
        var confirmInput = document.getElementById(c.confirmInput);
        var submit = document.getElementById(c.submit);
        var recordId = '';

        function matches() { return idInput.value.trim() === recordId && confirmInput.value.trim() === 'ERASE'; }
        function update() { submit.disabled = !matches(); }

        buttons.forEach(function (btn) {
          btn.addEventListener('click', function () {
            recordId = btn.getAttribute(c.button);
            if (typeof dialog.showModal !== 'function') {
              // A browser without <dialog> support: send them to the form at the top instead.
              var top = document.getElementById(c.topId);
              top.value = recordId;
              top.scrollIntoView();
              document.getElementById(c.topConfirm).focus();
              return;
            }
            expected.value = recordId;
            idInput.value = '';
            confirmInput.value = '';
            document.getElementById(c.label).textContent = c.prefix + recordId;
            document.getElementById(c.number).textContent = recordId;
            update();
            dialog.showModal();
            idInput.focus();
          });
        });
        idInput.addEventListener('input', update);
        confirmInput.addEventListener('input', update);
        document.getElementById(c.cancel).addEventListener('click', function () { dialog.close(); });
        form.addEventListener('submit', function (e) { if (!matches()) e.preventDefault(); });
      }
      wire({ dialog: 'erase-dialog', button: 'data-erase-case', form: 'erase-dialog-form', expected: 'erase-expected',
             idInput: 'erase-dialog-case-id', confirmInput: 'erase-dialog-confirm', submit: 'erase-dialog-submit',
             cancel: 'erase-dialog-cancel', label: 'erase-dialog-case', number: 'erase-dialog-number', prefix: 'GS-',
             topId: 'erase-case-id', topConfirm: 'erase-confirm' });
      wire({ dialog: 'erase-vet-dialog', button: 'data-erase-application', form: 'erase-vet-dialog-form', expected: 'erase-vet-expected',
             idInput: 'erase-vet-dialog-id', confirmInput: 'erase-vet-dialog-confirm', submit: 'erase-vet-dialog-submit',
             cancel: 'erase-vet-dialog-cancel', label: 'erase-vet-dialog-label', number: 'erase-vet-dialog-number', prefix: 'VA-',
             topId: 'erase-vet-application-id', topConfirm: 'erase-vet-confirm' });
    })();
    </script>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
