<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

const props = defineProps({
    tab: { type: String, default: 'overview' },
    categories: { type: Array, required: true },
    activeCategory: { type: Object, default: null },
    stats: { type: Object, required: true },
    listStats: { type: Object, default: null },
    filters: { type: Object, required: true },
    companies: { type: Array, default: () => [] },
    candidateStatuses: { type: Array, default: () => [] },
    managers: { type: Object, default: null },
    candidates: { type: Object, default: null },
    assignees: { type: Array, required: true },
    currentUserId: { type: Number, required: true },
    recentActivity: { type: Array, default: () => [] },
    source: { type: Object, required: true },
});

const filterForm = ref({
    search: props.filters.search ?? '',
    company: props.filters.company ?? '',
    linkedin: props.filters.linkedin ?? '',
    assignee: props.filters.assignee ?? '',
    status: props.filters.status ?? '',
});

const editingKey = ref('');
const linkedinDraft = ref('');

watch(() => props.filters, (value) => {
    filterForm.value = {
        search: value.search ?? '',
        company: value.company ?? '',
        linkedin: value.linkedin ?? '',
        assignee: value.assignee ?? '',
        status: value.status ?? '',
    };
}, { deep: true });

const tabs = computed(() => [
    { id: 'overview', label: 'Обзор' },
    ...props.categories.map((category) => ({
        id: category.slug,
        label: category.label,
    })),
    { id: 'candidates', label: 'Кандидаты на проверку' },
]);

const rows = computed(() => props.managers?.data ?? []);
const candidateRows = computed(() => props.candidates?.data ?? []);
const pagination = computed(() => props.tab === 'candidates' ? props.candidates : props.managers);

function goTab(tab) {
    router.get('/projects/kommersant-ranking', { tab }, {
        preserveScroll: false,
        preserveState: false,
    });
}

