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
- one Laravel database queue worker managed by systemd for Vacancy Source.

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

Telegram Bot credentials are production secrets. The preferred setup uses one GitHub Actions repository secret, `TELEGRAM_BOT_TOKEN`; the deploy workflow transfers it to the VPS through a short-lived mode-600 temp file, writes it into the shared production `.env`, generates the webhook secret on the VPS if missing, removes the temp file, and configures the webhook automatically.

Only this value needs to be entered manually in GitHub:

```text
Settings → Secrets and variables → Actions
TELEGRAM_BOT_TOKEN=<fresh BotFather token>
```

`TELEGRAM_BOT_WEBHOOK_SECRET` is derived deterministically from the BotFather token and stored in the persistent shared `.env`. This keeps Laravel's webhook validation and the GitHub runner on the same secret without exposing it in Git. `TELEGRAM_BOT_USERNAME` is optional; the bot works without it, but the admin UI can show a direct `t.me` link when it is configured.

On every later main deployment, if the GitHub secret exists, the token and derived webhook secret are refreshed in the shared `.env`. The GitHub runner validates the token with Telegram `getMe` and configures `setWebhook` directly from Actions. This avoids relying on VPS→Telegram connectivity for webhook registration. Runtime Laravel Bot API calls use IPv4 explicitly because the VPS has shown unreliable direct Telegram connectivity when address-family selection is left automatic.

Telegram webhook setup remains non-fatal for the website deployment: a Telegram outage or invalid bot token must not take the site down, and the Actions log prints a warning with Telegram's description.

The webhook URL is:

`https://zampolit73.duckdns.org/api/telegram/bot/webhook`

Laravel checks Telegram's `X-Telegram-Bot-Api-Secret-Token` header before processing an update. Bot tokens and webhook secrets must never be committed or pasted into docs.

The token is never committed to Git and the transient upload file is removed by the remote deploy cleanup trap.


### Telegram Bot diagnostics

Production deploy runs two safe diagnostics when `TELEGRAM_BOT_TOKEN` is configured:

- on the VPS: `php8.3 artisan telegram:bot:diagnose` reports whether token/webhook secret are configured, counts linked accounts and used/unused invites, and probes outbound Bot API connectivity without printing secrets;
- on the GitHub runner: `getWebhookInfo` reports pending updates / last delivery error, and a signed synthetic POST checks that Laravel accepts the configured webhook secret with HTTP 200.

These diagnostics intentionally avoid printing the BotFather token, webhook secret, Telegram user IDs or chat IDs.


### Telegram webhook IPv4 pinning

When Actions registers the Telegram webhook, it resolves the production hostname to the current public IPv4 address and passes that address to Telegram's `setWebhook` as `ip_address`. This keeps Telegram delivery on the verified IPv4 path even if hostname resolution/address-family selection is unreliable. `getWebhookInfo` remains part of deploy diagnostics and reports pending updates and the last delivery error.
