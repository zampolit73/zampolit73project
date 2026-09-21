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

### Build job

The build job:

1. checks out source;
2. sets PHP 8.3 and required extensions;
3. sets Node.js 22;
4. creates local test SQLite;
5. runs `composer install`;
6. runs `npm install`;
7. generates a test app key;
8. runs PHPUnit;
9. runs `npm run build`;
10. creates and uploads the release archive.

### Deploy job

The deploy job:

1. downloads the build artifact;
2. uploads it to VPS over password-based SSH;
3. checks DNS resolution for the production domain;
4. ensures Nginx, Composer, Certbot and PHP packages exist;
5. extracts a new release;
6. attaches shared `.env`, SQLite and `storage`;
7. installs PHP production dependencies on the VPS;
8. creates APP_KEY if absent;
9. ensures VAPID keys exist;
10. runs migrations;
11. runs Laravel optimize;
12. switches `current` symlink;
13. writes Nginx configuration;
14. obtains/reuses Let's Encrypt certificate;
15. enables HTTP→HTTPS redirect;
16. checks `/up`, manifest and service worker locally;
17. performs public HTTPS checks;
18. sends successful-deploy push to admin subscriptions.

Old release directories are pruned, keeping the newest releases.

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

Only after those checks does the workflow execute `push:deploy-success`.

## Rollback model

The filesystem layout supports rollback because releases are separate directories and `current` is a symlink.

There is currently no automated rollback job. A rollback must also consider database migrations; switching PHP code back does not automatically reverse schema changes.

## DuckDNS

The DuckDNS token is not used by the application or deploy workflow.

It would only be needed if we add automatic dynamic-DNS updates when the VPS public IP changes.
