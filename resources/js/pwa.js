const createPullToRefresh = (registration) => {
    let startY = 0;
    let pulling = false;
    let refreshing = false;
    let indicator = null;

    const ensureIndicator = () => {
        if (indicator) return indicator;
        indicator = document.createElement('div');
        indicator.className = 'pwa-refresh-indicator';
        indicator.setAttribute('aria-hidden', 'true');
        indicator.innerHTML = '<span class="pwa-refresh-indicator__spinner"></span><span class="pwa-refresh-indicator__label">Потяните для обновления</span>';
        document.body.appendChild(indicator);
        return indicator;
    };

    const reset = () => {
        pulling = false;
        startY = 0;
        if (indicator) {
            indicator.classList.remove('is-visible', 'is-ready', 'is-refreshing');
            indicator.querySelector('.pwa-refresh-indicator__label').textContent = 'Потяните для обновления';
        }
    };

    const refresh = async () => {
        refreshing = true;
        const element = ensureIndicator();
        element.classList.add('is-visible', 'is-refreshing');
        element.querySelector('.pwa-refresh-indicator__label').textContent = 'Обновление…';

        let updated = false;
        const handleControllerChange = () => {
            updated = true;
            window.location.reload();
        };

        navigator.serviceWorker.addEventListener('controllerchange', handleControllerChange, {once: true});

        try {
            await registration.update();
            await new Promise(resolve => setTimeout(resolve, 700));
            if (!updated) {
                window.location.reload();
            }
        } catch {
            window.location.reload();
        } finally {
            refreshing = false;
        }
    };

    const onTouchStart = event => {
        if (refreshing || window.scrollY > 0 || event.touches.length !== 1) return;
        startY = event.touches[0].clientY;
        pulling = true;
    };

    const onTouchMove = event => {
        if (!pulling || refreshing || window.scrollY > 0 || event.touches.length !== 1) return;
        const distance = Math.max(0, event.touches[0].clientY - startY);
        if (distance < 8) return;
        const element = ensureIndicator();
        element.style.setProperty('--pull-distance', Math.min(distance, 110) + 'px');
        element.classList.add('is-visible');
        const ready = distance >= 90;
        element.classList.toggle('is-ready', ready);
        element.querySelector('.pwa-refresh-indicator__label').textContent = ready ? 'Отпустите для обновления' : 'Потяните для обновления';
        if (ready) event.preventDefault();
    };

    const onTouchEnd = event => {
        if (!pulling || refreshing) return;
        const distance = Math.max(0, event.changedTouches[0].clientY - startY);
        reset();
        if (distance >= 90) refresh();
    };

    document.addEventListener('touchstart', onTouchStart, {passive: true});
    document.addEventListener('touchmove', onTouchMove, {passive: false});
    document.addEventListener('touchend', onTouchEnd, {passive: true});
};

const registerPwa = async () => {
    if (!('serviceWorker' in navigator)) return null;
    const registration = await navigator.serviceWorker.register('/sw.js', {scope: '/'});
    registration.update().catch(() => {});
    createPullToRefresh(registration);

    let deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault();
        deferredPrompt = event;
        window.dispatchEvent(new CustomEvent('pwa-install-available'));
    });
    window.addEventListener('pwa-install-request', async () => {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        await deferredPrompt.userChoice;
        deferredPrompt = null;
    });
    return registration;
};

registerPwa().catch(error => console.error('PWA registration failed', error));
