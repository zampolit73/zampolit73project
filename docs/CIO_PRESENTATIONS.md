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
- PDF, PPT or PPTX URL, or a public presentation share (`file_type=link`);
- source-page URL;
- review and link status;
- manual flags for email, phone and good lead;
- discovery / review timestamps.

## Built-in starter sources

Production receives an idempotent starter set through a data migration, not a seeder. Existing rows are preserved because preset URLs are inserted with `insertOrIgnore`.

The approved built-in set contains four source families:

- TAdviser / TAdviser SummIT pages with CIO and IT-director programs, speakers and event archives;
- CNews pages and indexes centered on CIO / IT-director conference materials;
- 4CIO / ОКИТ archives of presentations by enterprise IT and digital-transformation leaders;
- ИТ-Диалог event pages with public presentation links.

1C and Global CIO are not part of the preset source set. Manual user-added sources outside the preset list are not removed.

Preset sources are normal rows after installation: admins/moderators can scan them the same way as manually added sources.

The 2026 preset layer adds the official TAdviser SummIT pages for May and November 2026, TAdviser IT Prize 2026, and year-specific CNews FORUM / CNews FORUM Кейсы pages.

### Additional Russian archives

Migration `2026_09_22_120000_add_russian_cio_presentation_sources.php` adds seven pages:

| Source | URL | Priority |
| --- | --- | --- |
| 4CIO / ОКИТ 2026 | https://okit2026.4cio.ru/report | 130 |
| 4CIO / ОКИТ 2025 | https://okit2025.4cio.ru/report | 115 |
| 4CIO / ОКИТ 2024 | https://okit2024.4cio.ru/report | 105 |
| ИТ-Диалог / Киберконтур 2025 | https://xn--90ard6a.xn--80agbpbtv1a.xn--p1ai/kc2025 | 100 |
| CNews FORUM Кейсы 2026 | https://cnewsforum.ru/cases/2026/presentations | 130 |
| CNews FORUM Кейсы 2025 | https://cnewsforum.ru/cases/2025/presentations | 115 |
| CNews FORUM Кейсы 2024 | https://cnewsforum.ru/cases/2024/presentations | 105 |

All seven pages returned public HTML containing presentation links when checked on 2026-09-22. These are individual material pages, so discovery does not require recursive crawling. Source availability and presentation relevance still need manual review. ComNews paid/password-protected material packages are not included.

The additive migration does not alter existing source names, priorities, active states, scan history or presentations. It uses `insertOrIgnore`, including when a matching URL was added manually. Rollback preserves the rows because they may already contain user changes and review history.

## Clean restart

The presentations tab has a destructive **Очистить презентации** action. It hard-deletes all presentation rows and resets source scan timestamps/counts/errors, while preserving the source catalog.

The migration that introduced this action also performs a one-time reset of presentation/history data so production starts from zero as requested.

## Lightweight scanner

The first MVP scanner is intentionally narrow:

1. An admin/moderator adds a public HTTP/HTTPS source page.
2. A manual scan fetches only that HTML page plus the site's root `/sitemap.xml` when available.
3. It extracts direct links ending in `.pdf`, `.ppt` or `.pptx`, plus explicitly labelled public Yandex Disk presentation shares.
4. Presentation files themselves are never requested.
5. New URLs are deduplicated by the unique `file_url` column.

Public share recognition is limited to `disk.yandex.ru`, `disk.yandex.com` and `yadi.sk`, with `/i/<token>` or `/d/<token>` paths and link text identifying a presentation or slides. Video, photo and unlabelled share links are ignored. The share page is not requested by the scanner. A share is saved as `file_type=link`, shown as a link in the UI and available in the type filter/manual-entry form; its actual document format is not guessed.

Empty CNews Forum overlay links use text from their presentation card (topic and speaker details) as the searchable title, capped at 500 characters. URL fragments are removed before deduplication.

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
