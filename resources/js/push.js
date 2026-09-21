import { csrfHeaders } from './lib/csrf.js';

const getVapidPublicKey = async () => {
    const response = await fetch('/push/config', { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Не удалось получить настройки push.');
    const data = await response.json();
    if (!data.enabled || !data.publicKey) throw new Error('Push-уведомления ещё не настроены на сервере.');
    return data.publicKey;
};

const urlBase64ToUint8Array = (value) => {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    return Uint8Array.from(atob((value + padding).replace(/-/g, '+').replace(/_/g, '/')), char => char.charCodeAt(0));
};

const subscribeToPush = async () => {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        throw new Error('Push-уведомления не поддерживаются этим браузером.');
    }
    if (!window.isSecureContext) throw new Error('Push-уведомления требуют HTTPS.');

    const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
    if (permission !== 'granted') throw new Error('Разрешение на уведомления не предоставлено.');

    const registration = await navigator.serviceWorker.ready;
    let subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
        subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(await getVapidPublicKey()),
        });
    }

    const response = await fetch('/push/subscriptions', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', ...csrfHeaders()},
        body: JSON.stringify(subscription.toJSON()),
    });
    if (!response.ok) {
        let message = '';
        try {
            const data = await response.json();
            message = data.message || '';
        } catch {
            // Ответ может быть HTML при редиректе auth middleware.
        }
        throw new Error(`Не удалось сохранить push-подписку (${response.status}${message ? `: ${message}` : ''}).`);
    }
    return subscription;
};

const unsubscribeFromPush = async () => {
    const subscription = await (await navigator.serviceWorker.ready).pushManager.getSubscription();
    if (!subscription) return;
    const response = await fetch('/push/subscriptions', {
        method: 'DELETE',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', ...csrfHeaders()},
        body: JSON.stringify({endpoint: subscription.endpoint}),
    });
    if (!response.ok) throw new Error('Не удалось отключить push-подписку.');
    await subscription.unsubscribe();
};

const getPushState = async () => {
    if (!('Notification' in window) || !('serviceWorker' in navigator)) return 'unsupported';
    if (Notification.permission === 'denied') return 'denied';
    const subscription = await (await navigator.serviceWorker.ready).pushManager.getSubscription();
    return subscription ? 'subscribed' : 'available';
};

export {subscribeToPush, unsubscribeFromPush, getPushState};
