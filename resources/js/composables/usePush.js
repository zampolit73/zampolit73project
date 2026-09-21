import { onMounted, onUnmounted, ref } from 'vue';
import { getPushState, subscribeToPush, unsubscribeFromPush } from '../push.js';

export const usePush = () => {
    const pushState = ref('unknown');
    const pushBusy = ref(false);
    const pushError = ref('');

    const refreshPushState = async () => {
        try {
            pushState.value = await getPushState();
        } catch {
            pushState.value = 'unsupported';
        }
    };

    const togglePush = async () => {
        pushBusy.value = true;
        pushError.value = '';

        try {
            if (pushState.value === 'subscribed') {
                await unsubscribeFromPush();
            } else {
                await subscribeToPush();
            }
            await refreshPushState();
        } catch (error) {
            pushError.value = error.message || 'Не удалось изменить настройки уведомлений.';
        } finally {
            pushBusy.value = false;
        }
    };

    onMounted(refreshPushState);

    return { pushState, pushBusy, pushError, refreshPushState, togglePush };
};
