# Project rules

These rules apply to the whole repository.

## Before changing code
1. Read this file and README.md.
2. Inspect the current repository structure and current main HEAD before editing.
3. For Laravel behavior, prefer the official documentation for the version used by this project.
4. One logical task should normally be one commit.
5. Never treat a deployment as successful until the relevant GitHub Actions run is green and the public health check passes.

## Architecture
- Laravel handles routes, authentication, data and server-side mutations.
- Inertia is used for page transitions and server props.
- Vue pages live in `resources/js/pages/`.
- Shared Vue components live in `resources/js/components/`.
- Layouts live in `resources/js/layouts/`.
- Frontend entrypoint: `resources/js/app.js`.
- Styles live in `resources/css/`.
- Blade is only the minimal Inertia shell.
- Do not add inline JavaScript to Blade.
- New user-facing sections should add route, Vue page and navigation entry together.

## Visual language
Keep the editorial/poster visual language established by the project:
- warm cream background;
- near-black text and strong borders;
- deep red accent;
- large condensed display typography;
- offset shadows and geometric decorative elements;
- no rounded, generic dashboard styling unless a task explicitly calls for it.

Do not introduce extremist symbols, slogans or hate-group imagery.

## Responsive design — mandatory
Every new page and every substantial UI change must include mobile adaptation in the same task.

Minimum checks:
- usable at 320px viewport width;
- no unintended horizontal scrolling;
- readable typography without zooming;
- usable touch targets;
- fixed/sticky elements do not cover content;
- navigation remains usable on mobile.

Do not postpone mobile work to a later task.

## Production deployment
Production runs directly on Ubuntu without Docker:
- Nginx;
- PHP 8.3 FPM;
- Laravel;
- SQLite;
- static Vite build produced in GitHub Actions.

Do not reintroduce Docker or Compose unless explicitly requested.

Persistent production state must live outside release directories:
- production `.env`;
- SQLite database;
- Laravel storage.

Deployments use release directories plus a `current` symlink. Do not store secrets in Git.

## Database
- Use Laravel migrations for schema changes.
- Production deploys run migrations but never automatically run seeders.
- Seeders must not reset existing production credentials.

## PWA and Web Push
PWA infrastructure remains in the codebase, but Web Push is intentionally not part of the initial production rollout.
Do not make push notifications a deployment dependency until HTTPS/domain setup is complete and the feature is explicitly enabled.

## Documentation
Update README.md when architecture, deployment requirements or user-visible project structure changes.
