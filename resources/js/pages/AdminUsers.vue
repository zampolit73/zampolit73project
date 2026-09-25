<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

const props = defineProps({
    users: { type: Array, required: true },
    telegramInvite: { type: Object, default: null },
    telegramBot: { type: Object, required: true },
});

const createdMessage = ref('');
const actionBusy = ref(null);
const copiedCode = ref('');

const form = useForm({
    username: '',
    password: '',
    password_confirmation: '',
});

function createUser() {
    const username = form.username.trim();

    createdMessage.value = '';
    form.username = username;

    form.post('/admin/users', {
        preserveScroll: true,
        onSuccess: () => {
            createdMessage.value = `Пользователь «${username}» создан. Передай ему начальный пароль безопасным способом.`;
            form.reset();
        },
    });
}

function generateInvite(user) {
    actionBusy.value = `invite:${user.id}`;

    router.post(
        `/admin/users/${user.id}/telegram-invite`,
        {},
        {
            preserveScroll: true,
            onFinish: () => { actionBusy.value = null; },
        },
    );
}

function unlinkTelegram(user) {
    if (!window.confirm(`Отвязать Telegram от пользователя «${user.username}»?`)) return;

    actionBusy.value = `unlink:${user.id}`;

    router.delete(
        `/admin/users/${user.id}/telegram-binding`,
        {
            preserveScroll: true,
            onFinish: () => { actionBusy.value = null; },
        },
    );
}

async function copyInvite(code) {
    if (!navigator.clipboard) return;

    await navigator.clipboard.writeText(`/start ${code}`);
    copiedCode.value = code;
    window.setTimeout(() => {
        if (copiedCode.value === code) copiedCode.value = '';
    }, 1600);
}

function inviteFor(user) {
    return props.telegramInvite?.user_id === user.id
        ? props.telegramInvite
        : null;
}

function telegramLabel(user) {
    if (!user.telegram) return 'Не подключён';

    return user.telegram.telegram_username
        ? `@${user.telegram.telegram_username}`
        : `ID ${user.telegram.telegram_user_id}`;
}

function botStartLink(code) {
    if (!props.telegramBot.username) return null;

    return `https://t.me/${props.telegramBot.username}?start=${encodeURIComponent(code)}`;
}

