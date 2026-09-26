<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

const props = defineProps({
    reader: { type: Object, required: true },
    folders: { type: Array, default: () => [] },
    serviceError: { type: String, default: null },
});

const page = usePage();
const phoneForm = useForm({ phone: '' });
const codeForm = useForm({ code: '' });
const passwordForm = useForm({ password: '' });
const folderForm = useForm({ folder_id: props.reader.selected_folder?.id ?? '' });
const actionBusy = ref(false);
const qrBusy = ref(false);
let pollTimer = null;

const authorized = computed(() => Boolean(props.reader.authorized));
const qrPending = computed(() => props.reader.auth_state === 'qr_pending');
const qrExpired = computed(() => props.reader.auth_state === 'qr_expired');
const codeSent = computed(() => props.reader.auth_state === 'code_sent');
const passwordRequired = computed(() => props.reader.auth_state === 'password_required');
const showQrPassword = computed(() => passwordRequired.value || Boolean(props.reader.qr_auth_started));
const selectedFolderId = computed(() => props.reader.selected_folder?.id ?? null);

function requestQr() {
    qrBusy.value = true;
    router.post('/admin/telegram-reader/request-qr', {}, {
        preserveScroll: true,
        onFinish: () => { qrBusy.value = false; },
    });
}

function requestCode() {
    phoneForm.post('/admin/telegram-reader/request-code', {
        preserveScroll: true,
        onSuccess: (responsePage) => {
            if (!responsePage.props.flash?.readerError) phoneForm.reset();
        },
    });
}

function submitCode() {
    codeForm.post('/admin/telegram-reader/submit-code', {
        preserveScroll: true,
        onSuccess: (responsePage) => {
            if (!responsePage.props.flash?.readerError) codeForm.reset();
        },
    });
}

function submitPassword() {
    passwordForm.post('/admin/telegram-reader/submit-password', {
        preserveScroll: true,
        onSuccess: (responsePage) => {
            if (!responsePage.props.flash?.readerError) passwordForm.reset();
        },
    });
}

function selectFolder() {
    folderForm.post('/admin/telegram-reader/select-folder', {
        preserveScroll: true,
    });
}

function syncNow() {
    actionBusy.value = true;
    router.post('/admin/telegram-reader/sync', {}, {
        preserveScroll: true,
        onFinish: () => { actionBusy.value = false; },
    });
}

function formatDate(value) {
    if (!value) return 'ещё не было';

    return new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'short',
        timeStyle: 'medium',
    }).format(new Date(value));
}

onMounted(() => {
    pollTimer = window.setInterval(() => {
        const waitingForLogin = qrPending.value || showQrPassword.value;
        const syncing = authorized.value && (props.reader.sync_running || selectedFolderId.value);

        if (waitingForLogin || syncing) {
            router.reload({
                only: ['reader', 'folders', 'serviceError'],
                preserveScroll: true,
                preserveState: true,
            });
        }
    }, 2500);
});

onBeforeUnmount(() => {
    if (pollTimer) window.clearInterval(pollTimer);
});
</script>

