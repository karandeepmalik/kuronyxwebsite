<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/upload.php';
$vet = require_vet_login();

$pdo = db();
$vetApp = $pdo->prepare('SELECT * FROM vet_applications WHERE id = ? LIMIT 1');
$vetApp->execute([$vet['vet_application_id']]);
$vetApp = $vetApp->fetch();

csrf_token();

$errors = [];
$old    = $_POST ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors['_form'] = 'Your session expired. Please review and submit the form again.';
    } else {
        $fields = [
            'owner_full_name' => trim($_POST['owner_full_name'] ?? ''),
            'owner_email'     => trim($_POST['owner_email'] ?? ''),
            'owner_phone'     => trim($_POST['owner_phone'] ?? ''),
            'owner_address'   => trim($_POST['owner_address'] ?? ''),
            'owner_city'      => trim($_POST['owner_city'] ?? ''),
            'owner_state'     => trim($_POST['owner_state'] ?? ''),
            'owner_pin'       => trim($_POST['owner_pin'] ?? ''),
            'owner_country'   => trim($_POST['owner_country'] ?? 'India'),

            'patient_name'      => trim($_POST['patient_name'] ?? ''),
            'patient_breed'     => trim($_POST['patient_breed'] ?? ''),
            'patient_sex'       => trim($_POST['patient_sex'] ?? 'unknown'),
            'patient_dob'       => trim($_POST['patient_dob'] ?? ''),
            'patient_weight_kg' => trim($_POST['patient_weight_kg'] ?? ''),
            'patient_neutered'  => isset($_POST['patient_neutered']) ? 1 : 0,
            'patient_microchip' => trim($_POST['patient_microchip'] ?? ''),
            'clinical_notes'    => trim($_POST['clinical_notes'] ?? ''),

            'requested_formulation' => trim($_POST['requested_formulation'] ?? ''),
        ];

        $required = ['owner_full_name','owner_email','owner_phone','owner_address','owner_city','owner_state','owner_pin','patient_name','requested_formulation'];
        foreach ($required as $key) {
            if ($fields[$key] === '') $errors[$key] = 'Required';
        }
        if ($fields['owner_email'] !== '' && !filter_var($fields['owner_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['owner_email'] = 'Enter a valid email address';
        }
        if (!in_array($fields['requested_formulation'], ['injection', 'oral'], true)) {
            $errors['requested_formulation'] = 'Select a formulation';
        }
        if (empty($_POST['consent'])) {
            $errors['consent'] = 'Please confirm the attestation to submit this request';
        }
        $dob = null;
        if ($fields['patient_dob'] !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $fields['patient_dob']);
            // createFromFormat() silently rolls invalid calendar dates over to the next
            // valid one (e.g. "2026-02-30" becomes March 2) instead of failing, so a
            // mismatch between the input and the round-tripped output is the only way to
            // catch that — $d alone being truthy isn't enough.
            if ($d && $d->format('Y-m-d') === $fields['patient_dob'] && $d <= new DateTime()) {
                $dob = $fields['patient_dob'];
            } else {
                $errors['patient_dob'] = 'Enter a valid date';
            }
        }
        $weight = null;
        if ($fields['patient_weight_kg'] !== '') {
            if (is_numeric($fields['patient_weight_kg']) && (float) $fields['patient_weight_kg'] > 0) {
                $weight = (float) $fields['patient_weight_kg'];
            } else {
                $errors['patient_weight_kg'] = 'Enter a valid weight';
            }
        }

        $prescriptionUpload = null;
        $supportingUploads  = [];
        if (empty($_FILES['prescription']['name'])) {
            $errors['prescription'] = 'A prescription upload is required';
        } elseif (empty($errors)) {
            try {
                $prescriptionUpload = store_uploaded_file($_FILES['prescription'], 'gs-requests/pending');
                foreach (['supporting_1', 'supporting_2'] as $docField) {
                    if (!empty($_FILES[$docField]['name'])) {
                        $supportingUploads[] = store_uploaded_file($_FILES[$docField], 'gs-requests/pending');
                    }
                }
            } catch (UploadException $e) {
                $errors['_form'] = $e->getMessage();
            }
        }

        if (!empty($errors) && empty($errors['_form'])) {
            $errors['_form'] = 'Please check the highlighted fields below and try again.';
        }

        if (empty($errors)) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO gs_requests
                        (source, vet_account_id, owner_full_name, owner_email, owner_phone, owner_address, owner_city, owner_state,
                         owner_pin, owner_country, patient_name, patient_breed, patient_sex, patient_dob,
                         patient_weight_kg, patient_neutered, patient_microchip, clinical_notes,
                         vet_name, vet_clinic, vet_email, vet_phone, vet_registration_info, requested_formulation)
                     VALUES (\'veterinarian\',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $vet['id'],
                    $fields['owner_full_name'], $fields['owner_email'], $fields['owner_phone'], $fields['owner_address'],
                    $fields['owner_city'], $fields['owner_state'], $fields['owner_pin'], $fields['owner_country'],
                    $fields['patient_name'], $fields['patient_breed'] ?: null, $fields['patient_sex'], $dob,
                    $weight, $fields['patient_neutered'], $fields['patient_microchip'] ?: null,
                    $fields['clinical_notes'] ?: null,
                    $vetApp['full_name'], $vetApp['clinic_name'], $vetApp['professional_email'], $vetApp['mobile'],
                    $vetApp['registration_number'] . ' (' . $vetApp['registration_state'] . ')', $fields['requested_formulation'],
                ]);
                $requestId = (int) $pdo->lastInsertId();

                relocate_uploaded_file($prescriptionUpload['stored_filename'], 'gs-requests/pending', "gs-requests/{$requestId}");
                $docStmt = $pdo->prepare(
                    'INSERT INTO gs_request_documents (gs_request_id, doc_type, stored_filename, original_filename, mime_type, size_bytes)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $docStmt->execute([
                    $requestId, 'prescription', $prescriptionUpload['stored_filename'],
                    $prescriptionUpload['original_filename'], $prescriptionUpload['mime_type'], $prescriptionUpload['size_bytes'],
                ]);
                foreach ($supportingUploads as $meta) {
                    relocate_uploaded_file($meta['stored_filename'], 'gs-requests/pending', "gs-requests/{$requestId}");
                    $docStmt->execute([$requestId, 'supporting', $meta['stored_filename'], $meta['original_filename'], $meta['mime_type'], $meta['size_bytes']]);
                }

                $consentStmt = $pdo->prepare(
                    'INSERT INTO consent_records (gs_request_id, consent_type, consent_text_version, ip_address, user_agent)
                     VALUES (?, \'case_processing\', \'privacy-policy-2026-07-18\', ?, ?)'
                );
                $consentStmt->execute([$requestId, $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);

                $pdo->prepare(
                    'INSERT INTO case_status_history (gs_request_id, previous_status, new_status, changed_by, note)
                     VALUES (?, NULL, \'submitted\', NULL, \'Case submitted by veterinarian via portal\')'
                )->execute([$requestId]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                // The uploaded file(s) already landed on disk before this transaction ran —
                // rolling back the DB rows without also removing them would leave them
                // orphaned forever. relocate_uploaded_file() may or may not have run yet
                // depending on where the failure happened, so check both the pending bucket
                // and the per-request folder for each file.
                foreach (array_merge([$prescriptionUpload], $supportingUploads) as $meta) {
                    if (!$meta) continue;
                    delete_uploaded_file($meta['stored_filename'], 'gs-requests/pending');
                    if (isset($requestId)) {
                        delete_uploaded_file($meta['stored_filename'], "gs-requests/{$requestId}");
                    }
                }
                $errors['_form'] = 'Something went wrong submitting your request. Please try again.';
            }

            if (empty($errors)) {
                audit('case_submitted', 'gs_request', $requestId, ['source' => 'veterinarian', 'vet_account_id' => $vet['id']], 'public');
                header('Location: /for-veterinarians/portal/view.php?id=' . $requestId . '&submitted=1');
                exit;
            }
        }
        $old = $fields;
    }
}

