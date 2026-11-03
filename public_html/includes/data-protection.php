<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/storage-path.php';
require_once __DIR__ . '/case-statuses.php';

// Identifies the exact Privacy Policy text a consent was given against: a short fingerprint of
// the policy file itself. It used to be a hand-typed date string nothing kept in step with the
// policy, so editing the policy would silently leave every new consent pointing at the old
// "version". Now any edit to the file produces a new value automatically, and every stored
// consent can be traced to the precise wording that was on the site at that moment.
function privacy_policy_version(): string {
    static $version = null;
    if ($version === null) {
        $path = dirname(__DIR__) . '/Privacy Policy.html';
        $hash = is_file($path) ? hash_file('sha256', $path) : false;
        $version = 'pp-' . ($hash !== false ? substr($hash, 0, 16) : 'unknown');
    }
    return $version;
}

// How long after a case reached its final status it shows up on the data-erasure page's review
// list. Nothing is ever deleted automatically — see admin/data-erasure/index.php. Set
// RETENTION_MONTHS in db-config.php once the pharmacy has confirmed how long prescription
// and dispensing records must legally be kept (and whether that clock starts when the case is
// closed, as it does here, or from the dispatch date); 36 is only a placeholder default.
function retention_months(): int {
    return defined('RETENTION_MONTHS') ? max(1, (int) RETENTION_MONTHS) : 36;
}

// A case can only be erased once it is finished with. An open case is still being worked on
// (and its prescription is still needed); close or cancel it first.
const ERASABLE_CASE_STATUSES = GS_TERMINAL_STATUSES; // defined once, in case-statuses.php

// Terminal-status cases that reached that status more than retention_months() ago and are not
// already erased, oldest first - the review list, not an automatic deletion queue.
//
// The clock is `closed_at`: when the case entered the status it is in now, i.e. the newest
// case_status_history row whose new_status equals the current status (so a case that was
// reopened and closed again counts from the later close). It is NOT gs_requests.updated_at: that
// resets on every write to the row - fixing a price, assigning someone, the lock_version bump -
// so a long-closed case that merely got edited would drop off the list. Every status change
// writes its history row in the same transaction, so history is the record of when it closed;
// a case with no history row at all (older data) falls back to updated_at rather than vanishing.
function erasure_candidates(PDO $pdo, int $limit = 100): array {
    $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . retention_months() . ' months'));
    $in     = implode(',', array_fill(0, count(ERASABLE_CASE_STATUSES), '?'));
    $stmt   = $pdo->prepare(
        "SELECT c.id, c.status, c.source, c.created_at, c.updated_at, c.closed_at FROM (
             SELECT g.id, g.status, g.source, g.created_at, g.updated_at,
                    COALESCE(
                        (SELECT MAX(h.created_at) FROM case_status_history h
                          WHERE h.gs_request_id = g.id AND h.new_status = g.status),
                        g.updated_at
                    ) AS closed_at
               FROM gs_requests g
              WHERE g.erased_at IS NULL AND g.status IN ({$in})
         ) c
         WHERE c.closed_at < ?
         ORDER BY c.closed_at ASC, c.id ASC LIMIT " . (int) $limit
    );
    $stmt->execute([...ERASABLE_CASE_STATUSES, $cutoff]);
    return $stmt->fetchAll();
}

