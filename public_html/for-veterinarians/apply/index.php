<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/upload.php';
require __DIR__ . '/../../includes/data-protection.php';
require_once __DIR__ . '/../../includes/orphan-uploads.php';
require_once __DIR__ . '/../../includes/form-errors.php'; // field_class(), the per-field messages and the focus script

// Must run before any HTML output so the session cookie ships with the first
// response headers — csrf_field() alone (called later, inside the template)
// is too late and silently breaks CSRF verification on every submission.
csrf_token();

$errors  = [];
$success = false;
$rateHit = null;
// Raw POST until the early checks pass, then the cleaned `$fields` (see for-cat-owners/index.php). Keep it an array.
$old     = $_POST ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors['_form'] = 'Your session expired. Please review and submit the form again.';
    } elseif (!empty($_POST['website_url'])) {
        // Honeypot: this field is invisible to people (see the form) but bots that fill in
        // every input fill it in too. Pretend it worked so a script gets no signal to adapt to.
        header('Location: /request-received/');
        exit;
    } elseif (($rateHit = rate_limit_reserve('vet_apply_submit:' . client_ip(), 8, 60)) === null) {
        // Reserved here, handed back below if the submission is bounced for ordinary field errors.
        $errors['_form'] = 'Too many submissions from this connection. Please try again later.';
    } elseif (!consume_one_time_token('vet_apply_submit')) {
        // See for-cat-owners/index.php's identical guard — a double-click or an F5
        // resubmitting the same POST must not create a second application.
        $errors['_form'] = 'This form was already submitted. If you need to submit again, please reload the page first.';
    } elseif (!captcha_verify()) {
        $errors['_form'] = 'Please complete the verification check and submit again.';
    } else {
        $fields = [
            'full_name'            => trim($_POST['full_name'] ?? ''),
            'professional_email'   => trim($_POST['professional_email'] ?? ''),
            'mobile'               => trim($_POST['mobile'] ?? ''),
            'registration_number'  => trim($_POST['registration_number'] ?? ''),
            'registration_state'   => trim($_POST['registration_state'] ?? ''),
            'registration_country' => trim($_POST['registration_country'] ?? 'India'),
            'qualification'        => trim($_POST['qualification'] ?? ''),
            'year_qualified'       => trim($_POST['year_qualified'] ?? ''),
            'practice_type'        => trim($_POST['practice_type'] ?? ''),
            'clinic_name'          => trim($_POST['clinic_name'] ?? ''),
            'clinic_address'       => trim($_POST['clinic_address'] ?? ''),
            'clinic_city'          => trim($_POST['clinic_city'] ?? ''),
            'clinic_state'         => trim($_POST['clinic_state'] ?? ''),
            'clinic_pin'           => trim($_POST['clinic_pin'] ?? ''),
            'clinic_country'       => trim($_POST['clinic_country'] ?? 'India'),
            'clinic_phone'         => trim($_POST['clinic_phone'] ?? ''),
            'clinic_email'         => trim($_POST['clinic_email'] ?? ''),
            'clinic_website'       => trim($_POST['clinic_website'] ?? ''),
        ];

        $required = [
            'full_name','professional_email','mobile','registration_number','registration_state',
            'qualification','year_qualified','practice_type','clinic_name','clinic_address',
            'clinic_city','clinic_state','clinic_pin','clinic_phone',
        ];
        foreach ($required as $key) {
            if ($fields[$key] === '') {
                $errors[$key] = 'Please fill this in';
            }
        }
        // See for-cat-owners/index.php — column-size and format checks before the INSERT.
        $limits = [
            'full_name' => 150, 'professional_email' => 190, 'mobile' => 30, 'registration_number' => 100,
            'registration_state' => 100, 'registration_country' => 100, 'qualification' => 150, 'practice_type' => 100,
            'clinic_name' => 190, 'clinic_address' => 255, 'clinic_city' => 100, 'clinic_state' => 100,
            'clinic_pin' => 20, 'clinic_country' => 100, 'clinic_phone' => 30, 'clinic_email' => 190, 'clinic_website' => 255,
        ];
        foreach (field_length_errors($fields, $limits) as $key) {
            $errors[$key] = too_long_message($limits[$key]);
        }
        foreach (['mobile', 'clinic_phone'] as $phoneKey) {
            if ($fields[$phoneKey] !== '' && !preg_match('/^[0-9+()\-\s.]{5,30}$/', $fields[$phoneKey])) {
                $errors[$phoneKey] = 'Enter a valid phone number';
            }
        }
        if ($fields['clinic_website'] !== '' && !filter_var($fields['clinic_website'], FILTER_VALIDATE_URL)) {
            $errors['clinic_website'] = 'Enter a valid URL';
        }
        if ($fields['professional_email'] !== '' && !filter_var($fields['professional_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['professional_email'] = 'Enter a valid email address';
        }
        if ($fields['clinic_email'] !== '' && !filter_var($fields['clinic_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['clinic_email'] = 'Enter a valid email address';
        }
        $year = (int) $fields['year_qualified'];
        if ($year < 1950 || $year > (int) date('Y')) {
            $errors['year_qualified'] = 'Enter a valid year';
        }
        if (empty($_POST['consent'])) {
            $errors['consent'] = 'Consent is required to submit this application';
        }

        // The registration certificate is what staff verify the registration number against before
        // approving (see docs/vet-approval-checklist.md), so an application without one can't be reviewed.
        if (!upload_was_chosen('registration_certificate')) {
            $errors['registration_certificate'] = 'Please attach your veterinary registration certificate';
        }

        $uploads = [];
        if (empty($errors)) {
            $uploadField = 'registration_certificate'; // which file was being checked if one is refused
            try {
                foreach (['registration_certificate', 'professional_id', 'other_document'] as $docField) {
                    $uploadField = $docField;
                    if (upload_was_chosen($docField)) {
                        $uploads[$docField] = store_uploaded_file($_FILES[$docField], 'vet-applications/pending');
                    }
                }
            } catch (UploadException $e) {
                // A later document (e.g. the third of three) can fail validation after an
                // earlier one was already written to the pending bucket — clean those up
                // too, or they're orphaned forever since $errors being non-empty means the
                // DB transaction below (which would otherwise relocate or reference them)
                // never runs.
                foreach ($uploads as $meta) {
                    delete_uploaded_file($meta['stored_filename'], 'vet-applications/pending');
                }
                // The reason is printed under the file that failed; the banner stays generic. '_form' is set so this
                // still counts against the hourly allowance (upload failures do, ordinary field typos do not).
                $errors[$uploadField] = $e->getMessage();
                $errors['_form'] = 'Please check the highlighted fields below and try again.';
            }
        }

        if (!empty($errors) && empty($errors['_form'])) {
            $errors['_form'] = 'Please check the highlighted fields below and try again.';
            rate_limit_release($rateHit); // a typo being corrected isn't a submission
        }

        if (empty($errors)) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO vet_applications
                        (full_name, professional_email, mobile, registration_number, registration_state,
                         registration_country, qualification, year_qualified, practice_type, clinic_name,
                         clinic_address, clinic_city, clinic_state, clinic_pin, clinic_country, clinic_phone,
                         clinic_email, clinic_website)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $fields['full_name'], $fields['professional_email'], $fields['mobile'],
                    $fields['registration_number'], $fields['registration_state'], $fields['registration_country'],
                    $fields['qualification'], $year, $fields['practice_type'], $fields['clinic_name'],
                    $fields['clinic_address'], $fields['clinic_city'], $fields['clinic_state'], $fields['clinic_pin'],
                    $fields['clinic_country'], $fields['clinic_phone'],
                    $fields['clinic_email'] ?: null, $fields['clinic_website'] ?: null,
                ]);
                $applicationId = (int) $pdo->lastInsertId();

                if ($uploads) {
                    $docStmt = $pdo->prepare(
                        'INSERT INTO vet_application_documents
                            (application_id, stored_filename, original_filename, mime_type, size_bytes)
                         VALUES (?,?,?,?,?)'
                    );
                    foreach ($uploads as $meta) {
                        relocate_uploaded_file($meta['stored_filename'], 'vet-applications/pending', "vet-applications/{$applicationId}");
                        $docStmt->execute([
                            $applicationId, $meta['stored_filename'], $meta['original_filename'],
                            $meta['mime_type'], $meta['size_bytes'],
                        ]);
                    }
                }

                // The proof of consent is its own record, like a case's consent_records row (consent_records
                // belongs to gs_requests, so applications have vet_application_consents): time, IP address,
                // browser and the Privacy Policy version, saved in the same transaction as the application.
                // The audit log is not a consent register - it holds no browser details and is not the record.
                $pdo->prepare(
                    'INSERT INTO vet_application_consents (application_id, consent_type, consent_text_version, ip_address, user_agent)
                     VALUES (?,?,?,?,?)'
                )->execute([
                    $applicationId, 'application_processing', privacy_policy_version(),
                    client_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);

                // Audited *inside* the transaction, before it commits - like for-cat-owners/index.php's own
                // submission. Auditing afterward meant that if audit()'s own INSERT ever failed, it would throw
                // uncaught, surfacing as an opaque 500 for a submission that had actually already succeeded -
                // which an applicant, seeing an error, would plausibly just resubmit. The IP is no longer copied
                // here: it lives in the consent record above, which erasure deletes.
                audit('application_submitted', 'vet_application', $applicationId, [
                    'consent_given'   => true,
                    'consent_version' => privacy_policy_version(),
                ], 'public');
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                // The uploaded file(s) already landed on disk before this transaction ran —
                // rolling back the DB rows without also removing them would leave them
                // orphaned forever. relocate_uploaded_file() may or may not have run yet
                // depending on where the failure happened, so check both the pending bucket
                // and the per-application folder for each file.
                foreach ($uploads as $meta) {
                    delete_uploaded_file($meta['stored_filename'], 'vet-applications/pending');
                    if (isset($applicationId)) {
                        delete_uploaded_file($meta['stored_filename'], "vet-applications/{$applicationId}");
                    }
                }
                $errors['_form'] = 'Something went wrong submitting your application. Please try again.';
            }

            if (empty($errors)) {
                maybe_sweep_orphan_uploads(); // tidies uploads orphaned by a request that died (see includes/orphan-uploads.php); never breaks this one
                gs_session_start();
                $_SESSION['gs_request_received'] = 'vet_application';
                header('Location: /request-received/');
                exit;
            }
        }
        $old = $fields;
        $old['consent'] = $_POST['consent'] ?? ''; // not part of $fields: do not make them tick it again
    }
}

