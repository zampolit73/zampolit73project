# Architecture

Updated for the current application structure on 2026-09-22.

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
- `GET /push/config`;
- `POST /push/subscriptions`;
- `DELETE /push/subscriptions`;
- `POST /push/test`;
- `POST /logout`.

Admin only:

- `GET /admin/users` — account list / create form;
- `POST /admin/users` — create a `user` account with an initial password;
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

### jobs

A jobs table exists, but production currently uses `QUEUE_CONNECTION=sync`; there is no queue worker in the current deployment.

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