function applyFilters() {
    router.get('/projects/kommersant-ranking', {
        tab: props.tab,
        ...filterForm.value,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function resetFilters() {
    filterForm.value = {
        search: '',
        company: '',
        linkedin: '',
        assignee: '',
        status: '',
    };
    applyFilters();
}

function isMine(record) {
    return record.assigned_to_user_id === props.currentUserId;
}

function assignmentAction(type, record, action) {
    router.patch(
        `/projects/kommersant-ranking/${type === 'candidate' ? 'candidates' : 'managers'}/${record.id}`,
        { assignment_action: action },
        { preserveScroll: true },
    );
}

function startLinkedinEdit(type, record) {
    editingKey.value = `${type}:${record.id}`;
    linkedinDraft.value = record.linkedin_url ?? '';
}

function cancelLinkedinEdit() {
    editingKey.value = '';
    linkedinDraft.value = '';
}

function saveLinkedin(type, record) {
    router.patch(
        `/projects/kommersant-ranking/${type === 'candidate' ? 'candidates' : 'managers'}/${record.id}`,
        { linkedin_url: linkedinDraft.value.trim() || null },
        {
            preserveScroll: true,
            onSuccess: cancelLinkedinEdit,
        },
    );
}

function assignmentInitial(record) {
    return record.assignee?.username?.slice(0, 1)?.toUpperCase() ?? '—';
}

function linkedinLabel(url) {
    if (!url) return 'LinkedIn не найден';
    try {
        const parsed = new URL(url);
        return parsed.pathname.replace(/^\//, '').replace(/\/$/, '') || parsed.hostname;
    } catch {
        return url;
    }
}

function statusClass(status) {
    const normalized = String(status ?? '').toLowerCase();
    if (normalized.includes('подтверж')) return 'is-success';
    if (normalized.includes('отклон')) return 'is-danger';
    return 'is-warning';
}

function activityLabel(activity) {
    return {
        assignment_taken: 'взял(а) в работу',
        assignment_released: 'снял(а) с себя',
        linkedin_updated: 'обновил(а) LinkedIn',
        linkedin_removed: 'очистил(а) LinkedIn',
    }[activity.action] ?? activity.action;
}

function formatActivityDate(value) {
    if (!value) return '';
    return new Intl.DateTimeFormat('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}
</script>

<template>
    <AppLayout>
        <Head title="Рейтинг Коммерсанта" />

        <main class="kr-page">
            <a href="/projects" class="kr-back">← ПРОЕКТЫ</a>

            <header class="kr-hero">
                <div class="kr-hero__copy">
                    <p class="kr-eyebrow">PROJECTS / MANAGER RANKINGS</p>
                    <h1>РЕЙТИНГ <span>КОММЕРСАНТА</span></h1>
                    <p>
                        Общая рабочая база по рейтингу топ-менеджеров «Коммерсанта»
                        с редактируемыми ссылками на LinkedIn и отслеживанием ответственного.
                    </p>
                </div>

                <div class="kr-hero__poster" aria-hidden="true">
                    <span class="kr-hero__mark">Ъ</span>
                    <strong>ЛЮДИ<br>КОМПАНИИ<br>ВОЗМОЖНОСТИ</strong>
                </div>
            </header>

            <div class="kr-tabs-wrap">
                <nav class="kr-tabs" aria-label="Разделы рейтинга">
                    <button
                        v-for="item in tabs"
                        :key="item.id"
                        type="button"
                        :class="{ 'is-active': tab === item.id }"
                        @click="goTab(item.id)"
                    >
                        {{ item.label }}
                    </button>
                </nav>
            </div>

            <div v-if="$page.props.errors?.assignment" class="kr-alert" role="alert">
                {{ $page.props.errors.assignment }}
            </div>
            <div v-if="$page.props.errors?.linkedin_url" class="kr-alert" role="alert">
                {{ $page.props.errors.linkedin_url }}
            </div>

            <template v-if="tab === 'overview'">
                <section class="kr-stats" aria-label="Статистика проекта">
                    <article>
                        <span>Всего записей</span>
                        <strong>{{ stats.total }}</strong>
                    </article>
                    <article>
                        <span>С LinkedIn</span>
                        <strong>{{ stats.withLinkedin }}</strong>
                    </article>
                    <article>
                        <span>Свободные</span>
                        <strong>{{ stats.free }}</strong>
                    </article>
                    <article>
                        <span>В работе</span>
                        <strong class="is-red">{{ stats.inWork }}</strong>
                    </article>
                    <article>
                        <span>Мои</span>
                        <strong>{{ stats.mine }}</strong>
                    </article>
                    <article>
                        <span>Кандидаты</span>
                        <strong>{{ stats.candidates }}</strong>
                    </article>
                </section>

                <section class="kr-overview-grid">
                    <article class="kr-panel">
                        <div class="kr-panel__title"><b>01</b><h2>КАК ЭТО РАБОТАЕТ</h2></div>
                        <ol class="kr-flow">
                            <li><b>1</b><div><strong>Рейтинг уже импортирован</strong><span>Все направления исходной таблицы собраны в общей SQLite-базе.</span></div></li>
                            <li><b>2</b><div><strong>Берём менеджеров в работу</strong><span>Колонка «В работе у» показывает единственного ответственного.</span></div></li>
                            <li><b>3</b><div><strong>Уточняем LinkedIn</strong><span>Ссылку можно добавить, исправить или очистить прямо в строке.</span></div></li>
                            <li><b>4</b><div><strong>Разбираем кандидатов</strong><span>Отдельная вкладка сохраняет неподтверждённые совпадения из исходного файла.</span></div></li>
                        </ol>
                    </article>

                    <article class="kr-panel kr-panel--note">
                        <div class="kr-panel__title"><b>02</b><h2>ОБЩАЯ РАБОЧАЯ БАЗА</h2></div>
                        <p>
                            Ответственный и LinkedIn сохраняются на сервере и сразу видны всей команде.
                            Чужую запись нельзя перехватить: свободную строку пользователь забирает на себя атомарно.
                        </p>
                        <small>{{ source.publication }} · {{ source.file }}</small>
                    </article>
                </section>

                <section class="kr-overview-grid kr-overview-grid--bottom">
                    <article class="kr-panel">
                        <div class="kr-panel__title"><b>03</b><h2>НАПРАВЛЕНИЯ</h2></div>
                        <div class="kr-directions">
                            <button
                                v-for="category in categories"
                                :key="category.slug"
                                type="button"
                                @click="goTab(category.slug)"
                            >
                                <span>{{ category.label }}</span>
                                <strong>{{ category.managers_count }}</strong>
                                <b>→</b>
                            </button>
                        </div>
                    </article>

                    <article class="kr-panel">
                        <div class="kr-panel__title"><b>04</b><h2>ПОСЛЕДНЯЯ АКТИВНОСТЬ</h2></div>
                        <div v-if="recentActivity.length" class="kr-activity">
                            <div v-for="activity in recentActivity" :key="activity.id">
                                <span class="kr-avatar">{{ activity.user?.username?.slice(0, 2)?.toUpperCase() ?? '—' }}</span>
                                <p>
                                    <strong>{{ activity.user?.username ?? 'Удалённый пользователь' }}</strong>
                                    {{ activityLabel(activity) }}:
                                    <b>{{ activity.subject_name }}</b>
                                </p>
                                <time>{{ formatActivityDate(activity.created_at) }}</time>
                            </div>
                        </div>
                        <p v-else class="kr-empty">Рабочая история пока пустая — первая активность появится после назначения или изменения LinkedIn.</p>
                    </article>
                </section>
            </template>

            <template v-else>
                <section v-if="tab === 'candidates'" class="kr-intro">
                    <b>i</b>
                    <div>
                        <h2>КАНДИДАТЫ НА ПРОВЕРКУ</h2>
                        <p>
                            Неподтверждённые совпадения LinkedIn из исходной таблицы. Здесь удобно распределить ручную проверку между командой.
                        </p>
                    </div>
                </section>

                <section v-else class="kr-intro">
                    <b>{{ activeCategory?.position ?? '•' }}</b>
                    <div>
                        <p class="kr-eyebrow">НАПРАВЛЕНИЕ</p>
                        <h2>{{ activeCategory?.title }}</h2>
                        <p>{{ activeCategory?.source_note }}</p>
                    </div>
                </section>

                <form class="kr-filters" @submit.prevent="applyFilters">
                    <label class="kr-search">
                        <span class="sr-only">Поиск</span>
                        <input v-model="filterForm.search" type="search" placeholder="Поиск по Ф.И.О., компании или должности…">
                    </label>

                    <label>
                        <span>Компания</span>
                        <select v-model="filterForm.company">
                            <option value="">Все</option>
                            <option v-for="item in companies" :key="item" :value="item">{{ item }}</option>
                        </select>
                    </label>

                    <label v-if="tab !== 'candidates'">
                        <span>LinkedIn</span>
                        <select v-model="filterForm.linkedin">
                            <option value="">Все</option>
                            <option value="found">Найден</option>
                            <option value="missing">Не найден</option>
                        </select>
                    </label>

                    <label v-else>
                        <span>Статус</span>
                        <select v-model="filterForm.status">
                            <option value="">Все</option>
                            <option v-for="item in candidateStatuses" :key="item" :value="item">{{ item }}</option>
                        </select>
                    </label>

                    <label>
                        <span>В работе у</span>
                        <select v-model="filterForm.assignee">
                            <option value="">Все</option>
                            <option value="unassigned">Свободные</option>
                            <option value="mine">Мои</option>
                            <option value="assigned">Все в работе</option>
                            <option v-for="user in assignees" :key="user.id" :value="`user:${user.id}`">{{ user.username }}</option>
                        </select>
                    </label>

                    <button type="submit" class="kr-button kr-button--primary">Применить</button>
                    <button type="button" class="kr-button" @click="resetFilters">Сбросить</button>
                </form>

                <div class="kr-list-summary">
                    <div><span>Всего</span><strong>{{ listStats?.total ?? pagination?.total ?? 0 }}</strong></div>
                    <div><span>Свободные</span><strong>{{ listStats?.free ?? 0 }}</strong></div>
                    <div><span>В работе</span><strong class="is-red">{{ listStats?.inWork ?? 0 }}</strong></div>
                    <div><span>Мои</span><strong>{{ listStats?.mine ?? 0 }}</strong></div>
                </div>

                <div v-if="tab !== 'candidates'" class="kr-table-scroll">
                    <table class="kr-table">
                        <thead>
                            <tr>
                                <th>№</th>
                                <th>Отрасль</th>
                                <th>Место</th>
                                <th>Ф.И.О.</th>
                                <th>LinkedIn</th>
                                <th>В работе у</th>
                                <th>Должность</th>
                                <th>Компания</th>
                                <th>Стр. PDF</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="record in rows" :key="record.id" :class="{ 'is-mine': isMine(record) }">
                                <td class="kr-number">{{ record.row_number }}</td>
                                <td>{{ record.industry || '—' }}</td>
                                <td class="kr-place">{{ record.place ?? '—' }}</td>
                                <td class="kr-name">{{ record.full_name }}</td>
                                <td class="kr-linkedin">
                                    <template v-if="editingKey === `manager:${record.id}`">
                                        <input v-model="linkedinDraft" type="url" placeholder="https://www.linkedin.com/in/...">
                                        <div class="kr-inline-actions">
                                            <button type="button" class="kr-mini kr-mini--primary" @click="saveLinkedin('manager', record)">Сохранить</button>
                                            <button type="button" class="kr-mini" @click="cancelLinkedinEdit">Отмена</button>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <a v-if="record.linkedin_url" :href="record.linkedin_url" target="_blank" rel="noopener noreferrer">
                                            <b>in</b><span>{{ linkedinLabel(record.linkedin_url) }}</span><i>↗</i>
                                        </a>
                                        <span v-else class="kr-linkedin__missing">LinkedIn не найден</span>
                                        <button type="button" class="kr-edit-link" @click="startLinkedinEdit('manager', record)">Редактировать</button>
                                    </template>
                                </td>
                                <td class="kr-assignment">
                                    <template v-if="record.assignee">
                                        <span class="kr-avatar" :class="{ 'is-mine': isMine(record) }">{{ assignmentInitial(record) }}</span>
                                        <div>
                                            <strong>{{ isMine(record) ? 'Моя' : record.assignee.username }}</strong>
                                            <button v-if="isMine(record)" type="button" @click="assignmentAction('manager', record, 'release')">Снять с себя</button>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <span class="kr-free">Свободно</span>
                                        <button type="button" class="kr-take" @click="assignmentAction('manager', record, 'take')">Взять в работу</button>
                                    </template>
                                </td>
                                <td>{{ record.job_title || '—' }}</td>
                                <td class="kr-company">{{ record.company || '—' }}</td>
                                <td class="kr-pdf">{{ record.pdf_page ?? '—' }}</td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td colspan="9" class="kr-empty-cell">По этим фильтрам ничего не найдено.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="kr-table-scroll">
                    <table class="kr-table kr-table--candidates">
                        <thead>
                            <tr>
                                <th>Ф.И.О.</th>
                                <th>Компания</th>
                                <th>Должность</th>
                                <th>LinkedIn-кандидат</th>
                                <th>Статус / уверенность</th>
                                <th>Результат и основание</th>
                                <th>Источник / проверка</th>
                                <th>В работе у</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="record in candidateRows" :key="record.id" :class="{ 'is-mine': isMine(record) }">
                                <td class="kr-name">{{ record.full_name }}</td>
                                <td class="kr-company">{{ record.company || '—' }}</td>
                                <td>{{ record.job_title || '—' }}</td>
                                <td class="kr-linkedin">
                                    <template v-if="editingKey === `candidate:${record.id}`">
                                        <input v-model="linkedinDraft" type="url" placeholder="https://www.linkedin.com/in/...">
                                        <div class="kr-inline-actions">
                                            <button type="button" class="kr-mini kr-mini--primary" @click="saveLinkedin('candidate', record)">Сохранить</button>
                                            <button type="button" class="kr-mini" @click="cancelLinkedinEdit">Отмена</button>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <a v-if="record.linkedin_url" :href="record.linkedin_url" target="_blank" rel="noopener noreferrer">
                                            <b>in</b><span>{{ linkedinLabel(record.linkedin_url) }}</span><i>↗</i>
                                        </a>
                                        <span v-else class="kr-linkedin__missing">Нет ссылки</span>
                                        <button type="button" class="kr-edit-link" @click="startLinkedinEdit('candidate', record)">Редактировать</button>
                                    </template>
                                </td>
                                <td><span class="kr-status" :class="statusClass(record.confidence_status)">{{ record.confidence_status || '—' }}</span></td>
                                <td class="kr-reason">{{ record.result_reason || '—' }}</td>
                                <td class="kr-source">{{ record.source_check || '—' }}</td>
                                <td class="kr-assignment">
                                    <template v-if="record.assignee">
                                        <span class="kr-avatar" :class="{ 'is-mine': isMine(record) }">{{ assignmentInitial(record) }}</span>
                                        <div>
                                            <strong>{{ isMine(record) ? 'Моя' : record.assignee.username }}</strong>
                                            <button v-if="isMine(record)" type="button" @click="assignmentAction('candidate', record, 'release')">Снять с себя</button>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <span class="kr-free">Свободно</span>
                                        <button type="button" class="kr-take" @click="assignmentAction('candidate', record, 'take')">Взять в работу</button>
                                    </template>
                                </td>
                            </tr>
                            <tr v-if="!candidateRows.length">
                                <td colspan="8" class="kr-empty-cell">По этим фильтрам ничего не найдено.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <nav v-if="pagination?.links?.length > 3" class="kr-pagination" aria-label="Пагинация">
                    <Link
                        v-for="link in pagination.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        :class="{ 'is-active': link.active, 'is-disabled': !link.url }"
                        preserve-scroll
                        v-html="link.label"
                    />
                </nav>
            </template>
        </main>
    </AppLayout>
</template>
