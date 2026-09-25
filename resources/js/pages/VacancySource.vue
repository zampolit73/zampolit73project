<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

const props = defineProps({
    history: { type: Array, required: true },
    activeInvestigation: { type: Object, default: null },
    isAdmin: { type: Boolean, default: false },
});

const form = useForm({
    input_text: '',
});

const active = ref(props.activeInvestigation ? { ...props.activeInvestigation } : null);
let pollTimer = null;
let pollBusy = false;

const terminalStatuses = new Set(['completed', 'partial', 'failed', 'cancelled']);
const stageOrder = ['queued', 'starting', 'telegram_search', 'web_search', 'candidate_analysis', 'completed'];

const currentStageIndex = computed(() => {
    if (!active.value) return -1;

    const index = stageOrder.indexOf(active.value.progress_stage);

    return index === -1 ? 0 : index;
});

const activeIsTerminal = computed(() => active.value && terminalStatuses.has(active.value.status));

function startInvestigation() {
    form.post('/projects/vacancy-source/investigations', {
        preserveScroll: true,
        onSuccess: () => form.reset('input_text'),
    });
}

function cancelActive() {
    if (!active.value?.can_cancel) return;

    router.post(
        '/projects/vacancy-source/investigations/' + active.value.id + '/cancel',
        {},
        {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['activeInvestigation', 'history'] }),
        },
    );
}

function statusLabel(status) {
    return {
        queued: 'В очереди',
        running: 'В работе',
        completed: 'Готово',
        partial: 'Частично',
        failed: 'Ошибка',
        cancelled: 'Отменено',
    }[status] ?? status;
}

function reviewLabel(status) {
    return {
        correct: 'Верно',
        incorrect: 'Неверно',
        partial: 'Частично',
    }[status] ?? 'Не проверено';
}

