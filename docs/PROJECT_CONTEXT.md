# Project context / handoff

Updated: 2026-09-21

This file is the primary handoff for continuing work on `zampolit73/zampolit73project`.
It is a snapshot, not a substitute for checking the live repository. Before changing code, always read `AGENTS.md`, fetch the current `main` HEAD, and follow the optimistic-lock rules for `main`.

## Repository and production

- Repository: `zampolit73/zampolit73project`
- Branch: `main`
- Production: `https://zampolit73.duckdns.org`
- Snapshot HEAD before this handoff update: `039090139b1907532d8c0ba0d5a6a4255f8e40f6`
- Last verified production deploy before this docs-only update: GitHub Actions run #45 — success
- Production is native Ubuntu; there is no Docker/Compose deployment

## Product shape

The site is a small multi-project application. The user wants new tools to live under the existing **Проекты** section instead of becoming unrelated top-level pages.

Current projects:

1. `/projects/bmp-to-mip` — public client-side Quake 1 texture converter.
2. `/projects/cio-presentations` — internal CIO / IT-director presentation catalog for sales research.
3. `/projects/pushkin-fairytales` — public animated living-book experiment based on Pushkin's fairytales.

The `/projects` page is the project selector. Do not replace or collapse the existing BMP → MIP project when changing the CIO project.

## Stack

- Laravel 13
- PHP 8.3
- Inertia Laravel 3
- Vue 3.5
- Vite 7
- Tailwind CSS 4
- SQLite
- Nginx + PHP 8.3-FPM
- Let's Encrypt / Certbot
- PWA
- Web Push / VAPID
- GitHub Actions CI/CD

## Production layout

```text
/var/www/zampolit73project/
├── current -> releases/<sha>
├── releases/
└── shared/
    ├── .env
    ├── database/database.sqlite
    └── storage/
```

TLS state lives under `/etc/letsencrypt/`.

Persistent state must stay outside release directories. Never commit production SQLite, `.env`, passwords, SSH credentials, VAPID private keys or other secrets.

## CI/CD

Workflow: `.github/workflows/deploy.yml`.

```text
Backend CI ─────┐
                ├──> Package ──> Deploy ──> Health Check ──> Notify
Frontend Build ─┘
```

Backend CI uses PHP 8.3, Composer and PHPUnit.
Frontend Build uses Node.js 22, npm and Vite.
Package combines tested backend/source with built `public/build`.
Deploy uploads the release to the VPS, links shared persistent state, runs migrations, optimizes Laravel and atomically switches `current`.
Health Check verifies production externally.
Notify sends the deploy-success Web Push only after health checks pass.

A deployment is not considered successful until the relevant Actions run is fully green and public HTTPS checks have passed.

Production deploy runs migrations with `--force` but does **not** run seeders.

## Main branch safety

Treat `main` as an optimistic-lock target.

1. Record current `main` HEAD before editing.
2. Re-fetch `main` immediately before updating the ref.
3. If HEAD changed, rebuild/rebase the task on the new HEAD.
4. Update only by fast-forward.
5. Never force-update `main`.

One logical task should normally be one commit.

## Visual language

Keep the established editorial/poster design:

- warm cream background;
- near-black foreground;
- deep red accent;
- hard borders;
- offset shadows;
- condensed display typography;
- geometric decoration.

Avoid generic rounded SaaS dashboards unless explicitly requested.

Responsive behavior is mandatory, including 320 px mobile widths and laptop-height viewports.

## Main routes

- `/` — public home
- `/stas` — public Stas page
- `/projects` — public project selector
- `/projects/bmp-to-mip` — public browser-only BMP → Quake 1 MIP converter
- `/projects/cio-presentations` — authenticated admin/moderator CIO presentation project
- `/projects/pushkin-fairytales` — public animated Pushkin fairytales book
- `/login` — guest login
- `/design-system` — admin/moderator
- `/tests` — authenticated service/test page
- `/up` — public Laravel health endpoint

Authenticated push API:

- `GET /push/config`
- `POST /push/subscriptions`
- `POST /push/test`
- `DELETE /push/subscriptions`

## Authentication

Roles:

- `admin`
- `moderator`
- `user`

The CIO presentations project intentionally uses the same access rule as the design system: admin/moderator only.

## PWA / Web Push

- manifest: `public/site.webmanifest`
- service worker: `public/sw.js`
- PWA bootstrap: `resources/js/pwa.js`
- frontend push client: `resources/js/push.js`
- push backend: `PushSubscriptionController`
- sender: `WebPushService`
- VAPID bootstrap: `scripts/ensure-vapid.php`

VAPID private keys belong only in production `.env`.

---

# BMP → MIP project

The first project under `/projects` is a browser-only Quake 1 texture converter.

Current behavior:

- input: local BMP files/folder;
- source images never leave the browser;
- classic Quake 1 palette quantization;
- avoids accidental fullbright colors for normal source images;
- automatically resizes invalid dimensions to a multiple-of-16 target while preserving proportions;
- centers content and extends edge pixels instead of adding black bars;
- proportionally downscales oversized inputs to the browser safety ceiling;
- writes raw little-endian `miptex_t` with four mip levels;
- packs successful conversions into a ZIP;
- invalid inputs are reported and skipped without aborting the batch.