// Permanently erases the personal data held on one GS-441524 case, in response to a deletion
// request or once the retention period has passed:
//   - the uploaded prescription / supporting files are deleted from disk and the DB
//   - owner, patient and veterinarian details on the case are blanked
//   - consent records (which hold the visitor's IP and browser), every email logged against the
//     case, and the free-text internal notes are deleted
//   - status-history notes and the metadata on this case's audit entries (which can hold email
//     addresses and IPs) are cleared
// The case row itself, its status history (without notes), the formulation/price and the audit
// entries (without metadata) are kept so the pharmacy can still show that a case existed and
// what happened to it, without any of it identifying a person. Returns ['ok' => bool,
// 'message' => string, 'failed_files' => string[]].
//
// The files are deleted from disk only AFTER the database change commits (a rolled-back erasure
// must not have destroyed the documents), so two things can go wrong afterwards: a delete fails,
// or the process dies before it runs. Neither may be silent, and by then the document rows that
// named the files are gone. So the stored filenames (random hex names, not personal data) are
// written into the `case_erased` audit entry INSIDE the transaction - a durable record of what had
// to go - and afterwards either `case_erasure_files_deleted` (all gone) or
// `case_erasure_files_failed` (named files still there) is added. erasure_leftover_files() reads
// that trail to find files still on disk, and 'ok' stays true because the database part did
// succeed, but a failed delete is reported as 'failed_files' and the page shows it as an error.
// $deleteFile exists so tests can make a delete fail; production always uses unlink().
function erase_gs_request(PDO $pdo, int $id, ?callable $deleteFile = null): array {
    $files = [];
    $result = run_serialized_transaction($pdo, function (PDO $pdo) use ($id, &$files) {
        $stmt = $pdo->prepare('SELECT id, status, erased_at FROM gs_requests WHERE id = ?' . locking_read_suffix());
        $stmt->execute([$id]);
        $case = $stmt->fetch();
        if (!$case) {
            return ['ok' => false, 'message' => "Case GS-{$id} does not exist."];
        }
        if ($case['erased_at'] !== null) {
            return ['ok' => false, 'message' => "Case GS-{$id} has already been erased."];
        }
        if (!in_array($case['status'], ERASABLE_CASE_STATUSES, true)) {
            return ['ok' => false, 'message' => "Case GS-{$id} is still open (status: {$case['status']}). Set it to finished, closed, cancelled or rejected first."];
        }

        $docs = $pdo->prepare('SELECT id, stored_filename FROM gs_request_documents WHERE gs_request_id = ?');
        $docs->execute([$id]);
        $docs = $docs->fetchAll();
        $files = array_column($docs, 'stored_filename');
        $docIds = array_map('intval', array_column($docs, 'id'));

        foreach (['gs_request_documents', 'consent_records', 'case_emails', 'case_internal_notes'] as $table) {
            $pdo->prepare("DELETE FROM {$table} WHERE gs_request_id = ?")->execute([$id]);
        }
        $pdo->prepare('UPDATE case_status_history SET note = NULL WHERE gs_request_id = ?')->execute([$id]);

        $pdo->prepare("UPDATE audit_log SET metadata_json = NULL WHERE entity_type = 'gs_request' AND entity_id = ?")->execute([$id]);
        if ($docIds) {
            $in = implode(',', array_fill(0, count($docIds), '?'));
            $pdo->prepare("UPDATE audit_log SET metadata_json = NULL WHERE entity_type = 'gs_request_document' AND entity_id IN ({$in})")->execute($docIds);
        }

        $pdo->prepare(
            "UPDATE gs_requests SET
                owner_full_name = '[erased]', owner_email = '[erased]', owner_phone = '[erased]',
                owner_address = '[erased]', owner_city = '[erased]', owner_state = '[erased]',
                owner_pin = '[erased]', owner_country = '[erased]', patient_name = '[erased]',
                patient_breed = NULL, patient_dob = NULL, patient_weight_kg = NULL, patient_neutered = NULL,
                patient_microchip = NULL, clinical_notes = NULL, vet_name = NULL, vet_clinic = NULL,
                vet_email = NULL, vet_phone = NULL, vet_registration_info = NULL,
                tracking_number = NULL, closure_reason = NULL,
                erased_at = ?, lock_version = lock_version + 1
             WHERE id = ?"
        )->execute([gmdate('Y-m-d H:i:s'), $id]);

        // After the scrub above, or this entry's own metadata would be cleared with the rest. The
        // file list goes in it (only when there are files), as the durable record described above.
        $meta = ['documents_deleted' => count($docIds)];
        if ($files) {
            $meta['files'] = array_values($files);
        }
        audit('case_erased', 'gs_request', $id, $meta);
        return ['ok' => true, 'message' => "Case GS-{$id} erased: personal details and " . count($docIds) . ' uploaded document(s) deleted.', 'failed_files' => []];
    });

    // Files go only after the DB change has committed - a rolled-back erasure must not have
    // already destroyed the documents.
    if ($result['ok'] && $files) {
        $failed = delete_case_files($id, $files, $deleteFile);
        try {
            if ($failed) {
                audit('case_erasure_files_failed', 'gs_request', $id, ['files' => $failed]);
            } else {
                audit('case_erasure_files_deleted', 'gs_request', $id, ['files' => count($files)]);
            }
        } catch (Throwable $e) {
            // The erasure itself is done; failing to add this note must not turn it into an error.
            error_log('[data-erasure] Could not record the file-deletion outcome for GS-' . $id . ': ' . log_safe($e->getMessage()));
        }
        if ($failed) {
            $result['failed_files'] = $failed;
            $result['message'] = "Case GS-{$id} was erased in the database, but " . count($failed) . ' uploaded file(s) could NOT be deleted from the server and are still stored there: '
                . implode(', ', $failed) . '. They are listed under "Leftover files" below - retry the delete from there, or remove them from the server by hand.';
        }
    }
    return $result;
}

