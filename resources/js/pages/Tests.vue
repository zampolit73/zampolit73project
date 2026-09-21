<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import Button from '../components/ui/Button.vue';
import { getPushState, subscribeToPush, unsubscribeFromPush } from '../push.js';

const pushState = ref('unknown');
const busy = ref(false);
const message = ref('');
const error = ref('');

const stateLabel = computed(() => ({
    unknown: 'Проверяем…',
    unsupported: 'Не поддерживается',
    denied: 'Запрещено браузером',
    available: 'Можно подключить',
    subscribed: 'Подписка активна',
}[pushState.value] ?? pushState.value));

const refresh = async () => {
    try {
        pushState.value = await getPushState();
    } catch {
        pushState.value = 'unsupported';
    }
};

const enablePush = async () => {
    busy.value = true;
    message.value = '';
    error.value = '';

    try {
        await subscribeToPush();
        await refresh();
        message.value = 'Push-подписка создана. Теперь можно отправить тест.';
    } catch (e) {
        error.value = e?.message || 'Не удалось включить push-уведомления.';
    } finally {
        busy.value = false;
    }
};

const disablePush = async () => {
    busy.value = true;
    message.value = '';
    error.value = '';

    try {
        await unsubscribeFromPush();
        await refresh();
        message.value = 'Push-подписка отключена.';
    } catch (e) {
        error.value = e?.message || 'Не удалось отключить push-уведомления.';
    } finally {
        busy.value = false;
    }
};

const sendTest = async () => {
    busy.value = true;
    message.value = '';
    error.value = '';

    try {
        if (pushState.value !== 'subscribed') {
            await subscribeToPush();
            await refresh();
        }

        const response = await fetch('/push/test', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.message || `Ошибка отправки push (${response.status}).`);
        }

        if ((data.sent ?? 0) < 1) {
            throw new Error('Сервер не смог доставить тестовое уведомление. Проверь VAPID и подписку.');
        }

        message.value = 'Тестовое push-уведомление отправлено. Оно должно появиться на этом устройстве.';
    } catch (e) {
        error.value = e?.message || 'Не удалось отправить тестовое push-уведомление.';
    } finally {
        busy.value = false;
    }
};

onMounted(refresh);
</script>

<template>
    <AppLayout>
        <Head title="Tests" />

        <section class="tests-page">
            <header class="tests-header">
                <p class="tests-header__eyebrow">SYSTEM / TESTS</p>
                <h1>TESTS</h1>
                <p>
                    Служебная страница для проверки возможностей приложения в production.
                </p>
            </header>

            <article class="tests-card">
                <div class="tests-card__index">01</div>
                <div class="tests-card__body">
                    <p class="tests-card__eyebrow">PWA / WEB PUSH</p>
                    <h2>Push-уведомления</h2>
                    <p class="tests-card__copy">
                        Проверка проходит через реальную браузерную Push API подписку,
                        Laravel и VAPID. При первом запуске браузер попросит разрешение на уведомления.
                    </p>

                    <div class="tests-status">
                        <span>Статус</span>
                        <strong>{{ stateLabel }}</strong>
                    </div>

                    <div class="tests-actions">
                        <Button
                            v-if="pushState !== 'subscribed'"
                            type="button"
                            :disabled="busy || pushState === 'unsupported' || pushState === 'denied'"
                            @click="enablePush"
                        >
                            Включить push
                        </Button>

                        <Button
                            type="button"
                            :disabled="busy || pushState === 'unsupported' || pushState === 'denied'"
                            @click="sendTest"
                        >
                            {{ busy ? 'Проверка…' : 'Отправить тестовый push' }}
                        </Button>

                        <Button
                            v-if="pushState === 'subscribed'"
                            variant="secondary"
                            type="button"
                            :disabled="busy"
                            @click="disablePush"
                        >
                            Отключить push
                        </Button>
                    </div>

                    <p v-if="message" class="tests-message tests-message--success">{{ message }}</p>
                    <p v-if="error" class="tests-message tests-message--error">{{ error }}</p>

                    <p v-if="pushState === 'denied'" class="tests-hint">
                        Уведомления запрещены в настройках браузера. Разреши их для этого сайта и обнови страницу.
                    </p>
                </div>
            </article>
        </section>
    </AppLayout>
</template>
