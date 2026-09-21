# Project rules

These rules apply to the whole repository.

## Before changing code

1. Read this file and README.md.
2. Inspect the current repository structure and current `main` HEAD before editing.
3. For framework behavior, use documentation matching the versions in `composer.json` / `package.json`.
4. One logical task should normally be one commit.
5. Never report production deployment as successful until the relevant GitHub Actions run is green and public HTTPS checks pass.
6. Update project documentation when architecture, deployment, routes, production requirements or user-facing structure changes.

## Concurrent changes / main safety

Treat `main` as an optimistic-lock target. Another commit may arrive while a task is in progress.

1. Record the current `main` HEAD before starting edits.
2. Immediately before creating/updating the final `main` ref, fetch `main` HEAD again.
3. If HEAD changed, do **not** write the prepared commit on top of the stale base. Re-read the files changed upstream, rebuild/rebase the task on the new HEAD, and re-run relevant checks.
4. Update `main` only as a fast-forward operation. Never force-push or use a forced ref update on `main`.
5. If `main` changes again during the final write, abort/retry from the new HEAD rather than overwriting concurrent work.

## Architecture

- Laravel owns routes, authentication, data persistence and server-side mutations.
- Inertia connects Laravel routes to Vue pages.
- Vue pages: `resources/js/pages/`.
- Shared Vue components: `resources/js/components/`.
- Layouts: `resources/js/layouts/`.
- Frontend entrypoint: `resources/js/app.js`.
- Shared styles: `resources/css/`.
- Blade is only the minimal Inertia shell.
- Do not add inline JavaScript to Blade.
- A new user-facing section should normally include route + Vue page + navigation entry in the same change.

See `docs/ARCHITECTURE.md`.

## Visual language

Keep the established editorial/poster language:

- warm cream background;
- near-black foreground;
- deep red accent;
- strong borders;
- offset shadows;
- condensed display typography;
- geometric decoration.

Avoid generic rounded dashboard styling unless explicitly requested.

Do not introduce extremist symbols, slogans, emblems or hate-group imagery.

## Responsive design — mandatory

Every new page and substantial UI change must include mobile adaptation in the same task.

Minimum checks:

- usable at 320px viewport width;
- no unintended horizontal scrolling;
- readable without browser zoom;
- usable touch targets;
- fixed/sticky UI does not cover content;
- navigation remains usable on mobile.

Desktop changes should also consider laptop-height viewports, not only wide desktop screens.

## Authentication and roles

Current roles:

- `admin`;
- `moderator`;
- `user`.

`/design-system` is restricted to admin/moderator.
`/tests` and push API require authentication.

Never hardcode real production passwords in source, migrations, docs or workflow files.

## Database

Production database is SQLite.

- Schema changes must use Laravel migrations.
- Production deploy runs migrations with `--force`.
- Production deploy must not automatically run seeders.
- Seeders are development/bootstrap helpers only.
- Seeders must not overwrite existing credentials.
- Production SQLite must remain in shared persistent storage, outside release directories.

## Production deployment

Production is native Ubuntu, without Docker:

- Nginx;
- PHP 8.3-FPM;
- Laravel;
- SQLite;
- HTTPS via Let's Encrypt / Certbot;
- Vite assets built in GitHub Actions.

Persistent state:

- shared production `.env`;
- shared SQLite database;
- shared Laravel `storage`;
- Let's Encrypt state under `/etc/letsencrypt`.

Deployments use `releases/<sha>` + atomic `current` symlink.

Do not reintroduce Docker/Compose unless explicitly requested.

See `docs/PRODUCTION.md`.

## PWA and Web Push

PWA and Web Push are active production features.

- manifest: `public/site.webmanifest`;
- service worker: `public/sw.js`;
- frontend PWA bootstrap: `resources/js/pwa.js`;
- frontend push client: `resources/js/push.js`;
- push API: `PushSubscriptionController`;
- server sender: `WebPushService`;
- VAPID bootstrap: `scripts/ensure-vapid.php`;
- deploy-success notification: `push:deploy-success`.

VAPID private keys belong only in production `.env`, never in Git.

A deploy-success push must run only after public deployment checks succeed.

See `docs/PWA_PUSH.md`.

## Security and secrets

Never commit:

- production `.env`;
- passwords;
- private SSH keys;
- VAPID private key;
- DuckDNS token;
- production database.

Repository GitHub secrets are currently `VPS_HOST`, `VPS_USER`, `VPS_PWD`.

## Documentation

Keep these files current:

- `README.md`;
- `docs/ARCHITECTURE.md`;
- `docs/PRODUCTION.md`;
- `docs/PWA_PUSH.md`;
- `docs/TECHNICAL_DEBT.md`.
- `docs/PROJECT_CONTEXT.md`;
- `docs/BMP_TO_MIP.md`.

Do not document assumptions as deployed facts. Verify current code/workflow first.
