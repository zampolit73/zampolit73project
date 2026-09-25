# Production and deployment

## Endpoint

Production domain:

`https://zampolit73.duckdns.org`

HTTP is redirected to HTTPS.

## Server stack

Production runs directly on Ubuntu without Docker:

- Nginx on 80/443;
- PHP 8.3-FPM;
- Laravel;
- SQLite;
- Certbot;
- one Laravel database queue worker managed by systemd for Vacancy Source;
- one Laravel Telegram Bot long-polling process managed by systemd;
- one Python/Telethon Telegram Reader process managed by systemd when Reader secrets are configured.

Nginx serves static files from Laravel `public/` and forwards `index.php` to PHP-FPM.

## Filesystem layout

```text
/var/www/zampolit73project/
├── current -> releases/<sha>
├── releases/
│   ├── <sha>
│   └── ...
└── shared/
    ├── .env
    ├── database/
    │   └── database.sqlite
    └── storage/
```

Release directories are disposable.

Persistent application state must remain under `shared/`.

TLS state is outside the app tree under `/etc/letsencrypt/`.

## GitHub Actions

Workflow: `.github/workflows/deploy.yml`.

Triggers:

- push to `main`;
- PR to `main` runs build job;
- manual workflow dispatch is declared.

Production deploy runs only for push events on `main`.

### Concurrency

Production pushes use a single concurrency group. When a newer push to `main` arrives, GitHub Actions cancels any older running or queued production workflow. Only the newest commit is allowed to continue toward production. PR and manual runs use separate groups and cannot cancel a production deploy.

### Workflow graph

Backend and frontend checks run in parallel. Production stages start only after both are green:

```text
Backend CI ─────┐
                ├──> Package ──> Deploy ──> Health Check ──> Notify
Frontend Build ─┘
```

### Backend CI

1. checkout;
2. PHP 8.3 setup;
3. Composer download cache;
4. test SQLite environment;
5. `composer install`;
6. application key generation;
7. PHPUnit;
8. for a production push, packages backend/source files and the generated Composer lock as an intermediate artifact.

### Frontend Build

1. checkout;
2. Node.js 22 setup;
3. npm download cache;
4. `npm install`;
5. Vite production build;
6. for a production push, uploads `public/build` as an intermediate artifact.

### Package

Runs only for a push to `main` after both parallel CI jobs succeed. It downloads backend and frontend artifacts, combines them into one release and uploads `release.tar.gz`.

### Deploy

Downloads the assembled release, uploads it to the VPS over SSH and activates it. The VPS still keeps the fast-path optimizations:

- apt provisioning only when required packages/extensions are missing;
- reuse of the previous release's `vendor/` when Composer lock is unchanged;
- shared `.env`, SQLite and `storage`;
- migrations and Laravel optimize;
- atomic `current` symlink switch;
- Certbot issuance only when the certificate is missing;
- Nginx reload and local HTTPS/PWA checks with short retries to tolerate the brief socket handoff immediately after reload.

### Health Check

Runs from a separate GitHub runner after Deploy. It verifies public DNS, HTTPS `/up`, homepage, manifest, service worker and the HTTP→HTTPS redirect with bounded request timeouts and retries. The HTTPS connection/TLS phase allows up to 8 seconds per attempt so temporary hosted-runner TLS handshake latency does not create a false failed deploy.

### Notify

Runs only after Health Check succeeds and sends `push:deploy-success` to stored admin push subscriptions.

## HTTPS

Certificate management uses Certbot webroot validation.

Current domain:

`zampolit73.duckdns.org`

Certbot is run with `--keep-until-expiring`; renewal is handled by `certbot.timer`.

Ports 80 and 443 must remain reachable publicly. Port 80 is also required for normal HTTP-01 renewal flow and redirects users to HTTPS.

## Environment

Production values are written into shared `.env`.