// ---- Veterinary applications ----

// How long after an application was REJECTED it shows up on the data-erasure page's review list. Nothing is
// deleted automatically. No law fixes a period for rejected applications: the data protection rule is to keep
// personal data no longer than the purpose needs (the purpose, deciding the application, is over once it is
// decided), so the default is 12 months - enough to handle an appeal or a re-application and to spot someone
// re-applying after a rejection (the approval checklist treats a repeat registration number as a red flag),
// short enough not to hoard. Set VET_APPLICATION_RETENTION_MONTHS in db-config.php to change it, and keep the
// Privacy Policy in step.
function vet_retention_months(): int {
    return defined('VET_APPLICATION_RETENTION_MONTHS') ? max(1, (int) VET_APPLICATION_RETENTION_MONTHS) : 12;
}

// Rejected, not-yet-erased applications whose rejection is more than vet_retention_months() ago, oldest
// first - the review list, not an automatic deletion queue. The clock is reviewed_at, which the reject action
// stamps (updated_at is the fallback for older rows: it moves on any write, so it is only a fallback).
function vet_erasure_candidates(PDO $pdo, int $limit = 100): array {
    $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . vet_retention_months() . ' months'));
    $stmt = $pdo->prepare(
        "SELECT v.id, v.status, v.created_at, COALESCE(v.reviewed_at, v.updated_at) AS decided_at FROM vet_applications v
          WHERE v.erased_at IS NULL AND v.status = 'rejected' AND COALESCE(v.reviewed_at, v.updated_at) < ?
          ORDER BY decided_at ASC, v.id ASC LIMIT " . (int) $limit
    );
    $stmt->execute([$cutoff]);
    return $stmt->fetchAll();
}

