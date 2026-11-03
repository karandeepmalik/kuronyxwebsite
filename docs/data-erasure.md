# Data erasure: what it removes, and what it can't

Admins erase a GS-441524 case from **Admin → Data Erasure** (`/admin/data-erasure/`), for a deletion
request under the Privacy Policy or once a case is past its retention period. Only finished, closed,
cancelled or rejected cases can be erased, and it needs the case number and the word `ERASE` typed.
It cannot be undone. The code is `erase_gs_request()` in `public_html/includes/data-protection.php`.

## What the tool removes (inside the application)

- The uploaded prescription and supporting documents (files and database rows).
- The owner, patient and veterinarian details on the case, which are blanked.
- Consent records (they hold the visitor's IP address and browser).
- Every email logged against the case, and the internal notes.
- Notes on the status history, and the details (`metadata_json`) on that case's audit-log entries:
  email addresses, IP addresses, and each status change's from/to.

## What if a file can't be deleted, or the server stops partway

The database change commits first and the uploaded files are deleted from disk right after (so a
rolled-back erasure never destroys documents). That leaves two ways for a prescription to stay on disk
after a case is marked erased: a delete fails (permissions, a locked file), or the process dies between
the commit and the deletes. Neither is silent:

- The names of the case's files (random hex names, not personal data) are written into the `case_erased`
  audit entry **inside the same transaction**, so the record of what had to go survives a crash.
- If a delete fails, the page shows a red message naming the files, and a `case_erasure_files_failed`
  audit entry is written. When every file is gone, `case_erasure_files_deleted` is written.
- **Leftover files from erasures** (a panel on the Data Erasure page) lists any file named in an erasure
  record that is still on disk, and **Delete leftover files** retries. It only ever touches files named
  in an erasure's own audit entry for an already-erased case; it never scans the storage folder (an upload
  for a submission still in progress sits on disk before its database row exists, and would look like
  litter). Names are checked before use, so a tampered record can't point at another path.

If the retry also fails, fix the cause (file permissions, a locked file) and press it again, or remove the
files from the server by hand and they drop off the list.

## What it keeps

The case number, its status history (the statuses and dates, without notes), the formulation and
price, and the audit-log entries without their details (what happened, when, and by whom). The audit
log also keeps the acting staff member's or veterinarian's name and id (`actor_name`/`actor_id`),
because they are the people doing the work, not the person whose data was erased.

## What it cannot reach: copies outside the application

Erasing a case does **not** remove personal data from these places. Anyone answering a deletion
request should know this.

1. **Hostinger's backups.** Hostinger keeps backups of the site files (which include the uploaded
   documents) and of the database, and an erased case can remain in those backups until they are
   rotated out. Restoring a backup would bring erased cases back, so after any restore, repeat the
   erasures made since that backup (the audit log shows which `case_erased` entries to repeat).
2. **Brevo's sent-email logs.** Emails sent from the case page, activation links and password resets
   go out through Brevo, which keeps its own log of each message (recipient, subject and content).
   Erasing the case deletes our copy only. If a request has to cover those too, delete the messages
   from Brevo's transactional email logs, or ask Brevo to. The newsletter list is separate again and
   is not touched by this tool.
3. **Copies people already hold.** Emails already delivered to the owner or the vet, and any
   document a staff member downloaded to their own computer. The software cannot track these.

The tool covers GS-441524 cases only. Veterinary applications and their documents are not erased by it.

## What the Privacy Policy says

Section 07 (Data retention) of `Privacy Policy.html` tells people that deleted data is removed from
our live systems, but that copies may remain for a limited period in Hostinger's backups and in
Brevo's sent-email logs until the provider's own retention period ends. If you change what the tool
or the backups do, keep that paragraph true. Editing the policy file creates a new policy version
for consent records (see `privacy_policy_version()`).

## Veterinary applications

The same page erases a **veterinary application** (`erase_vet_application()` in
`public_html/includes/data-protection.php`), for a deletion request from an applicant or vet, or when a
rejected application is past its retention period. It needs the application number (the number after
`VA-`) and the word `ERASE`, and cannot be undone. Pending, under-review, rejected and suspended
applications can be erased; an **approved** one must be suspended first, because the vet may be using the
portal (suspending also locks the login).

**What it removes.** The uploaded registration certificate (file and row); the consent record (`vet_application_consents`: the applicant's IP address and browser, saved when they ticked the box); the applicant and clinic details,
which are blanked; the staff review notes (which describe the checks done on the person and the emails sent
to them); the details (`metadata_json`) on the audit entries about the application and its account; and the
vet's **name** on the audit entries of the vet's own actions (logins, case submissions), because it is their
own data (the entries stay, with the id). The portal account is **neutralised, not deleted**, because cases
the vet submitted still point at it: its login email becomes a placeholder
(`erased-vet-<id>@erased.invalid`), the password and every activation/reset token are removed, and it is
suspended. The same records of a failed or interrupted file delete, the leftover panel and the retry apply as
for cases (audit actions `vet_application_erased`, `vet_application_erasure_files_deleted` / `_failed`).

**What it keeps.** The application number, its status, who reviewed it and when. **The cases the vet
submitted are not changed.** A case is also the pharmacy's record of a prescription, and the prescriber's
details are part of it, so each case clears them when that case is erased on its own retention schedule. The
message after an erasure says how many cases still hold the prescriber's details.

**How long rejected applications are kept.** 12 months after the rejection (`VET_APPLICATION_RETENTION_MONTHS`
in db-config.php; the page lists the ones past it, and nothing is deleted automatically). No law fixes a
period for rejected applications: India's DPDP Act (section 8(7)) says to erase personal data once the
purpose is no longer served unless another law requires keeping it, and the three-year erase-after-inactivity
rule in the DPDP Rules' Third Schedule only covers large e-commerce, gaming and social media companies. Twelve
months leaves room for an appeal or a re-application and for noticing someone who re-applies after a
rejection (the approval checklist treats a repeat registration number as a red flag). The retention
obligations in those Rules are reported to start on 13 May 2027; confirm against the Gazette. **Have the
pharmacist or legal adviser confirm** the 12 months and that the prescriber's details may stay on dispensed
cases until each case is erased. Section 17 of `Privacy Policy.html` states both; keep them in step.

An erased application is **read-only** on its page: every action is refused on the server and no forms are
shown. Not removed, like for cases: Hostinger's backups and Brevo's sent-email logs (activation and reset
emails), plus short-lived rate-limit rows that expire on their own.