function formatDate(value) {
    if (!value) return '—';

    return new Intl.DateTimeFormat('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(value));
}
</script>

<template>
    <AppLayout>
        <Head title="Пользователи" />

        <main class="admin-users-page">
            <header class="admin-users-header">
                <div>
                    <p class="admin-users-eyebrow">ADMIN / ACCESS</p>
                    <h1>ПОЛЬЗОВАТЕЛИ</h1>
                </div>

                <p>
                    Создавай аккаунты для доступа к разделу «Проекты».
                    Новый аккаунт всегда получает роль <strong>user</strong>.
                    Здесь же можно связать сайт с личным Telegram пользователя.
                </p>
            </header>

            <div
                class="telegram-bot-state"
                :class="{ 'is-ready': telegramBot.configured }"
            >
                <strong>
                    TELEGRAM BOT:
                    {{ telegramBot.configured ? 'КОНФИГ НА СЕРВЕРЕ ЕСТЬ' : 'ЕЩЁ НЕ НАСТРОЕН' }}
                </strong>
                <span v-if="telegramBot.username">@{{ telegramBot.username }}</span>
                <p v-if="!telegramBot.configured">
                    Код привязки уже можно создать, но бот начнёт принимать его только после добавления
                    BotFather token + webhook secret в production .env и настройки webhook.
                </p>
            </div>

            <section class="admin-users-grid">
                <article class="admin-users-panel admin-users-panel--form">
                    <div class="admin-users-panel__head">
                        <span>01</span>
                        <div>
                            <p>НОВЫЙ АККАУНТ</p>
                            <h2>Добавить пользователя</h2>
                        </div>
                    </div>

                    <form class="admin-users-form" @submit.prevent="createUser">
                        <label>
                            <span>Логин</span>
                            <input
                                v-model="form.username"
                                type="text"
                                name="username"
                                autocomplete="off"
                                minlength="3"
                                maxlength="255"
                                required
                                placeholder="например: ivan"
                            >
                            <small v-if="form.errors.username">{{ form.errors.username }}</small>
                        </label>

                        <label>
                            <span>Начальный пароль</span>
                            <input
                                v-model="form.password"
                                type="password"
                                name="password"
                                autocomplete="new-password"
                                minlength="8"
                                maxlength="255"
                                required
                                placeholder="минимум 8 символов"
                            >
                            <small v-if="form.errors.password">{{ form.errors.password }}</small>
                        </label>

                        <label>
                            <span>Повтори пароль</span>
                            <input
                                v-model="form.password_confirmation"
                                type="password"
                                name="password_confirmation"
                                autocomplete="new-password"
                                minlength="8"
                                maxlength="255"
                                required
                                placeholder="ещё раз"
                            >
                        </label>

                        <div class="admin-users-form__note">
                            Пароль сохраняется только как хеш. После создания посмотреть его в админке нельзя.
                        </div>

                        <button type="submit" :disabled="form.processing">
                            {{ form.processing ? 'СОЗДАЮ…' : 'СОЗДАТЬ ПОЛЬЗОВАТЕЛЯ →' }}
                        </button>

                        <p v-if="createdMessage" class="admin-users-success" role="status">
                            {{ createdMessage }}
                        </p>
                    </form>
                </article>

                <article class="admin-users-panel">
                    <div class="admin-users-panel__head">
                        <span>02</span>
                        <div>
                            <p>ACCESS LIST / TELEGRAM</p>
                            <h2>Текущие аккаунты</h2>
                        </div>
                    </div>

                    <div class="admin-users-list">
                        <div class="admin-users-list__header">
                            <span>Логин</span>
                            <span>Роль</span>
                            <span>Telegram</span>
                            <span>Создан</span>
                        </div>

                        <div
                            v-for="user in props.users"
                            :key="user.id"
                            class="admin-users-row"
                        >
                            <strong class="admin-users-row__name">{{ user.username }}</strong>

                            <span class="admin-users-row__role">
                                {{ user.role === 'admin' ? 'Администратор' : 'Пользователь' }}
                            </span>

                            <div class="telegram-binding">
                                <template v-if="user.telegram">
                                    <div class="telegram-binding__status is-linked">
                                        <strong>{{ telegramLabel(user) }}</strong>
                                        <small>Подключён {{ formatDate(user.telegram.linked_at) }}</small>
                                    </div>

                                    <button
                                        class="telegram-action telegram-action--light"
                                        type="button"
                                        :disabled="actionBusy === `unlink:${user.id}`"
                                        @click="unlinkTelegram(user)"
                                    >
                                        {{ actionBusy === `unlink:${user.id}` ? 'ОТВЯЗЫВАЮ…' : 'ОТВЯЗАТЬ' }}
                                    </button>
                                </template>

                                <template v-else>
                                    <div class="telegram-binding__status">
                                        <strong>Не подключён</strong>
                                        <small>Нужен одноразовый /start код</small>
                                    </div>

                                    <button
                                        class="telegram-action"
                                        type="button"
                                        :disabled="actionBusy === `invite:${user.id}`"
                                        @click="generateInvite(user)"
                                    >
                                        {{ actionBusy === `invite:${user.id}` ? 'СОЗДАЮ…' : 'СОЗДАТЬ TELEGRAM-КОД' }}
                                    </button>
                                </template>

                                <div
                                    v-if="inviteFor(user)"
                                    class="telegram-invite"
                                    role="status"
                                >
                                    <span>Одноразовый код — покажется только сейчас</span>
                                    <code>{{ inviteFor(user).code }}</code>
                                    <div class="telegram-invite__actions">
                                        <button
                                            type="button"
                                            @click="copyInvite(inviteFor(user).code)"
                                        >
                                            {{ copiedCode === inviteFor(user).code ? 'СКОПИРОВАНО' : 'КОПИРОВАТЬ /START' }}
                                        </button>

                                        <a
                                            v-if="botStartLink(inviteFor(user).code)"
                                            :href="botStartLink(inviteFor(user).code)"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            ОТКРЫТЬ БОТА ↗
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <time class="admin-users-row__created">{{ formatDate(user.created_at) }}</time>
                        </div>
                    </div>

                    <p
                        v-if="$page.props.errors?.telegram"
                        class="telegram-global-error"
                    >
                        {{ $page.props.errors.telegram }}
                    </p>
                </article>
            </section>
        </main>
    </AppLayout>
</template>

<style scoped>
.admin-users-page {
    width: min(100%, 1320px);
    margin: 8px auto 0;
    color: var(--ds-color-black);
}

.admin-users-header {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(280px, .45fr);
    gap: 40px;
    align-items: end;
    margin-bottom: 24px;
}

.admin-users-eyebrow {
    margin: 0 0 10px;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: .16em;
}

.admin-users-header h1 {
    margin: 0;
    font-family: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
    font-size: clamp(66px, 8vw, 118px);
    line-height: .8;
    letter-spacing: -.02em;
}

.admin-users-header > p {
    margin: 0;
    padding-top: 16px;
    border-top: 4px solid var(--ds-color-black);
    font-size: 13px;
    font-weight: 700;
    line-height: 1.55;
}

.telegram-bot-state {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 14px;
    align-items: center;
    margin-bottom: 26px;
    padding: 13px 15px;
    border: 3px solid var(--ds-color-black);
    background: #f0c9bd;
    box-shadow: 5px 5px 0 var(--ds-color-black);
}

.telegram-bot-state.is-ready {
    background: #dce2bd;
}

.telegram-bot-state strong,
.telegram-bot-state span {
    font-size: 10px;
    font-weight: 900;
    letter-spacing: .06em;
}

.telegram-bot-state p {
    flex-basis: 100%;
    margin: 0;
    font-size: 10px;
    font-weight: 750;
    line-height: 1.45;
}

.admin-users-grid {
    display: grid;
    grid-template-columns: minmax(300px, .72fr) minmax(560px, 1.28fr);
    gap: 26px;
    align-items: start;
}

.admin-users-panel {
    min-width: 0;
    border: 5px solid var(--ds-color-black);
    background: var(--ds-color-surface);
    box-shadow: 9px 9px 0 var(--ds-color-black);
}

.admin-users-panel--form {
    box-shadow: 9px 9px 0 var(--ds-color-red);
}

.admin-users-panel__head {
    display: grid;
    grid-template-columns: 58px 1fr;
    gap: 14px;
    align-items: center;
    padding: 18px;
    border-bottom: 4px solid var(--ds-color-black);
}

.admin-users-panel__head > span {
    display: grid;
    width: 50px;
    height: 50px;
    place-items: center;
    background: var(--ds-color-red);
    color: #fff;
    font-family: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
    font-size: 24px;
}

.admin-users-panel__head p,
.admin-users-panel__head h2 {
    margin: 0;
}

.admin-users-panel__head p {
    font-size: 8px;
    font-weight: 900;
    letter-spacing: .12em;
}

.admin-users-panel__head h2 {
    margin-top: 3px;
    font-size: 20px;
    line-height: 1;
}

.admin-users-form {
    display: grid;
    gap: 16px;
    padding: 22px;
}

.admin-users-form label {
    display: grid;
    gap: 7px;
}

.admin-users-form label > span {
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.admin-users-form input {
    width: 100%;
    min-height: 50px;
    padding: 0 13px;
    border: 3px solid var(--ds-color-black);
    border-radius: 0;
    background: #fffaf0;
    color: var(--ds-color-black);
    font: inherit;
    font-size: 13px;
    font-weight: 700;
    outline: 0;
}

.admin-users-form input:focus {
    box-shadow: 4px 4px 0 var(--ds-color-red);
}

.admin-users-form small {
    color: var(--ds-color-red);
    font-size: 10px;
    font-weight: 800;
}

.admin-users-form__note {
    padding: 11px 12px;
    border: 2px solid var(--ds-color-black);
    background: #eadfca;
    font-size: 10px;
    font-weight: 750;
    line-height: 1.45;
}

.admin-users-form button {
    min-height: 52px;
    padding: 10px 14px;
    border: 3px solid var(--ds-color-black);
    background: var(--ds-color-black);
    color: #fff;
    cursor: pointer;
    font: inherit;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: .06em;
}

.admin-users-form button:disabled,
.telegram-action:disabled {
    cursor: wait;
    opacity: .55;
}

.admin-users-success {
    margin: 0;
    padding: 12px;
    border: 3px solid var(--ds-color-black);
    background: #dce2bd;
    font-size: 11px;
    font-weight: 800;
    line-height: 1.45;
}

.admin-users-list {
    min-width: 0;
}

.admin-users-list__header,
.admin-users-row {
    display: grid;
    grid-template-columns: minmax(90px, .8fr) 100px minmax(220px, 1.55fr) 92px;
    gap: 12px;
    align-items: start;
    padding: 14px 16px;
}

.admin-users-list__header {
    border-bottom: 3px solid var(--ds-color-black);
    background: var(--ds-color-black);
    color: #fff;
    font-size: 8px;
    font-weight: 900;
    letter-spacing: .1em;
    text-transform: uppercase;
}

.admin-users-row {
    min-height: 76px;
    border-bottom: 2px solid var(--ds-color-black);
    font-size: 11px;
}

.admin-users-row:last-child {
    border-bottom: 0;
}

.admin-users-row__name {
    overflow-wrap: anywhere;
    font-size: 13px;
}

.admin-users-row__role,
.admin-users-row__created {
    padding-top: 4px;
    font-weight: 750;
}

.telegram-binding {
    display: grid;
    gap: 8px;
    min-width: 0;
}

.telegram-binding__status {
    display: grid;
    gap: 2px;
}

.telegram-binding__status strong {
    overflow-wrap: anywhere;
    font-size: 11px;
}

.telegram-binding__status small {
    font-size: 9px;
    font-weight: 700;
    opacity: .7;
}

.telegram-binding__status.is-linked strong::before {
    content: '● ';
}

.telegram-action {
    width: fit-content;
    min-height: 34px;
    padding: 6px 9px;
    border: 2px solid var(--ds-color-black);
    border-radius: 0;
    background: var(--ds-color-black);
    color: #fff;
    cursor: pointer;
    font: inherit;
    font-size: 8px;
    font-weight: 900;
    letter-spacing: .05em;
}

.telegram-action--light {
    background: #eadfca;
    color: var(--ds-color-black);
}

.telegram-invite {
    display: grid;
    gap: 7px;
    padding: 10px;
    border: 3px solid var(--ds-color-black);
    background: #dce2bd;
}

.telegram-invite > span {
    font-size: 8px;
    font-weight: 900;
    line-height: 1.35;
    text-transform: uppercase;
}

.telegram-invite code {
    width: fit-content;
    padding: 4px 6px;
    border: 2px solid var(--ds-color-black);
    background: #fffaf0;
    font-size: 16px;
    font-weight: 900;
    letter-spacing: .08em;
}

.telegram-invite__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.telegram-invite__actions button,
.telegram-invite__actions a {
    min-height: 32px;
    padding: 6px 8px;
    border: 2px solid var(--ds-color-black);
    background: #fffaf0;
    color: var(--ds-color-black);
    cursor: pointer;
    font: inherit;
    font-size: 8px;
    font-weight: 900;
    text-decoration: none;
}

.telegram-global-error {
    margin: 0;
    padding: 12px 16px;
    border-top: 3px solid var(--ds-color-black);
    background: #f0c9bd;
    font-size: 10px;
    font-weight: 850;
}

@media (max-width: 1050px) {
    .admin-users-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 760px) {
    .admin-users-header {
        grid-template-columns: 1fr;
        gap: 20px;
    }

    .admin-users-list__header {
        display: none;
    }

    .admin-users-row {
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 8px 14px;
    }

    .admin-users-row__name {
        grid-column: 1;
    }

    .admin-users-row__role {
        grid-column: 2;
        text-align: right;
    }

    .telegram-binding {
        grid-column: 1 / -1;
        padding-top: 8px;
        border-top: 2px solid var(--ds-color-black);
    }

    .admin-users-row__created {
        grid-column: 1 / -1;
        padding-top: 0;
    }
}

@media (max-width: 560px) {
    .admin-users-header h1 {
        font-size: clamp(54px, 18vw, 82px);
    }

    .admin-users-panel {
        border-width: 4px;
        box-shadow: 6px 6px 0 var(--ds-color-black);
    }

    .admin-users-panel--form {
        box-shadow: 6px 6px 0 var(--ds-color-red);
    }

    .admin-users-panel__head {
        grid-template-columns: 48px 1fr;
        padding: 14px;
    }

    .admin-users-panel__head > span {
        width: 42px;
        height: 42px;
        font-size: 21px;
    }

    .admin-users-form {
        padding: 16px;
    }

    .admin-users-row {
        padding: 13px;
    }

    .telegram-action,
    .telegram-invite__actions button,
    .telegram-invite__actions a {
        width: 100%;
        min-height: 42px;
        display: grid;
        place-items: center;
    }
}
</style>
