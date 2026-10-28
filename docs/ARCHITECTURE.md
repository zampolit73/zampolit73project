# Architecture

Updated for the current application structure on 2026-09-24.

## Application stack

Backend:

- PHP 8.3+;
- Laravel 13;
- Inertia Laravel 3;
- SQLite;
- minishlink/web-push 11.

Frontend:

- Vue 3.5;
- Inertia Vue 3;
- Vite 7;
- Tailwind CSS 4.

Production edge:

- Nginx;
- PHP 8.3-FPM;
- Let's Encrypt / Certbot.

## Request flow

```text
Browser / installed PWA
        |
      HTTPS
        |
      Nginx
        |
    PHP-FPM
        |
     Laravel
        |
    +---+-------------------+
    |                       |
 Inertia pages           JSON endpoints
    |                       |
   Vue                Push subscription API
```

Laravel owns routing. Vue pages are resolved by `resources/js/inertia-app.js`.

## Route map

Public:

- `GET /` → `Home.vue`;
- `GET /login` → `Login.vue`;
- `POST /login` → session login;
- `GET /up` → Laravel health endpoint.

Authenticated users:

- `GET /projects` → `Projects.vue`;
- `GET /projects/bmp-to-mip` → `BmpToMip.vue`;
- `GET /projects/pushkin-fairytales` → `PushkinFairytales.vue`;
- `GET /projects/reading-diary` → `ReadingDiary.vue`;
- `GET /projects/cio-presentations` → `CioPresentations.vue` with full project functionality for any authenticated user;
- `GET /projects/kommersant-ranking` → `KommersantRanking.vue`, shared Kommersant manager-ranking workspace;
- `GET /projects/vacancy-source` → `VacancySource.vue`, personal/team investigation history and launcher;
- `POST /projects/vacancy-source/investigations` → enqueue a new web investigation;
- `GET /projects/vacancy-source/investigations/{id}/status` → polling status endpoint;
- `POST /projects/vacancy-source/investigations/{id}/cancel` → cancel a still-queued investigation;
- `POST /api/telegram/bot/webhook` → stateless Telegram Bot webhook protected by Telegram's secret-token header;
- `PATCH /projects/kommersant-ranking/managers/{manager}` → LinkedIn / assignment mutation;
- `PATCH /projects/kommersant-ranking/candidates/{candidate}` → candidate LinkedIn / assignment mutation;
- `GET /push/config`;
- `POST /push/subscriptions`;
- `DELETE /push/subscriptions`;
- `POST /push/test`;
- `POST /logout`.

Admin only:

- `GET /admin/users` — account list / create form / Telegram binding state;
- `POST /admin/users` — create a `user` account with an initial password;
- `POST /admin/users/{user}/telegram-invite` — create a one-time, no-expiry Telegram binding code;
- `DELETE /admin/users/{user}/telegram-binding` — unlink the current Telegram account;
- `GET /stas`;
- `GET /design-system`;
- `GET /tests`;

## Authentication

Authentication uses Laravel session guard and Eloquent `User`.

Login identifier is `username`, not email.

Roles are normalized to exactly two values:

- `admin`;
- `user`.

Guests are unauthenticated sessions, not a database role. Any legacy non-admin/non-user role is migrated to `user`.

Admin-created accounts are created only as `user`. The initial password is accepted over the authenticated admin form and immediately hashed with Laravel's `Hash` facade; plaintext passwords are never stored or returned in the user list.

Session storage is file-based in production and persists through shared Laravel storage.

Production sets secure session cookies because the site is HTTPS-only.

## Data model

### users

Important fields:

- `id`;
- `username` unique;
- `role`;
- `password`;
- remember token;
- timestamps.

### push_subscriptions

Each browser/device subscription stores:

- `user_id`;
- unique push endpoint;
- P-256 public key;
- auth token;
- content encoding;
- timestamps.

A single user can therefore have multiple device/browser subscriptions.

### kommersant_categories / kommersant_managers / kommersant_candidates / kommersant_activities

The Kommersant ranking project persists shared team state in SQLite. Categories preserve the spreadsheet directions/tabs; manager rows store ranking metadata and editable LinkedIn URLs; candidates store the manual-verification queue. Managers and candidates can each reference one nullable responsible `users.id` plus an assignment timestamp. Activity rows record assignment and LinkedIn changes for the project overview.

