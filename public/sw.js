const CACHE_PREFIX = 'zampolit73-shell-';
const CACHE = `${CACHE_PREFIX}v2`;
const OFFLINE = '/offline.html';
const SHELL = [OFFLINE, '/site.webmanifest', '/icon-192.svg', '/icon-512.svg'];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE)
            .then(cache => cache.addAll(SHELL))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => event.waitUntil(
    caches.keys()
        .then(keys => Promise.all(keys.filter(key => key.startsWith(CACHE_PREFIX) && key !== CACHE).map(key => caches.delete(key))))
        .then(() => self.clients.claim())
));

self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET' || url.origin !== self.location.origin) return;

    // Laravel HTML contains session-specific props and CSRF data, even on public pages.
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request, { cache: 'no-store' })
                .catch(async () => (await (await caches.open(CACHE)).match(OFFLINE)) || Response.error())
        );
        return;
    }

    // Inertia JSON and API responses must always use the current server session.
    if (event.request.headers.has('X-Inertia')) return;
    if (!url.pathname.startsWith('/build/assets/') && !SHELL.includes(url.pathname)) return;

    event.respondWith(
        fetch(event.request)
            .then(async response => {
                if (response.ok) {
                    try {
                        await (await caches.open(CACHE)).put(event.request, response.clone());
                    } catch {
                        // A full/disabled cache must not turn a successful request into a failure.
                    }
                }
                return response;
            })
            .catch(async () => (await (await caches.open(CACHE)).match(event.request)) || Response.error())
    );
});

self.addEventListener('push', event => {
    let data = {title: 'Zampolit73', body: 'Новое уведомление', url: '/'};
    try { if (event.data) data = {...data, ...event.data.json()}; } catch {}

    event.waitUntil(self.registration.showNotification(data.title, {
        body: data.body,
        icon: '/icon-192.svg',
        badge: '/icon-192.svg',
        data: {url: data.url},
    }));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const url = event.notification.data?.url || '/';
    event.waitUntil(
        clients.matchAll({type: 'window', includeUncontrolled: true}).then(windows => {
            const existing = windows.find(window => 'focus' in window);
            if (existing) return existing.navigate(url).then(() => existing.focus());
            return clients.openWindow(url);
        })
    );
});
