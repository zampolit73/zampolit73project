<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

const props = defineProps({
    prospects: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Object, required: true },
    priorities: { type: Object, required: true },
    users: { type: Array, required: true },
    stats: { type: Object, required: true },
    highlight: { type: Number, default: 0 },
});

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || '');
const editorOpen = ref(false);
const editingId = ref(null);
const blank = {
    name: '', inn: '', website: '', status: 'new', priority: 'medium',
    owner_id: '', contact_name: '', contact_role: '', contact_url: '',
    evidence_url: '', notes: '', next_step: '',
};
const form = useForm({ ...blank });

function applyFilters() {
    router.get('/projects/irlix-sales', {
        search: search.value,
        status: status.value,
    }, { preserveScroll: true, preserveState: false, replace: true });
}

function create() {
    editingId.value = null;
    form.defaults({ ...blank });
    form.reset();
    form.clearErrors();
    editorOpen.value = true;
}

function edit(row) {
    editingId.value = row.id;
    form.clearErrors();
    Object.keys(blank).forEach((key) => {
        form[key] = row[key] == null ? '' : row[key];
    });
    editorOpen.value = true;
    document.getElementById('sales-editor')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function save() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            editorOpen.value = false;
            editingId.value = null;
            form.reset();
        },
    };
    if (editingId.value) {
        form.patch('/projects/irlix-sales/prospects/' + editingId.value, options);
    } else {
        form.post('/projects/irlix-sales/prospects', options);
    }
}
</script>

