# PWA and Web Push

## PWA components

Manifest:

`public/site.webmanifest`

Service worker:

`public/sw.js`

Offline fallback:

`public/offline.html`

Frontend registration:

`resources/js/pwa.js`

The app runs over HTTPS, so service workers and Push API operate in a secure context.

## Installation

The manifest uses:

- `start_url: /`;
- `scope: /`;
- standalone display;
- 192 and 512 icons;
- application theme/background colors.

`pwa.js` listens for `beforeinstallprompt` and exposes an install action to the layout when supported by the browser.

Installation UX differs by browser/platform; not every platform emits `beforeinstallprompt`.

## Service worker

The service worker:

- pre-caches only the static offline page, manifest and icons;
- fetches navigations from the network, falling back to the generic offline page;
- caches only static Vite assets and the pre-cached static resources;
- never stores Laravel HTML, Inertia JSON or push API responses, which may contain session data;
- removes previous app cache versions on activation;
- handles incoming `push` events;
- handles notification clicks and opens/navigates the app.

## Push architecture

```text
Browser
  |
  | PushManager.subscribe(VAPID public key)
  v
Laravel /push/subscriptions
  |
  v
SQLite push_subscriptions
  |
  | WebPushService + VAPID private key
  v
Browser push service
  |
  v
Service Worker
  |
  v
OS/browser notification
```

## VAPID

Configuration:

- `VAPID_SUBJECT`;
- `VAPID_PUBLIC_KEY`;
- `VAPID_PRIVATE_KEY`.

On production deploy, `scripts/ensure-vapid.php`:

1. reads shared production `.env`;
2. sets the subject to the production HTTPS URL;
3. creates a VAPID key pair only when keys are missing;
4. preserves existing keys on later deploys.

The private key must never be committed.

## User subscription flow

Push endpoints require authentication.

Native fetch mutations read the current Laravel `XSRF-TOKEN` cookie and send
`X-XSRF-TOKEN` on each request. This includes subscribe, unsubscribe and test push,
so logging in or rotating the session through Inertia does not leave a stale
token from the initial HTML document.

On `/tests`, the user can:

- request notification permission;
- create a browser push subscription;
- store it in Laravel;
- send a test push;
- remove the subscription.

A browser permission denial cannot be bypassed by the application. The user must re-enable notifications in browser/site settings.

## Test push

Endpoint:

`POST /push/test`

The server sends a test notification to all stored subscriptions for the current authenticated user.

## Deploy-success push

Artisan command:

`php artisan push:deploy-success`

It selects subscriptions whose related user has role `admin` and sends:

- title indicating deployment completed;
- success body;
- click target `/`.

GitHub Actions invokes the command only after public HTTPS checks succeed.

This means a green workflow can still send to zero devices when no admin has an active stored push subscription.

## Expired subscriptions

`WebPushService` deletes a stored subscription if the push provider reports that it has expired.

Other delivery failures currently return false but are not stored in a dedicated delivery log.

## Operational check

For an admin device:

1. sign in;
2. open `/tests`;
3. enable push;
4. send a test push;
5. confirm the notification arrives;
6. future successful deploys should then generate an admin notification.