function formatDate(value) {
    if (!value) return '—';

    return new Intl.DateTimeFormat('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

function restartPolling() {
    if (pollTimer) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }

    if (!active.value || terminalStatuses.has(active.value.status)) return;

    pollTimer = window.setInterval(pollStatus, 1500);
    pollStatus();
}

async function pollStatus() {
    if (!active.value || pollBusy || terminalStatuses.has(active.value.status)) return;

    pollBusy = true;

    try {
        const response = await fetch(
            '/projects/vacancy-source/investigations/' + active.value.id + '/status',
            {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            },
        );

        if (!response.ok) {
            if (pollTimer) {
                window.clearInterval(pollTimer);
                pollTimer = null;
            }

            return;
        }

        active.value = await response.json();

        if (terminalStatuses.has(active.value.status)) {
            if (pollTimer) {
                window.clearInterval(pollTimer);
                pollTimer = null;
            }

            router.reload({ only: ['activeInvestigation', 'history'], preserveScroll: true });
        }
    } finally {
        pollBusy = false;
    }
}

watch(
    () => props.activeInvestigation,
    (value) => {
        active.value = value ? { ...value } : null;
        restartPolling();
    },
    { deep: true },
);

onMounted(restartPolling);

onBeforeUnmount(() => {
    if (pollTimer) window.clearInterval(pollTimer);
});
</script>

<template>
    <AppLayout>
        <Head title="Vacancy Source" />

        <main class="vacancy-source-page">
            <header class="vacancy-source-hero">
                <div>
                    <p class="vacancy-source-eyebrow">PROJECT 06 / SALES RESEARCH</p>
                    <h1>VACANCY<br>SOURCE</h1>
                </div>

                <div class="vacancy-source-hero__note">
                    <strong>ТЕХНИЧЕСКИЙ MVP</strong>
                    <p>
                        Сейчас здесь работает сквозной асинхронный каркас: очередь, статусы и история.
                        Telegram- и веб-поиск пока не подключены — результат не выдаёт выдуманного клиента.
                    </p>
                </div>
            </header>

            <section class="vacancy-source-grid">
                <article class="vacancy-panel vacancy-panel--input">
                    <div class="vacancy-panel__head">
                        <span>01</span>
                        <div>
                            <p>NEW INVESTIGATION</p>
                            <h2>Вставить вакансию</h2>
                        </div>
                    </div>

                    <form class="vacancy-form" @submit.prevent="startInvestigation">
                        <label for="vacancy-input">Текст вакансии</label>
                        <textarea
                            id="vacancy-input"
                            v-model="form.input_text"
                            name="input_text"
                            rows="13"
                            maxlength="30000"
                            placeholder="Вставь сюда исходное сообщение с вакансией целиком…"
                            required
                        ></textarea>

                        <p v-if="form.errors.input_text" class="vacancy-error">
                            {{ form.errors.input_text }}
                        </p>

                        <button
                            class="vacancy-primary"
                            type="submit"
                            :disabled="form.processing || form.input_text.trim().length < 20"
                        >
                            {{ form.processing ? 'СТАВЛЮ В ОЧЕРЕДЬ…' : 'НАЙТИ КОНЕЧНОГО КЛИЕНТА →' }}
                        </button>
                    </form>
                </article>

                <article class="vacancy-panel vacancy-panel--status">
                    <div class="vacancy-panel__head">
                        <span>02</span>
                        <div>
                            <p>PIPELINE</p>
                            <h2>Текущая проверка</h2>
                        </div>
                    </div>

                    <div v-if="active" class="vacancy-status">
                        <div class="vacancy-status__top">
                            <strong>Проверка #{{ active.id }}</strong>
                            <span :data-status="active.status">{{ statusLabel(active.status) }}</span>
                        </div>

                        <div class="vacancy-steps" aria-label="Прогресс расследования">
                            <div
                                v-for="(stage, index) in stageOrder"
                                :key="stage"
                                class="vacancy-step"
                                :class="{
                                    'is-done': index < currentStageIndex || activeIsTerminal,
                                    'is-current': index === currentStageIndex && !activeIsTerminal,
                                }"
                            >
                                <span>{{ String(index + 1).padStart(2, '0') }}</span>
                            </div>
                        </div>

                        <p class="vacancy-status__message">
                            {{ active.progress_text || 'Ожидаю обновления…' }}
                        </p>

                        <div v-if="active.result_summary" class="vacancy-result">
                            <strong>Результат каркаса</strong>
                            <p>{{ active.result_summary }}</p>
                        </div>

                        <button
                            v-if="active.can_cancel"
                            class="vacancy-secondary"
                            type="button"
                            @click="cancelActive"
                        >
                            ОТМЕНИТЬ, ПОКА НЕ НАЧАЛОСЬ
                        </button>
                    </div>

                    <div v-else class="vacancy-empty">
                        <strong>Пока тихо.</strong>
                        <p>Отправь вакансию слева — новая проверка появится здесь.</p>
                    </div>
                </article>
            </section>

            <section class="vacancy-history">
                <div class="vacancy-history__head">
                    <div>
                        <p>03 / HISTORY</p>
                        <h2>{{ isAdmin ? 'История команды' : 'Мои проверки' }}</h2>
                    </div>
                    <span>{{ history.length }} последних</span>
                </div>

                <div v-if="history.length" class="vacancy-history__list">
                    <a
                        v-for="item in history"
                        :key="item.id"
                        class="vacancy-history__row"
                        :href="'/projects/vacancy-source?active=' + item.id"
                    >
                        <span class="vacancy-history__id">#{{ item.id }}</span>

                        <div class="vacancy-history__main">
                            <strong>
                                {{ item.best_candidate?.company_name || 'Клиент пока не определён' }}
                            </strong>
                            <small>
                                {{ item.result_summary || item.progress_text || 'Без результата' }}
                            </small>
                        </div>

                        <div v-if="isAdmin" class="vacancy-history__user">
                            {{ item.user?.username || '—' }}
                        </div>

                        <div class="vacancy-history__status">
                            <span>{{ statusLabel(item.status) }}</span>
                            <small>{{ reviewLabel(item.review_status) }}</small>
                        </div>

                        <time>{{ formatDate(item.created_at) }}</time>
                    </a>
                </div>

                <div v-else class="vacancy-history__empty">
                    История появится после первой проверки.
                </div>
            </section>
        </main>
    </AppLayout>
</template>

<style scoped>
.vacancy-source-page {
    width: min(100%, 1320px);
    margin: 8px auto 0;
    color: var(--ds-color-black);
}

.vacancy-source-hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(280px, .42fr);
    gap: 36px;
    align-items: end;
    margin-bottom: 28px;
}

.vacancy-source-eyebrow {
    margin: 0 0 10px;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: .16em;
}

.vacancy-source-hero h1 {
    margin: 0;
    font-family: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
    font-size: clamp(68px, 9vw, 126px);
    line-height: .76;
    letter-spacing: -.025em;
}

