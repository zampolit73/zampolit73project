# Project context / handoff

Updated: 2026-09-21

This file is a compact handoff for continuing work on `zampolit73/zampolit73project`.
It is a snapshot, not a substitute for checking the current repository state. Before changing anything, fetch the current `main` HEAD and follow `AGENTS.md`.

## Repository and production

- Repository: `zampolit73/zampolit73project`
- Branch: `main`
- Production: `https://zampolit73.duckdns.org`
- Snapshot HEAD: `827a86a3a233c467dfaf616f8f2ee2822bffe005`
- Last verified production deploy before this docs-only commit: GitHub Actions run #35 — success.

## Stack

- Laravel 13
- PHP 8.3
- Inertia Laravel 3
- Vue 3.5
- Vite 7
- Tailwind CSS 4
- SQLite
- Nginx + PHP 8.3-FPM
- PWA
- Web Push / VAPID
- no Docker

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

## CI/CD

Workflow: `.github/workflows/deploy.yml`.

```text
Backend CI ─────┐
                ├──> Package ──> Deploy ──> Health Check ──> Notify
Frontend Build ─┘
```

### Backend CI

- PHP 8.3
- Composer cache
- test SQLite environment
- `composer install`
- application key generation
- PHPUnit
- backend/source artifact for production pushes

### Frontend Build

- Node.js 22
- npm cache
- `npm install --prefer-offline --no-audit --no-fund`
- Vite production build
- `public/build` artifact for production pushes

### Package

Combines the tested backend/source artifact with the built frontend artifact into `release.tar.gz`.

### Deploy

- downloads the assembled release;
- uploads to the VPS over SSH;
- apt provisioning only if required server packages/extensions are missing;
- reuses the current release `vendor/` when Composer lock is unchanged;
- links shared `.env`, SQLite and `storage`;
- runs migrations and Laravel optimize;
- atomically switches the `current` symlink;
- reuses the existing TLS certificate and runs Certbot issuance only if missing;
- reloads Nginx;
- performs local HTTPS/PWA checks.

### Health Check

External checks verify:

- DNS resolution;
- `/up`;
- homepage;
- `/site.webmanifest`;
- `/sw.js`;
- HTTP → HTTPS redirect.

Requests have explicit connection/request timeouts so failures do not hang the job for many minutes.

### Notify

After a successful Health Check, the workflow runs:

```bash
php8.3 artisan push:deploy-success
```

for administrator push subscriptions.

## Deployment concurrency

Production pushes use one concurrency group.

When a newer push reaches `main`:

- older running/queued production runs are cancelled;
- only the newest push continues toward production.

PR/manual runs use separate groups and cannot cancel the production pipeline.

## Concurrent main safety

Treat `main` as an optimistic-lock target:

1. Record current `main` HEAD before editing.
2. Fetch `main` HEAD again immediately before the final ref update.
3. If it changed, do not write the stale prepared commit.
4. Re-read upstream changes and rebuild/rebase the task on the new HEAD.
5. Update `main` only by fast-forward.
6. Never force-push or force-update `main`.

## Routes / pages

- `/` — public home page, “Привет, Валера!”, clocks for Moscow / Ulyanovsk / Berlin
- `/stas` — public “Привет, Стас!” page with an interactive animated figure
- `/projects` — public browser-tool catalog
- `/projects/bmp-to-mip` — client-side bulk BMP → Quake 1 MIP converter
- `/login` — guest login
- `/design-system` — admin/moderator
- `/tests` — authenticated service/test page, including Web Push
- `/up` — Laravel health endpoint

Authenticated push API:

- `GET /push/config`
- `POST /push/subscriptions`
- `POST /push/test`
- `DELETE /push/subscriptions`

## /stas

Main page file: `resources/js/pages/Stas.vue`.

Current behavior:

- “Привет, Стас!” greeting;
- stylized person illustration;
- the arm is an interactive button;
- click raises the arm with a smooth animation;
- second click lowers it;
- label switches between `НАЖМИ НА РУКУ` and `ПРИВЕТ!`;
- keyboard focus / `aria-pressed` support;
- reduced-motion fallback.

Relevant commit before this handoff snapshot:

`827a86a3a233c467dfaf616f8f2ee2822bffe005` — `Make Stas greeting arm interactive`.

## PWA / Web Push

- manifest: `public/site.webmanifest`
- service worker: `public/sw.js`
- PWA bootstrap: `resources/js/pwa.js`
- frontend push client: `resources/js/push.js`
- composable: `resources/js/composables/usePush.js`
- backend: `PushSubscriptionController`
- sender: `WebPushService`
- VAPID bootstrap: `scripts/ensure-vapid.php`

VAPID private key belongs only in production `.env`, never in Git.

## Authentication and users

Roles:

- `admin`
- `moderator`
- `user`

Production deploy does not run seeders.
Production credentials must not be committed.

## SSH / network

Deploy currently uses SSH port 22 with:

- host-key scan;
- SCP;
- SSH;
- connection timeout;
- server keepalive settings.

When UFW is active, deployment keeps these ports allowed:

- 22/tcp
- 80/tcp
- 443/tcp

## Important operational rules

- Never call a production deploy successful before the relevant Actions run is fully green and public checks pass.
- Never overwrite concurrent commits on `main`.
- Never commit production secrets or SQLite data.
- Never run production seeders automatically.
- Do not reintroduce Docker/Compose unless explicitly requested.
- Keep architecture/deployment docs updated when behavior changes.
- New UI work must remain usable on mobile and laptop-height viewports.

## Recent important commits

- `827a86a3...` — Make Stas greeting arm interactive
- `73e2ae85...` — Fix backend artifact packaging
- `fa42acae...` — Split CI and deploy into explicit stages
- `bb0c9604...` — Speed up production deployment
- `8042c2de...` — Document concurrent main update safety
- `32bcdabb...` — Harden deploy concurrency and health checks

## Continuing in a new session

Start with:

> Continue work on `zampolit73/zampolit73project`. Read `AGENTS.md` and `docs/PROJECT_CONTEXT.md`, then fetch the current `main` HEAD before making changes. Never overwrite concurrent commits.


## BMP → MIP project

The first item in `/projects` is a client-only bulk texture converter.

- input: local BMP files/folder;
- classic Quake 1 palette quantization;
- normal source colors avoid accidental fullbright indices;
- automatically resizes non-conforming BMP dimensions to a multiple-of-16 Quake canvas while preserving image proportions;
- writes raw little-endian `miptex_t` files with 4 mip levels;
- packs successful conversions into `convertedDDMMYYYY.zip`;
- invalid files are reported and skipped without aborting the batch;
- source images never leave the browser.

Implementation:

- `resources/js/pages/Projects.vue`;
- `resources/js/pages/BmpToMip.vue`;
- `resources/js/lib/quakeMip.js`;
- `tests/js/quake-mip.mjs`;
- `docs/BMP_TO_MIP.md`.
