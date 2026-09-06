<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
$staff = require_login();

$id = (int) ($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM gs_requests WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$case = $stmt->fetch();
if (!$case) {
    http_response_code(404);
    exit('Case not found.');
}

$statuses = ['submitted','under_review','awaiting_information','communication_in_progress','formulation_discussion','approved','compounding','ready_for_dispatch','dispatched','finished','closed','cancelled','rejected'];
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'err', 'text' => 'Your session expired. Please try again.'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'change_status') {
            $newStatus = $_POST['new_status'] ?? '';
            $note      = trim($_POST['status_note'] ?? '');
            if (!in_array($newStatus, $statuses, true)) {
                $flash = ['type' => 'err', 'text' => 'Invalid status.'];
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE gs_requests SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
                    $pdo->prepare('INSERT INTO case_status_history (gs_request_id, previous_status, new_status, changed_by, note) VALUES (?,?,?,?,?)')
                        ->execute([$id, $case['status'], $newStatus, $staff['id'], $note ?: null]);
                    $pdo->commit();
                    audit('case_status_changed', 'gs_request', $id, ['from' => $case['status'], 'to' => $newStatus]);
                    $flash = ['type' => 'ok', 'text' => 'Status updated.'];
                    $case['status'] = $newStatus;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $flash = ['type' => 'err', 'text' => 'Could not update status.'];
                }
            }
        } elseif ($action === 'add_note') {
            $content = trim($_POST['content'] ?? '');
            if ($content === '') {
                $flash = ['type' => 'err', 'text' => 'Note cannot be empty.'];
            } else {
                $pdo->prepare('INSERT INTO case_internal_notes (gs_request_id, staff_id, content) VALUES (?,?,?)')
                    ->execute([$id, $staff['id'], $content]);
                audit('note_added', 'gs_request', $id, []);
                $flash = ['type' => 'ok', 'text' => 'Note added.'];
            }
        } elseif ($action === 'update_final') {
            $final = [
                'final_formulation'   => trim($_POST['final_formulation'] ?? '') ?: null,
                'final_concentration' => trim($_POST['final_concentration'] ?? '') ?: null,
                'final_quantity'      => trim($_POST['final_quantity'] ?? '') ?: null,
                'final_price'         => ($_POST['final_price'] ?? '') !== '' ? (float) $_POST['final_price'] : null,
                'courier'             => trim($_POST['courier'] ?? '') ?: null,
                'tracking_number'     => trim($_POST['tracking_number'] ?? '') ?: null,
                'dispatch_date'       => trim($_POST['dispatch_date'] ?? '') ?: null,
                'closure_reason'      => trim($_POST['closure_reason'] ?? '') ?: null,
            ];
            $pdo->prepare(
                'UPDATE gs_requests SET final_formulation=?, final_concentration=?, final_quantity=?, final_price=?,
                 courier=?, tracking_number=?, dispatch_date=?, closure_reason=? WHERE id=?'
            )->execute([...array_values($final), $id]);
            audit('formulation_recorded', 'gs_request', $id, ['fields' => array_keys(array_filter($final, fn($v) => $v !== null))]);
            $flash = ['type' => 'ok', 'text' => 'Case details updated.'];
            $case = array_merge($case, $final);
        } elseif ($action === 'assign_staff') {
            $assignedId = (int) ($_POST['assigned_staff_id'] ?? 0) ?: null;
            $pdo->prepare('UPDATE gs_requests SET assigned_staff_id = ? WHERE id = ?')->execute([$assignedId, $id]);
            audit('case_assigned', 'gs_request', $id, ['assigned_staff_id' => $assignedId]);
            $flash = ['type' => 'ok', 'text' => 'Assignment updated.'];
            $case['assigned_staff_id'] = $assignedId;
        }
    }
}

$documents = $pdo->prepare('SELECT * FROM gs_request_documents WHERE gs_request_id = ? ORDER BY created_at ASC');
$documents->execute([$id]);
$documents = $documents->fetchAll();

$notes = $pdo->prepare('SELECT n.*, s.name AS staff_name FROM case_internal_notes n JOIN staff_users s ON s.id = n.staff_id WHERE n.gs_request_id = ? ORDER BY n.created_at DESC');
$notes->execute([$id]);
$notes = $notes->fetchAll();

$history = $pdo->prepare('SELECT h.*, s.name AS staff_name FROM case_status_history h LEFT JOIN staff_users s ON s.id = h.changed_by WHERE h.gs_request_id = ? ORDER BY h.created_at DESC');
$history->execute([$id]);
$history = $history->fetchAll();

$allStaff = $pdo->query('SELECT id, name FROM staff_users ORDER BY name ASC')->fetchAll();