Important current production settings include:

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://zampolit73.duckdns.org
APP_LOCALE=ru
DB_CONNECTION=sqlite
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
CACHE_STORE=file
QUEUE_CONNECTION=database
QUEUE_FAILED_DRIVER=null
LOG_LEVEL=warning
```

VAPID values are generated/preserved on the server.

## GitHub secrets

Required repository secrets:

- `VPS_HOST`;
- `VPS_USER`;
- `VPS_PWD`.

Vacancy Source Telegram integrations additionally use:

- `TELEGRAM_BOT_TOKEN`;
- `TELEGRAM_READER_API_ID`;
- `TELEGRAM_READER_API_HASH`.

Do not put these values in docs or source.

## Vacancy Source queue worker

Production has one dedicated systemd unit:

`zampolit73project-vacancy-source-worker.service`

It runs:

```bash
php8.3 artisan queue:work database --queue=vacancy-source --sleep=1 --tries=1 --timeout=330
```

The deploy workflow writes/refreshes the unit, enables it, and restarts it after the atomic `current` symlink switch. The unit sends SIGTERM and allows up to 360 seconds for a graceful stop, matching the five-minute investigation ceiling plus shutdown margin.

The queue connection uses the existing SQLite `jobs` table. Failed-job persistence is disabled for this first iteration; the job itself marks its investigation as `failed`.

## Database and migrations

Production deploy automatically runs:

`php artisan migrate --force`

It does **not** run `db:seed`.

This distinction is intentional: production credentials must never be reset by seeders.

## Health checks

A release is not considered deployed until:

- local HTTPS `/up` succeeds;
- local manifest succeeds;
- local service worker succeeds;
- public HTTPS `/up` succeeds;
- public homepage succeeds;
- public manifest succeeds;
- public service worker succeeds;
- HTTP redirects to HTTPS.

Local post-reload checks use short retry windows because Nginx can briefly reset a connection while workers hand off. Public checks have explicit DNS/connect/request timeouts, bounded retries and print DNS, remote IP and timing diagnostics. HTTPS probes allow a longer TLS connection budget than the local checks because hosted runners can occasionally establish TCP quickly but take more than three seconds to complete the remote TLS handshake; persistent failures still fail the deploy.

Only after those checks does the workflow execute `push:deploy-success`.

## Rollback model

The filesystem layout supports rollback because releases are separate directories and `current` is a symlink.

There is currently no automated rollback job. A rollback must also consider database migrations; switching PHP code back does not automatically reverse schema changes.

## DuckDNS

The DuckDNS token is not used by the application or deploy workflow.

It would only be needed if we add automatic dynamic-DNS updates when the VPS public IP changes.


## Deployment performance

The workflow is optimized for frequent small pushes:

- backend and frontend dependency work runs in parallel;
- Composer download cache is persisted by GitHub Actions;
- npm download cache is persisted by GitHub Actions;
- `npm install` uses the local cache preferentially and skips audit/funding calls;
- the VPS skips `apt update/install` when the native stack is already provisioned;
- unchanged Composer dependencies reuse the previous release's `vendor/` via hardlinks;
- PHP-FPM is not restarted on every release;
- Certbot issuance is skipped while a valid certificate is already present;
- Nginx receives a lightweight configuration reload after the atomic release switch.

The first run after a dependency or server-package change can still be slower than a normal application-only deploy.


### Public health-check DNS behavior

The public health job resolves the production hostname once with `getent ahostsv4`, records the public IPv4 address, then uses curl `--resolve` for HTTPS and redirect probes. This still validates DNS availability at the start of the job while avoiding repeated resolver lookups that can intermittently time out on hosted runners.

## Telegram Bot production setup

Telegram Bot credentials remain production secrets. Only one GitHub Actions repository secret is required:

```text
TELEGRAM_BOT_TOKEN=<fresh BotFather token>
```

The deploy transfers the token to the VPS through a short-lived mode-600 file, writes it into the persistent shared `.env`, and removes the temporary file.

Production currently uses **long polling** instead of Telegram webhooks.

### Why long polling is pinned to a Telegram IPv4

Network diagnostics established this VPS-specific route problem:

- DNS `api.telegram.org` → `149.154.166.110`: TCP 443 times out;
- `149.154.167.220`: TCP 443 and TLS succeed;
- GitHub and Cloudflare HTTPS from the VPS succeed;
- UFW is inactive;
- iptables INPUT and OUTPUT default policies are ACCEPT.

Therefore deploy writes:

```text
TELEGRAM_BOT_API_IP=149.154.167.220
TELEGRAM_BOT_PUSH_ENABLED=true
```

Laravel keeps using the URL hostname `api.telegram.org` but resolves it to `TELEGRAM_BOT_API_IP` inside `TelegramBotClient`. TLS hostname verification/SNI still uses `api.telegram.org`.

This is an operational workaround for the current provider/network route. Re-test before changing or removing the pinned IP.

### Telegram long-polling service

Production has a dedicated systemd unit:

`zampolit73project-telegram-bot.service`

It runs:

```bash
php8.3 artisan telegram:bot:poll
```

The deploy writes/refreshes the unit, enables it and restarts it after the atomic release switch.

The poller calls `deleteWebhook(drop_pending_updates=false)` on startup, then long-polls `getUpdates`. The last processed update ID is stored in Laravel's persistent file cache under shared storage, so atomic deploys do not intentionally replay already processed updates.

Background Bot API replies are enabled because the client now uses the reachable pinned Telegram IPv4.

### Webhook fallback

The application still exposes:

`POST /api/telegram/bot/webhook`

Laravel validates `X-Telegram-Bot-Api-Secret-Token` there. The route remains useful as a fallback and for automated tests, but production Bot updates are not expected to arrive there while long polling is active.

GitHub Actions calls Telegram `deleteWebhook` with `drop_pending_updates=false` after deployment and prints safe `getWebhookInfo` diagnostics to confirm the webhook URL is empty.

### Telegram diagnostics

Production deploy runs `php8.3 artisan telegram:bot:diagnose` when the bot token is configured.

It reports, without exposing secrets or Telegram IDs:

- token/webhook-secret presence;
- linked-account and invite aggregate counts;
- default IPv4 route;
- UFW / iptables policy;
- Telegram DNS result;
- TCP/TLS reachability to the DNS-selected and alternate Telegram Bot API IPv4;
- GitHub/Cloudflare control HTTPS probes;
- real Bot API `getMe` through the configured pinned API IP.

The BotFather token must never be committed or pasted into documentation.


## Vacancy Source public web search

Real web research v1 uses Bing's public RSS-formatted web-search result page. No additional production secret is required.

The deploy runs a non-user diagnostic after local application checks:

```bash
php8.3 artisan vacancy:web:probe
```

It searches a generic vacancy query and reports only result count/source hosts. The probe is intentionally non-fatal for site deployment: search-provider availability must not take down the application, while the Actions log still exposes provider connectivity problems.

Investigation jobs themselves handle provider failures and may finish as `partial`; they never manufacture a client when source evidence is unavailable.

## Telegram Reader production setup

When both `TELEGRAM_READER_API_ID` and `TELEGRAM_READER_API_HASH` exist in GitHub Actions Secrets, deploy provisions the MTProto Reader automatically.

Persistent/private state:

```text
/var/lib/zampolit73-telegram-reader/
├── reader.session
├── corpus.sqlite3
└── corpus.sqlite3-wal / -shm when active
```

The directory is owned by `zampolit-reader` with mode 0700. It is outside release directories, outside Nginx webroot and intentionally not part of normal application backups.

Runtime control socket:

`/run/zampolit73-telegram-reader/reader.sock`

Systemd unit:

`zampolit73project-telegram-reader.service`

Runtime:

`/opt/zampolit73-telegram-reader-venv/bin/python /var/www/zampolit73project/current/telegram_reader/reader.py`

The service runs as `zampolit-reader` with group `www-data`. Laravel can connect to the Unix socket but cannot read the session state directory.

Deploy installs/updates Telethon into a persistent virtualenv, refreshes the systemd unit, restarts it and runs:

```bash
php8.3 artisan telegram-reader:diagnose
```

The first deploy after adding Reader secrets should report the service as connected but `authorized=no`. One-time MTProto login is then completed from the admin-only page `/admin/telegram-reader`; no terminal/VNC login is required.
