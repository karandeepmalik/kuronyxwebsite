<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
$vet = require_vet_login();

$id  = (int) ($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM gs_requests WHERE id = ? AND vet_account_id = ? LIMIT 1');
$stmt->execute([$id, $vet['id']]);
$case = $stmt->fetch();
if (!$case) {
    http_response_code(404);
    exit('Case not found.');
}

$documents = $pdo->prepare('SELECT * FROM gs_request_documents WHERE gs_request_id = ? ORDER BY created_at ASC');
$documents->execute([$id]);
$documents = $documents->fetchAll();

$history = $pdo->prepare('SELECT h.*, s.name AS staff_name FROM case_status_history h LEFT JOIN staff_users s ON s.id = h.changed_by WHERE h.gs_request_id = ? ORDER BY h.created_at DESC');
$history->execute([$id]);
$history = $history->fetchAll();

$pageTitle     = "GS-{$id} — Kuronyx Veterinary Portal";
$robotsNoindex = true;
$activeNav     = 'for-veterinarians';
$backHref      = '/for-veterinarians/portal/';
$backLabel     = 'Your requests';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Veterinary portal · GS-441524 case</p>
    <h1 class="doc-title">GS-<?= $id ?></h1>
    <p class="doc-meta">
      <span class="status-pill status-<?= htmlspecialchars($case['status'], ENT_QUOTES) ?>"><?= ucwords(str_replace('_', ' ', $case['status'])) ?></span><span class="sep">·</span>
      Submitted <?= htmlspecialchars(fmt_time($case['created_at']), ENT_QUOTES) ?>
    </p>

    <?php if (!empty($_GET['submitted'])): ?>
      <div class="alert alert-ok">Request submitted. Our team will review it and follow up as needed.</div>
    <?php endif; ?>

    <div class="card-panel">
      <h2>Owner</h2>
      <div class="kv-grid">
        <div class="k">Name</div><div class="v"><?= htmlspecialchars($case['owner_full_name'], ENT_QUOTES) ?></div>
        <div class="k">Email</div><div class="v"><?= htmlspecialchars($case['owner_email'], ENT_QUOTES) ?></div>
        <div class="k">Phone</div><div class="v"><?= htmlspecialchars($case['owner_phone'], ENT_QUOTES) ?></div>
        <div class="k">Address</div><div class="v"><?= htmlspecialchars($case['owner_address'], ENT_QUOTES) ?>, <?= htmlspecialchars($case['owner_city'], ENT_QUOTES) ?>, <?= htmlspecialchars($case['owner_state'], ENT_QUOTES) ?> <?= htmlspecialchars($case['owner_pin'], ENT_QUOTES) ?></div>
      </div>
    </div>

    <div class="card-panel">
      <h2>Patient</h2>
      <div class="kv-grid">
        <div class="k">Name</div><div class="v"><?= htmlspecialchars($case['patient_name'], ENT_QUOTES) ?></div>
        <div class="k">Species / breed</div><div class="v"><?= htmlspecialchars($case['patient_species'], ENT_QUOTES) ?> · <?= htmlspecialchars($case['patient_breed'] ?? '—', ENT_QUOTES) ?></div>
        <div class="k">Sex</div><div class="v"><?= htmlspecialchars(ucfirst($case['patient_sex']), ENT_QUOTES) ?></div>
        <div class="k">Weight</div><div class="v"><?= $case['patient_weight_kg'] !== null ? htmlspecialchars($case['patient_weight_kg'], ENT_QUOTES) . ' kg' : '—' ?></div>
        <div class="k">Requested formulation</div><div class="v"><?= ucfirst($case['requested_formulation']) ?></div>
      </div>
      <?php if ($case['clinical_notes']): ?>
        <p style="margin-top:1rem; font-size:0.8125rem; color:var(--paper-2); white-space:pre-wrap;"><?= htmlspecialchars($case['clinical_notes'], ENT_QUOTES) ?></p>
      <?php endif; ?>
    </div>

    <?php
    // Staff fill in the final formulation/price while a case is still being discussed or
    // reviewed — showing those fields to the vet at that point exposes numbers staff haven't
    // decided to share yet (and may still change). Only revealed once the case has actually
    // been approved/moved into fulfilment.
    $fulfilmentVisible = in_array($case['status'], ['approved','compounding','ready_for_dispatch','dispatched','finished'], true);
    ?>
    <?php if ($fulfilmentVisible && ($case['final_formulation'] || $case['final_price'] || $case['tracking_number'])): ?>
    <div class="card-panel">
      <h2>Formulation &amp; fulfilment</h2>
      <div class="kv-grid">
        <div class="k">Final formulation</div><div class="v"><?= htmlspecialchars($case['final_formulation'] ?? '—', ENT_QUOTES) ?></div>
        <div class="k">Concentration / strength</div><div class="v"><?= htmlspecialchars($case['final_concentration'] ?? '—', ENT_QUOTES) ?></div>
        <div class="k">Quantity</div><div class="v"><?= htmlspecialchars($case['final_quantity'] ?? '—', ENT_QUOTES) ?></div>
        <div class="k">Courier</div><div class="v"><?= htmlspecialchars($case['courier'] ?? '—', ENT_QUOTES) ?></div>
        <div class="k">Tracking number</div><div class="v"><?= htmlspecialchars($case['tracking_number'] ?? '—', ENT_QUOTES) ?></div>
        <div class="k">Dispatch date</div><div class="v"><?= htmlspecialchars($case['dispatch_date'] ?? '—', ENT_QUOTES) ?></div>
      </div>
    </div>
    <?php endif; ?>

    <div class="card-panel">
      <h2>Documents</h2>
      <?php if (!$documents): ?>
        <p style="font-size:0.8125rem; color:var(--paper-3);">No documents on file.</p>
      <?php endif; ?>
      <?php foreach ($documents as $d): ?>
        <div class="note-item">
          <span class="status-pill" style="margin-right:0.6rem;"><?= $d['doc_type'] === 'prescription' ? 'Prescription' : 'Supporting' ?></span>
          <a href="/download.php?kind=gs_request&doc_id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener noreferrer" style="color:var(--paper); border-bottom:1px solid var(--paper-3);"><?= htmlspecialchars($d['original_filename'], ENT_QUOTES) ?></a>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card-panel">
      <h2>Status history</h2>
      <?php foreach ($history as $h): ?>
        <div class="note-item">
          <div class="note-meta"><?= htmlspecialchars(fmt_time($h['created_at']), ENT_QUOTES) ?></div>
          <div class="note-body"><?= ucwords(str_replace('_',' ',$h['new_status'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
