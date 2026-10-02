<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

// Deletes session files in $dir that haven't been touched for $maxAgeSeconds. Only ever looks at
// files named sess_* and examines at most $limit directory entries per call, so one unlucky
// request never has to walk a huge folder. A live session's file is touched on every request,
// so an active login is never removed. Returns how many files were deleted.
function gs_session_cleanup(string $dir, int $maxAgeSeconds, int $limit = 2000): int {
    $handle = @opendir($dir);
    if ($handle === false) return 0;
    $cutoff  = time() - $maxAgeSeconds;
    $deleted = 0;
    $seen    = 0;
    while (($entry = readdir($handle)) !== false && $seen < $limit) {
        if (strncmp($entry, 'sess_', 5) !== 0) continue;
        $seen++;
        $path = $dir . '/' . $entry;
        $mtime = @filemtime($path);
        if ($mtime !== false && $mtime < $cutoff && @unlink($path)) {
            $deleted++;
        }
    }
    closedir($handle);
    return $deleted;
}
