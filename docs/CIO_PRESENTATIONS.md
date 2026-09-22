# CIO presentations project

Internal sales-support project under `/projects/cio-presentations`.

## Goal

Build a catalog of links to public presentations by CIO / IT directors while keeping the production server lightweight.

The application stores metadata and source URLs. It does **not** download or proxy presentation files. Opening a presentation sends the user's browser directly to the original public URL.

## Access

The project requires authentication.

- `user` and `admin` have the same full access to all project functions;
- both roles can browse, filter, add sources, scan, add/clear presentations and update review metadata;
- guests are redirected to `/login`.

The admin role is reserved for site-administration functions outside this project.

## Data model

`presentation_sources` stores the built-in starter set plus public source pages added manually:

- name;
- URL and domain;
- priority / active state;
- last scan timestamp;
- number of newly discovered presentation links;
- last scan error.

`presentations` stores:

- optional source;
- title / speaker / role / company / event metadata;
- PDF, PPT or PPTX URL;
- source-page URL;
- review and link status;
- manual flags for email, phone and good lead;
- discovery / review timestamps.

## Built-in starter sources

Production receives an idempotent starter set through a data migration, not a seeder. Existing rows are preserved because preset URLs are inserted with `insertOrIgnore`.

The approved built-in source families are:

- TAdviser / TAdviser SummIT;
- CNews, including the dedicated CNews FORUM Кейсы presentation archive;
- Industrial++ conference abstracts/presentation pages;
- ЦИПР, including the dedicated `cipr-reports.ru` presentation archive;
- IB-Bank's «Цифровая устойчивость промышленных систем» materials archive.

1C and Global CIO are not part of the preset source set. Manual user-added sources outside the preset list are not removed.

Preset sources are normal rows after installation: any authenticated user can scan them the same way as manually added sources.

The 2026 preset layer adds the official TAdviser SummIT pages for May and November 2026, TAdviser IT Prize 2026, and year-specific CNews FORUM / CNews FORUM Кейсы pages.

## Clean restart

The presentations tab has a destructive **Очистить презентации** action. It hard-deletes all presentation rows and resets source scan timestamps/counts/errors, while preserving the source catalog.

The migration that introduced this action also performs a one-time reset of presentation/history data so production starts from zero as requested.

## Lightweight scanner

The scanner stays deliberately bounded:

1. An authenticated user scans a public HTTP/HTTPS source page.
2. It extracts direct links ending in `.pdf`, `.ppt` or `.pptx`.
3. It may follow up to **6 relevant same-origin HTML pages** linked from the source, one level deep. Presentation/material/abstract/report paths and CIO/industrial keywords are prioritized.
4. Registration, sponsor and unrelated/external links are not discovery targets.
5. The site's root `/sitemap.xml` is still checked when available.
6. Presentation files themselves are never requested.
7. New URLs are deduplicated by the unique `file_url` column.

Safety controls:

- only HTTP/HTTPS;
- only ports 80/443;
- localhost, private and reserved IP ranges are rejected;
- redirects are followed manually and revalidated;
- page fetches use short connect/request timeouts;
- HTML/XML body size is capped;
- scans are user-triggered in the MVP.

## Discovery limits and next layer

The current one-level discovery pass is intentionally small so a manual scan cannot turn into a broad crawler. It does not recursively traverse discovered pages.

A later layer can add source-specific metadata extraction (speaker, role, company, event) from surrounding HTML. It should continue to store links only and keep strict per-domain/request limits.
