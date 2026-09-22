<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

const props = defineProps({
    users: { type: Array, required: true },
});

const createdMessage = ref('');

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
                </p>
            </header>

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
                            <p>ACCESS LIST</p>
                            <h2>Текущие аккаунты</h2>
                        </div>
                    </div>

                    <div class="admin-users-list">
                        <div class="admin-users-list__header">
                            <span>Логин</span>
                            <span>Роль</span>
                            <span>Создан</span>
                        </div>

                        <div
                            v-for="user in props.users"
                            :key="user.id"
                            class="admin-users-row"
                        >
                            <strong>{{ user.username }}</strong>
                            <span>
                                {{ user.role === 'admin' ? 'Администратор' : 'Пользователь' }}
                            </span>
                            <time>{{ formatDate(user.created_at) }}</time>
                        </div>
                    </div>
                </article>
            </section>
        </main>
    </AppLayout>
</template>

<style scoped>
.admin-users-page {
    width: min(100%, 1280px);
    margin: 8px auto 0;
    color: var(--ds-color-black);
}

.admin-users-header {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(280px, .45fr);
    gap: 40px;
    align-items: end;
    margin-bottom: 30px;
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

.admin-users-grid {
    display: grid;
    grid-template-columns: minmax(320px, .8fr) minmax(480px, 1.2fr);
    gap: 26px;
    align-items: start;
}

.admin-users-panel {
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

.admin-users-form button:disabled {
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
    grid-template-columns: minmax(120px, 1.2fr) minmax(120px, .8fr) 110px;
    gap: 12px;
    align-items: center;
    padding: 14px 18px;
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
    min-height: 58px;
    border-bottom: 2px solid var(--ds-color-black);
    font-size: 11px;
}

.admin-users-row:last-child {
    border-bottom: 0;
}

.admin-users-row strong {
    overflow-wrap: anywhere;
    font-size: 13px;
}

.admin-users-row span,
.admin-users-row time {
    font-weight: 750;
}

@media (max-width: 900px) {
    .admin-users-header,
    .admin-users-grid {
        grid-template-columns: 1fr;
    }

    .admin-users-header {
        gap: 20px;
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

    .admin-users-list__header {
        display: none;
    }

    .admin-users-row {
        grid-template-columns: 1fr auto;
        gap: 7px 12px;
    }

    .admin-users-row strong {
        grid-column: 1 / -1;
    }

    .admin-users-row time {
        text-align: right;
    }
}
</style>
