# Technical debt and known limitations

This file describes the current implementation, not hypothetical future requirements.

## Dependency lock files are missing

The repository currently has no `composer.lock` and no `package-lock.json`.

Consequences:

- dependency resolution can change between runs;
- CI and production are less reproducible;
- `npm install` is used instead of `npm ci`.

Priority: high.

Recommended next step: generate and commit lock files, then use deterministic install commands.

## Composer runs again on production VPS

Frontend assets are built in GitHub Actions, but PHP dependencies are installed again on the VPS.

This is functional but increases deploy time and leaves production dependency resolution dependent on Composer/network availability.

After adding `composer.lock`, consider packaging `vendor/` in CI or keeping server-side `composer install --no-dev` with the lock file.

## Password-based SSH deployment

GitHub Actions currently uses `sshpass` with `VPS_PWD`.

This works, but an SSH deploy key is preferable for narrower permissions, rotation and auditability.

Priority: medium.

## Host key is learned at deploy time

The workflow runs `ssh-keyscan` before each deployment.

That protects later SSH calls in the same run from unexpected changes, but it is not equivalent to pinning an independently verified host fingerprint.

Priority: medium.

## Every main documentation commit deploys production

The workflow triggers on every push to `main`, including docs-only changes.

Because successful deploys also send admin push, documentation changes can cause production deployment notifications.

Possible improvement: path filters or a separate build/deploy decision.

## No automated rollback

The release/symlink layout supports manual rollback, but no GitHub Actions rollback workflow exists.

Database migrations make rollback more complex because schema rollback must be considered separately.

## SQLite backup is not automated

Production SQLite is persistent, but the repository/workflow does not currently define scheduled off-server backups.

Priority rises as production data becomes valuable.

Recommended: scheduled SQLite backup with retention and a copy outside the VPS.

## Queue table exists but queue is synchronous

A `jobs` table exists, but production uses:

`QUEUE_CONNECTION=sync`

There is no queue worker or Supervisor/systemd worker configuration.

This is fine for the current workload but should be revisited before adding slow background tasks.

## No scheduler service

There is currently no production cron/systemd timer for `php artisan schedule:run`.

No current feature requires it.

## Push delivery observability is minimal

Push test returns a sent count and deploy logs show subscription/sent counts.

There is no durable push delivery history, failure reason storage or metrics dashboard.

## PWA cache strategy is intentionally simple

The service worker caches application responses with a lightweight strategy.

As the application becomes data-heavy, authenticated/dynamic response caching rules should be reviewed carefully to avoid stale UX.

## Seeder credentials are development defaults

`UsersSeeder` contains default bootstrap passwords.

Production deploy does not run seeders, so production credentials are not reset. Still, these credentials must be treated only as local/bootstrap defaults and never as production secrets.

## Application timezone is UTC

Laravel `config/app.php` currently uses UTC.

Homepage city clocks are browser-side and explicitly use IANA zones, so they are not affected.

Future server-side scheduled timestamps should deliberately choose whether UTC remains the canonical application timezone.

## DuckDNS dynamic update is not configured

DNS currently points at the VPS and HTTPS works.

If the VPS public IP can change, automatic DuckDNS update would require a DuckDNS token stored as a secret. The token must never be committed.