// Permanently erases the personal data held on one veterinary application, on a deletion request or once the
// retention period has passed:
//   - the uploaded registration certificate is deleted from disk and the DB
//   - the applicant and clinic details are blanked and the staff review notes (which describe the checks done
//     on them and the emails sent to them) are cleared
//   - the portal account is neutralised, not deleted: cases the vet submitted still point at it. Its login
//     email becomes a placeholder, the password and every token are removed, and it is suspended
//   - the metadata of the audit entries about this application and account, and the vet's NAME on the audit
//     entries of the vet's own actions (logins, case submissions), are cleared; the entries stay, with the id
// What it deliberately does NOT touch: the cases this vet submitted. A case is also the pharmacy's record of a
// prescription, and the prescriber's details are part of it; each case's own erasure (erase_gs_request) blanks
// them when that case is erased, on its own retention schedule. The result says how many cases are affected.
// An approved application must be suspended first (the vet may be using the portal). Files are deleted after
// the commit, with the same durable record, failure reporting and retry as for cases (see erase_gs_request()).
// Returns ['ok' => bool, 'message' => string, 'failed_files' => string[]].
function erase_vet_application(PDO $pdo, int $id, ?callable $deleteFile = null): array {
    $files = [];
    $result = run_serialized_transaction($pdo, function (PDO $pdo) use ($id, &$files) {
        $stmt = $pdo->prepare('SELECT id, status, erased_at FROM vet_applications WHERE id = ?' . locking_read_suffix());
        $stmt->execute([$id]);
        $app = $stmt->fetch();
        if (!$app) {
            return ['ok' => false, 'message' => "Application VA-{$id} does not exist."];
        }
        if ($app['erased_at'] !== null) {
            return ['ok' => false, 'message' => "Application VA-{$id} has already been erased."];
        }
        if (!in_array($app['status'], VET_APPLICATION_ERASABLE_STATUSES, true)) {
            return ['ok' => false, 'message' => "Application VA-{$id} is approved, so its portal account may be in use. Suspend it first (that also locks the login), then erase it."];
        }

        $acct = $pdo->prepare('SELECT id, email FROM vet_accounts WHERE vet_application_id = ?' . locking_read_suffix());
        $acct->execute([$id]);
        $acct = $acct->fetch() ?: null;

        $docs = $pdo->prepare('SELECT id, stored_filename FROM vet_application_documents WHERE application_id = ?');
        $docs->execute([$id]);
        $docs = $docs->fetchAll();
        $files = array_column($docs, 'stored_filename');
        $docIds = array_map('intval', array_column($docs, 'id'));
        $pdo->prepare('DELETE FROM vet_application_documents WHERE application_id = ?')->execute([$id]);
        // The proof of consent holds the applicant's IP address and browser: it goes with the application, like a case's consent records.
        $pdo->prepare('DELETE FROM vet_application_consents WHERE application_id = ?')->execute([$id]);

        $casesKept = 0;
        if ($acct) {
            $count = $pdo->prepare('SELECT COUNT(*) FROM gs_requests WHERE vet_account_id = ? AND erased_at IS NULL');
            $count->execute([(int) $acct['id']]);
            $casesKept = (int) $count->fetchColumn();

            // The login email is UNIQUE and NOT NULL, so it becomes a per-account placeholder (a reserved .invalid
            // address) instead of being deleted; that also frees the real address for a future account.
            $pdo->prepare(
                "UPDATE vet_accounts SET email = ?, password_hash = NULL, status = 'suspended',
                        activation_token_hash = NULL, activation_expires_at = NULL,
                        password_reset_token_hash = NULL, password_reset_expires_at = NULL
                  WHERE id = ?"
            )->execute(['erased-vet-' . (int) $acct['id'] . '@erased.invalid', (int) $acct['id']]);
            // Failed-login bookkeeping is keyed on the email address.
            clear_login_attempts('vet:' . strtolower((string) $acct['email']));

            $pdo->prepare("UPDATE audit_log SET metadata_json = NULL WHERE entity_type = 'vet_account' AND entity_id = ?")->execute([(int) $acct['id']]);
            // The vet's own actions carry the vet's name (audit_log.actor_name): that is their personal data.
            $pdo->prepare("UPDATE audit_log SET actor_name = NULL WHERE actor_type = 'vet' AND actor_id = ?")->execute([(int) $acct['id']]);
        }

        $pdo->prepare("UPDATE audit_log SET metadata_json = NULL WHERE entity_type = 'vet_application' AND entity_id = ?")->execute([$id]);
        if ($docIds) {
            $in = implode(',', array_fill(0, count($docIds), '?'));
            $pdo->prepare("UPDATE audit_log SET metadata_json = NULL WHERE entity_type = 'vet_application_document' AND entity_id IN ({$in})")->execute($docIds);
        }

        $pdo->prepare(
            "UPDATE vet_applications SET
                full_name = '[erased]', professional_email = '[erased]', mobile = '[erased]',
                registration_number = '[erased]', registration_state = '[erased]', registration_country = '[erased]',
                qualification = '[erased]', year_qualified = 0, practice_type = '[erased]',
                clinic_name = '[erased]', clinic_address = '[erased]', clinic_city = '[erased]', clinic_state = '[erased]',
                clinic_pin = '[erased]', clinic_country = '[erased]', clinic_phone = '[erased]',
                clinic_email = NULL, clinic_website = NULL, internal_notes = NULL,
                erased_at = ?
             WHERE id = ?"
        )->execute([gmdate('Y-m-d H:i:s'), $id]);

        // After the scrub above, or this entry's own metadata would be cleared with the rest.
        $meta = ['documents_deleted' => count($docIds), 'account_neutralised' => $acct !== null, 'cases_still_holding_prescriber_details' => $casesKept];
        if ($files) {
            $meta['files'] = array_values($files);
        }
        audit('vet_application_erased', 'vet_application', $id, $meta);

        $message = "Application VA-{$id} erased: personal details and " . count($docIds) . ' uploaded document(s) deleted'
            . ($acct ? ', and its portal account locked out' : '') . '.';
        if ($casesKept > 0) {
            $message .= " {$casesKept} case(s) this veterinarian submitted still hold their prescriber details; each is cleared when that case is erased.";
        }
        return ['ok' => true, 'message' => $message, 'failed_files' => []];
    });

    if ($result['ok'] && $files) {
        $failed = delete_stored_files('vet_application', $id, $files, $deleteFile);
        try {
            if ($failed) {
                audit('vet_application_erasure_files_failed', 'vet_application', $id, ['files' => $failed]);
            } else {
                audit('vet_application_erasure_files_deleted', 'vet_application', $id, ['files' => count($files)]);
            }
        } catch (Throwable $e) {
            error_log('[data-erasure] Could not record the file-deletion outcome for VA-' . $id . ': ' . log_safe($e->getMessage()));
        }
        if ($failed) {
            $result['failed_files'] = $failed;
            $result['message'] = "Application VA-{$id} was erased in the database, but " . count($failed) . ' uploaded file(s) could NOT be deleted from the server and are still stored there: '
                . implode(', ', $failed) . '. They are listed under "Leftover files" below - retry the delete from there, or remove them from the server by hand.';
        }
    }
    return $result;
}

