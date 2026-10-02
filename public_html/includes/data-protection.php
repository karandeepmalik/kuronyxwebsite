<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/storage-path.php';

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

// How long after a case was last touched it shows up on the data-erasure page's review list.
// Nothing is ever deleted automatically — see admin/data-erasure/index.php. Set
// RETENTION_MONTHS in db-config.php once the pharmacy has confirmed how long prescription
// and dispensing records must legally be kept; 36 is only a placeholder default.
function retention_months(): int {
    return defined('RETENTION_MONTHS') ? max(1, (int) RETENTION_MONTHS) : 36;
}

// A case can only be erased once it is finished with. An open case is still being worked on
// (and its prescription is still needed); close or cancel it first.
const ERASABLE_CASE_STATUSES = ['finished', 'closed', 'cancelled', 'rejected'];

// Terminal-status cases not touched for retention_months() and not already erased, oldest
// first - the review list, not an automatic deletion queue.
function erasure_candidates(PDO $pdo, int $limit = 100): array {
    $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . retention_months() . ' months'));
    $in     = implode(',', array_fill(0, count(ERASABLE_CASE_STATUSES), '?'));
    $stmt   = $pdo->prepare(
        "SELECT id, status, source, created_at, updated_at FROM gs_requests
         WHERE erased_at IS NULL AND status IN ({$in}) AND updated_at < ?
         ORDER BY updated_at ASC LIMIT " . (int) $limit
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
// 'message' => string].
function erase_gs_request(PDO $pdo, int $id): array {
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

        // After the scrub above, or this entry's own (empty) metadata would be cleared with the rest.
        audit('case_erased', 'gs_request', $id, ['documents_deleted' => count($docIds)]);
        return ['ok' => true, 'message' => "Case GS-{$id} erased: personal details and " . count($docIds) . ' uploaded document(s) deleted.'];
    });

    // Files go only after the DB change has committed - a rolled-back erasure must not have
    // already destroyed the documents. A file that can't be deleted is logged, not fatal.
    if ($result['ok']) {
        $base = private_storage_path() . '/gs-requests';
        foreach ($files as $name) {
            foreach (["{$base}/{$id}/{$name}", "{$base}/pending/{$name}"] as $path) {
                if (is_file($path) && !@unlink($path)) {
                    error_log("[data-erasure] Could not delete {$path}");
                }
            }
        }
        @rmdir("{$base}/{$id}"); // only succeeds if now empty
    }
    return $result;
}
