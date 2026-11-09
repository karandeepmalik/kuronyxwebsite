<?php
// Not a page - refuse direct requests.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

// The ONE place the status lists live. They used to be retyped in the case list, the case page, the
// erasure code and the vet portal (plus the two schema files), so adding a status meant remembering every
// copy - and missing one failed quietly (a filter that rejected the new status, a case that could never be
// erased). Everything in PHP now builds off these; the two SQL schema files can't (they are SQL), so
// tests/cases/65_case_statuses.php fails if they differ from the lists below.

// Every status a GS-441524 case can have, in workflow order. Must equal the `status` enum in
// db/schema.sql and the CHECK list in db/schema.sqlite.sql (same order: MySQL sorts an ENUM by position).
const GS_CASE_STATUSES = [
    'submitted', 'under_review', 'awaiting_information', 'communication_in_progress', 'formulation_discussion',
    'approved', 'compounding', 'ready_for_dispatch', 'dispatched', 'finished', 'closed', 'cancelled', 'rejected',
];

// Named subsets, each defined by what it MEANS (and each must stay inside GS_CASE_STATUSES - the test checks).
//
// The case is finished with: nothing more will happen to it. Only these can be erased, and the data-erasure
// retention clock (closed_at) runs from the moment a case reached one of them.
const GS_TERMINAL_STATUSES = ['finished', 'closed', 'cancelled', 'rejected'];

// Fulfilment is under way or done, so a vet in the portal may see the formulation, price and tracking.
const GS_FULFILMENT_STATUSES = ['approved', 'compounding', 'ready_for_dispatch', 'dispatched', 'finished'];

// Veterinary account applications have their own, separate lifecycle (db/schema.sql vet_applications.status).
const VET_APPLICATION_STATUSES = ['pending', 'under_review', 'approved', 'rejected', 'suspended'];

// The review actions on an application: the status each one moves it to, and the statuses it may be taken from.
// Reversals are allowed on purpose (re-approve a rejected application, reject an approved one: the approve and
// reject code reconciles the portal account for those). What is refused is the nonsensical: suspending an
// application that was never approved (suspend takes a working account out of use), and "approving" what is
// already approved, "rejecting" what is already rejected, or asking for more information on what is already
// under review (which is also what a double-click or a stale tab does). Every action therefore REALLY changes the
// status, which matters: the page applies each one as `UPDATE ... WHERE id = ? AND status = ?` and reads
// rowCount() as "the status was still what I checked", but MySQL/MariaDB count only rows that CHANGED (SQLite
// counts rows that matched), so an update that set the same value would look like a lost race there.
// admin/veterinary-applications/view.php enforces these on the server and only shows the buttons that apply; each
// form also sends the status it was loaded with, so acting on an out-of-date page is refused.
// 'note' = the review note is REQUIRED for this action: approving, rejecting and suspending decide who may send us
// prescriptions, so each must leave a record of what was checked or why (the approval checklist already tells staff
// to write it; without the server enforcing it, Approve with an empty box just logged "Approved.").
const VET_APPLICATION_ACTIONS = [
    'approve'      => ['to' => 'approved',     'from' => ['pending', 'under_review', 'rejected', 'suspended'], 'note' => true],
    'reject'       => ['to' => 'rejected',     'from' => ['pending', 'under_review', 'approved', 'suspended'], 'note' => true],
    'suspend'      => ['to' => 'suspended',    'from' => ['approved'],                                         'note' => true],
    'request_info' => ['to' => 'under_review', 'from' => ['pending'],                                          'note' => false],
];

// An application can be erased unless it is approved: an approved vet may be using the portal right now, so
// suspend the account first (that also locks the login) and erase afterwards. Rejected ones are the ones
// that fall due after the retention period.
const VET_APPLICATION_ERASABLE_STATUSES = ['pending', 'under_review', 'rejected', 'suspended'];

// How a status badge is coloured: 'good' = teal (done / positive), 'bad' = red (ended badly / blocked),
// 'neutral' = the plain outline (waiting or in progress). EVERY status of every list above must appear here
// (tests/cases/65_case_statuses.php fails otherwise), so adding a status forces a colour decision instead of
// leaving it quietly grey, and layout-header.php builds the badge CSS from this map (status_pill_css()).
// 'published' is the dispatch list's live badge. A status that two lists share (approved, rejected,
// under_review) has one tone in both. Note 'approved' and 'rejected' also colour the Active/Deactivated badge
// on the staff page and the sent/failed badge on the email log - change them with those in mind.
const STATUS_TONES = [
    // GS-441524 cases
    'submitted' => 'neutral', 'under_review' => 'neutral', 'awaiting_information' => 'neutral',
    'communication_in_progress' => 'neutral', 'formulation_discussion' => 'neutral',
    'approved' => 'good', 'compounding' => 'neutral', 'ready_for_dispatch' => 'neutral',
    'dispatched' => 'good',   // shipped: a positive milestone, the same family as approved and finished
    'finished' => 'good', 'closed' => 'bad', 'cancelled' => 'bad', 'rejected' => 'bad',
    // veterinary applications (approved / rejected / under_review are shared with the list above)
    'pending' => 'neutral',
    'suspended' => 'bad',     // a blocked account is a negative state, like rejected
    // dispatches
    'published' => 'good',
];

// "under_review" -> "Under Review". One place, so no list can show "Under_review" again.
function status_label(string $status): string {
    return ucwords(str_replace('_', ' ', $status));
}

// The CSS for the tinted badges, generated from STATUS_TONES (neutral needs none: it is the default look).
function status_pill_css(): string {
    $css = '';
    foreach (['good' => 'var(--teal)', 'bad' => 'var(--err)'] as $tone => $colour) {
        $selectors = [];
        foreach (STATUS_TONES as $status => $t) {
            if ($t === $tone) {
                $selectors[] = '.status-pill.status-' . $status;
            }
        }
        if ($selectors) {
            $css .= '  ' . implode(',', $selectors) . '{ border-color:' . $colour . '; color:' . $colour . '; }' . "\n";
        }
    }
    return $css;
}