$pageTitle     = 'New GS-441524 Request — Kuronyx Veterinary Portal';
$robotsNoindex = true;
$activeNav     = 'for-veterinarians';
$backHref      = '/for-veterinarians/portal/';
$backLabel     = 'Your requests';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';

function fc(array $errors, string $key): string { return 'field' . (isset($errors[$key]) ? ' has-error' : ''); }
function ov(array $old, string $key): string { return htmlspecialchars($old[$key] ?? '', ENT_QUOTES); }
?>
    <p class="doc-eyebrow">Veterinary portal · New request</p>
    <h1 class="doc-title">Submit a GS-441524 request</h1>
    <p class="doc-meta">Submitting as <?= htmlspecialchars($vetApp['full_name'], ENT_QUOTES) ?><span class="sep">·</span><?= htmlspecialchars($vetApp['clinic_name'], ENT_QUOTES) ?></p>

    <?php if (!empty($errors['_form'])): ?>
      <div class="alert"><?= htmlspecialchars($errors['_form'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>

      <fieldset>
        <legend>Owner information</legend>
        <div class="field-row two">
          <label class="<?= fc($errors, 'owner_full_name') ?>"><span class="lbl">Full name</span><input type="text" name="owner_full_name" value="<?= ov($old, 'owner_full_name') ?>" required></label>
          <label class="<?= fc($errors, 'owner_email') ?>"><span class="lbl">Email</span><input type="email" name="owner_email" value="<?= ov($old, 'owner_email') ?>" required></label>
        </div>
        <div class="field-row two">
          <label class="<?= fc($errors, 'owner_phone') ?>"><span class="lbl">Mobile number</span><input type="tel" name="owner_phone" value="<?= ov($old, 'owner_phone') ?>" required></label>
          <label class="<?= fc($errors, 'owner_address') ?>"><span class="lbl">Address</span><input type="text" name="owner_address" value="<?= ov($old, 'owner_address') ?>" required></label>
        </div>
        <div class="field-row three">
          <label class="<?= fc($errors, 'owner_city') ?>"><span class="lbl">City</span><input type="text" name="owner_city" value="<?= ov($old, 'owner_city') ?>" required></label>
          <label class="<?= fc($errors, 'owner_state') ?>"><span class="lbl">State</span><input type="text" name="owner_state" value="<?= ov($old, 'owner_state') ?>" required></label>
          <label class="<?= fc($errors, 'owner_pin') ?>"><span class="lbl">PIN</span><input type="text" name="owner_pin" value="<?= ov($old, 'owner_pin') ?>" required></label>
        </div>
        <label class="field"><span class="lbl">Country</span><input type="text" name="owner_country" value="<?= ov($old, 'owner_country') ?: 'India' ?>"></label>
      </fieldset>

      <fieldset>
        <legend>Patient</legend>
        <div class="field-row two">
          <label class="<?= fc($errors, 'patient_name') ?>"><span class="lbl">Cat's name</span><input type="text" name="patient_name" value="<?= ov($old, 'patient_name') ?>" required></label>
          <label class="field"><span class="lbl">Breed</span><input type="text" name="patient_breed" value="<?= ov($old, 'patient_breed') ?>"></label>
        </div>
        <div class="field-row three">
          <label class="field">
            <span class="lbl">Sex</span>
            <select name="patient_sex">
              <?php foreach (['unknown' => 'Unknown', 'male' => 'Male', 'female' => 'Female'] as $val => $label): ?>
                <option value="<?= $val ?>" <?= ($old['patient_sex'] ?? 'unknown') === $val ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="<?= fc($errors, 'patient_dob') ?>"><span class="lbl">Date of birth (if known)</span><input type="date" name="patient_dob" value="<?= ov($old, 'patient_dob') ?>" max="<?= date('Y-m-d') ?>"></label>
          <label class="<?= fc($errors, 'patient_weight_kg') ?>"><span class="lbl">Weight (kg)</span><input type="number" step="0.01" min="0" name="patient_weight_kg" value="<?= ov($old, 'patient_weight_kg') ?>"></label>
        </div>
        <div class="field-row two">
          <label class="field"><span class="lbl">Microchip / patient ID (optional)</span><input type="text" name="patient_microchip" value="<?= ov($old, 'patient_microchip') ?>"></label>
          <div class="checkbox-field" style="margin:0; align-items:center;">
            <input type="checkbox" name="patient_neutered" id="patient_neutered" value="1" <?= !empty($old['patient_neutered']) ? 'checked' : '' ?>>
            <label for="patient_neutered">Neutered / spayed</label>
          </div>
        </div>
        <label class="field"><span class="lbl">Clinical notes</span><textarea name="clinical_notes" rows="4"><?= ov($old, 'clinical_notes') ?></textarea></label>
      </fieldset>

      <fieldset>
        <legend>Requested formulation</legend>
        <div class="radio-row <?= isset($errors['requested_formulation']) ? 'has-error' : '' ?>">
          <div class="radio-field"><input type="radio" name="requested_formulation" id="f-injection" value="injection" <?= ($old['requested_formulation'] ?? '') === 'injection' ? 'checked' : '' ?>><label for="f-injection">Injection</label></div>
          <div class="radio-field"><input type="radio" name="requested_formulation" id="f-oral" value="oral" <?= ($old['requested_formulation'] ?? '') === 'oral' ? 'checked' : '' ?>><label for="f-oral">Oral tablet / pill</label></div>
        </div>
      </fieldset>

      <fieldset>
        <legend>Prescription</legend>
        <label class="<?= fc($errors, 'prescription') ?>">
          <span class="lbl">Prescription upload (PDF, JPG or PNG) — required</span>
          <input type="file" name="prescription" accept=".pdf,.jpg,.jpeg,.png" required>
        </label>
      </fieldset>

      <fieldset>
        <legend>Supporting documents (optional)</legend>
        <p class="field-hint" style="margin-bottom:1rem;">Laboratory reports, diagnostic reports, imaging, or other clinical documentation.</p>
        <div class="field-row two">
          <label class="field"><span class="lbl">Document 1</span><input type="file" name="supporting_1" accept=".pdf,.jpg,.jpeg,.png"></label>
          <label class="field"><span class="lbl">Document 2</span><input type="file" name="supporting_2" accept=".pdf,.jpg,.jpeg,.png"></label>
        </div>
      </fieldset>

      <div class="checkbox-field <?= isset($errors['consent']) ? 'has-error' : '' ?>">
        <input type="checkbox" name="consent" id="consent" value="1" <?= !empty($old['consent']) ? 'checked' : '' ?>>
        <label for="consent">I confirm I am the treating veterinarian for this patient and am authorising this GS-441524 compounding request on that basis.</label>
      </div>

      <button type="submit" class="btn-primary">Submit Request</button>
    </form>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
