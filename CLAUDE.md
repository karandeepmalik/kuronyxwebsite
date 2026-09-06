# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

The marketing website for Kuronyx Sciences (a veterinary compounding pharmacy), served as static PHP/HTML/JS from `public_html/` — no build step, no package manager, no framework. `public_html/` is deployed as-is to Hostinger shared hosting.

## Architecture

- `public_html/index.html` — the entire site is one long single-page HTML document (hero, "what we are", "why this matters", credentials, vets, dispatches/newsletter, enquiry form) with inline `<style>` and inline `<script>` for scroll-reveal, the section rail/progress indicator, and the enquiry form submit handler. There is no templating; edit sections directly in this file.
- `public_html/js/subscribe.js` — standalone handler for the newsletter subscribe form (`#subscribeForm`), separate from the inline enquiry-form script in `index.html`.
- `public_html/php/` — backend endpoints called via `fetch()` from the front end:
  - `config.php` — defines Brevo API constants (`BREVO_API_KEY`, template IDs, list ID, sender/receiver emails). Required by every other PHP file via `require_once 'config.php'`.
  - `send-welcome.php` — called by `subscribe.js` on newsletter signup; sends a Brevo welcome email, then adds the contact to the Brevo list only if the email send succeeded.
  - `send-enquiry.php` — called by the inline enquiry-form script; validates the contact fields server-side and sends the enquiry to `RECEIVER_EMAIL` via a Brevo template.
  - `unsubscribe.php` — GET-based landing page (linked from emails) that removes an email from Brevo list 7 and renders a static confirmation page inline.
  - `webhook.php` — Brevo webhook receiver; on a `delivered` event, upserts the contact into the Brevo list. Appends every request to `webhook.log` in the same directory regardless of outcome.
- Both `send-welcome.php` and `send-enquiry.php` implement the same CORS pattern: allow `https://kuronyx.in`, `https://www.kuronyx.in`, and localhost/127.0.0.1, reflect the origin back, and short-circuit `OPTIONS` preflight requests. Keep this pattern consistent if you touch either file.
- Forms also POST to Netlify's form-handling endpoint (`fetch('/', ...)`) as a backup/mirror submission alongside the Brevo call — this is intentional duplication, not dead code.
- `public_html/Privacy Policy.html` — standalone static page, not linked into the SPA's section rail.

## Deployment

`.github/workflows/deploy.yml` auto-deploys on every push to `main`: it FTPS-syncs `public_html/` to `/domains/kuronyx.in/public_html/` on Hostinger via `SamKirkland/FTP-Deploy-Action`, using `FTP_SERVER`/`FTP_USERNAME`/`FTP_PASSWORD` repo secrets. There is no CI build/test step — whatever is in `public_html/` on `main` goes live as-is. There is no local dev server or test suite in this repo; verify changes by opening `public_html/index.html` directly or serving the folder with any static file server (note the PHP endpoints won't run without a PHP server, e.g. `php -S localhost:8000 -t public_html`).

## Known issue — exposed secret

`public_html/php/config.php` is tracked in git and deployed publicly, but contains a live Brevo API key (`BREVO_API_KEY`), despite its own comment saying "never commit to git." This repo's remote (`karandeepmalik/kuronyxwebsite`) — if flip to private isn't already planned, the key should be rotated in Brevo and moved out of version control (e.g. read from a server-side env var or an untracked file). Flag this to the user before assuming it's already handled.