The initial 2026 dataset is imported exactly once by a migration from gzip/base64 text chunks committed under `database/data/kommersant-ranking-2026/`. Normal deploys never re-import or overwrite subsequent user edits.

### jobs

The existing `jobs` table backs Laravel's database queue. Production uses `QUEUE_CONNECTION=database` and runs one dedicated systemd worker for the `vacancy-source` queue. The worker processes one investigation at a time.

### user_telegram_accounts / telegram_invites

`user_telegram_accounts` links exactly one site user to one private Telegram identity/chat. `telegram_invites` stores only SHA-256 hashes of one-time binding codes; plaintext codes are returned to the admin only once and are never persisted. Unused codes do not expire automatically; issuing a new code invalidates the previous unused code for that user.

### vacancy_investigations / investigation_candidates / investigation_sources / investigation_reviews

Vacancy Source stores the submitted vacancy, normalized fingerprint, queue/progress state and result summary in `vacancy_investigations`. `investigation_candidates` stores ranked direct/indirect end-client hypotheses and intermediary flags. `investigation_sources` stores the strongest web source URL/title/snippet/query/evidence score and may reference a candidate. `investigation_reviews` remains the admin-validation boundary.

## Frontend structure

`AppLayout.vue` provides the shared sidebar/navigation and responsive mobile navigation.

Pages:

- `Home.vue` — greeting and world clocks;
- `Login.vue`;
- `DesignSystem.vue`;
- `Tests.vue` — operational browser tests, currently Web Push;
- `Stas.vue` — interactive greeting;
- `Projects.vue` — authenticated project catalog;
- `BmpToMip.vue` — client-only bulk BMP → Quake 1 MIP converter;
- `PushkinFairytales.vue` — authenticated interactive living-book animation with local Vue/CSS artwork;
- `ReadingDiary.vue` — authenticated browser-local reading diary rendered as an interactive bookshelf.
- `KommersantRanking.vue` — authenticated shared ranking workspace with category tabs, filters, inline LinkedIn editing and assignment actions.
- `VacancySource.vue` — authenticated vacancy-investigation launcher with live polling and role-aware history.

Shared UI components live in `resources/js/components/ui/`.

The visual system is centralized under `resources/css/design-system/` and `resources/css/app.css`.

## Homepage clocks

The homepage displays live browser-side time using `Intl.DateTimeFormat` with IANA zones:

- Moscow: `Europe/Moscow`;
- Ulyanovsk: `Europe/Ulyanovsk`;
- Berlin: `Europe/Berlin`.

Berlin DST changes are therefore handled by the browser's timezone database.

## PWA flow

`resources/js/app.js` loads `pwa.js` and the Inertia app.

`pwa.js` registers `/sw.js`, handles install prompt state and implements pull-to-refresh.

The service worker handles offline navigation fallback and incoming push notifications.

See `PWA_PUSH.md`.


## Client-only project tools

The BMP → MIP converter runs entirely in the browser:

```text
Local folder
    |
 browser File API / directory picker
    |
 BMP decode -> RGBA
    |
 Quake 1 palette quantization
    |
 four mip levels + miptex header
    |
 JSZip
    |
 convertedDDMMYYYY.zip
```

BMP data is not uploaded to Laravel. Laravel only serves the Inertia page and static JavaScript bundle.

Implementation:

- `resources/js/pages/BmpToMip.vue` — selection, browser decoding, progress and ZIP download;
- `resources/js/lib/quakeMip.js` — palette, naming, validation and binary encoder;
- `tests/js/quake-mip.mjs` — encoder smoke tests executed in Frontend Build.


## CIO presentations project

The `/projects/cio-presentations` section is available with the same full functionality to every authenticated user. Both `admin` and `user` can manage sources, scan, add/clear presentations and change review metadata. The admin distinction applies to site administration, not project functionality.

Laravel owns source/presentation persistence and the lightweight public-page scan. Vue renders the project workspace and opens presentation URLs directly in a new browser tab.

### presentation_sources

Stores public source pages and lightweight scan state:

- name, URL and domain;
- priority / active state;
- last scan timestamp;
- new-link count and last error.

### presentations

Stores metadata only:

- optional source;
- title / speaker / role / company / event;
- PDF/PPT/PPTX URL and source-page URL;
- manual review/link flags;
- optional responsible user and assignment timestamp;
- discovery/review timestamps.

Presentation files are not proxied or persisted. Assignment uses a nullable foreign key from `presentations.assigned_to_user_id` to `users.id`; claiming a presentation is performed with a conditional database update so concurrent users cannot overwrite each other. See `docs/CIO_PRESENTATIONS.md`.


## Pushkin fairytales project

The authenticated `/projects/pushkin-fairytales` page is a frontend-only visual experiment.

- no database state;
- no external image assets;
- no API requests;
- Vue controls page selection, autoplay and reduced-motion behavior;
- CSS provides the 3D book opening/page-turning animation and decorative tale miniatures;
- `prefers-reduced-motion` disables automatic page changes and motion-heavy transitions.

The page is listed as project #03 in `Projects.vue`.



## Kommersant ranking project

The authenticated `/projects/kommersant-ranking` page is project #05 and is shared by all authenticated users.

- Laravel/SQLite owns the ranking rows, candidate queue, LinkedIn edits, assignment state and activity history;
- the imported 2026 source contains 19 ranking directions, 1120 manager rows, 338 initial LinkedIn URLs and 55 LinkedIn candidates;
- the workbook title says TOP-1000, but the actual category sheets contain 1120 rows; the application reports the imported rows rather than inventing a 1000-row cap;
- LinkedIn edits accept only HTTP(S) URLs on `linkedin.com` or its subdomains; clearing the value is supported;
- claiming a manager/candidate uses a conditional database update and cannot overwrite another user's assignment; release is owner-only;
- category and candidate tables are horizontally scrollable inside their own containers on narrow screens, avoiding page-level overflow;
- imported payload chunks are a one-time bootstrap only and must not overwrite later user edits on normal deploys.

See `docs/KOMMERSANT_RANKING.md`.


## Project navigation across atomic deploys

`Projects.vue` uses full document links for project cards. Projects are independent tools, and a full navigation ensures the browser loads the current release's Vite manifest/chunks after an atomic deploy.

`resources/js/app.js` also handles Vite `vite:preloadError` by reloading the document. This protects long-lived tabs from stale hashed dynamic-import URLs.



## Reading diary project

The authenticated `/projects/reading-diary` page is project #04.

- frontend-only MVP; no Laravel persistence and no shared user data;
- data is stored in browser `localStorage` under `zampolit73.reading-diary.v1`;
- first use starts with the built-in **Сказки Пушкина** book;
- users can add books, select them from the shelf, assign a 1–5 rating, store a completion date and write a note;
- custom books can be removed; the built-in Pushkin entry stays on the shelf;
- the Pushkin entry links to the existing `/projects/pushkin-fairytales` project;
- the bookshelf/book-cover visuals are CSS-only and require no external assets;
- responsive layouts include contained horizontal shelf scrolling on small screens.


## Vacancy Source project

`/projects/vacancy-source` is project #06.

Current implemented flow:

```text
Web form or private Telegram Bot
        |
vacancy_investigations
        |
database queue: vacancy-source
        |
VacancySignalExtractor
        |
BingRssSearchProvider
        |
VacancyWebResearchService
        |
investigation_candidates + investigation_sources
        |
web live status/history + Telegram result
```

The queue worker is `zampolit73project-vacancy-source-worker.service`. Web research is deterministic and source-backed; Bing RSS titles/snippets are evidence, not silently treated as fetched full pages. Scoring weights live in `config/vacancy_source.php`.

The Telegram Bot user interface is handled by Laravel long polling in `zampolit73project-telegram-bot.service`. `TelegramUpdateHandler` is shared by the poller and the stateless webhook fallback. Forward metadata is ignored; only text/caption enters research.

The future Python MTProto Reader remains separate: it will index the user's selected work-folder chats as research sources and feed the same candidate/evidence layer. See `docs/VACANCY_SOURCE.md`.

## Vacancy Source Telegram Reader

The work-chat research source is a separate Python/Telethon process. It is intentionally not part of PHP-FPM and does not expose an HTTP API.