// ---- Files an erasure meant to delete (shared by cases and veterinary applications) ----

// Each kind of erasure: where its uploads live, how its records are labelled, and the audit actions that make
// up its trail (the entity_type of those entries is the kind itself).
const ERASURE_KINDS = [
    'gs_request'      => ['folder' => 'gs-requests',      'prefix' => 'GS-', 'erased' => 'case_erased',            'deleted' => 'case_erasure_files_deleted',            'failed' => 'case_erasure_files_failed'],
    'vet_application' => ['folder' => 'vet-applications', 'prefix' => 'VA-', 'erased' => 'vet_application_erased', 'deleted' => 'vet_application_erasure_files_deleted', 'failed' => 'vet_application_erasure_files_failed'],
];

// Where a stored upload can be on disk: in the record's own folder once filed, or in the "pending" bucket it
// was uploaded into before the record's id existed.
function erasure_file_paths(string $kind, int $id, string $storedFilename): array {
    $base = private_storage_path() . '/' . ERASURE_KINDS[$kind]['folder'];
    return ["{$base}/{$id}/{$storedFilename}", "{$base}/pending/{$storedFilename}"];
}

function gs_request_file_paths(int $caseId, string $storedFilename): array {
    return erasure_file_paths('gs_request', $caseId, $storedFilename);
}

// Stored upload names are random hex plus an extension. A name read back out of an audit entry is
// only ever turned into a path if it looks like one, so a tampered record can't point a delete
// at anything else ("../", slashes and so on).
function is_safe_stored_filename(string $name): bool {
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}$/', $name) && strpos($name, '..') === false;
}

// Deletes these stored files of one record from wherever they are, and returns the names that are
// STILL on disk afterwards (empty = all gone). "Still there" is checked on disk, not taken from
// the delete function's return value. A file that is already absent counts as gone.
function delete_stored_files(string $kind, int $id, array $files, ?callable $deleteFile = null): array {
    $deleteFile ??= fn(string $path): bool => @unlink($path);
    $failed = [];
    foreach ($files as $name) {
        if (!is_string($name) || !is_safe_stored_filename($name)) {
            continue;
        }
        foreach (erasure_file_paths($kind, $id, $name) as $path) {
            if (!is_file($path)) {
                continue;
            }
            $deleteFile($path);
            clearstatcache(true, $path);
            if (is_file($path)) {
                error_log("[data-erasure] Could not delete {$path}");
                $failed[$name] = true;
            }
        }
    }
    @rmdir(private_storage_path() . '/' . ERASURE_KINDS[$kind]['folder'] . "/{$id}"); // only succeeds if now empty
    return array_keys($failed);
}

function delete_case_files(int $caseId, array $files, ?callable $deleteFile = null): array {
    return delete_stored_files('gs_request', $caseId, $files, $deleteFile);
}

