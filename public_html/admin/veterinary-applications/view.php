<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
$staff = require_role(['admin', 'pharmacy_staff']);

$id  = (int) ($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM vet_applications WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$app = $stmt->fetch();
if (!$app) {
    http_response_code(404);
    exit('Application not found.');
}

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'err', 'text' => 'Your session expired. Please try again.'];
    } else {
        $action = $_POST['action'] ?? '';
        $noteText = trim($_POST['note'] ?? '');

        $applyNote = function (string $prefix) use ($pdo, $id, $staff, $noteText, &$app) {
            $stamp = date('Y-m-d H:i');
            $entry = "[{$stamp} · {$staff['name']}] {$prefix}" . ($noteText !== '' ? ": {$noteText}" : '.');
            $updated = trim(($app['internal_notes'] ?? '') . "\n" . $entry);
            $pdo->prepare('UPDATE vet_applications SET internal_notes = ? WHERE id = ?')->execute([$updated, $id]);
            $app['internal_notes'] = $updated;
        };

        if ($action === 'approve') {
            $pdo->prepare('UPDATE vet_applications SET status = \'approved\', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')->execute([$staff['id'], $id]);
            $applyNote('Approved');
            audit('application_approved', 'vet_application', $id, []);
            $flash = ['type' => 'ok', 'text' => 'Application approved.'];
            $app['status'] = 'approved';
        } elseif ($action === 'reject') {
            $pdo->prepare('UPDATE vet_applications SET status = \'rejected\', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')->execute([$staff['id'], $id]);
            $applyNote('Rejected');
            audit('application_rejected', 'vet_application', $id, []);
            $flash = ['type' => 'ok', 'text' => 'Application rejected.'];
            $app['status'] = 'rejected';
        } elseif ($action === 'request_info') {
            $pdo->prepare('UPDATE vet_applications SET status = \'under_review\' WHERE id = ?')->execute([$id]);
            $applyNote('Requested more information');
            audit('application_reviewed', 'vet_application', $id, ['outcome' => 'more_info_requested']);
            $flash = ['type' => 'ok', 'text' => 'Marked as needing more information.'];
            $app['status'] = 'under_review';
        } elseif ($action === 'suspend') {
            $pdo->prepare('UPDATE vet_applications SET status = \'suspended\' WHERE id = ?')->execute([$id]);
            $applyNote('Suspended');
            audit('application_reviewed', 'vet_application', $id, ['outcome' => 'suspended']);
            $flash = ['type' => 'ok', 'text' => 'Account suspended.'];
            $app['status'] = 'suspended';
        } elseif ($action === 'add_note') {
            if ($noteText === '') {
                $flash = ['type' => 'err', 'text' => 'Note cannot be empty.'];
            } else {
                $applyNote('Note');
                audit('note_added', 'vet_application', $id, []);
                $flash = ['type' => 'ok', 'text' => 'Note added.'];
            }
        }
    }
}

$documents = $pdo->prepare('SELECT * FROM vet_application_documents WHERE application_id = ? ORDER BY created_at ASC');
$documents->execute([$id]);
$documents = $documents->fetchAll();

