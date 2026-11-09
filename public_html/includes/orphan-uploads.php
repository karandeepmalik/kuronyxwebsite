<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/data-protection.php';

// Uploads that no record points at: files written to disk by an intake form whose request then DIED (a timeout, the
// process killed for memory, a server restart) before the case/application row was saved. The form's own cleanup only
// runs for errors PHP can catch, so these stay on the server for ever - and they are prescriptions. Nothing ever looked
// for them.
//
// A file is an ORPHAN only if ALL of these hold:
//   - it is in one of the upload areas (gs-requests/, vet-applications/), in "pending" or in a numbered record folder
//     (the move out of "pending" happens BEFORE the commit, so a request that dies in between leaves its files in a
//     folder for a record that was never saved);
//   - its name looks like one of ours (32 hex characters + .pdf/.jpg/.png), and it is a plain file, not a link;
//   - NO row in gs_request_documents / vet_application_documents has that stored_filename. This is the rule that
//     matters: relocate_uploaded_file() only LOGS a failure and download.php deliberately falls back to "pending", so a
//     referenced file can legitimately stay there and must never be touched;
//   - it is older than ORPHAN_MIN_AGE_SECONDS: a submission in progress has a file with no row for a few seconds;
//   - it is not one an erasure is still waiting to delete (those have their own panel and retry).
//
// Orphans are MOVED to a holding folder (orphaned/<date>/), not deleted, and purged after ORPHAN_HOLD_DAYS. If the
// database is ever restored from an older backup, every file uploaded since then has no row and would look like an
// orphan; the holding period is what lets someone notice and recover them.
const ORPHAN_UPLOAD_AREAS       = ['gs-requests' => 'gs_request_documents', 'vet-applications' => 'vet_application_documents'];
const ORPHAN_HOLD_FOLDER        = 'orphaned';
const ORPHAN_MIN_AGE_SECONDS    = 86400;
const ORPHAN_HOLD_DAYS          = 30;
const ORPHAN_SWEEP_MAX_FILES    = 200;
const ORPHAN_NAME_PATTERN       = '/^[0-9a-f]{32}\.(pdf|jpg|png)$/';