```text
admin browser
    |
Laravel admin routes
    |
TelegramReaderClient
    |
Unix socket /run/zampolit73-telegram-reader/reader.sock
    |
Python Telethon Reader (zampolit-reader)
    |
local MTProto listener 127.0.0.1:1443
    |
local WSS bridge (tg-ws-proxy, no CF fallback)
    |
Telegram-owned WSS transport
    |
selected Telegram folder
    |
private SQLite corpus / FTS5
```

Security boundary:

- MTProto session/corpus state lives under `/var/lib/zampolit73-telegram-reader`;
- the service user owns that directory with mode 0700;
- Laravel never opens the session file;
- the runtime socket is group-accessible to `www-data` and accepts a small JSON RPC surface;
- phone/code/2FA values are transient RPC inputs and are not persisted by Laravel;
- media is never downloaded.

The Reader currently provides setup/auth/folder/backfill/sync/search primitives. Investigation scoring integration is a separate next iteration.


## IRLIX AI Sales (project #07)

Route: `GET /projects/irlix-sales` (admin-only pilot), Inertia page `IrlixSales.vue`.

- `POST /projects/irlix-sales/prospects`: create company manually;
- `PATCH /projects/irlix-sales/prospects/{prospect}`: update company manually;
- `POST /projects/irlix-sales/import-candidate/{candidate}`: save a candidate from `Vacancy Source` as **unverified** prospect.
- Table `sales_prospects`: normalized unique company name, optional unique INN, website, status, priority, assigned user, contact, evidence link, notes, next action, optional source candidate.
- The import endpoint checks that the candidate belongs to an investigation visible to the current user (admin may import any); creating the same company again is idempotent.
- Data is shared among authenticated users in the same way as other internal project tools. No automated outreach, CRM integration or AI API calls are part of this MVP.
- PHP feature tests in `tests/Feature/SalesProspectTest.php` cover authentication, CRUD, duplicate detection, URL and ownership validation, private candidate import.
- New user-facing navigation entry in the sidebar and Projects catalogue; responsive Vue page at 320px.

## IRLIX Intelligence — public Git-backed research

- Route `GET /projects/irlix-sales/research`, admin-only;
- Inertia page `IrlixResearch.vue`;
- Laravel service `CompanyResearchRepository` reads and checks JSON files
  bundled with the current release from `data/company-reports/*.json`;
- Each company is one public, versioned JSON file (`<stable-id>.json`), with UTF-8
  details, demand rating, public links, sources and unanswered questions;
- GitHub push to main triggers existing CI, staging of the code/research
  and a normal atomic production release (no separate data sync service);
- No OpenAI API and no hidden server-side research execution in this MVP;
- Research documents are **not** saved into the SQLite prospect table automatically.
  They are a distinct read-only section, linked from the IRLIX Sales page;
- Site authorization restricts display to administrators; the underlying GitHub
  report source is openly readable to anyone because this repository is public.
  Never commit nonpublic personal data or confidential sales materials.

## Expanded public buyer-intelligence report structure

CompanyResearchRepository additionally normalizes `decision_makers` (ranked and
sourced public professional contacts), `business_contacts` (published company
contact channels), `legal_profile`, `financials`, `projects`, `buying_signals`,
`tenders`, `procurement`, `sales_assessment`, `sales_plan`, and `crm_summary`.
The UI renders the commercial 10-section buyer report. No migration is required,
and existing v1 reports render with empty optional sections. Public repository
history remains accessible outside site authentication.


## IRLIX research JSON import and revision history

