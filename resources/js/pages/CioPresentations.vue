<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

const props = defineProps({
    tab: { type: String, default: 'overview' },
    stats: { type: Object, required: true },
    filters: { type: Object, required: true },
    presentations: { type: Object, required: true },
    latest: { type: Array, required: true },
    sources: { type: Array, required: true },
    assignees: { type: Array, required: true },
    currentUserId: { type: Number, required: true },
    canManage: { type: Boolean, default: false },
});

const activeTab = ref(props.tab);
const scanningId = ref(null);

const sourceForm = useForm({
    name: '',
    url: '',
});

const candidateForm = useForm({
    source_id: '',
    title: '',
    speaker_name: '',
    job_title: '',
    company: '',
    event_name: '',
    event_year: '',
    file_url: '',
    file_type: '',
    source_page_url: '',
});

const filterForm = ref({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    file_type: props.filters.file_type ?? '',
    source_id: props.filters.source_id ?? '',
    assignee: props.filters.assignee ?? '',
});

const tabs = computed(() => [
    { id: 'overview', label: 'Обзор' },
    { id: 'presentations', label: 'Презентации' },
    ...(props.canManage ? [
        { id: 'sources', label: 'Источники' },
        { id: 'search', label: 'Поиск / сканирование' },
    ] : []),
]);

const currentItems = computed(() => props.presentations?.data ?? []);

function switchTab(tab) {
    activeTab.value = tab;
}

function applyFilters() {
    router.get('/projects/cio-presentations', {
        ...filterForm.value,
        tab: 'presentations',
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onSuccess: () => { activeTab.value = 'presentations'; },
    });
}

function resetFilters() {
    filterForm.value = { search: '', status: '', file_type: '', source_id: '', assignee: '' };
    applyFilters();
}

function addSource() {
    sourceForm.post('/projects/cio-presentations/sources', {
        preserveScroll: true,
        onSuccess: () => sourceForm.reset(),
    });
}

function addCandidate() {
    candidateForm.post('/projects/cio-presentations/presentations', {
        preserveScroll: true,
        onSuccess: () => {
            candidateForm.reset();
            activeTab.value = 'presentations';
        },
    });
}

function scanSource(source) {
    scanningId.value = source.id;
    router.post(`/projects/cio-presentations/sources/${source.id}/scan`, {}, {
        preserveScroll: true,
        onFinish: () => { scanningId.value = null; },
    });
}

function clearPresentations() {
    if (!window.confirm('Удалить все презентации и историю сканирования? Источники останутся.')) {
        return;
    }

    router.delete('/projects/cio-presentations/presentations', {
        preserveScroll: false,
        onSuccess: () => {
            activeTab.value = 'presentations';
            filterForm.value = { search: '', status: '', file_type: '', source_id: '', assignee: '' };
        },
    });
}

function patchPresentation(presentation, payload) {
    router.patch(`/projects/cio-presentations/presentations/${presentation.id}`, payload, {
        preserveScroll: true,
    });
}

function toggleFlag(presentation, field) {
    patchPresentation(presentation, { [field]: !presentation[field] });
}

function assignmentAction(presentation, action) {
    patchPresentation(presentation, { assignment_action: action });
}

function isMine(presentation) {
    return presentation.assigned_to_user_id === props.currentUserId;
}

function assignmentLabel(presentation) {
    if (!presentation.assignee) {
        return 'Свободна';
    }

    return isMine(presentation) ? 'Моя' : 'В работе';
}