<template>
    <AppLayout>
        <Head title="IRLIX AI Sales — База заказчиков" />
        <main class="sales-page">
            <header class="sales-hero">
                <div>
                    <p class="sales-kicker">PROJECT 07 / IRLIX / SALES INTELLIGENCE</p>
                    <h1>БАЗА<br><em>ЗАКАЗЧИКОВ</em></h1>
                    <p class="sales-description">Потенциальные покупатели IT-аутстаффинга. Сохраняй факты, гипотезы, контакты и следующий шаг — без отдельной CRM.</p>
                </div>
                <div class="sales-hero-aside">
                    <strong>AI + ЧЕЛОВЕК</strong>
                    <p>AI помогает исследовать. Решение о продаже принимает менеджер. Гипотезы из Vacancy Source не равны подтверждённым закупкам.</p>
                    <a href="/projects/vacancy-source">ПЕРЕЙТИ В VACANCY SOURCE ↗</a>
                </div>
            </header>

            <section class="sales-stats" aria-label="Показатели базы">
                <div><strong>{{ stats.total }}</strong><span>Всего компаний</span></div>
                <div><strong>{{ stats.new }}</strong><span>Новых</span></div>
                <div><strong>{{ stats.active }}</strong><span>В активной работе</span></div>
            </section>

            <div class="sales-toolbar">
                <form class="sales-filters" @submit.prevent="applyFilters">
                    <label class="sr-only" for="sales-search">Поиск компании или контакта</label>
                    <input id="sales-search" v-model="search" placeholder="Название, ИНН или контакт…" maxlength="120">
                    <label class="sr-only" for="sales-status">Статус</label>
                    <select id="sales-status" v-model="status">
                        <option value="">Все статусы</option>
                        <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <button type="submit" class="sales-button sales-button--outline">НАЙТИ</button>
                </form>
                <button type="button" class="sales-button" @click="create">+ ДОБАВИТЬ КОМПАНИЮ</button>
            </div>

            <section v-if="editorOpen" id="sales-editor" class="sales-editor" aria-label="Карточка компании">
                <div class="sales-editor-head">
                    <h2>{{ editingId ? 'РЕДАКТИРОВАТЬ' : 'НОВАЯ КОМПАНИЯ' }}</h2>
                    <button type="button" class="sales-close" @click="editorOpen = false">Закрыть ×</button>
                </div>
                <form @submit.prevent="save">
                    <div class="sales-fields">
                        <label>Название компании *<input v-model="form.name" maxlength="200" required></label>
                        <label>ИНН<input v-model="form.inn" inputmode="numeric" maxlength="12" placeholder="10 или 12 цифр"></label>
                        <label>Сайт<input v-model="form.website" type="url" placeholder="https://"></label>
                        <label>Статус<select v-model="form.status"><option v-for="(label,key) in statuses" :key="key" :value="key">{{ label }}</option></select></label>
                        <label>Приоритет<select v-model="form.priority"><option v-for="(label,key) in priorities" :key="key" :value="key">{{ label }}</option></select></label>
                        <label>Ответственный<select v-model="form.owner_id"><option value="">Не назначен</option><option v-for="user in users" :key="user.id" :value="user.id">{{ user.username }}</option></select></label>
                        <label>Контакт ЛПР<input v-model="form.contact_name" maxlength="200" placeholder="ФИО"></label>
                        <label>Должность<input v-model="form.contact_role" maxlength="200"></label>
                        <label>Ссылка на контакт<input v-model="form.contact_url" type="url" placeholder="https://"></label>
                        <label>Ссылка на доказательство<input v-model="form.evidence_url" type="url" placeholder="https://"></label>
                        <label class="sales-field-wide">Заметки / подтверждения<textarea v-model="form.notes" maxlength="4000" rows="3" placeholder="Что точно знаем? Что ещё требуется проверить?"></textarea></label>
                        <label class="sales-field-wide">Следующий шаг<textarea v-model="form.next_step" maxlength="2000" rows="2" placeholder="Например: проверить закупки и найти руководителя разработки"></textarea></label>
                    </div>
                    <div v-if="Object.keys(form.errors).length" class="sales-errors" role="alert">
                        <p v-for="(message,field) in form.errors" :key="field">{{ message }}</p>
                    </div>
                    <div class="sales-actions">
                        <button type="submit" class="sales-button" :disabled="form.processing">{{ form.processing ? 'СОХРАНЕНИЕ…' : 'СОХРАНИТЬ КАРТОЧКУ →' }}</button>
                        <button type="button" class="sales-button sales-button--outline" @click="editorOpen = false">ОТМЕНА</button>
                    </div>
                </form>
            </section>

            <section class="sales-list" aria-label="Список компаний">
                <div class="sales-list-heading">
                    <h2>КОМПАНИИ</h2>
                    <span>{{ prospects.total }} записей по фильтру</span>
                </div>
                <div v-if="!prospects.data.length" class="sales-empty">
                    <strong>Пока пусто.</strong>
                    <p>Добавь первого заказчика или найди возможного конечного клиента через Vacancy Source.</p>
                </div>
                <article v-for="row in prospects.data" :key="row.id" class="sales-card" :class="{ 'sales-card--highlight': highlight === row.id }">
                    <div class="sales-card-main">
                        <div class="sales-card-topline">
                            <span>CLIENT #{{ row.id }}</span>
                            <span class="sales-priority" :data-priority="row.priority">{{ priorities[row.priority] }}</span>
                        </div>
                        <h3>{{ row.name }}</h3>
                        <p class="sales-card-meta">
                            <span>{{ statuses[row.status] }}</span>
                            <span v-if="row.inn">ИНН {{ row.inn }}</span>
                            <span>{{ row.owner?.username || 'Не назначен' }}</span>
                        </p>
                        <p v-if="row.notes" class="sales-card-notes">{{ row.notes }}</p>
                        <p v-if="row.next_step" class="sales-next"><b>Далее:</b> {{ row.next_step }}</p>
                    </div>
                    <div class="sales-card-side">
                        <p v-if="row.contact_name"><b>ЛПР:</b> {{ row.contact_name }}<span v-if="row.contact_role"> · {{ row.contact_role }}</span></p>
                        <div class="sales-card-links">
                            <a v-if="row.website" :href="row.website" target="_blank" rel="noopener noreferrer">Сайт ↗</a>
                            <a v-if="row.evidence_url" :href="row.evidence_url" target="_blank" rel="noopener noreferrer">Источник ↗</a>
                            <a v-if="row.contact_url" :href="row.contact_url" target="_blank" rel="noopener noreferrer">Контакт ↗</a>
                            <a v-if="row.source_candidate" :href="'/projects/vacancy-source?active=' + row.source_candidate.investigation_id">Расследование ↗</a>
                        </div>
                        <button type="button" class="sales-button sales-button--outline" @click="edit(row)">ОТКРЫТЬ КАРТОЧКУ →</button>
                    </div>
                </article>
                <nav v-if="prospects.last_page > 1" class="sales-pagination" aria-label="Страницы">
                    <a v-if="prospects.prev_page_url" :href="prospects.prev_page_url">← НАЗАД</a>
                    <span>{{ prospects.current_page }} / {{ prospects.last_page }}</span>
                    <a v-if="prospects.next_page_url" :href="prospects.next_page_url">ВПЕРЁД →</a>
                </nav>
            </section>
        </main>
    </AppLayout>
</template>

