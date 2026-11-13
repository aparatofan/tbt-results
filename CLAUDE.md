# TBT Results — Claude Code guidance

Keep this file concise. It is loaded at the start of every Claude Code session.

## Start here

- Work from the task, not from a full-repository scan.
- The current release is specified in `docs/SPEC-0.1.0.md`. Follow it in order. Phase 0 is read-only and ends with a report; do not edit anything before Mariusz answers it.
- `docs/prototypes/*.html` are the approved designs. Where the spec and a prototype disagree, the spec wins; where the spec is silent, the prototype decides.
- Sibling code is the reference for patterns: `aparatofan/tbt-test-maker` (assets, tokens, fonts, REST boot, deploy) and `aparatofan/tbt_notes` (the activity table and access helpers).
- Do not change unrelated code, formatting, wording, or styling.

## Project basics

- WordPress 6.x plugin; PHP 8.0+. Pure PHP + vanilla JavaScript. No jQuery, build system, CDN, Composer, or npm dependency.
- Bootstrap: `tbt-results.php`. Server-side: `includes/`. Assets: `assets/`. Standalone tests: `tests/`.
- Prefixes: `TBTR_` (classes, constants), `tbtr_` (functions, options, filters), `tbtr-` (CSS), REST namespace `tbt-results/v1`. Never use unprefixed `.tbt-*` class names; TBT Hub claims them.
- `assets/css/tbt-tokens.css` is a vendored copy of Hub's tokens. Never edit a value in it. No stylesheet here defines a colour of its own.

## Architecture to preserve

- **Results stores nothing.** It reads TBT Notes' activity log (`TBT_Notes_DB::table_activity()`, Notes 1.22.0+). It never writes, updates or deletes a row there.
- **One place decides visibility:** `TBTR_Access`. Admins see every row, including class-less rows (`class_id` 0). Teachers see rows in their own classes only. Students see only their own rows. Every query goes through it; UI hiding is never the boundary.
- Every finished sitting is its own row. Never collapse attempts.
- `score` NULL means "done, no score" and is shown as "✓ Done"; 0 is a real score.
- Dates are stored in UTC and shown in the site time zone.
- No page hero: Divi supplies the header. Tablet and desktop only (min 768 px); no phone layout.
- Results must not fatal when Notes is missing or older than 1.22.0; it shows one line and the routes answer 503.

## Security rules

- Every REST route has a real `permission_callback`; nonce `wp_rest`; no-cache headers on reads.
- `$wpdb->prepare()` for every query with a value; `$wpdb->esc_like()` for search.
- Escape all output. Never expose credentials or real configuration values.

## Validation

```bash
for f in tbt-results.php includes/*.php; do php -l "$f"; done
node --check assets/js/results.js
php tests/test-format.php
```

State clearly what still needs a browser or live-site check.

## Git and deployment

- **Never merge a PR and never push to `main`.** A merge to `main` deploys to the live site; that is Mariusz's decision. Stop at an open PR and report.
- Use a focused feature branch. Keep commits task-focused.
- Every release bumps `TBTR_VERSION` and the plugin header version; the version is the cache buster.
- **Never rename `tbt-results.php` or the `/tbt-results/` server folder.** The FTP deploy uploads but never deletes.
- Markdown files, `.github/`, `docs/` and `tests/` are excluded from the upload.

## Context discipline

- Prefer targeted search and narrow reads over broad exploration.
- Summarise completion as: what changed, what was checked, and what still needs live-site verification.
