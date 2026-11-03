<?php
// Not a page - refuse direct requests.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

// The Journal (a clinical education series, one "issue" per entry). Its entries live in the `dispatches` table - the
// table and the admin address keep their old names, nothing on the public site uses them. Shared by the public Journal
// pages and the admin editor.

const JOURNAL_ISSUE_MAX = 9999;

// "Issue 04" (two digits at least, as the series is written); '' when the entry has no issue number.
function journal_issue_label(?int $number): string {
    return $number === null ? '' : 'Issue ' . str_pad((string) $number, 2, '0', STR_PAD_LEFT);
}

// Newest first: the highest issue number first, entries without a number after the numbered ones (by date), with the
// id as the last tiebreaker so a page of the list never repeats or skips a row.
const JOURNAL_ORDER_SQL = '(issue_number IS NULL), issue_number DESC, published_at DESC, id DESC';