<template>
    <AppLayout>
        <Head title="Telegram Reader" />

        <main class="reader-page">
            <header class="reader-header">
                <div>
                    <p class="reader-eyebrow">ADMIN / VACANCY SOURCE / MTProto</p>
                    <h1>TELEGRAM<br>READER</h1>
                </div>

                <div class="reader-header__note">
                    <strong>ИСТОЧНИК РАБОЧИХ ЧАТОВ</strong>
                    <p>
                        Это не Bot API. Reader подключает обычный Telegram-аккаунт через MTProto,
                        читает только выбранную рабочую папку и хранит локальный текстовый индекс без media.
                    </p>
                </div>
            </header>

            <div v-if="serviceError" class="reader-alert reader-alert--error">
                <strong>READER НЕДОСТУПЕН</strong>
                <span>{{ serviceError }}</span>
            </div>

            <div v-else class="reader-status" :class="{ 'is-ready': authorized }">
                <div>
                    <span>Сервис</span>
                    <strong>{{ reader.connected ? 'ONLINE' : 'OFFLINE' }}</strong>
                </div>
                <div>
                    <span>MTProto</span>
                    <strong>{{ authorized ? 'АВТОРИЗОВАН' : 'НЕ АВТОРИЗОВАН' }}</strong>
                </div>
                <div>
                    <span>Чатов в индексе</span>
                    <strong>{{ reader.chat_count }}</strong>
                </div>
                <div>
                    <span>Сообщений</span>
                    <strong>{{ reader.indexed_message_count }}</strong>
                </div>
                <div>
                    <span>Последний sync</span>
                    <strong>{{ formatDate(reader.last_sync_at) }}</strong>
                </div>
            </div>

            <p v-if="page.props.flash?.readerMessage" class="reader-flash reader-flash--ok">
                {{ page.props.flash.readerMessage }}
            </p>
            <p v-if="page.props.flash?.readerError" class="reader-flash reader-flash--error">
                {{ page.props.flash.readerError }}
            </p>
            <p v-if="reader.last_error" class="reader-flash reader-flash--error">
                Последняя ошибка Reader: {{ reader.last_error }}
            </p>

            <section v-if="!authorized" class="reader-grid">
                <article class="reader-panel">
                    <div class="reader-panel__head">
                        <span>01</span>
                        <div>
                            <p>ONE-TIME LOGIN</p>
                            <h2>Авторизовать аккаунт</h2>
                        </div>
                    </div>

                    <div class="reader-panel__body">
                        <div class="reader-qr-login">
                            <div class="reader-qr-copy">
                                <strong>QR — основной способ</strong>
                                <p>
                                    Нажми кнопку, затем на телефоне открой Telegram → Настройки →
                                    Устройства → Подключить устройство и отсканируй QR.
                                </p>
                            </div>

                            <button
                                type="button"
                                class="reader-qr-button"
                                :disabled="qrBusy"
                                @click="requestQr"
                            >
                                {{ qrBusy ? 'ГОТОВЛЮ QR…' : (qrPending ? 'ОБНОВИТЬ QR →' : 'ВОЙТИ ПО QR →') }}
                            </button>

                            <div v-if="qrPending && reader.qr_image" class="reader-qr-code">
                                <img :src="reader.qr_image" alt="QR-код для входа Telegram">
                                <small>
                                    QR одноразовый и хранится только в памяти Reader.
                                    Действителен до {{ formatDate(reader.qr_expires_at) }}.
                                </small>
                            </div>

                            <p v-if="qrExpired" class="reader-qr-expired">
                                QR истёк. Нажми «Войти по QR», чтобы получить новый.
                            </p>
                        </div>

                        <div class="reader-auth-divider"><span>ЗАПАСНОЙ ВАРИАНТ — КОД</span></div>

                        <form class="reader-form" @submit.prevent="requestCode">
                            <label>
                                <span>Номер Telegram</span>
                                <input
                                    v-model="phoneForm.phone"
                                    type="tel"
                                    autocomplete="tel"
                                    placeholder="+79991234567"
                                    required
                                >
                                <small v-if="phoneForm.errors.phone">{{ phoneForm.errors.phone }}</small>
                            </label>

                            <button type="submit" :disabled="phoneForm.processing">
                                {{ phoneForm.processing ? 'ОТПРАВЛЯЮ…' : 'ПОЛУЧИТЬ КОД TELEGRAM →' }}
                            </button>
                        </form>

                        <form v-if="codeSent" class="reader-form reader-form--sub" @submit.prevent="submitCode">
                            <label>
                                <span>Код из Telegram</span>
                                <input
                                    v-model="codeForm.code"
                                    type="text"
                                    inputmode="numeric"
                                    autocomplete="one-time-code"
                                    maxlength="8"
                                    placeholder="12345"
                                    required
                                >
                                <small v-if="codeForm.errors.code">{{ codeForm.errors.code }}</small>
                            </label>

                            <button type="submit" :disabled="codeForm.processing">
                                {{ codeForm.processing ? 'ПРОВЕРЯЮ…' : 'ПОДТВЕРДИТЬ КОД →' }}
                            </button>
                        </form>

                        <form v-if="showQrPassword" class="reader-form reader-form--sub" @submit.prevent="submitPassword">
                            <p class="reader-2fa-hint">
                                Если после сканирования QR Telegram пишет, что нужен облачный пароль,
                                введи его здесь. Поле остаётся доступным до успешного входа.
                            </p>
                            <label>
                                <span>Пароль Telegram 2FA</span>
                                <input
                                    v-model="passwordForm.password"
                                    type="password"
                                    autocomplete="current-password"
                                    placeholder="пароль облачной защиты"
                                    required
                                >
                                <small v-if="passwordForm.errors.password">{{ passwordForm.errors.password }}</small>
                            </label>

                            <button type="submit" :disabled="passwordForm.processing">
                                {{ passwordForm.processing ? 'ПРОВЕРЯЮ…' : 'ЗАВЕРШИТЬ АВТОРИЗАЦИЮ →' }}
                            </button>
                        </form>
                    </div>
                </article>

                <article class="reader-panel reader-panel--explain">
                    <div class="reader-panel__head">
                        <span>!</span>
                        <div>
                            <p>SECURITY</p>
                            <h2>Что сохраняется</h2>
                        </div>
                    </div>

                    <div class="reader-panel__body reader-copy">
                        <p>
                            QR login-token живёт только в памяти Reader до входа или истечения срока.
                            Код входа и 2FA-пароль также не записываются в Laravel БД.
                        </p>
                        <p>
                            MTProto session хранит отдельный Python-процесс вне webroot.
                            PHP получает только статус и команды через локальный Unix socket.
                        </p>
                        <p>
                            После успешного входа повторная авторизация при обычных deploy не нужна.
                        </p>
                    </div>
                </article>
            </section>

            <section v-else class="reader-grid">
                <article class="reader-panel">
                    <div class="reader-panel__head">
                        <span>02</span>
                        <div>
                            <p>ACCOUNT</p>
                            <h2>Аккаунт подключён</h2>
                        </div>
                    </div>

                    <div class="reader-panel__body reader-account">
                        <strong>
                            {{ reader.account?.username ? '@' + reader.account.username : (reader.account?.first_name || 'Telegram user') }}
                        </strong>
                        <span>MTProto session активна</span>
                        <span>FTS5: {{ reader.fts_enabled ? 'готов' : 'fallback search' }}</span>
                    </div>
                </article>

                <article class="reader-panel reader-panel--folder">
                    <div class="reader-panel__head">
                        <span>03</span>
                        <div>
                            <p>DYNAMIC WHITELIST</p>
                            <h2>Рабочая папка</h2>
                        </div>
                    </div>

                    <form class="reader-panel__body reader-form" @submit.prevent="selectFolder">
                        <label>
                            <span>Telegram folder</span>
                            <select v-model="folderForm.folder_id" required>
                                <option value="" disabled>Выбери папку</option>
                                <option
                                    v-for="folder in folders"
                                    :key="folder.id"
                                    :value="folder.id"
                                >
                                    {{ folder.title }} — {{ folder.explicit_chat_count }} чатов
                                </option>
                            </select>
                            <small v-if="folderForm.errors.folder_id">{{ folderForm.errors.folder_id }}</small>
                        </label>

                        <button type="submit" :disabled="folderForm.processing">
                            {{ folderForm.processing ? 'СОХРАНЯЮ…' : 'ВЫБРАТЬ И ЗАПУСТИТЬ BACKFILL →' }}
                        </button>

                        <div v-if="reader.selected_folder" class="reader-selected-folder">
                            <strong>{{ reader.selected_folder.title }}</strong>
                            <span>
                                {{ reader.sync_running ? 'Синхронизация идёт…' : 'Активная папка' }}
                            </span>
                        </div>
                    </form>
                </article>

                <article v-if="reader.selected_folder" class="reader-panel reader-panel--wide">
                    <div class="reader-panel__head">
                        <span>04</span>
                        <div>
                            <p>INDEX / SYNC</p>
                            <h2>Корпус вакансий</h2>
                        </div>
                    </div>

                    <div class="reader-panel__body reader-sync">
                        <div>
                            <strong>{{ reader.chat_count }}</strong>
                            <span>активных чатов</span>
                        </div>
                        <div>
                            <strong>{{ reader.indexed_message_count }}</strong>
                            <span>vacancy-like сообщений</span>
                        </div>
                        <div>
                            <strong>90</strong>
                            <span>дней initial backfill</span>
                        </div>

                        <button type="button" :disabled="actionBusy || reader.sync_running" @click="syncNow">
                            {{ reader.sync_running ? 'SYNC ИДЁТ…' : 'СИНХРОНИЗИРОВАТЬ СЕЙЧАС' }}
                        </button>
                    </div>
                </article>
            </section>
        </main>
    </AppLayout>
