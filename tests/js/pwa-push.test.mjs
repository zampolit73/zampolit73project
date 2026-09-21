import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import { subscribeToPush, unsubscribeFromPush } from '../../resources/js/push.js';

const origin = 'https://app.example';
const workerSource = await readFile(new URL('../../public/sw.js', import.meta.url), 'utf8');

function worker() {
    const handlers = new Map();
    const stores = new Map();
    const requests = [];
    const key = request => new URL(typeof request === 'string' ? request : request.url, origin).href;
    let offline = false;
    const fetch = async request => {
        requests.push(key(request));
        if (offline) throw new TypeError('Offline');
        return new Response(key(request).endsWith('/offline.html') ? 'Offline page' : 'Network content');
    };
    const caches = {
        async open(name) {
            if (!stores.has(name)) stores.set(name, new Map());
            const entries = stores.get(name);
            return {
                async put(request, response) { entries.set(key(request), response.clone()); },
                async match(request) { return entries.get(key(request))?.clone(); },
                async addAll(urls) {
                    for (const url of urls) entries.set(key(url), await fetch(url));
                },
            };
        },
        async keys() { return [...stores.keys()]; },
        async delete(name) { return stores.delete(name); },
        async match(request) {
            for (const entries of stores.values()) {
                if (entries.has(key(request))) return entries.get(key(request)).clone();
            }
        },
    };
    const clients = { claim: async () => {} };
    vm.runInNewContext(workerSource, {
        URL, Response, caches, fetch, clients,
        self: {
            location: { origin }, clients,
            skipWaiting: async () => {},
            addEventListener(name, callback) { handlers.set(name, callback); },
        },
    });

    async function dispatch(name, request) {
        const pending = [];
        let response;
        handlers.get(name)({
            request,
            waitUntil(promise) { pending.push(promise); },
            respondWith(promise) { response = promise; },
        });
        const result = await response;
        await Promise.all(pending);
        await new Promise(resolve => setImmediate(resolve));
        return result;
    }

    return { caches, stores, requests, dispatch, goOffline() { offline = true; } };
}

function request(path, mode = 'cors', headers = {}) {
    return { url: origin + path, method: 'GET', mode, headers: new Headers(headers) };
}

test('install precaches only public static resources, without session pages', async () => {
    const sw = worker();
    await sw.dispatch('install');
    assert.ok(sw.requests.includes(origin + '/offline.html'));
    assert.ok(!sw.requests.includes(origin + '/'));
    assert.ok(!sw.requests.includes(origin + '/login'));
});

test('authenticated HTML is never stored or replayed after going offline', async () => {
    const sw = worker();
    await sw.dispatch('install');
    const privatePage = request('/projects/cio-presentations', 'navigate');
    assert.equal(await (await sw.dispatch('fetch', privatePage)).text(), 'Network content');
    assert.equal(await sw.caches.match(privatePage), undefined);
    sw.goOffline();
    assert.equal(await (await sw.dispatch('fetch', privatePage)).text(), 'Offline page');
});

test('Inertia and push API requests bypass the worker cache', async () => {
    const sw = worker();
    for (const req of [
        request('/projects/cio-presentations', 'cors', { 'X-Inertia': 'true' }),
        request('/push/config'),
    ]) {
        assert.equal(await sw.dispatch('fetch', req), undefined);
        assert.equal(await sw.caches.match(req), undefined);
    }
});

test('Vite assets remain available offline', async () => {
    const sw = worker();
    const asset = request('/build/assets/app-test.js');
    await sw.dispatch('fetch', asset);
    sw.goOffline();
    assert.equal(await (await sw.dispatch('fetch', asset)).text(), 'Network content');
});

test('activation deletes old app caches and preserves unrelated caches', async () => {
    const sw = worker();
    const old = await sw.caches.open('zampolit73-shell-v1');
    await old.put('/projects/cio-presentations', new Response('Private old data'));
    await sw.caches.open('another-app');
    await sw.dispatch('install');
    await sw.dispatch('activate');
    assert.ok(!(await sw.caches.keys()).includes('zampolit73-shell-v1'));
    assert.ok((await sw.caches.keys()).includes('another-app'));
    assert.equal(await sw.caches.match('/projects/cio-presentations'), undefined);
});

test('push mutations use the current cookie after session rotation', async t => {
    let unsubscribed = false;
    const subscription = {
        endpoint: 'https://push.example/device',
        toJSON() { return { endpoint: this.endpoint, keys: { p256dh: 'key', auth: 'auth' } }; },
        async unsubscribe() { unsubscribed = true; return true; },
    };
    const globals = {
        window: { PushManager: {}, Notification: {}, isSecureContext: true },
        Notification: { permission: 'granted' },
        document: { cookie: 'other=x; XSRF-TOKEN=after%2Blogin%3D; theme=dark', querySelector: () => ({ content: 'stale-token' }) },
        navigator: { serviceWorker: { ready: Promise.resolve({ pushManager: { getSubscription: async () => subscription } }) } },
    };
    for (const [name, value] of Object.entries(globals)) {
        const descriptor = Object.getOwnPropertyDescriptor(globalThis, name);
        Object.defineProperty(globalThis, name, { value, configurable: true, writable: true });
        t.after(() => {
            if (descriptor) Object.defineProperty(globalThis, name, descriptor);
            else delete globalThis[name];
        });
    }
    const calls = [];
    t.mock.method(globalThis, 'fetch', async (url, options) => {
        calls.push({ url, ...options });
        return Response.json({ subscribed: true });
    });

    await subscribeToPush();
    assert.equal(calls[0].headers['X-XSRF-TOKEN'], 'after+login=');
    assert.equal(calls[0].headers['X-CSRF-TOKEN'], undefined);
    document.cookie = 'XSRF-TOKEN=after%2Frotation%3D';
    await unsubscribeFromPush();
    assert.equal(calls[1].headers['X-XSRF-TOKEN'], 'after/rotation=');
    assert.equal(calls[1].method, 'DELETE');
    assert.equal(unsubscribed, true);
});