<style scoped>
.sales-page { width:min(100%,1280px); margin:0 auto; color:var(--ds-color-black,#151515); padding:12px 8px 70px; }
.sales-hero { display:grid; grid-template-columns:minmax(0,1.5fr) minmax(240px,.7fr); gap:24px; border-bottom:6px solid #1b1917; padding:18px 0 32px; }
.sales-kicker,.sales-card-topline { font-weight:900; letter-spacing:.15em; font-size:11px; }
.sales-hero h1 { margin:14px 0; font-size:clamp(42px,6vw,87px); line-height:.89; letter-spacing:-.055em; font-weight:1000; }
.sales-hero h1 em { color:#a62c24; font-style:normal; }
.sales-description { max-width:690px; font-size:18px; line-height:1.45; }
.sales-hero-aside { border:3px solid #1b1917; box-shadow:6px 6px 0 #1b1917; padding:20px; align-self:center; }
.sales-hero-aside strong { letter-spacing:.12em; }
.sales-hero-aside p { font-size:14px; line-height:1.6; }
.sales-hero-aside a { color:#a62c24; font-weight:900; text-decoration:underline; }
.sales-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; padding:25px 0; }
.sales-stats>div { border:3px solid #1b1917; padding:16px; display:flex; flex-direction:column; }
.sales-stats strong { font-size:clamp(28px,4vw,55px); line-height:1; }
.sales-stats span { margin-top:7px; font-size:12px; text-transform:uppercase; font-weight:800; }
.sales-toolbar { display:flex; align-items:stretch; flex-wrap:wrap; gap:12px; margin-bottom:22px; }
.sales-filters { display:flex; flex:1 1 480px; gap:8px; min-width:0; }
.sales-filters input { flex:1 1 140px; min-width:0; }
.sales-filters select { width:min(36%,190px); }
.sales-button { cursor:pointer; border:3px solid #1b1917; background:#a62c24; color:#fff8e8; font-weight:900; letter-spacing:.04em; padding:12px 15px; min-height:48px; box-shadow:3px 3px 0 #1b1917; }
.sales-button--outline { background:transparent; color:#1b1917; }
.sales-button:disabled { opacity:.5; cursor:wait; }
input,select,textarea { font:inherit; border:2px solid #1b1917; background:#fffdf7; color:#1b1917; padding:10px; min-height:44px; max-width:100%; width:100%; }
.sales-editor { border:4px solid #1b1917; box-shadow:7px 7px 0 #1b1917; padding:20px; margin-bottom:28px; scroll-margin-top:20px; }
.sales-editor-head { display:flex; justify-content:space-between; gap:14px; align-items:center; margin-bottom:16px; }
.sales-editor h2,.sales-list h2 { font-size:clamp(23px,3vw,35px); margin:0; }
.sales-close { border:0; background:none; font-weight:900; text-decoration:underline; cursor:pointer; min-height:44px; }
.sales-fields { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; }
.sales-fields label { display:flex; flex-direction:column; gap:7px; font-size:13px; font-weight:800; min-width:0; }
.sales-fields textarea { resize:vertical; }
.sales-field-wide { grid-column:span 3; }
.sales-errors { margin:15px 0; color:#a62c24; font-weight:700; }
.sales-actions { display:flex; flex-wrap:wrap; gap:12px; margin-top:18px; }
.sales-list-heading { display:flex; justify-content:space-between; align-items:center; gap:12px; border-bottom:4px solid #1b1917; padding-bottom:10px; }
.sales-list-heading span { font-size:12px; font-weight:700; }
.sales-empty { padding:55px 20px; text-align:center; border-bottom:2px solid #1b1917; }
.sales-card { display:grid; grid-template-columns:minmax(0,1.5fr) minmax(200px,.7fr); gap:24px; border-bottom:3px solid #1b1917; padding:23px 0; min-width:0; }
.sales-card--highlight { background:rgba(166,44,36,.08); }
.sales-card-topline { display:flex; justify-content:space-between; gap:12px; }
.sales-card h3 { font-size:clamp(23px,3vw,35px); margin:9px 0; overflow-wrap:anywhere; }
.sales-card-meta { display:flex; flex-wrap:wrap; gap:8px 16px; font-size:12px; font-weight:900; text-transform:uppercase; }
.sales-card-notes { white-space:pre-line; line-height:1.5; overflow-wrap:anywhere; }
.sales-next { background:#eee4d5; padding:10px 12px; line-height:1.45; overflow-wrap:anywhere; }
.sales-priority[data-priority=high] { color:#a62c24; }
.sales-card-side { display:flex; flex-direction:column; align-items:flex-start; gap:12px; }
.sales-card-side p { margin:0; overflow-wrap:anywhere; }
.sales-card-links { display:flex; flex-wrap:wrap; gap:12px; }
.sales-card-links a { color:#a62c24; font-weight:800; text-decoration:underline; }
.sales-pagination { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:22px 0; font-weight:900; }
.sales-pagination a { color:#a62c24; }
@media(max-width:900px) { .sales-fields {grid-template-columns:repeat(2,minmax(0,1fr));} .sales-field-wide {grid-column:span 2;} }
@media(max-width:650px) {
    .sales-page { padding:0 4px 48px; }
    .sales-hero,.sales-card { grid-template-columns:minmax(0,1fr); gap:18px; }
    .sales-hero {padding-top:15px;}
    .sales-stats { gap:5px; }
    .sales-stats>div {padding:10px 6px; border-width:2px;}
    .sales-stats span {font-size:10px;}
    .sales-toolbar,.sales-filters {flex-direction:column;}
    .sales-filters select {width:100%;}
    .sales-toolbar>.sales-button {width:100%;}
    .sales-fields {grid-template-columns:minmax(0,1fr);}
    .sales-field-wide {grid-column:span 1;}
    .sales-card-side {gap:12px;}
    .sales-card-side .sales-button {width:100%;}
    .sales-editor {padding:13px; box-shadow:3px 3px 0 #1b1917;}
}
</style>