</template>

<style scoped>
.reader-page {
    width: min(100%, 1280px);
    margin: 8px auto 0;
    color: var(--ds-color-black);
}

.reader-header {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(280px, .46fr);
    gap: 38px;
    align-items: end;
    margin-bottom: 24px;
}

.reader-eyebrow {
    margin: 0 0 10px;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: .14em;
}

.reader-header h1 {
    margin: 0;
    font-family: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
    font-size: clamp(58px, 8vw, 112px);
    line-height: .78;
    letter-spacing: -.02em;
}

.reader-header__note {
    padding: 15px;
    border: 4px solid var(--ds-color-black);
    background: var(--ds-color-red);
    color: #fff;
    box-shadow: 7px 7px 0 var(--ds-color-black);
}

.reader-header__note strong {
    font-size: 11px;
    letter-spacing: .08em;
}

.reader-header__note p {
    margin: 8px 0 0;
    font-size: 11px;
    font-weight: 750;
    line-height: 1.5;
}

.reader-status {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    border: 4px solid var(--ds-color-black);
    margin-bottom: 20px;
    background: #f0c9bd;
}

.reader-status.is-ready {
    background: #dce2bd;
}

.reader-status > div {
    min-width: 0;
    padding: 11px 12px;
    border-right: 2px solid var(--ds-color-black);
}

