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

### vacancy_investigations / investigation_candidates / investigation_reviews

The first Vacancy Source iteration stores the submitted vacancy, owner, queue/progress state and result summary in `vacancy_investigations`. Candidate and review tables establish the data boundary for later source matching and admin validation. The first job is intentionally a demo pipeline: it exercises queue/progress/history without claiming that Telegram or web research is already implemented.

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
Vue textarea
    |
Laravel investigation row
    |
database queue: vacancy-source
    |
RunVacancyInvestigation
    |
progress/status updates
    |
Vue polling + history
```

The production worker is managed by systemd as `zampolit73project-vacancy-source-worker.service`. It runs a single `queue:work` process so investigations are serialized on the small VPS.

This first iteration does **not** connect Telegram MTProto, Telegram Bot API, external web search or scoring. The queued job uses clearly labelled demo stages and never invents a client. See `docs/VACANCY_SOURCE.md`.

## Telegram Bot transport for Vacancy Source

Telegram Bot API is handled by Laravel, not by the future Python Telegram Reader.

```text
private Telegram chat
        |
Telegram Bot API
        |
POST /api/telegram/bot/webhook
        |
secret-token header validation
        |
site user binding lookup
        |
vacancy_investigations + database queue
```

The webhook ignores group/supergroup messages. Forward metadata is ignored; only the message text/caption becomes investigation input. Bound users can submit a vacancy through the bot and receive queue/progress/final technical-pipeline messages.

`app/Services/TelegramBotClient.php` owns outbound Bot API calls. `telegram:bot:set-webhook` configures the HTTPS webhook after production secrets are placed in shared `.env`.

The future Python MTProto Reader remains a separate concern: it will index the work-folder chats used as research sources and will not replace the Bot API user interface.