// Files that an erasure meant to delete but that are still on disk - a delete that failed, or a
// process that died after the commit. Read from the audit trail: every erasure entry (`case_erased`,
// `vet_application_erased`) that lists files and has no "files deleted" entry after it. Only those exact
// recorded names are ever considered, never a scan of the storage folder: an upload for a submission still in
// progress sits on disk before its database row exists, and a folder-wide sweep would mistake it for litter.
// Returns [['kind' => string, 'id' => int, 'case_id' => int (same as id, kept for older callers),
// 'label' => 'GS-5' / 'VA-3', 'file' => string, 'where' => string, 'erased_at' => string], ...].
function erasure_leftover_files(PDO $pdo, int $limit = 200): array {
    $out = [];
    foreach (ERASURE_KINDS as $kind => $k) {
        $stmt = $pdo->prepare(
            "SELECT a.entity_id AS rec_id, a.metadata_json, a.created_at FROM audit_log a
              WHERE a.action = ? AND a.entity_type = ? AND a.metadata_json LIKE ?
                AND NOT EXISTS (SELECT 1 FROM audit_log d
                                 WHERE d.action = ? AND d.entity_type = ? AND d.entity_id = a.entity_id)
              ORDER BY a.id DESC LIMIT " . (int) $limit
        );
        $stmt->execute([$k['erased'], $kind, '%"files"%', $k['deleted'], $kind]);
        foreach ($stmt->fetchAll() as $row) {
            $recId = (int) $row['rec_id'];
            $meta = json_decode((string) $row['metadata_json'], true);
            foreach ((is_array($meta) ? ($meta['files'] ?? []) : []) as $name) {
                if (!is_string($name) || !is_safe_stored_filename($name)) {
                    continue;
                }
                foreach (erasure_file_paths($kind, $recId, $name) as $path) {
                    if (is_file($path)) {
                        $out[] = [
                            'kind'      => $kind,
                            'id'        => $recId,
                            'case_id'   => $recId,
                            'label'     => $k['prefix'] . $recId,
                            'file'      => $name,
                            'where'     => str_starts_with($path, private_storage_path() . "/{$k['folder']}/{$recId}/") ? "{$k['folder']}/{$recId}/{$name}" : "{$k['folder']}/pending/{$name}",
                            'erased_at' => (string) $row['created_at'],
                        ];
                        break;
                    }
                }
            }
        }
    }
    return $out;
}

// Retries the delete for every leftover file found by erasure_leftover_files(), then records the
// outcome per record. It recomputes the list itself (nothing from the request says what to delete),
// so it can only ever touch files named in an erasure's own audit entry. Returns
// ['deleted' => int, 'failed' => string[] ("GS-5: name" / "VA-3: name"), 'message' => string].
function delete_erasure_leftovers(PDO $pdo, ?callable $deleteFile = null): array {
    $byRecord = [];
    foreach (erasure_leftover_files($pdo, 1000) as $l) {
        $byRecord[$l['kind']][$l['id']][$l['file']] = true;
    }
    $deleted = 0;
    $failed  = [];
    $any     = false;
    foreach ($byRecord as $kind => $records) {
        foreach ($records as $recId => $names) {
            $any   = true;
            $k     = ERASURE_KINDS[$kind];
            $names = array_keys($names);
            $still = delete_stored_files($kind, (int) $recId, $names, $deleteFile);
            $deleted += count($names) - count($still);
            foreach ($still as $n) {
                $failed[] = "{$k['prefix']}{$recId}: {$n}";
            }
            try {
                if ($still) {
                    audit($k['failed'], $kind, (int) $recId, ['files' => $still, 'via' => 'leftover_retry']);
                } else {
                    audit($k['deleted'], $kind, (int) $recId, ['files' => count($names), 'via' => 'leftover_retry']);
                }
            } catch (Throwable $e) {
                error_log('[data-erasure] Could not record the leftover cleanup for ' . $k['prefix'] . $recId . ': ' . log_safe($e->getMessage()));
            }
        }
    }
    if (!$any) {
        $message = 'There were no leftover files to delete.';
    } elseif ($failed) {
        $message = "Deleted {$deleted} leftover file(s), but " . count($failed) . ' could NOT be deleted and are still on the server: ' . implode(', ', $failed) . '. Check the file permissions or remove them by hand.';
    } else {
        $message = "Deleted {$deleted} leftover file(s).";
    }
    return ['deleted' => $deleted, 'failed' => $failed, 'message' => $message];
}