Main implementation:

- `resources/js/pages/Projects.vue`
- `resources/js/pages/BmpToMip.vue`
- `resources/js/lib/quakeMip.js`
- `tests/js/quake-mip.mjs`
- `docs/BMP_TO_MIP.md`

---

# CIO presentations project

## Intent

This is an internal sales-support tool for finding public presentations by CIOs / IT directors and quickly reviewing them.

The primary MVP question is:

> Can the application regularly discover useful public presentation links for IT decision-makers using only free/public sources, while keeping VPS traffic and CPU usage very low?

The tool is a **catalog of links and metadata**, not a PDF repository and not an outreach/CRM system.

## Critical product decisions

These decisions were explicitly chosen and should not be reversed without a new user request:

- Do **not** automatically download presentation files to the VPS.
- Do **not** store presentation files.
- Do **not** proxy presentation downloads through the VPS.
- Clicking **Открыть презентацию** must send the user's browser directly to the original source URL.
- The server may fetch lightweight HTML/XML source pages for discovery.
- The MVP should remain free: no paid search API, paid proxy, paid LLM or paid scraping service.
- No OCR or PDF parsing is required for the current MVP.
- Contacts inside presentations are not automatically extracted in the current version.
- Review is manual: the user can mark whether a presentation has email, phone, is a good lead, is verified or does not fit.
- Do not add automatic cold-emailing / mass outreach.
- The preinstalled source families approved by the user are **TAdviser and CNews only**.
- **1C must not be reintroduced as a preset source.**
- **Global CIO must not be reintroduced as a preset source unless the user explicitly approves it later.**

## Route and access

Main route:

`/projects/cio-presentations`

Access:

- admin — allowed
- moderator — allowed
- regular user — forbidden
- guest — redirected to login

Main backend:

- `app/Http/Controllers/CioPresentationController.php`
- `app/Services/PublicPresentationScanner.php`
- `app/Models/Presentation.php`
- `app/Models/PresentationSource.php`

Main frontend:

- `resources/js/pages/CioPresentations.vue`
- CIO styles live in `resources/css/app.css`

Main docs:

- `docs/CIO_PRESENTATIONS.md`

## Data model

### presentation_sources

Stores source pages used for discovery:

- name
- URL
- domain
- priority
- active state
- last scan timestamp
- number of newly found links
- last scan error
- timestamps

### presentations

Stores metadata only:

- optional source
- title
- speaker name
- job title
- company
- event name
- event year
- file type: PDF / PPT / PPTX
- direct file URL
- source page URL
- review status
- link status
- manual `has_email`
- manual `has_phone`
- manual `is_good_lead`
- discovery timestamp
- review timestamp

`file_url` is unique and is the primary URL-level dedupe mechanism.

## Current interface

Tabs:

- Обзор
- Презентации
- Источники
- Поиск / сканирование

The project includes:

- statistics cards;
- presentation filters/search;
- source list;
- manual source creation;
- manual presentation-link creation;
- manual source scan;
- quick review buttons;
- direct **Открыть презентацию ↗** link;
- destructive **Очистить презентации** action.

The UI follows the existing cream / black / red poster language and has mobile layouts.

## Clear presentations / clean restart

The user explicitly requested the ability to start from zero.

The presentations tab now contains **Очистить презентации**.

Behavior:

- hard-delete every row from `presentations`;
- keep `presentation_sources`;
- reset all source `last_scanned_at`;
- reset source `last_scan_found` to 0;
- clear source `last_error`;
- browser confirmation is required before the destructive request.

The migration that introduced this feature also performed a **one-time production reset**, so the project started again from zero when commit `039090139...` was deployed.

Do not make future migrations repeatedly wipe presentation data. Future resets should happen only via the explicit UI action unless the user asks for another one-time reset.

## Lightweight scanner

Current scanner behavior is intentionally narrow.

For one selected source:

1. Validate that the source is public HTTP/HTTPS.
2. Fetch the source HTML/XML page.
3. Optionally fetch root `/sitemap.xml`.
4. Extract direct URLs ending in `.pdf`, `.ppt`, `.pptx`.
5. Save new URLs as presentation candidates.
6. Never request the presentation file itself.

Security / traffic controls:

- only HTTP/HTTPS;
- only ports 80/443;
- localhost is rejected;
- private/reserved IP ranges are rejected;
- redirects are followed manually and revalidated;
- short connect/request timeouts;
- HTML/XML body size cap;
- scans are manual in the current MVP.

The scanner includes UTF-8 handling for Russian link titles.

Known limitation: it currently finds direct file links on the given page / root sitemap; it does not yet deeply crawl event archives or follow candidate internal pages recursively.

## Approved preset source policy

Only TAdviser and CNews are approved preset families.

Earlier preset rows for 1C and Global CIO were removed by corrective migration.

Manual sources added by the user are separate from presets and should not be deleted by preset-maintenance migrations unless explicitly requested.

