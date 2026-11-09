<?php
// Not a page - refuse direct requests.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

// Turns text into a "slug": the lowercase, hyphen-separated, URL-safe name an article is found by
// (a dispatch titled "Dosing & Storage Notes" becomes "dosing-storage-notes", reached at
// /dispatches/view.php?slug=dosing-storage-notes). Anything that isn't a letter a-z or a digit becomes
// a hyphen, runs of them collapse to one, and leading/trailing hyphens are dropped. If nothing is left
// (an empty title, only symbols, or a title in a non-Latin script, none of which survive the a-z0-9
// filter) the result is "dispatch" rather than an empty string.
//
// Lives here, not in a page, so that any page can use it: a function defined inside a page file is a
// fatal "cannot redeclare" error the moment a second file includes that page or defines the same name.
function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'dispatch';
}