$pageTitle     = "GS-{$id} — Kuronyx Admin";
$robotsNoindex = true;
$backHref      = '/admin/gs-requests/';
$backLabel     = 'All requests';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · GS-441524 case</p>
    <h1 class="doc-title">GS-<?= $id ?></h1>
    <p class="doc-meta">
      <?= $case['source'] === 'cat_owner' ? 'Cat owner submission' : 'Veterinarian submission' ?><span class="sep">·</span>
      <span class="status-pill status-<?= htmlspecialchars($case['status'], ENT_QUOTES) ?>"><?= ucwords(str_replace('_', ' ', $case['status'])) ?></span><span class="sep">·</span>
      Created <?= htmlspecialchars($case['created_at'], ENT_QUOTES) ?>
    </p>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : '' ?>"><?= htmlspecialchars($flash['text'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <div class="card-panel">
      <h2>Owner</h2>
      <div class="kv-grid">
        <div class="k">Name</div><div class="v"><?= htmlspecialchars($case['owner_full_name'], ENT_QUOTES) ?></div>
        <div class="k">Email</div><div class="v"><?= htmlspecialchars($case['owner_email'], ENT_QUOTES) ?></div>
        <div class="k">Phone</div><div class="v"><?= htmlspecialchars($case['owner_phone'], ENT_QUOTES) ?></div>
        <div class="k">Address</div><div class="v"><?= htmlspecialchars($case['owner_address'], ENT_QUOTES) ?>, <?= htmlspecialchars($case['owner_city'], ENT_QUOTES) ?>, <?= htmlspecialchars($case['owner_state'], ENT_QUOTES) ?> <?= htmlspecialchars($case['owner_pin'], ENT_QUOTES) ?>, <?= htmlspecialchars($case['owner_country'], ENT_QUOTES) ?></div>
      </div>
    </div>

    <div class="card-panel">
      <h2>Treating veterinarian</h2>
      <div class="kv-grid">
        <div class="k">Name</div><div class="v"><?= htmlspecialchars($case['vet_name'] ?? '—', ENT_QUOTES) ?></div>
        <div class="k">Clinic</div><div class="v"><?= htmlspecialchars($case['vet_clinic'] ?? '—', ENT_QUOTES) ?></div>
        <div class="k">Email</div><div class="v"><?= htmlspecialchars($case['vet_email'] ?? '—', ENT_QUOTES) ?></div>
        <div class="k">Phone</div><div class="v"><?= htmlspecialchars($case['vet_phone'] ?? '—', ENT_QUOTES) ?></div>
        <div class="k">Registration</div><div class="v"><?= htmlspecialchars($case['vet_registration_info'] ?? '—', ENT_QUOTES) ?></div>
      </div>
    </div>

    <div class="card-panel">
      <h2>Patient</h2>
      <div class="kv-grid">
        <div class="k">Name</div><div class="v"><?= htmlspecialchars($case['patient_name'], ENT_QUOTES) ?></div>
        <div class="k">Species / breed</div><div class="v"><?= htmlspecialchars($case['patient_species'], ENT_QUOTES) ?> · <?= htmlspecialchars($case['patient_breed'] ?? '—', ENT_QUOTES) ?></div>
        <div class="k">Sex</div><div class="v"><?= htmlspecialchars(ucfirst($case['patient_sex']), ENT_QUOTES) ?></div>
        <div class="k">DOB</div><div class="v"><?= htmlspecialchars($case['patient_dob'] ?? 'Unknown', ENT_QUOTES) ?></div>
        <div class="k">Weight</div><div class="v"><?= $case['patient_weight_kg'] !== null ? htmlspecialchars($case['patient_weight_kg'], ENT_QUOTES) . ' kg' : '—' ?></div>
        <div class="k">Neutered / spayed</div><div class="v"><?= $case['patient_neutered'] ? 'Yes' : 'No' ?></div>
        <div class="k">Microchip</div><div class="v"><?= htmlspecialchars($case['patient_microchip'] ?? '—', ENT_QUOTES) ?></div>
        <div class="k">Requested formulation</div><div class="v"><?= ucfirst($case['requested_formulation']) ?></div>
      </div>
      <?php if ($case['clinical_notes']): ?>
        <p style="margin-top:1rem; font-size:0.8125rem; color:var(--paper-2); white-space:pre-wrap;"><?= htmlspecialchars($case['clinical_notes'], ENT_QUOTES) ?></p>
      <?php endif; ?>
    </div>

    <div class="card-panel">
      <h2>Documents</h2>
      <?php if (!$documents): ?>
        <p style="font-size:0.8125rem; color:var(--paper-3);">No documents on file.</p>
      <?php endif; ?>
      <?php foreach ($documents as $d): ?>
        <div class="note-item">
          <span class="status-pill" style="margin-right:0.6rem;"><?= $d['doc_type'] === 'prescription' ? 'Prescription' : 'Supporting' ?></span>
          <a href="/download.php?kind=gs_request&doc_id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener noreferrer" style="color:var(--paper); border-bottom:1px solid var(--paper-3);"><?= htmlspecialchars($d['original_filename'], ENT_QUOTES) ?></a>
          <span style="color:var(--paper-4); font-size:0.75rem;"> · <?= htmlspecialchars($d['created_at'], ENT_QUOTES) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card-panel">
      <h2>Final formulation &amp; fulfilment (staff-only)</h2>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_final">
        <div class="field-row three">
          <label class="field"><span class="lbl">Final formulation</span><input type="text" name="final_formulation" value="<?= htmlspecialchars($case['final_formulation'] ?? '', ENT_QUOTES) ?>"></label>
          <label class="field"><span class="lbl">Concentration / strength</span><input type="text" name="final_concentration" value="<?= htmlspecialchars($case['final_concentration'] ?? '', ENT_QUOTES) ?>"></label>
          <label class="field"><span class="lbl">Quantity</span><input type="text" name="final_quantity" value="<?= htmlspecialchars($case['final_quantity'] ?? '', ENT_QUOTES) ?>"></label>
        </div>
        <div class="field-row three">
          <label class="field"><span class="lbl">Price (INR)</span><input type="number" step="0.01" name="final_price" value="<?= htmlspecialchars($case['final_price'] ?? '', ENT_QUOTES) ?>"></label>
          <label class="field"><span class="lbl">Courier</span><input type="text" name="courier" value="<?= htmlspecialchars($case['courier'] ?? '', ENT_QUOTES) ?>"></label>
          <label class="field"><span class="lbl">Tracking number</span><input type="text" name="tracking_number" value="<?= htmlspecialchars($case['tracking_number'] ?? '', ENT_QUOTES) ?>"></label>
        </div>
        <div class="field-row two">
          <label class="field"><span class="lbl">Dispatch date</span><input type="date" name="dispatch_date" value="<?= htmlspecialchars($case['dispatch_date'] ?? '', ENT_QUOTES) ?>"></label>
          <label class="field"><span class="lbl">Closure reason (if closing)</span><input type="text" name="closure_reason" value="<?= htmlspecialchars($case['closure_reason'] ?? '', ENT_QUOTES) ?>"></label>
        </div>
        <button type="submit" class="btn-secondary">Save Case Details</button>
      </form>
    </div>

    <div class="card-panel">
      <h2>Status</h2>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_status">
        <div class="field-row two">
          <label class="field">
            <span class="lbl">New status</span>
            <select name="new_status">
              <?php foreach ($statuses as $s): ?>
                <option value="<?= $s ?>" <?= $case['status'] === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field">
            <span class="lbl">Note (optional)</span>
            <input type="text" name="status_note" placeholder="e.g. Formulation discussed and confirmed by treating veterinarian">
          </label>
        </div>
        <button type="submit" class="btn-primary">Update Status</button>
      </form>

      <h2 style="margin-top:2rem;">History</h2>
      <?php foreach ($history as $h): ?>
        <div class="note-item">
          <div class="note-meta"><?= htmlspecialchars($h['staff_name'] ?? 'System', ENT_QUOTES) ?> · <?= htmlspecialchars($h['created_at'], ENT_QUOTES) ?></div>
          <div class="note-body"><?= $h['previous_status'] ? ucwords(str_replace('_',' ',$h['previous_status'])) . ' → ' : '' ?><?= ucwords(str_replace('_',' ',$h['new_status'])) ?><?= $h['note'] ? ' — ' . htmlspecialchars($h['note'], ENT_QUOTES) : '' ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card-panel">
      <h2>Assigned staff</h2>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_staff">
        <div class="field-row two">
          <label class="field">
            <span class="lbl">Assigned to</span>
            <select name="assigned_staff_id">
              <option value="">Unassigned</option>
              <?php foreach ($allStaff as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= (int) $case['assigned_staff_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name'], ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit" class="btn-secondary" style="align-self:end;">Save</button>
        </div>
      </form>
    </div>

    <div class="card-panel">
      <h2>Internal notes (staff only)</h2>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_note">
        <label class="field">
          <textarea name="content" rows="3" placeholder="e.g. Called veterinarian, requested updated prescription"></textarea>
        </label>
        <button type="submit" class="btn-secondary">Add Note</button>
      </form>
      <?php foreach ($notes as $n): ?>
        <div class="note-item">
          <div class="note-meta"><?= htmlspecialchars($n['staff_name'], ENT_QUOTES) ?> · <?= htmlspecialchars($n['created_at'], ENT_QUOTES) ?></div>
          <div class="note-body"><?= htmlspecialchars($n['content'], ENT_QUOTES) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card-panel">
      <h2>Communication history</h2>
      <p style="font-size:0.8125rem; color:var(--paper-3);">Manual email composer is coming in a later phase. For now, record any email/phone communication with the veterinarian or owner as an internal note above.</p>
    </div>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
