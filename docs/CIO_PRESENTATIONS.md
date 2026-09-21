# CIO presentations project

Internal sales-support project under `/projects/cio-presentations`.

## Goal

Build a catalog of links to public presentations by CIO / IT directors while keeping the production server lightweight.

The application stores metadata and source URLs. It does **not** download or proxy presentation files. Opening a presentation sends the user's browser directly to the original public URL.

## Access

The project requires authentication and is limited to `admin` / `moderator` roles.

The public `/projects` catalog may link to it; guests are redirected to login and regular users receive 403.

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

The initial set includes:

- the 1C:ERP forum archive and 2021–2025 program/material pages;
- the 1C:ERP / Управление холдингом presentations page;
- selected CNews pages with published CIO presentations;
- a Global CIO event page as a discovery seed.

Preset sources are normal rows after installation: admins/moderators can scan them the same way as manually added sources.

## Lightweight scanner

The first MVP scanner is intentionally narrow:

1. An admin/moderator adds a public HTTP/HTTPS source page.
2. A manual scan fetches only that HTML page plus the site's root `/sitemap.xml` when available.
3. It extracts direct links ending in `.pdf`, `.ppt` or `.pptx`.
4. Presentation files themselves are never requested.
5. New URLs are deduplicated by the unique `file_url` column.

Safety controls:

- only HTTP/HTTPS;
- only ports 80/443;
- localhost, private and reserved IP ranges are rejected;
- redirects are followed manually and revalidated;
- page fetches use short connect/request timeouts;
- HTML/XML body size is capped;
- scans are user-triggered in the MVP.

## Next discovery layer

The next stage can expand discovery without paid APIs by adding conservative crawling of known source archives, event/material pages and other public indexes. It should continue to store links only and must keep per-domain request limits.