.reader-status > div:last-child {
    border-right: 0;
}

.reader-status span,
.reader-status strong {
    display: block;
}

.reader-status span {
    font-size: 8px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.reader-status strong {
    margin-top: 3px;
    font-size: 11px;
    overflow-wrap: anywhere;
}

.reader-alert,
.reader-flash {
    margin: 0 0 18px;
    padding: 12px 14px;
    border: 3px solid var(--ds-color-black);
    font-size: 11px;
    font-weight: 850;
}

.reader-alert strong,
.reader-alert span {
    display: block;
}

.reader-alert--error,
.reader-flash--error {
    background: #f0c9bd;
}

.reader-flash--ok {
    background: #dce2bd;
}

.reader-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 24px;
    align-items: start;
}

.reader-panel {
    min-width: 0;
    border: 5px solid var(--ds-color-black);
    background: var(--ds-color-surface);
    box-shadow: 8px 8px 0 var(--ds-color-black);
}

.reader-panel--folder {
    box-shadow: 8px 8px 0 var(--ds-color-red);
}

.reader-panel--wide {
    grid-column: 1 / -1;
}

.reader-panel__head {
    display: grid;
    grid-template-columns: 52px minmax(0, 1fr);
    gap: 12px;
    align-items: center;
    padding: 15px;
    border-bottom: 4px solid var(--ds-color-black);
}

.reader-panel__head > span {
    display: grid;
    width: 44px;
    height: 44px;
    place-items: center;
    background: var(--ds-color-red);
    color: #fff;
    font-family: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
    font-size: 22px;
}

.reader-panel__head p,
.reader-panel__head h2 {
    margin: 0;
}

.reader-panel__head p {
    font-size: 8px;
    font-weight: 950;
    letter-spacing: .1em;
}

.reader-panel__head h2 {
    margin-top: 3px;
    font-size: 18px;
    line-height: 1.05;
}

.reader-panel__body {
    padding: 18px;
}

.reader-form {
    display: grid;
    gap: 14px;
}

.reader-qr-login {
    display: grid;
    gap: 12px;
}

.reader-qr-copy strong {
    display: block;
    font-size: 15px;
}

.reader-qr-copy p {
    margin: 5px 0 0;
    font-size: 11px;
    font-weight: 750;
    line-height: 1.5;
}

.reader-qr-button {
    min-height: 46px;
    padding: 10px 14px;
    border: 3px solid var(--ds-color-black);
    border-radius: 0;
    background: var(--ds-color-red);
    color: #fff;
    font-size: 10px;
    font-weight: 950;
    letter-spacing: .05em;
    cursor: pointer;
}

.reader-qr-button:disabled {
    cursor: wait;
    opacity: .55;
}

.reader-qr-code {
    display: grid;
    grid-template-columns: minmax(180px, 260px) minmax(0, 1fr);
    gap: 16px;
    align-items: center;
    padding: 14px;
    border: 3px solid var(--ds-color-black);
    background: #fff;
}

.reader-qr-code img {
    display: block;
    width: 100%;
    aspect-ratio: 1;
}

.reader-qr-code small,
.reader-qr-expired {
    font-size: 10px;
    font-weight: 850;
    line-height: 1.45;
}

.reader-qr-expired {
    margin: 0;
    padding: 10px;
    border-left: 4px solid var(--ds-color-red);
    background: #f0c9bd;
}