Authenticated users now access \`GET /projects/irlix-sales/research\`
and \`POST /projects/irlix-sales/research/import\` (throttled). Main
sales-prospects CRUD remains admin-only.

The upload endpoint validates UTF-8 JSON (<=256 KiB), v1 schema,
mandatory attributes, provenance links, identity consistency, collision
confirmation and user review. It normalizes the document through the same
\`CompanyResearchRepository::normalizePayload\` path as bundled public reports,
then writes whitelisted JSON into a persistent SQLite transaction. Historical
versions live in \`company_research_import_revisions\`. The original upload
is not kept and no import data is sent to GitHub.

The repository service merges public Git-backed JSON and private SQLite
reports, matching identity by INN or stable ID, with the imported record
taking precedence for display. Each displayed document includes origin,
revision/author metadata (for SQLite) and a standard JSON export action.

Security: only logged-in employees can view SQLite research, not guests.
There is no direct ChatGPT-to-private-SQLite connector or background AI API.
Direct chat-to-GitHub remains public-only; to ingest a private report prepared
by a colleague, upload its JSON through the authenticated site.


## JSON repair endpoint for ChatGPT-shaped research reports

Authenticated, throttled `POST /projects/irlix-sales/research/repair`
accepts the same size-limited JSON upload as the web importer. The new
`CompanyResearchJsonRepairService` transforms only known field aliases and
returns canonical report data plus `changes` / `warnings`. It delegates to
`CompanyResearchImportService::prepare` after the translation so all identity,
URL, date, evidence and array-limit checks remain shared with Telegram and web
imports. The returned normalized report is not written to disk or SQLite.
The Vue interface lets staff download the canonical JSON and stages it as the
file to import, requiring existing human confirmation and collision confirmation.

Never auto-save repaired data. Unsupported fields require manual review;
no AI-based facts/contacts or new secret storage are introduced.

## Telegram JSON company research importer

The existing TelegramUpdateHandler delegates incoming private-chat JSON
documents and callback_query updates to TelegramResearchImportHandler.
TelegramBotClient uses getFile/file-download via the configured api.telegram.org
TLS transport, the same transportOptions/IP override as polling. The existing
long-polling systemd process now subscribes to both message and callback_query.
The secret-header webhook fallback remains available and handles inline
reply_markup. Vacancy text and /status continue unchanged; /import gives help.

All document validation and persistence are centralized in
CompanyResearchImportService, shared by the web controller and Telegram:
UTF-8/256-KiB file size, v1 schema and business fields, HTTP(S) source
sanitization, company-INN matching, proof requirement for confirmed demand,
collision check by INN or report ID, SQLite revisions and author attribution.

Unconfirmed uploads are stored in \`company_research_telegram_pending\` with
random confirmation-token SHA-256, bound site user, Telegram identity/chat,
message id, expiry, original preview collision state and normalized JSON.
Callbacks are accepted only for the original linked user in their private chat,
while pending, before 30-minute expiry and with the exact token.
Replay, cancellation and expiry cannot re-save. Applied/rejected/cancelled
pending rows erase the temporary JSON payload; expired rows are pruned on
subsequent document intake. No incoming file is saved under public storage.

An import that would unexpectedly overwrite a newly appeared company between
preview and confirmation is rejected and must be sent again. The private
SQLite research tables remain outside GitHub and the application release.
Telegram cloud document transport is not end-to-end encrypted; use direct
web upload for especially sensitive files.


## Telegram research confirmation delivery and version-aware replay

\`company_research_telegram_pending\` stores nullable \`result_revision\`
and \`result_changed\` for applied callbacks. Old applied rows remain readable.
The pending row is committed in the same SQLite transaction as its corresponding
research revision. Replays emit a version-aware acknowledgement without a second
write, even after later imports of the same company.

After a terminal callback the handler returns a \`clear_inline_keyboard\`
message-id hint to the transport. Long polling sends the success message FIRST
and clears the original inline keyboard using Telegram
\`editMessageReplyMarkup\` only when sendMessage succeeds. If notification
delivery fails, the update offset remains unacknowledged and the same callback
will retry using persisted result metadata; no duplicate revision is created.
The webhook fallback uses explicit Telegram API sendMessage + cleanup for
terminal callbacks and returns HTTP 503 if delivery fails, allowing retry.
Keyboard-removal errors are best-effort and do not revert SQLite writes.

Tests verify save-v1/save-v2 replay versions, button removal, cancellation,
failure to send acknowledgement, and original permission checks.

## Telegram delivery during VPS outbound outages

Production deploy explicitly selects `services.telegram.transport=webhook`.
GitHub Actions registers message/callback delivery with the existing secret and
verifies the HTTPS endpoint. Basic replies, including `/start CODE`, are sent
as the webhook HTTP response; no outbound Bot API connection is needed.
The existing systemd bot command runs only its stale-job watchdog in this mode,
so restart cannot remove the webhook. Polling remains an explicit alternate mode.
JSON downloads and the existing confirmed import/background-send paths still
require outbound Telegram connectivity. See `docs/PRODUCTION.md`.