.vacancy-source-hero__note {
    padding: 16px;
    border: 4px solid var(--ds-color-black);
    background: #eadfca;
    box-shadow: 7px 7px 0 var(--ds-color-red);
}

.vacancy-source-hero__note strong {
    display: block;
    margin-bottom: 8px;
    font-size: 10px;
    letter-spacing: .11em;
}

.vacancy-source-hero__note p {
    margin: 0;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.55;
}

.vacancy-source-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(360px, .95fr);
    gap: 24px;
    align-items: start;
}

.vacancy-panel {
    border: 5px solid var(--ds-color-black);
    background: var(--ds-color-surface);
    box-shadow: 9px 9px 0 var(--ds-color-black);
}

.vacancy-panel--input {
    box-shadow: 9px 9px 0 var(--ds-color-red);
}

.vacancy-panel__head {
    display: grid;
    grid-template-columns: 58px minmax(0, 1fr);
    gap: 14px;
    align-items: center;
    padding: 17px;
    border-bottom: 4px solid var(--ds-color-black);
}

.vacancy-panel__head > span {
    display: grid;
    width: 50px;
    height: 50px;
    place-items: center;
    background: var(--ds-color-red);
    color: #fff;
    font-family: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
    font-size: 24px;
}

.vacancy-panel__head p,
.vacancy-panel__head h2 {
    margin: 0;
}

.vacancy-panel__head p {
    font-size: 8px;
    font-weight: 900;
    letter-spacing: .12em;
}

.vacancy-panel__head h2 {
    margin-top: 3px;
    font-size: 21px;
    line-height: 1;
}

.vacancy-form,
.vacancy-status,
.vacancy-empty {
    padding: 20px;
}

.vacancy-form {
    display: grid;
    gap: 11px;
}

.vacancy-form label {
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .1em;
    text-transform: uppercase;
}

.vacancy-form textarea {
    width: 100%;
    min-height: 300px;
    resize: vertical;
    padding: 14px;
    border: 3px solid var(--ds-color-black);
    border-radius: 0;
    background: #fffaf0;
    color: var(--ds-color-black);
    font: inherit;
    font-size: 13px;
    font-weight: 650;
    line-height: 1.5;
    outline: 0;
}

.vacancy-form textarea:focus {
    box-shadow: 5px 5px 0 var(--ds-color-red);
}

.vacancy-primary,
.vacancy-secondary {
    min-height: 50px;
    border: 3px solid var(--ds-color-black);
    border-radius: 0;
    cursor: pointer;
    font: inherit;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: .07em;
}

.vacancy-primary {
    background: var(--ds-color-black);
    color: #fff;
}

.vacancy-secondary {
    width: 100%;
    margin-top: 15px;
    background: #eadfca;
    color: var(--ds-color-black);
}

.vacancy-primary:disabled {
    cursor: not-allowed;
    opacity: .45;
}

.vacancy-error {
    margin: 0;
    color: var(--ds-color-red);
    font-size: 11px;
    font-weight: 850;
}

.vacancy-status__top {
    display: flex;
    gap: 14px;
    align-items: center;
    justify-content: space-between;
}

.vacancy-status__top strong {
    font-size: 20px;
}

.vacancy-status__top > span {
    padding: 7px 9px;
    border: 2px solid var(--ds-color-black);
    background: #eadfca;
    font-size: 9px;
    font-weight: 900;
    text-transform: uppercase;
}

.vacancy-status__top > span[data-status="completed"] {
    background: #dce2bd;
}

.vacancy-status__top > span[data-status="failed"] {
    background: #f0c9bd;
}

.vacancy-steps {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 6px;
    margin: 24px 0 18px;
}

.vacancy-step {
    display: grid;
    min-height: 38px;
    place-items: center;
    border: 2px solid var(--ds-color-black);
    background: #fffaf0;
}

.vacancy-step span {
    font-size: 9px;
    font-weight: 900;
}

.vacancy-step.is-done {
    background: var(--ds-color-black);
    color: #fff;
}

.vacancy-step.is-current {
    background: var(--ds-color-red);
    color: #fff;
}

.vacancy-status__message,
.vacancy-empty p {
    margin: 0;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.55;
}

.vacancy-result {
    margin-top: 18px;
    padding: 14px;
    border: 3px solid var(--ds-color-black);
    background: #dce2bd;
}