## Current preset sources

The preset set contains TAdviser/CNews historical discovery points plus 2026-specific sources.

### TAdviser / TAdviser SummIT

2026 sources currently include:

- `https://tadvisersummit.ru/a/2026-1/` — TAdviser SummIT, 28 May 2026
- `https://tadvisersummit.ru/` — TAdviser SummIT, 26 November 2026 / current event entry
- `https://itprize.tadviser.ru/` — TAdviser IT Prize 2026

Existing historical TAdviser presets also include older SummIT archives/pages such as 2016/2017 and the archive/plans entry already added before the 2026 layer.

### CNews

2026 sources currently include:

- `https://www.cnews.ru/news/top/2026-09-16_sotni_it-direktorov_rossii`
- `https://www.cnews.ru/news/top/2026-08-13_cnews_forum_2026_pervye_dokladchiki`
- `https://www.cnews.ru/news/top/2026-05-13_cnews_forum_kejsy_2026_sotni_it-direktorov`
- `https://www.cnews.ru/articles/2026-06-29_ot_temnyh_dannyh_do_avtonomnyh/4`

Existing CNews presets also include the CIO / IT-director index and selected historical event/material pages.

## Discovery strategy going forward

The intended free discovery model is:

```text
approved source catalog
        ↓
lightweight HTML / sitemap discovery
        ↓
internal event/archive/material pages
        ↓
direct PDF/PPT/PPTX links
        ↓
metadata catalog
        ↓
manual browser review
```

The preferred direction is to make the application better at mining **known high-value sources** rather than depending on a paid web-search API.

Potential next implementation step:

- add **Просканировать все активные**;
- crawl a small number of same-domain internal links from event/archive/material pages;
- prioritize URLs/pages containing terms such as CIO, ИТ-директор, директор по ИТ, директор по информационным технологиям, Head of IT, доклад, презентация, материалы, спикеры;
- strict per-domain request limits;
- keep presentation files untouched;
- collect surrounding HTML context so title / speaker / company can be inferred without opening the PDF.

Do not build a broad aggressive crawler before adding domain limits and URL caps.

## Traffic philosophy

The key architecture choice is to keep presentation traffic off the VPS.

Desired flow:

```text
VPS -> source HTML/XML only

User browser -> original PDF/PPT/PPTX source directly
```

Not:

```text
source -> VPS -> user
```

Deleting a downloaded file would not undo network traffic, which is why presentation files are not downloaded by the server in the first place.

## Review workflow

The practical sales workflow is:

1. Scanner finds a candidate URL.
2. Candidate appears under **Презентации**.
3. User opens the original presentation in the browser.
4. User manually marks:
   - email present;
   - phone present;
   - good lead;
   - verified;
   - not relevant.
5. Filters help process the remaining queue.

The application currently stores only those flags; it does not automatically store contact details from inside the document.

## Privacy / outreach boundary

Publicly available professional contact information is not automatically permission for unrestricted marketing.

The current product intentionally stops at internal sourcing/review. It does not automatically send email, place calls or run campaigns.

If outbound automation is added later, channel-specific privacy/direct-marketing requirements must be reviewed separately.

## Current recent CIO commits

- `e4ae504d...` — Add CIO presentations project MVP
- `86067119...` — Fix UTF-8 presentation link titles
- `35b0c867...` — Preinstall CIO presentation sources
- `f29b5f57...` — Replace CIO presets with TAdviser and CNews
- `03909013...` — Reset CIO history and add 2026 sources

## Continuing in a new session

Start with:

> Continue work on `zampolit73/zampolit73project`. Read `AGENTS.md`, `docs/PROJECT_CONTEXT.md` and `docs/CIO_PRESENTATIONS.md`, then fetch the current `main` HEAD before changing anything. The CIO project must keep presentation files off the VPS and preset sources must remain TAdviser/CNews only unless the user explicitly changes that decision.

Before implementing a new CIO feature, verify:

- current `main` HEAD;
- current routes/controller/page/migrations;
- latest Actions status;
- whether a production data migration is really necessary;
- that the change does not reintroduce 1C/Global CIO presets;
- that presentation files are still opened directly from the original source.


---

# Pushkin fairytales project

Project #03 under `/projects`.

Route:

`/projects/pushkin-fairytales`

Implementation:

- `resources/js/pages/PushkinFairytales.vue`
- styles in `resources/css/app.css`
- project card in `resources/js/pages/Projects.vue`

Product/design decisions:

- public route;
- frontend-only, no database;
- no external images or third-party visual libraries;
- animated 3D book that opens on entry and turns pages;
- five tale-themed spreads: golden fish, Tsar Saltan, dead princess, golden cockerel, Balda;
- autoplay every few seconds plus manual previous/next and direct dot selection;
- pause/play control;
- CSS-only decorative miniatures, paper texture, moon/stars, ornaments and table shadow;
- follows the site's cream / black / red visual language but adds dark blue night and muted gold accents;
- mobile adaptation down to 320 px;
- `prefers-reduced-motion` disables autoplay and heavy movement.