$pageTitle       = 'Apply for a veterinary account | Kuronyx';
$pageDescription = 'Apply for a verified Kuronyx veterinary account. Applications are reviewed manually before access is granted.';
$canonical       = 'https://kuronyx.in/for-veterinarians/apply/';
$robotsNoindex   = true; // application form itself isn't a content page worth indexing
$activeNav       = 'for-veterinarians';
$wideWrap        = true;
require __DIR__ . '/../../includes/layout-header.php';

?>
    <p class="doc-eyebrow">For veterinarians · Account application</p>
    <h1 class="doc-title">Apply for a veterinary account</h1>
    <p class="doc-meta">Kuronyx Sciences<span class="sep">·</span>Manual review, not instant approval</p>

    <p class="lead">
      This is an application for a verified Kuronyx veterinary account. Submitting this form does not create an
      active account - our team manually reviews each application before granting access.
    </p>

    <?php if (!empty($errors['_form'])): ?>
      <div class="alert"><?= htmlspecialchars($errors['_form'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>
      <div style="position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden;" aria-hidden="true">
        <label>Leave this empty <input type="text" name="website_url" value="" tabindex="-1" autocomplete="off"></label>
      </div>
      <?= one_time_field('vet_apply_submit') ?>

      <fieldset>
        <legend>Veterinarian</legend>
        <div class="field-row two">
          <label class="<?= field_class($errors, 'full_name') ?>">
            <span class="lbl">Full name</span>
            <input type="text" name="full_name" value="<?= old_value($old, 'full_name') ?>" required<?= field_error_attrs($errors, 'full_name') ?>>
            <?= field_error_html($errors, 'full_name') ?>
          </label>
          <label class="<?= field_class($errors, 'professional_email') ?>">
            <span class="lbl">Professional email</span>
            <input type="email" name="professional_email" value="<?= old_value($old, 'professional_email') ?>" required<?= field_error_attrs($errors, 'professional_email') ?>>
            <?= field_error_html($errors, 'professional_email') ?>
          </label>
        </div>
        <div class="field-row two">
          <label class="<?= field_class($errors, 'mobile') ?>">
            <span class="lbl">Mobile number</span>
            <input type="tel" name="mobile" value="<?= old_value($old, 'mobile') ?>" required<?= field_error_attrs($errors, 'mobile') ?>>
            <?= field_error_html($errors, 'mobile') ?>
          </label>
          <label class="<?= field_class($errors, 'qualification') ?>">
            <span class="lbl">Qualification</span>
            <input type="text" name="qualification" placeholder="e.g. BVSc & AH" value="<?= old_value($old, 'qualification') ?>" required<?= field_error_attrs($errors, 'qualification') ?>>
            <?= field_error_html($errors, 'qualification') ?>
          </label>
        </div>
        <div class="field-row two">
          <label class="<?= field_class($errors, 'registration_number') ?>">
            <span class="lbl">Veterinary registration number</span>
            <input type="text" name="registration_number" value="<?= old_value($old, 'registration_number') ?>" required<?= field_error_attrs($errors, 'registration_number') ?>>
            <?= field_error_html($errors, 'registration_number') ?>
          </label>
          <label class="<?= field_class($errors, 'registration_state') ?>">
            <span class="lbl">Registration state / country</span>
            <input type="text" name="registration_state" placeholder="State" value="<?= old_value($old, 'registration_state') ?>" required<?= field_error_attrs($errors, 'registration_state') ?>>
            <?= field_error_html($errors, 'registration_state') ?>
          </label>
        </div>
        <div class="field-row two">
          <label class="field">
            <span class="lbl">Registration country</span>
            <input type="text" name="registration_country" value="<?= old_value($old, 'registration_country') ?: 'India' ?>">
          </label>
          <label class="<?= field_class($errors, 'year_qualified') ?>">
            <span class="lbl">Year of qualification</span>
            <input type="number" name="year_qualified" min="1950" max="<?= date('Y') ?>" value="<?= old_value($old, 'year_qualified') ?>" required<?= field_error_attrs($errors, 'year_qualified') ?>>
            <?= field_error_html($errors, 'year_qualified') ?>
          </label>
        </div>
        <label class="<?= field_class($errors, 'practice_type') ?>">
          <span class="lbl">Practice type</span>
          <select name="practice_type" required<?= field_error_attrs($errors, 'practice_type') ?>>
            <option value="">Select…</option>
            <?php foreach (['Independent practice','Multi-vet clinic','Hospital','Academic/teaching','Government','Other'] as $opt): ?>
              <option value="<?= htmlspecialchars($opt, ENT_QUOTES) ?>" <?= ($old['practice_type'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
          </select>
          <?= field_error_html($errors, 'practice_type') ?>
        </label>
      </fieldset>

      <fieldset>
        <legend>Clinic / Hospital</legend>
        <label class="<?= field_class($errors, 'clinic_name') ?>">
          <span class="lbl">Clinic / hospital name</span>
          <input type="text" name="clinic_name" value="<?= old_value($old, 'clinic_name') ?>" required<?= field_error_attrs($errors, 'clinic_name') ?>>
          <?= field_error_html($errors, 'clinic_name') ?>
        </label>
        <label class="<?= field_class($errors, 'clinic_address') ?>">
          <span class="lbl">Address</span>
          <input type="text" name="clinic_address" value="<?= old_value($old, 'clinic_address') ?>" required<?= field_error_attrs($errors, 'clinic_address') ?>>
          <?= field_error_html($errors, 'clinic_address') ?>
        </label>
        <div class="field-row three">
          <label class="<?= field_class($errors, 'clinic_city') ?>">
            <span class="lbl">City</span>
            <input type="text" name="clinic_city" value="<?= old_value($old, 'clinic_city') ?>" required<?= field_error_attrs($errors, 'clinic_city') ?>>
            <?= field_error_html($errors, 'clinic_city') ?>
          </label>
          <label class="<?= field_class($errors, 'clinic_state') ?>">
            <span class="lbl">State</span>
            <input type="text" name="clinic_state" value="<?= old_value($old, 'clinic_state') ?>" required<?= field_error_attrs($errors, 'clinic_state') ?>>
            <?= field_error_html($errors, 'clinic_state') ?>
          </label>
          <label class="<?= field_class($errors, 'clinic_pin') ?>">
            <span class="lbl">PIN</span>
            <input type="text" name="clinic_pin" value="<?= old_value($old, 'clinic_pin') ?>" required<?= field_error_attrs($errors, 'clinic_pin') ?>>
            <?= field_error_html($errors, 'clinic_pin') ?>
          </label>
        </div>
        <div class="field-row two">
          <label class="field">
            <span class="lbl">Country</span>
            <input type="text" name="clinic_country" value="<?= old_value($old, 'clinic_country') ?: 'India' ?>">
          </label>
          <label class="<?= field_class($errors, 'clinic_phone') ?>">
            <span class="lbl">Clinic phone</span>
            <input type="tel" name="clinic_phone" value="<?= old_value($old, 'clinic_phone') ?>" required<?= field_error_attrs($errors, 'clinic_phone') ?>>
            <?= field_error_html($errors, 'clinic_phone') ?>
          </label>
        </div>
        <div class="field-row two">
          <label class="<?= field_class($errors, 'clinic_email') ?>">
            <span class="lbl">Clinic email (optional)</span>
            <input type="email" name="clinic_email" value="<?= old_value($old, 'clinic_email') ?>"<?= field_error_attrs($errors, 'clinic_email') ?>>
            <?= field_error_html($errors, 'clinic_email') ?>
          </label>
          <label class="<?= field_class($errors, 'clinic_website') ?>">
            <span class="lbl">Website (optional)</span>
            <input type="url" name="clinic_website" value="<?= old_value($old, 'clinic_website') ?>"<?= field_error_attrs($errors, 'clinic_website') ?>>
            <?= field_error_html($errors, 'clinic_website') ?>
          </label>
        </div>
      </fieldset>

      <fieldset>
        <legend>Verification documents</legend>
        <p class="field-hint" style="margin-bottom:1rem;">PDF, JPG or PNG, up to <?= upload_size_text(upload_limit_bytes()) ?> each. The registration certificate is required, because we check your registration number against it. The other two documents are optional but help us verify you faster.</p>
        <?= reattach_files_note($errors, 'registration_certificate', 'your registration certificate or any other documents') ?>
        <div class="field-row two">
          <label class="<?= field_class($errors, 'registration_certificate') ?>">
            <span class="lbl">Veterinary registration certificate (required)</span>
            <input type="file" name="registration_certificate" accept=".pdf,.jpg,.jpeg,.png" data-max-bytes="<?= upload_limit_bytes() ?>" required<?= field_error_attrs($errors, 'registration_certificate') ?>>
            <?= field_error_html($errors, 'registration_certificate') ?>
          </label>
          <label class="<?= field_class($errors, 'professional_id') ?>">
            <span class="lbl">Professional ID (optional)</span>
            <input type="file" name="professional_id" accept=".pdf,.jpg,.jpeg,.png" data-max-bytes="<?= upload_limit_bytes() ?>"<?= field_error_attrs($errors, 'professional_id') ?>>
            <?= field_error_html($errors, 'professional_id') ?>
          </label>
        </div>
        <label class="<?= field_class($errors, 'other_document') ?>">
          <span class="lbl">Other supporting document (optional)</span>
          <input type="file" name="other_document" accept=".pdf,.jpg,.jpeg,.png" data-max-bytes="<?= upload_limit_bytes() ?>"<?= field_error_attrs($errors, 'other_document') ?>>
          <?= field_error_html($errors, 'other_document') ?>
        </label>
      </fieldset>

      <div class="<?= field_class($errors, 'consent', 'checkbox-field') ?>">
        <input type="checkbox" name="consent" id="consent" value="1"<?= field_error_attrs($errors, 'consent') ?> <?= !empty($old['consent']) ? 'checked' : '' ?>>
        <label for="consent">I confirm that I am authorised to provide this information and consent to Kuronyx processing the information and documents submitted for verification, communication and related compliance requirements, in accordance with the <a href="/privacy-policy/" target="_blank" style="color:var(--paper); border-bottom:1px solid var(--paper-3);">Kuronyx Privacy Notice</a>.</label>
      </div>
      <?= field_error_html($errors, 'consent') ?>

      <?= captcha_widget() ?>
      <button type="submit" class="btn-primary">Submit application</button>
    </form>
    <script src="/js/upload-limit.js"></script>
    <?= form_error_focus_script($errors) ?>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
