<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/upload.php';

// Must run before any HTML output so the session cookie ships with the first
// response headers — csrf_field() alone (called later, inside the template)
// is too late and silently breaks CSRF verification on every submission.
csrf_token();

$errors = [];
$old    = $_POST ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors['_form'] = 'Your session expired. Please review and submit the form again.';
    } elseif (rate_limited('cat_owner_submit:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 8, 60)) {
        $errors['_form'] = 'Too many submissions from this connection. Please try again later.';
    } else {
        record_rate_limit_hit('cat_owner_submit:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
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

            'vet_name'              => trim($_POST['vet_name'] ?? ''),
            'vet_clinic'            => trim($_POST['vet_clinic'] ?? ''),
            'vet_email'             => trim($_POST['vet_email'] ?? ''),
            'vet_phone'             => trim($_POST['vet_phone'] ?? ''),
            'vet_registration_info' => trim($_POST['vet_registration_info'] ?? ''),

            'requested_formulation' => trim($_POST['requested_formulation'] ?? ''),
        ];

        $required = [
            'owner_full_name','owner_email','owner_phone','owner_address','owner_city','owner_state','owner_pin',
            'patient_name',
            'vet_name','vet_clinic','vet_email','vet_phone',
            'requested_formulation',
        ];
        foreach ($required as $key) {
            if ($fields[$key] === '') {
                $errors[$key] = 'Required';
            }
        }
        if ($fields['owner_email'] !== '' && !filter_var($fields['owner_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['owner_email'] = 'Enter a valid email address';
        }
        if ($fields['vet_email'] !== '' && !filter_var($fields['vet_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['vet_email'] = 'Enter a valid email address';
        }
        if (!in_array($fields['requested_formulation'], ['injection', 'oral'], true)) {
            $errors['requested_formulation'] = 'Select a formulation';
        }
        if (empty($_POST['consent'])) {
            $errors['consent'] = 'Consent is required to submit this request';
        }
        $dob = null;
        if ($fields['patient_dob'] !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $fields['patient_dob']);
            if ($d && $d <= new DateTime()) {
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
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO gs_requests
                        (source, owner_full_name, owner_email, owner_phone, owner_address, owner_city, owner_state,
                         owner_pin, owner_country, patient_name, patient_breed, patient_sex, patient_dob,
                         patient_weight_kg, patient_neutered, patient_microchip, clinical_notes,
                         vet_name, vet_clinic, vet_email, vet_phone, vet_registration_info, requested_formulation)
                     VALUES (\'cat_owner\',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $fields['owner_full_name'], $fields['owner_email'], $fields['owner_phone'], $fields['owner_address'],
                    $fields['owner_city'], $fields['owner_state'], $fields['owner_pin'], $fields['owner_country'],
                    $fields['patient_name'], $fields['patient_breed'] ?: null, $fields['patient_sex'], $dob,
                    $weight, $fields['patient_neutered'], $fields['patient_microchip'] ?: null,
                    $fields['clinical_notes'] ?: null,
                    $fields['vet_name'], $fields['vet_clinic'], $fields['vet_email'], $fields['vet_phone'],
                    $fields['vet_registration_info'] ?: null, $fields['requested_formulation'],
                ]);
                $requestId = (int) $pdo->lastInsertId();

                relocate_uploaded_file($prescriptionUpload['stored_filename'], 'gs-requests/pending', "gs-requests/{$requestId}");
                foreach ($supportingUploads as $meta) {
                    relocate_uploaded_file($meta['stored_filename'], 'gs-requests/pending', "gs-requests/{$requestId}");
                }

                $docStmt = $pdo->prepare(
                    'INSERT INTO gs_request_documents (gs_request_id, doc_type, stored_filename, original_filename, mime_type, size_bytes)
                     VALUES (?,?,?,?,?,?)'
                );
                $docStmt->execute([
                    $requestId, 'prescription', $prescriptionUpload['stored_filename'],
                    $prescriptionUpload['original_filename'], $prescriptionUpload['mime_type'], $prescriptionUpload['size_bytes'],
                ]);
                foreach ($supportingUploads as $meta) {
                    $docStmt->execute([
                        $requestId, 'supporting', $meta['stored_filename'],
                        $meta['original_filename'], $meta['mime_type'], $meta['size_bytes'],
                    ]);
                }

                $consentStmt = $pdo->prepare(
                    'INSERT INTO consent_records (gs_request_id, consent_type, consent_text_version, ip_address, user_agent)
                     VALUES (?,?,?,?,?)'
                );
                $consentStmt->execute([
                    $requestId, 'case_processing', 'privacy-policy-2026-07-18',
                    $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
                if (!empty($_POST['marketing_consent'])) {
                    $consentStmt->execute([
                        $requestId, 'marketing', 'privacy-policy-2026-07-18',
                        $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    ]);
                }

                $historyStmt = $pdo->prepare(
                    'INSERT INTO case_status_history (gs_request_id, previous_status, new_status, changed_by, note)
                     VALUES (?, NULL, \'submitted\', NULL, \'Case submitted by cat owner\')'
                );
                $historyStmt->execute([$requestId]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors['_form'] = 'Something went wrong submitting your request. Please try again.';
            }

            if (empty($errors)) {
                audit('case_submitted', 'gs_request', $requestId, [
                    'source'         => 'cat_owner',
                    'source_ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
                    'consent_given'  => true,
                ], 'public');
                gs_session_start();
                $_SESSION['gs_request_received'] = 'case';
                header('Location: /request-received');
                exit;
            }
        }
        $old = $fields;
    }
}

$pageTitle       = 'For Cat Owners — GS-441524 Requests | Kuronyx Sciences';
$pageDescription = 'Submit your cat\'s information and your treating veterinarian\'s prescription for GS-441524 review by Kuronyx Sciences.';
$canonical       = 'https://kuronyx.in/for-cat-owners';
$activeNav       = 'for-cat-owners';
$wideWrap        = true;
require __DIR__ . '/../includes/layout-header.php';

function fc(array $errors, string $key): string {
    return 'field' . (isset($errors[$key]) ? ' has-error' : '');
}
function ov(array $old, string $key): string {
    return htmlspecialchars($old[$key] ?? '', ENT_QUOTES);
}
?>
    <p class="doc-eyebrow">For cat owners / caregivers · GS-441524</p>
    <h1 class="doc-title">GS-441524, reviewed for your cat.</h1>
    <p class="doc-meta">Kuronyx Sciences<span class="sep">·</span>No account required</p>

    <p class="lead">
      GS-441524 is an antiviral compound used in veterinary medicine, most often in connection with the treatment
      of feline infectious peritonitis (FIP), under the diagnosis and supervision of your treating veterinarian.
      Kuronyx compounds to prescription, on a patient-by-patient basis — we do not diagnose your cat or replace
      your veterinarian at any point.
    </p>

    <div class="notice">
      <span class="notice-label">Prescription required</span>
      <p>A valid veterinary prescription is required before Kuronyx can review and process this request. Dosing and treatment decisions belong solely to your treating veterinarian.</p>
    </div>

    <p class="lead">
      Submit your cat's information along with your treating veterinarian's prescription below. Our team will
      review the prescription and information provided, and a member of the Kuronyx team will contact you by
      email regarding the request. You do not need to create an account.
    </p>

    <?php if (!empty($errors['_form'])): ?>
      <div class="alert"><?= htmlspecialchars($errors['_form'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>

      <fieldset>
        <legend>Owner information</legend>
        <div class="field-row two">
          <label class="<?= fc($errors, 'owner_full_name') ?>">
            <span class="lbl">Full name</span>
            <input type="text" name="owner_full_name" value="<?= ov($old, 'owner_full_name') ?>" required>
          </label>
          <label class="<?= fc($errors, 'owner_email') ?>">
            <span class="lbl">Email</span>
            <input type="email" name="owner_email" value="<?= ov($old, 'owner_email') ?>" required>
          </label>
        </div>
        <div class="field-row two">
          <label class="<?= fc($errors, 'owner_phone') ?>">
            <span class="lbl">Mobile number</span>
            <input type="tel" name="owner_phone" value="<?= ov($old, 'owner_phone') ?>" required>
          </label>
          <label class="<?= fc($errors, 'owner_address') ?>">
            <span class="lbl">Address</span>
            <input type="text" name="owner_address" value="<?= ov($old, 'owner_address') ?>" required>
          </label>
        </div>
        <div class="field-row three">
          <label class="<?= fc($errors, 'owner_city') ?>">
            <span class="lbl">City</span>
            <input type="text" name="owner_city" value="<?= ov($old, 'owner_city') ?>" required>
          </label>
          <label class="<?= fc($errors, 'owner_state') ?>">
            <span class="lbl">State</span>
            <input type="text" name="owner_state" value="<?= ov($old, 'owner_state') ?>" required>
          </label>
          <label class="<?= fc($errors, 'owner_pin') ?>">
            <span class="lbl">PIN</span>
            <input type="text" name="owner_pin" value="<?= ov($old, 'owner_pin') ?>" required>
          </label>
        </div>
        <label class="field">
          <span class="lbl">Country</span>
          <input type="text" name="owner_country" value="<?= ov($old, 'owner_country') ?: 'India' ?>">
        </label>
      </fieldset>

      <fieldset>
        <legend>Cat information</legend>
        <div class="field-row two">
          <label class="<?= fc($errors, 'patient_name') ?>">
            <span class="lbl">Cat's name</span>
            <input type="text" name="patient_name" value="<?= ov($old, 'patient_name') ?>" required>
          </label>
          <label class="field">
            <span class="lbl">Breed</span>
            <input type="text" name="patient_breed" value="<?= ov($old, 'patient_breed') ?>">
          </label>
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
          <label class="<?= fc($errors, 'patient_dob') ?>">
            <span class="lbl">Date of birth (if known)</span>
            <input type="date" name="patient_dob" value="<?= ov($old, 'patient_dob') ?>" max="<?= date('Y-m-d') ?>">
          </label>
          <label class="<?= fc($errors, 'patient_weight_kg') ?>">
            <span class="lbl">Weight (kg)</span>
            <input type="number" step="0.01" min="0" name="patient_weight_kg" value="<?= ov($old, 'patient_weight_kg') ?>">
          </label>
        </div>
        <div class="field-row two">
          <label class="field">
            <span class="lbl">Microchip / patient ID (optional)</span>
            <input type="text" name="patient_microchip" value="<?= ov($old, 'patient_microchip') ?>">
          </label>
          <div class="checkbox-field" style="margin:0; align-items:center;">
            <input type="checkbox" name="patient_neutered" id="patient_neutered" value="1" <?= !empty($old['patient_neutered']) ? 'checked' : '' ?>>
            <label for="patient_neutered">Neutered / spayed</label>
          </div>
        </div>
        <label class="field">
          <span class="lbl">Relevant clinical information (optional)</span>
          <textarea name="clinical_notes" rows="4" placeholder="Diagnosis, symptoms, or anything your veterinarian has shared that's relevant to this request."><?= ov($old, 'clinical_notes') ?></textarea>
        </label>
      </fieldset>

      <fieldset>
        <legend>Treating veterinarian</legend>
        <div class="field-row two">
          <label class="<?= fc($errors, 'vet_name') ?>">
            <span class="lbl">Veterinarian name</span>
            <input type="text" name="vet_name" value="<?= ov($old, 'vet_name') ?>" required>
          </label>
          <label class="<?= fc($errors, 'vet_clinic') ?>">
            <span class="lbl">Clinic / hospital</span>
            <input type="text" name="vet_clinic" value="<?= ov($old, 'vet_clinic') ?>" required>
          </label>
        </div>
        <div class="field-row two">
          <label class="<?= fc($errors, 'vet_email') ?>">
            <span class="lbl">Veterinarian email</span>
            <input type="email" name="vet_email" value="<?= ov($old, 'vet_email') ?>" required>
          </label>
          <label class="<?= fc($errors, 'vet_phone') ?>">
            <span class="lbl">Veterinarian phone</span>
            <input type="tel" name="vet_phone" value="<?= ov($old, 'vet_phone') ?>" required>
          </label>
        </div>
        <label class="field">
          <span class="lbl">Registration information (optional)</span>
          <input type="text" name="vet_registration_info" value="<?= ov($old, 'vet_registration_info') ?>">
        </label>
      </fieldset>

      <fieldset>
        <legend>Requested formulation</legend>
        <p class="field-hint" style="margin-bottom:1rem;">Subject to prescription review and may be discussed or modified with the treating veterinarian before fulfilment.</p>
        <div class="radio-row <?= isset($errors['requested_formulation']) ? 'has-error' : '' ?>">
          <div class="radio-field">
            <input type="radio" name="requested_formulation" id="f-injection" value="injection" <?= ($old['requested_formulation'] ?? '') === 'injection' ? 'checked' : '' ?>>
            <label for="f-injection">Injection</label>
          </div>
          <div class="radio-field">
            <input type="radio" name="requested_formulation" id="f-oral" value="oral" <?= ($old['requested_formulation'] ?? '') === 'oral' ? 'checked' : '' ?>>
            <label for="f-oral">Oral tablet / pill</label>
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend>Veterinary prescription</legend>
        <label class="<?= fc($errors, 'prescription') ?>">
          <span class="lbl">Prescription upload (PDF, JPG or PNG) — required</span>
          <input type="file" name="prescription" accept=".pdf,.jpg,.jpeg,.png" required>
        </label>
      </fieldset>

      <fieldset>
        <legend>Optional supporting documents</legend>
        <p class="field-hint" style="margin-bottom:1rem;">Laboratory reports, diagnostic reports, imaging, or other clinical documentation.</p>
        <div class="field-row two">
          <label class="field">
            <span class="lbl">Document 1</span>
            <input type="file" name="supporting_1" accept=".pdf,.jpg,.jpeg,.png">
          </label>
          <label class="field">
            <span class="lbl">Document 2</span>
            <input type="file" name="supporting_2" accept=".pdf,.jpg,.jpeg,.png">
          </label>
        </div>
      </fieldset>

      <div class="checkbox-field <?= isset($errors['consent']) ? 'has-error' : '' ?>">
        <input type="checkbox" name="consent" id="consent" value="1" <?= !empty($old['consent']) ? 'checked' : '' ?>>
        <label for="consent">I confirm that I am authorised to provide this information and consent to Kuronyx processing the information and documents submitted for prescription review, communication, formulation, fulfilment and related compliance requirements, in accordance with the <a href="/Privacy%20Policy.html" target="_blank" style="color:var(--paper); border-bottom:1px solid var(--paper-3);">Kuronyx Privacy Notice</a>.</label>
      </div>
      <div class="checkbox-field">
        <input type="checkbox" name="marketing_consent" id="marketing_consent" value="1" <?= !empty($old['marketing_consent']) ? 'checked' : '' ?>>
        <label for="marketing_consent">I'd also like to receive occasional updates from Kuronyx by email (optional).</label>
      </div>

      <button type="submit" class="btn-primary">Submit Request</button>
    </form>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
