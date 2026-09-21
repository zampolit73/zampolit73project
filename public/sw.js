const CACHE = 'zampolit73-shell-v1';
const OFFLINE = '/offline.html';
const SHELL = ['/', '/login', OFFLINE];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE)
            .then(cache => cache.addAll(SHELL))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => event.waitUntil(
    caches.keys()
        .then(keys => Promise.all(keys.filter(key => key !== CACHE).map(key => caches.delete(key))))
        .then(() => self.clients.claim())
));

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET' || new URL(event.request.url).origin !== self.location.origin) return;

    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then(response => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(CACHE).then(cache => cache.put(event.request, copy));
                    }
                    return response;
                })
                .catch(() => caches.match(event.request).then(cached => cached || caches.match(OFFLINE)))
        );
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then(response => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE).then(cache => cache.put(event.request, copy));
                }
                return response;
            })
            .catch(() => caches.match(event.request))
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