.reader-auth-divider {
    position: relative;
    margin: 20px 0 16px;
    border-top: 3px solid var(--ds-color-black);
    text-align: center;
}

.reader-auth-divider span {
    position: relative;
    top: -9px;
    padding: 0 8px;
    background: var(--ds-color-surface);
    font-size: 8px;
    font-weight: 950;
    letter-spacing: .08em;
}

.reader-2fa-hint {
    margin: 0;
    padding: 10px;
    border-left: 4px solid var(--ds-color-red);
    background: #f0c9bd;
    font-size: 10px;
    font-weight: 800;
    line-height: 1.45;
}

.reader-form--sub {
    margin-top: 20px;
    padding-top: 18px;
    border-top: 3px solid var(--ds-color-black);
}

.reader-form label,
.reader-form label > span,
.reader-form small {
    display: block;
}

.reader-form label > span {
    margin-bottom: 6px;
    font-size: 9px;
    font-weight: 950;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.reader-form input,
.reader-form select {
    width: 100%;
    min-height: 46px;
    padding: 10px 12px;
    border: 3px solid var(--ds-color-black);
    border-radius: 0;
    background: #fff;
    color: var(--ds-color-black);
    font: inherit;
    font-size: 14px;
    font-weight: 750;
}

.reader-form small {
    margin-top: 5px;
    color: var(--ds-color-red);
    font-size: 9px;
    font-weight: 900;
}

.reader-form button,
.reader-sync button {
    min-height: 46px;
    padding: 10px 14px;
    border: 3px solid var(--ds-color-black);
    border-radius: 0;
    background: var(--ds-color-black);
    color: #fff;
    font-size: 10px;
    font-weight: 950;
    letter-spacing: .05em;
    cursor: pointer;
}

.reader-form button:disabled,
.reader-sync button:disabled {
    cursor: wait;
    opacity: .55;
}

.reader-copy {
    display: grid;
    gap: 14px;
}

.reader-copy p {
    margin: 0;
    padding-left: 12px;
    border-left: 4px solid var(--ds-color-red);
    font-size: 11px;
    font-weight: 720;
    line-height: 1.5;
}

.reader-account {
    display: grid;
    gap: 5px;
}

.reader-account strong {
    font-size: 24px;
}

.reader-account span {
    font-size: 10px;
    font-weight: 800;
}

.reader-selected-folder {
    padding: 10px;
    border: 3px solid var(--ds-color-black);
    background: #dce2bd;
}

.reader-selected-folder strong,
.reader-selected-folder span {
    display: block;
}

.reader-selected-folder strong {
    font-size: 13px;
}

.reader-selected-folder span {
    margin-top: 3px;
    font-size: 9px;
    font-weight: 850;
}

.reader-sync {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr)) auto;
    gap: 14px;
    align-items: center;
}

.reader-sync > div {
    padding: 10px;
    border-left: 4px solid var(--ds-color-red);
}

.reader-sync strong,
.reader-sync span {
    display: block;
}

.reader-sync strong {
    font-family: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
    font-size: 28px;
}

.reader-sync span {
    font-size: 9px;
    font-weight: 850;
    text-transform: uppercase;
}

@media (max-width: 900px) {
    .reader-header,
    .reader-grid {
        grid-template-columns: 1fr;
    }

    .reader-status {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .reader-status > div {
        border-bottom: 2px solid var(--ds-color-black);
    }

    .reader-panel--wide {
        grid-column: auto;
    }

    .reader-sync {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .reader-sync button {
        grid-column: 1 / -1;
    }
}

@media (max-width: 520px) {
    .reader-page {
        margin-top: 2px;
    }

    .reader-header {
        gap: 18px;
    }

    .reader-header h1 {
        font-size: clamp(48px, 19vw, 76px);
    }

    .reader-status,
    .reader-sync {
        grid-template-columns: 1fr;
    }

    .reader-status > div {
        border-right: 0;
    }

    .reader-panel {
        box-shadow: 5px 5px 0 var(--ds-color-black);
    }

    .reader-panel__head {
        grid-template-columns: 44px minmax(0, 1fr);
        padding: 12px;
    }

    .reader-panel__head > span {
        width: 38px;
        height: 38px;
        font-size: 18px;
    }

    .reader-panel__body {
        padding: 14px;
    }

    .reader-qr-code {
        grid-template-columns: 1fr;
    }
}
</style>