// One run. Returns null if another run holds the lock (or the holding folder can't be made), else
// ['held' => ['gs-requests/pending/<name>', ...], 'referenced' => int (files kept because a row points at them),
//  'purged' => int (files deleted from holding folders past ORPHAN_HOLD_DAYS), 'more' => bool (stopped at the cap)].
// $actorType is 'system' for the automatic run and 'staff' for the admin's "check now" button.
function sweep_orphan_uploads(PDO $pdo, string $actorType = 'system', ?int $now = null, int $minAgeSeconds = ORPHAN_MIN_AGE_SECONDS, int $maxFiles = ORPHAN_SWEEP_MAX_FILES): ?array {
    $now ??= time();
    $root = private_storage_path();
    $holdRoot = $root . '/' . ORPHAN_HOLD_FOLDER;
    if (!is_dir($holdRoot) && !@mkdir($holdRoot, 0750, true) && !is_dir($holdRoot)) {
        error_log("[orphan-sweep] Could not create {$holdRoot}");
        return null;
    }
    $lock = @fopen($holdRoot . '/.sweep.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { // two sweeps at once would fight over the same files
        if ($lock !== false) fclose($lock);
        return null;
    }
    try {
        $cutoff = $now - $minAgeSeconds;
        $skip = []; // files an erasure still has to delete: the "Leftover files" panel owns those
        foreach (erasure_leftover_files($pdo, 1000) as $l) {
            $skip[$l['file']] = true;
        }
        $held = [];
        $referenced = 0;
        $more = false;
        foreach (ORPHAN_UPLOAD_AREAS as $area => $table) {
            $isReferenced = $pdo->prepare("SELECT 1 FROM {$table} WHERE stored_filename = ? LIMIT 1"); // $table is one of the constants above
            $areaDir = "{$root}/{$area}";
            foreach (@scandir($areaDir) ?: [] as $sub) {
                if ($sub !== 'pending' && !ctype_digit($sub)) continue;
                $dir = "{$areaDir}/{$sub}";
                if (is_link($dir) || !is_dir($dir)) continue;
                foreach (@scandir($dir) ?: [] as $name) {
                    if (!preg_match(ORPHAN_NAME_PATTERN, $name) || isset($skip[$name])) continue;
                    $path = "{$dir}/{$name}";
                    if (is_link($path) || !is_file($path)) continue;
                    $mtime = @filemtime($path);
                    if ($mtime === false || $mtime > $cutoff) continue;
                    $isReferenced->execute([$name]);
                    $inUse = $isReferenced->fetchColumn() !== false;
                    $isReferenced->closeCursor();
                    if ($inUse) {
                        $referenced++;
                        continue;
                    }
                    if (count($held) >= $maxFiles) {
                        $more = true;
                        break 3;
                    }
                    $holdDir = $holdRoot . '/' . date('Y-m-d', $now) . "/{$area}";
                    $target = "{$holdDir}/{$sub}__{$name}"; // keeps where it was found (pending, or which record folder)
                    if ((!is_dir($holdDir) && !@mkdir($holdDir, 0750, true) && !is_dir($holdDir)) || file_exists($target) || !@rename($path, $target)) {
                        error_log("[orphan-sweep] Could not move {$area}/{$sub}/{$name} to the holding folder");
                        continue;
                    }
                    $held[] = "{$area}/{$sub}/{$name}";
                }
                if ($sub !== 'pending') @rmdir($dir); // a numbered folder that is now empty; fails harmlessly if not
            }
        }
        if ($held) {
            audit('orphan_uploads_held', 'upload_storage', null, ['count' => count($held), 'files' => $held, 'more' => $more], $actorType);
        }

        $purged = orphan_holding_purge($holdRoot, $now);
        if ($purged > 0) {
            audit('orphan_uploads_purged', 'upload_storage', null, ['count' => $purged, 'held_longer_than_days' => ORPHAN_HOLD_DAYS], $actorType);
        }
        return ['held' => $held, 'referenced' => $referenced, 'purged' => $purged, 'more' => $more];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// Deletes holding-folder days (orphaned/YYYY-MM-DD/) older than ORPHAN_HOLD_DAYS. Only folders named like a date are
// looked at, and only plain files inside them are removed. Returns how many files were deleted.
function orphan_holding_purge(string $holdRoot, int $now): int {
    $purged = 0;
    $limit = $now - ORPHAN_HOLD_DAYS * 86400;
    foreach (@scandir($holdRoot) ?: [] as $day) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) continue;
        $dayTime = strtotime($day . ' 00:00:00');
        $dayDir = "{$holdRoot}/{$day}";
        if ($dayTime === false || $dayTime >= $limit || is_link($dayDir) || !is_dir($dayDir)) continue;
        foreach (@scandir($dayDir) ?: [] as $area) {
            if (!isset(ORPHAN_UPLOAD_AREAS[$area])) continue;
            $areaDir = "{$dayDir}/{$area}";
            if (is_link($areaDir) || !is_dir($areaDir)) continue;
            foreach (@scandir($areaDir) ?: [] as $file) {
                $path = "{$areaDir}/{$file}";
                if ($file !== '.' && $file !== '..' && is_file($path) && !is_link($path) && @unlink($path)) $purged++;
            }
            @rmdir($areaDir);
        }
        @rmdir($dayDir);
    }
    return $purged;
}

// What the admin page shows: files currently in the holding folder, how many days it holds, and the oldest day.
function orphan_holding_summary(): array {
    $holdRoot = private_storage_path() . '/' . ORPHAN_HOLD_FOLDER;
    $count = 0;
    $oldest = null;
    foreach (@scandir($holdRoot) ?: [] as $day) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !is_dir("{$holdRoot}/{$day}")) continue;
        foreach (array_keys(ORPHAN_UPLOAD_AREAS) as $area) {
            foreach (@scandir("{$holdRoot}/{$day}/{$area}") ?: [] as $file) {
                if (is_file("{$holdRoot}/{$day}/{$area}/{$file}")) {
                    $count++;
                    if ($oldest === null || $day < $oldest) $oldest = $day;
                }
            }
        }
    }
    return ['files' => $count, 'oldest' => $oldest, 'days' => ORPHAN_HOLD_DAYS];
}

// Called on a successful intake submission: roughly 1 in ORPHAN_SWEEP_ONE_IN of them runs a sweep, the way
// session files are tidied. Capped by ORPHAN_SWEEP_MAX_FILES, and never allowed to break the submission that happened
// to trigger it.
if (!defined('ORPHAN_SWEEP_ONE_IN')) define('ORPHAN_SWEEP_ONE_IN', 50);
function maybe_sweep_orphan_uploads(): void {
    try {
        if (random_int(1, max(1, (int) ORPHAN_SWEEP_ONE_IN)) !== 1) return;
        sweep_orphan_uploads(db());
    } catch (Throwable $e) {
        error_log('[orphan-sweep] ' . log_safe($e->getMessage()));
    }
}