$pageTitle     = "VA-{$id} — Kuronyx Admin";
$robotsNoindex = true;
$backHref      = '/admin/veterinary-applications/';
$backLabel     = 'All applications';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · Veterinary application</p>
    <h1 class="doc-title">VA-<?= $id ?> · <?= htmlspecialchars($app['full_name'], ENT_QUOTES) ?></h1>
    <p class="doc-meta">
      <span class="status-pill status-<?= htmlspecialchars($app['status'], ENT_QUOTES) ?>"><?= ucwords($app['status']) ?></span><span class="sep">·</span>
      Submitted <?= htmlspecialchars($app['created_at'], ENT_QUOTES) ?>
    </p>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : '' ?>"><?= htmlspecialchars($flash['text'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <div class="card-panel">
      <h2>Veterinarian</h2>
      <div class="kv-grid">
        <div class="k">Full name</div><div class="v"><?= htmlspecialchars($app['full_name'], ENT_QUOTES) ?></div>
        <div class="k">Email</div><div class="v"><?= htmlspecialchars($app['professional_email'], ENT_QUOTES) ?></div>
        <div class="k">Mobile</div><div class="v"><?= htmlspecialchars($app['mobile'], ENT_QUOTES) ?></div>
        <div class="k">Registration #</div><div class="v"><?= htmlspecialchars($app['registration_number'], ENT_QUOTES) ?></div>
        <div class="k">Registration state</div><div class="v"><?= htmlspecialchars($app['registration_state'], ENT_QUOTES) ?>, <?= htmlspecialchars($app['registration_country'], ENT_QUOTES) ?></div>
        <div class="k">Qualification</div><div class="v"><?= htmlspecialchars($app['qualification'], ENT_QUOTES) ?> (<?= (int) $app['year_qualified'] ?>)</div>
        <div class="k">Practice type</div><div class="v"><?= htmlspecialchars($app['practice_type'], ENT_QUOTES) ?></div>
      </div>
    </div>

    <div class="card-panel">
      <h2>Clinic</h2>
      <div class="kv-grid">
        <div class="k">Name</div><div class="v"><?= htmlspecialchars($app['clinic_name'], ENT_QUOTES) ?></div>
        <div class="k">Address</div><div class="v"><?= htmlspecialchars($app['clinic_address'], ENT_QUOTES) ?>, <?= htmlspecialchars($app['clinic_city'], ENT_QUOTES) ?>, <?= htmlspecialchars($app['clinic_state'], ENT_QUOTES) ?> <?= htmlspecialchars($app['clinic_pin'], ENT_QUOTES) ?></div>
        <div class="k">Phone</div><div class="v"><?= htmlspecialchars($app['clinic_phone'], ENT_QUOTES) ?></div>
        <div class="k">Email</div><div class="v"><?= htmlspecialchars($app['clinic_email'] ?? '—', ENT_QUOTES) ?></div>
        <div class="k">Website</div><div class="v"><?= htmlspecialchars($app['clinic_website'] ?? '—', ENT_QUOTES) ?></div>
      </div>
    </div>

    <div class="card-panel">
      <h2>Documents</h2>
      <?php if (!$documents): ?>
        <p style="font-size:0.8125rem; color:var(--paper-3);">No documents uploaded.</p>
      <?php endif; ?>
      <?php foreach ($documents as $d): ?>
        <div class="note-item">
          <a href="/download.php?kind=vet_application&doc_id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener noreferrer" style="color:var(--paper); border-bottom:1px solid var(--paper-3);"><?= htmlspecialchars($d['original_filename'], ENT_QUOTES) ?></a>
          <span style="color:var(--paper-4); font-size:0.75rem;"> · <?= htmlspecialchars($d['created_at'], ENT_QUOTES) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card-panel">
      <h2>Review actions</h2>
      <form method="POST">
        <?= csrf_field() ?>
        <label class="field">
          <span class="lbl">Note (recorded with every action below — internal only, never shown to the applicant)</span>
          <textarea name="note" rows="2"></textarea>
        </label>
        <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:1rem;">
          <button type="submit" name="action" value="approve" class="btn-primary">Approve</button>
          <button type="submit" name="action" value="reject" class="btn-secondary">Reject</button>
          <button type="submit" name="action" value="request_info" class="btn-secondary">Request More Information</button>
          <button type="submit" name="action" value="suspend" class="btn-secondary">Suspend</button>
          <button type="submit" name="action" value="add_note" class="btn-secondary">Add Note Only</button>
        </div>
      </form>
    </div>

    <div class="card-panel">
      <h2>Internal notes &amp; review history (staff only)</h2>
      <pre style="white-space:pre-wrap; font-family:var(--mono); font-size:0.75rem; color:var(--paper-2); line-height:1.7;"><?= htmlspecialchars($app['internal_notes'] ?? 'No notes yet.', ENT_QUOTES) ?></pre>
    </div>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