.vacancy-result strong {
    font-size: 10px;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.vacancy-result p {
    margin: 7px 0 0;
    font-size: 12px;
    font-weight: 750;
    line-height: 1.5;
}

.vacancy-empty strong {
    display: block;
    margin-bottom: 8px;
    font-size: 23px;
}

.vacancy-history {
    margin-top: 34px;
    border-top: 6px solid var(--ds-color-black);
}

.vacancy-history__head {
    display: flex;
    gap: 20px;
    align-items: end;
    justify-content: space-between;
    padding: 20px 0 14px;
}

.vacancy-history__head p,
.vacancy-history__head h2 {
    margin: 0;
}

.vacancy-history__head p {
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .12em;
}

.vacancy-history__head h2 {
    margin-top: 4px;
    font-size: 30px;
    line-height: 1;
}

.vacancy-history__head > span {
    font-size: 9px;
    font-weight: 900;
    text-transform: uppercase;
}

.vacancy-history__list {
    border: 4px solid var(--ds-color-black);
    background: var(--ds-color-surface);
}

.vacancy-history__row {
    display: grid;
    grid-template-columns: 58px minmax(200px, 1fr) minmax(90px, .35fr) 130px;
    gap: 14px;
    align-items: center;
    min-height: 78px;
    padding: 12px 15px;
    border-bottom: 2px solid var(--ds-color-black);
    color: inherit;
    text-decoration: none;
}

.vacancy-history__row:last-child {
    border-bottom: 0;
}

.vacancy-history__row:hover {
    background: #eadfca;
}

.vacancy-history__id {
    font-family: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
    font-size: 22px;
}

.vacancy-history__main {
    min-width: 0;
}

.vacancy-history__main strong,
.vacancy-history__main small {
    display: block;
}

.vacancy-history__main strong {
    font-size: 13px;
}

.vacancy-history__main small {
    margin-top: 4px;
    overflow: hidden;
    color: inherit;
    font-size: 10px;
    font-weight: 650;
    line-height: 1.35;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.vacancy-history__user,
.vacancy-history__status,
.vacancy-history__row time {
    font-size: 10px;
    font-weight: 800;
}

.vacancy-history__status span,
.vacancy-history__status small {
    display: block;
}

.vacancy-history__status small {
    margin-top: 3px;
    opacity: .7;
}

.vacancy-history__empty {
    padding: 22px;
    border: 4px solid var(--ds-color-black);
    background: var(--ds-color-surface);
    font-size: 12px;
    font-weight: 800;
}

@media (max-width: 900px) {
    .vacancy-source-hero,
    .vacancy-source-grid {
        grid-template-columns: 1fr;
    }

    .vacancy-source-hero {
        gap: 20px;
    }

    .vacancy-history__row {
        grid-template-columns: 52px minmax(0, 1fr) auto;
    }

    .vacancy-history__user,
    .vacancy-history__status {
        grid-column: 2;
    }

    .vacancy-history__row time {
        grid-column: 3;
        grid-row: 1;
        text-align: right;
    }
}

@media (max-width: 560px) {
    .vacancy-source-hero h1 {
        font-size: clamp(58px, 20vw, 84px);
    }

    .vacancy-panel {
        border-width: 4px;
        box-shadow: 6px 6px 0 var(--ds-color-black);
    }

    .vacancy-panel--input {
        box-shadow: 6px 6px 0 var(--ds-color-red);
    }

    .vacancy-panel__head {
        grid-template-columns: 48px minmax(0, 1fr);
        padding: 13px;
    }

    .vacancy-panel__head > span {
        width: 42px;
        height: 42px;
        font-size: 21px;
    }

    .vacancy-form,
    .vacancy-status,
    .vacancy-empty {
        padding: 14px;
    }

    .vacancy-form textarea {
        min-height: 260px;
    }

    .vacancy-history__head {
        align-items: flex-start;
        flex-direction: column;
    }

    .vacancy-history__row {
        grid-template-columns: 48px minmax(0, 1fr);
        gap: 8px 10px;
    }

    .vacancy-history__main,
    .vacancy-history__user,
    .vacancy-history__status,
    .vacancy-history__row time {
        grid-column: 2;
        grid-row: auto;
        text-align: left;
    }

    .vacancy-history__main small {
        white-space: normal;
    }
}
</style>