function formatAssignmentDate(value) {
    if (!value) return '';

    return new Intl.DateTimeFormat('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

function statusLabel(value) {
    return {
        new: 'Новая',
        verified: 'Проверено',
        rejected: 'Не подходит',
        archived: 'Архив',
    }[value] ?? value;
}

function formatDate(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium' }).format(new Date(value));
}
</script>

<template>
    <AppLayout>
        <Head title="Презентации ИТ-директоров" />

        <main class="cio-page">
            <Link href="/projects" class="cio-back">← ПРОЕКТЫ</Link>

            <header class="cio-header">
                <p class="cio-eyebrow">PROJECTS / CIO OPEN MATERIALS</p>
                <h1>ПРЕЗЕНТАЦИИ<br>ИТ-ДИРЕКТОРОВ</h1>
                <p>
                    Каталог публичных ссылок на презентации ИТ-руководителей. Файлы не загружаются на наш сервер:
                    кнопка открытия ведёт прямо на оригинальный источник.
                </p>
            </header>

            <nav class="cio-tabs" aria-label="Разделы проекта">
                <button
                    v-for="item in tabs"
                    :key="item.id"
                    type="button"
                    :class="{ 'is-active': activeTab === item.id }"
                    @click="switchTab(item.id)"
                >
                    {{ item.label }}
                </button>
            </nav>

            <section class="cio-stats" aria-label="Статистика">
                <article><strong>{{ stats.total }}</strong><span>Всего презентаций</span></article>
                <article><strong class="is-red">{{ stats.new }}</strong><span>Новых</span></article>
                <article><strong>{{ stats.verified }}</strong><span>Проверено</span></article>
                <article><strong>{{ stats.withEmail }}</strong><span>С email</span></article>
                <article><strong>{{ stats.withPhone }}</strong><span>С телефоном</span></article>
                <article><strong>{{ stats.sources }}</strong><span>Источников</span></article>
            </section>

            <div v-if="$page.props.errors?.scan" class="cio-alert" role="alert">
                {{ $page.props.errors.scan }}
            </div>
            <div v-if="$page.props.errors?.assignment" class="cio-alert" role="alert">
                {{ $page.props.errors.assignment }}
            </div>

            <section v-if="activeTab === 'overview'" class="cio-section">
                <div class="cio-section__head">
                    <div>
                        <p class="cio-eyebrow">DASHBOARD</p>
                        <h2>ОБЗОР</h2>
                    </div>
                    <button v-if="canManage" type="button" class="cio-button cio-button--primary" @click="switchTab('sources')">
                        Добавить источник
                    </button>
                </div>

                <div class="cio-overview-grid">
                    <article class="cio-panel">
                        <h3>КАК ЭТО РАБОТАЕТ</h3>
                        <ol class="cio-flow">
                            <li><b>01</b><span>Добавляем публичную страницу конференции, мероприятия или компании.</span></li>
                            <li><b>02</b><span>Лёгкий скан читает только HTML/XML и собирает прямые PDF/PPT/PPTX ссылки.</span></li>
                            <li><b>03</b><span>Презентация открывается напрямую в твоём браузере с сайта-источника.</span></li>
                            <li><b>04</b><span>После просмотра отмечаем email, телефон и качество лида одним кликом.</span></li>
                        </ol>
                    </article>

                    <article class="cio-panel cio-panel--note">
                        <h3>ФАЙЛЫ НЕ СКАЧИВАЮТСЯ</h3>
                        <p>
                            Сервер хранит URL и служебные метаданные. Содержимое презентации идёт напрямую
                            от сайта-источника в твой браузер.
                        </p>
                    </article>
                </div>

                <article class="cio-panel cio-latest">
                    <div class="cio-panel__title-row">
                        <h3>ПОСЛЕДНИЕ НАЙДЕННЫЕ</h3>
                        <button type="button" class="cio-text-button" @click="switchTab('presentations')">Смотреть все →</button>
                    </div>

                    <div v-if="latest.length" class="cio-list">
                        <div v-for="item in latest" :key="item.id" class="cio-row">
                            <span class="cio-file">{{ item.file_type }}</span>
                            <div class="cio-row__copy">
                                <strong>{{ item.title || 'Без названия' }}</strong>
                                <span>{{ item.speaker_name || 'Спикер не указан' }} · {{ item.company || 'Компания не указана' }}</span>
                                <small>{{ item.source?.domain || 'ручная ссылка' }} · {{ formatDate(item.discovered_at) }}</small>
                            </div>
                            <span class="cio-tag" :class="{ 'cio-tag--new': item.review_status === 'new' }">
                                {{ statusLabel(item.review_status) }}
                            </span>
                            <a :href="item.file_url" target="_blank" rel="noopener noreferrer" class="cio-button">
                                Открыть ↗
                            </a>
                        </div>
                    </div>
                    <div v-else class="cio-empty">
                        Пока пусто. Добавь первый источник и запусти лёгкий скан.
                    </div>
                </article>
            </section>

            <section v-else-if="activeTab === 'presentations'" class="cio-section">
                <div class="cio-workspace">
                    <aside class="cio-panel cio-filters">
                        <h3>ФИЛЬТРЫ</h3>
                        <label>
                            Поиск
                            <input v-model="filterForm.search" type="search" placeholder="ФИО, компания, доклад…">
                        </label>
                        <label>
                            Статус
                            <select v-model="filterForm.status">
                                <option value="">Все</option>
                                <option value="new">Новые</option>
                                <option value="verified">Проверено</option>
                                <option value="rejected">Не подходит</option>
                                <option value="archived">Архив</option>
                            </select>
                        </label>
                        <label>
                            Тип файла
                            <select v-model="filterForm.file_type">
                                <option value="">Все</option>
                                <option value="pdf">PDF</option>
                                <option value="ppt">PPT</option>
                                <option value="pptx">PPTX</option>
                            </select>
                        </label>
                        <label>
                            Источник
                            <select v-model="filterForm.source_id">
                                <option value="">Все</option>
                                <option v-for="source in sources" :key="source.id" :value="source.id">
                                    {{ source.name }}
                                </option>
                            </select>
                        </label>
                        <label>
                            Ответственный
                            <select v-model="filterForm.assignee">
                                <option value="">Все</option>
                                <option value="unassigned">Свободные</option>
                                <option value="mine">Мои</option>
                                <option value="assigned">Все в работе</option>
                                <option
                                    v-for="assignee in assignees"
                                    :key="assignee.id"
                                    :value="`user:${assignee.id}`"
                                >
                                    {{ assignee.username }}
                                </option>
                            </select>
                        </label>
                        <button type="button" class="cio-button cio-button--primary" @click="applyFilters">Применить</button>
                        <button type="button" class="cio-button" @click="resetFilters">Сбросить</button>
                    </aside>

                    <article class="cio-panel">
                        <div class="cio-panel__title-row">
                            <h3>ПРЕЗЕНТАЦИИ</h3>
                            <div class="cio-panel__actions">
                                <span>{{ presentations.total }} шт. · В работе: {{ stats.inWork }} · Моих: {{ stats.mine }}</span>
                                <button
                                    v-if="canManage && stats.total > 0"
                                    type="button"
                                    class="cio-button cio-button--danger"
                                    @click="clearPresentations"
                                >
                                    Очистить презентации
                                </button>
                            </div>
                        </div>

                        <div v-if="currentItems.length" class="cio-list">
                            <div
                                v-for="item in currentItems"
                                :key="item.id"
                                class="cio-row cio-row--review"
                                :class="{ 'is-mine': isMine(item) }"
                            >
                                <span class="cio-file">{{ item.file_type }}</span>
                                <div class="cio-row__copy">
                                    <strong>{{ item.title || 'Без названия' }}</strong>
                                    <span>
                                        {{ item.speaker_name || 'Спикер не указан' }}
                                        <template v-if="item.job_title"> · {{ item.job_title }}</template>
                                        <template v-if="item.company"> · {{ item.company }}</template>
                                    </span>
                                    <small>
                                        {{ item.event_name || item.source?.name || 'Ручное добавление' }}
                                        · {{ formatDate(item.discovered_at) }}
                                    </small>
                                    <div v-if="canManage" class="cio-quick">
                                        <button type="button" :class="{ 'is-on': item.has_email }" @click="toggleFlag(item, 'has_email')">Email</button>
                                        <button type="button" :class="{ 'is-on': item.has_phone }" @click="toggleFlag(item, 'has_phone')">Телефон</button>
                                        <button type="button" :class="{ 'is-on': item.is_good_lead }" @click="toggleFlag(item, 'is_good_lead')">Хороший лид</button>
                                        <button type="button" @click="patchPresentation(item, { review_status: 'verified' })">Проверено</button>
                                        <button type="button" class="is-danger" @click="patchPresentation(item, { review_status: 'rejected' })">Не подходит</button>
                                    </div>
                                </div>
                                <div class="cio-assignment" :class="{ 'is-mine': isMine(item), 'is-free': !item.assignee }">
                                    <div class="cio-assignment__status">
                                        <span class="cio-assignment__dot" aria-hidden="true"></span>
                                        <b>{{ assignmentLabel(item) }}</b>
                                    </div>
                                    <strong v-if="item.assignee">{{ item.assignee.username }}</strong>
                                    <small v-if="item.assigned_at">{{ formatAssignmentDate(item.assigned_at) }}</small>
                                    <button
                                        v-if="!item.assignee"
                                        type="button"
                                        class="cio-assignment__action"
                                        @click="assignmentAction(item, 'take')"
                                    >
                                        Взять в работу
                                    </button>
                                    <button
                                        v-else-if="isMine(item)"
                                        type="button"
                                        class="cio-assignment__action"
                                        @click="assignmentAction(item, 'release')"
                                    >
                                        Снять с себя
                                    </button>
                                </div>
                                <div class="cio-row__right">
                                    <span class="cio-tag" :class="{ 'cio-tag--new': item.review_status === 'new' }">
                                        {{ statusLabel(item.review_status) }}
                                    </span>
                                    <a :href="item.file_url" target="_blank" rel="noopener noreferrer" class="cio-button">
                                        Открыть презентацию ↗
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div v-else class="cio-empty">По текущим фильтрам ничего нет.</div>

                        <div v-if="presentations.last_page > 1" class="cio-pagination">
                            <Link
                                v-for="link in presentations.links"
                                :key="link.label"
                                :href="link.url || '#'"
                                preserve-scroll
                                :class="{ 'is-active': link.active, 'is-disabled': !link.url }"
                                v-html="link.label"
                            />
                        </div>
                    </article>
                </div>
            </section>

            <section v-else-if="activeTab === 'sources'" class="cio-section">
                <div class="cio-workspace cio-workspace--sources">
                    <aside class="cio-panel cio-source-form">
                        <h3>ДОБАВИТЬ ИСТОЧНИК</h3>
                        <p>Стартовый набор источников уже добавлен. Сюда можно докидывать новые страницы «Материалы», «Доклады» и архивы конференций.</p>
                        <form @submit.prevent="addSource">
                            <label>
                                Название
                                <input v-model="sourceForm.name" type="text" placeholder="CIO Forum 2026">
                                <small v-if="sourceForm.errors.name">{{ sourceForm.errors.name }}</small>
                            </label>
                            <label>
                                URL
                                <input v-model="sourceForm.url" type="url" placeholder="https://example.ru/materials">
                                <small v-if="sourceForm.errors.url">{{ sourceForm.errors.url }}</small>
                            </label>
                            <button type="submit" class="cio-button cio-button--primary" :disabled="sourceForm.processing">
                                Добавить
                            </button>
                        </form>
                    </aside>

                    <article class="cio-panel">
                        <div class="cio-panel__title-row">
                            <h3>ИСТОЧНИКИ</h3>
                            <span>{{ sources.length }}</span>
                        </div>

                        <div v-if="sources.length" class="cio-source-list">
                            <div v-for="source in sources" :key="source.id" class="cio-source-row">
                                <div>
                                    <strong>{{ source.name }}</strong>
                                    <a :href="source.url" target="_blank" rel="noopener noreferrer">{{ source.domain }} ↗</a>
                                </div>
                                <span><b>{{ source.presentations_count }}</b> презентаций</span>
                                <span>
                                    Последний скан:<br>
                                    <b>{{ formatDate(source.last_scanned_at) }}</b>
                                </span>
                                <span v-if="source.last_error" class="cio-source-error">{{ source.last_error }}</span>
                                <span v-else>Новых: <b>{{ source.last_scan_found }}</b></span>
                                <button
                                    type="button"
                                    class="cio-button"
                                    :disabled="scanningId === source.id"
                                    @click="scanSource(source)"
                                >
                                    {{ scanningId === source.id ? 'Сканирую…' : 'Просканировать' }}
                                </button>
                            </div>
                        </div>
                        <div v-else class="cio-empty">Источников пока нет.</div>
                    </article>
                </div>
            </section>

            <section v-else class="cio-section">
                <div class="cio-search-grid">
                    <article class="cio-panel">
                        <h3>РУЧНАЯ ССЫЛКА</h3>
                        <p>
                            Если презентацию нашли в браузере сами, добавь её сюда. Файл всё равно останется
                            на исходном сайте.
                        </p>
                        <form class="cio-form-grid" @submit.prevent="addCandidate">
                            <label class="cio-form-grid__wide">
                                URL презентации
                                <input v-model="candidateForm.file_url" type="url" placeholder="https://.../presentation.pdf">
                                <small v-if="candidateForm.errors.file_url">{{ candidateForm.errors.file_url }}</small>
                            </label>
                            <label>
                                Тип
                                <select v-model="candidateForm.file_type">
                                    <option value="">Определить по URL</option>
                                    <option value="pdf">PDF</option>
                                    <option value="ppt">PPT</option>
                                    <option value="pptx">PPTX</option>
                                </select>
                                <small v-if="candidateForm.errors.file_type">{{ candidateForm.errors.file_type }}</small>
                            </label>
                            <label>
                                Источник
                                <select v-model="candidateForm.source_id">
                                    <option value="">Без источника</option>
                                    <option v-for="source in sources" :key="source.id" :value="source.id">
                                        {{ source.name }}
                                    </option>
                                </select>
                            </label>
                            <label class="cio-form-grid__wide">
                                Название
                                <input v-model="candidateForm.title" type="text" placeholder="Название доклада">
                            </label>
                            <label>
                                Спикер
                                <input v-model="candidateForm.speaker_name" type="text" placeholder="Иван Петров">
                            </label>
                            <label>
                                Компания
                                <input v-model="candidateForm.company" type="text" placeholder="Компания">
                            </label>
                            <label>
                                Должность
                                <input v-model="candidateForm.job_title" type="text" placeholder="CIO / ИТ-директор">
                            </label>
                            <label>
                                Мероприятие
                                <input v-model="candidateForm.event_name" type="text" placeholder="CIO Forum">
                            </label>
                            <button type="submit" class="cio-button cio-button--primary cio-form-grid__wide" :disabled="candidateForm.processing">
                                Добавить в каталог
                            </button>
                        </form>
                    </article>

                    <article class="cio-panel cio-panel--note">
                        <h3>БЕСПЛАТНЫЙ ПОИСК: ЭТАП 1</h3>
                        <p>
                            Уже работает лёгкий скан добавленных публичных страниц и их sitemap.xml:
                            он читает только HTML/XML и забирает прямые ссылки на PDF/PPT/PPTX.
                        </p>
                        <p>
                            Следующий слой — автоматическое расширение по архивам конференций и бесплатным
                            публичным поисковым точкам, без платных API и без загрузки файлов на VPS.
                        </p>
                        <button type="button" class="cio-button" @click="switchTab('sources')">Перейти к источникам →</button>
                    </article>
                </div>
            </section>
        </main>
    </AppLayout>
</template>
