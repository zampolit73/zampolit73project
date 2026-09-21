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
- Certbot.

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
- Nginx reload and local HTTPS/PWA checks.

### Health Check

Runs from a separate GitHub runner after Deploy. It verifies public DNS, HTTPS `/up`, homepage, manifest, service worker and the HTTP→HTTPS redirect with strict request timeouts.

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
QUEUE_CONNECTION=sync
LOG_LEVEL=warning
```

VAPID values are generated/preserved on the server.

## GitHub secrets

Required repository secrets:

- `VPS_HOST`;
- `VPS_USER`;
- `VPS_PWD`.

Do not put these values in docs or source.

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

Public checks have explicit DNS/connect/request timeouts and print DNS, remote IP and timing diagnostics, so an unreachable domain fails quickly instead of occupying the deploy runner until the job-level timeout.

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
