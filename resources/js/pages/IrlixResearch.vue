<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

const props = defineProps({
    reports: { type: Array, required: true },
});

const search = ref('');
const selectedId = ref(props.reports[0]?.id ?? '');
const ratings = {
    confirmed: 'Есть подтверждения',
    likely: 'Есть косвенные сигналы',
    unclear: 'Не установлено',
    no_evidence: 'Доказательств не найдено',
};
const visibleReports = computed(() => {
    const q = search.value.trim().toLocaleLowerCase('ru-RU');
    if (!q) return props.reports;
    return props.reports.filter((report) =>
        [report.company.name, report.company.inn, report.summary, ...report.technologies]
            .join(' ').toLocaleLowerCase('ru-RU').includes(q)
    );
});
const selected = computed(() => visibleReports.value.find((item) => item.id === selectedId.value) ?? visibleReports.value[0] ?? null);

function selectReport(id) {
    selectedId.value = id;
}
function formattedDate(date) {
    return date ? new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium' }).format(new Date(date)) : '—';
}
</script>

<template>
    <AppLayout>
        <Head title="IRLIX Intelligence — Исследования компаний" />
        <main class="research-page">
            <header class="research-hero">
                <p class="research-overline">IRLIX / INTELLIGENCE / SOURCE-BACKED</p>
                <h1>ИССЛЕДОВАНИЯ<br><span>КОМПАНИЙ</span></h1>
                <p>Проверенные публичные сведения и гипотезы для поиска заказчиков IT-аутстаффинга. Источник отчётов — GitHub.</p>
                <div class="research-hero-links">
                    <a href="/projects/irlix-sales">← БАЗА ЗАКАЗЧИКОВ</a>
                    <a href="/projects/vacancy-source">VACANCY SOURCE ↗</a>
                </div>
            </header>

            <section class="research-overview" aria-label="Сводка">
                <div><strong>{{ reports.length }}</strong><span>Исследований в базе</span></div>
                <div><strong>{{ reports.filter((r) => r.outstaff_demand.assessment === 'confirmed').length }}</strong><span>С подтверждённым сигналом</span></div>
                <div><strong>{{ reports.filter((r) => r.outstaff_demand.assessment === 'likely').length }}</strong><span>С косвенными сигналами</span></div>
            </section>

            <div class="research-public-warning">
                <strong>ПУБЛИЧНЫЙ GITHUB</strong>
                <p>Сюда попадают только открытые исследования. Непубличные контакты, условия договоров и внутренние коммерческие сведения не публикуются.</p>
            </div>

            <section class="research-workspace" aria-label="Исследования компаний">
                <aside class="research-index">
                    <label for="research-search">ПОИСК ПО ИССЛЕДОВАНИЯМ</label>
                    <input id="research-search" v-model="search" placeholder="Компания, ИНН, стек…" type="search" maxlength="120">
                    <div class="research-list">
                        <button
                            v-for="report in visibleReports"
                            :key="report.id"
                            class="research-list-item"
                            type="button"
                            :class="{ 'is-selected': selected?.id === report.id }"
                            @click="selectReport(report.id)"
                        >
                            <strong>{{ report.company.name }}</strong>
                            <span>{{ ratings[report.outstaff_demand.assessment] }}</span>
                            <small>{{ formattedDate(report.researched_at) }}</small>
                        </button>
                        <p v-if="!visibleReports.length && reports.length" class="research-empty">Совпадений не найдено.</p>
                    </div>
                </aside>

                <article v-if="selected && visibleReports.some((r) => r.id === selected.id)" class="research-detail">
                    <div class="research-detail-top">
                        <div>
                            <p class="research-overline">COMPANY INTELLIGENCE / {{ selected.id }}</p>
                            <h2>{{ selected.company.name }}</h2>
                        </div>
                        <span class="research-signal" :data-assessment="selected.outstaff_demand.assessment">
                            {{ ratings[selected.outstaff_demand.assessment] }}
                        </span>
                    </div>
                    <div class="research-meta">
                        <span v-if="selected.company.inn"><b>ИНН:</b> {{ selected.company.inn }}</span>
                        <span v-if="selected.company.industry"><b>Сфера:</b> {{ selected.company.industry }}</span>
                        <span><b>Обновлено:</b> {{ formattedDate(selected.researched_at) }}</span>
                        <a v-if="selected.company.website" :href="selected.company.website" target="_blank" rel="noopener noreferrer">Сайт компании ↗</a>
                    </div>

                    <section class="research-section">
                        <h3>01 / ВЫВОД</h3>
                        <p>{{ selected.summary || 'Общий вывод пока не сформирован.' }}</p>
                    </section>

                    <section class="research-section">
                        <h3>02 / ПОКУПКА АУТСТАФФИНГА</h3>
                        <p>{{ selected.outstaff_demand.explanation || 'Недостаточно данных для вывода.' }}</p>
                    </section>

                    <section v-if="selected.technologies.length" class="research-section">
                        <h3>03 / ТЕХНОЛОГИИ</h3>
                        <div class="research-chips">
                            <span v-for="tech in selected.technologies" :key="tech">{{ tech }}</span>
                        </div>
                    </section>

                    <section v-if="selected.contractors.length" class="research-section">
                        <h3>04 / ПОДРЯДЧИКИ</h3>
                        <div v-for="(item,index) in selected.contractors" :key="index" class="research-line">
                            <strong>{{ item.name }}</strong>
                            <small>{{ item.relationship === 'confirmed' ? 'Подтверждено источником' : 'Гипотеза' }}</small>
                            <p v-if="item.note">{{ item.note }}</p>
                        </div>
                    </section>

                    <section v-if="selected.decision_makers.length" class="research-section">
                        <h3>05 / ПУБЛИЧНЫЕ ЛПР</h3>
                        <div v-for="(person,index) in selected.decision_makers" :key="index" class="research-line">
                            <strong>{{ person.name }}</strong><span>{{ person.role }}</span>
                            <a :href="person.public_url" target="_blank" rel="noopener noreferrer">Публичный источник ↗</a>
                        </div>
                    </section>

                    <section v-if="selected.recommendation" class="research-section">
                        <h3>06 / РЕКОМЕНДАЦИЯ</h3>
                        <p>{{ selected.recommendation }}</p>
                    </section>

                    <section v-if="selected.open_questions.length" class="research-section">
                        <h3>07 / ЧТО ЕЩЁ ПРОВЕРИТЬ</h3>
                        <ul><li v-for="(question,index) in selected.open_questions" :key="index">{{ question }}</li></ul>
                    </section>

                    <section class="research-section">
                        <h3>08 / ПУБЛИЧНЫЕ ИСТОЧНИКИ ({{ selected.evidence.length }})</h3>
                        <div v-if="selected.evidence.length" class="research-sources">
                            <a v-for="(source,index) in selected.evidence" :key="index" :href="source.url" target="_blank" rel="noopener noreferrer">
                                <strong>{{ source.title || source.url }}</strong>
                                <span v-if="source.finding">{{ source.finding }}</span>
                                <small>{{ source.url }}</small>
                            </a>
                        </div>
                        <p v-else>Подтверждающие источники пока не добавлены.</p>
                    </section>
                </article>

                <div v-else class="research-detail research-no-reports">
                    <strong>{{ reports.length ? 'Не найдено' : 'Исследований пока нет' }}</strong>
                    <p v-if="!reports.length">Попроси AI в чате: «Проанализируй компанию и сохрани исследование в GitHub». После публикации отчёт появится здесь автоматически.</p>
                    <p v-else>Измени запрос поиска, чтобы открыть отчёт.</p>
                </div>
            </section>
        </main>
    </AppLayout>
</template>

<style scoped>
.research-page {width:min(100%,1370px);margin:0 auto;padding:12px 7px 70px;color:var(--ds-color-black,#171512);}
.research-hero {border-bottom:6px solid #201b18;padding:14px 0 23px;}
.research-overline {font-size:11px;font-weight:900;letter-spacing:.15em;text-transform:uppercase;}
.research-hero h1 {font-size:clamp(39px,6vw,81px);font-weight:1000;letter-spacing:-.055em;line-height:.97;margin:12px 0;}
.research-hero h1 span {color:#a82a22;}
.research-hero>p:not(.research-overline){max-width:680px;font-size:17px;line-height:1.45;}
.research-hero-links {display:flex;gap:20px;flex-wrap:wrap;margin-top:20px;}
.research-hero a,.research-detail a {color:#a82a22;font-weight:800;text-decoration:underline;overflow-wrap:anywhere;}
.research-overview {display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:24px 0;}
.research-overview>div {border:3px solid #201b18;padding:14px;min-width:0;display:flex;flex-direction:column;gap:6px;}
.research-overview strong {font-size:clamp(29px,4vw,46px);line-height:1;}
.research-overview span {font-size:12px;font-weight:800;text-transform:uppercase;}
.research-public-warning {border-left:6px solid #a82a22;padding:9px 16px;background:#f1e5d8;margin-bottom:24px;}
.research-public-warning strong {font-size:12px;letter-spacing:.07em;}
.research-public-warning p {margin:5px 0;line-height:1.45;font-size:13px;}
.research-workspace {display:grid;grid-template-columns:minmax(240px,.7fr) minmax(0,1.7fr);gap:20px;align-items:start;}
.research-index {border:3px solid #201b18;padding:14px;min-width:0;}
.research-index label {display:block;font-weight:900;font-size:12px;margin-bottom:10px;}
.research-index input {border:2px solid #201b18;padding:11px;width:100%;min-height:45px;background:#fffdf7;font:inherit;}
.research-list {display:grid;gap:7px;margin-top:15px;max-height:75vh;overflow:auto;}
.research-list-item {text-align:left;display:flex;flex-direction:column;gap:7px;background:transparent;border:2px solid #201b18;padding:13px;cursor:pointer;color:inherit;}
.research-list-item strong{font-size:16px;overflow-wrap:anywhere;}
.research-list-item span,.research-list-item small{font-size:12px;}
.research-list-item.is-selected {background:#201b18;color:#fffaf0;}
.research-detail {border:3px solid #201b18;padding:clamp(14px,3vw,25px);min-width:0;box-shadow:5px 5px 0 #201b18;}
.research-detail-top {display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;}
.research-detail-top h2 {font-size:clamp(26px,4vw,46px);line-height:1.08;margin:4px 0 12px;overflow-wrap:anywhere;}
.research-signal {padding:8px 11px;border:2px solid #201b18;font-size:12px;font-weight:900;text-transform:uppercase;}
.research-signal[data-assessment=confirmed] {background:#d8e4cb;}
.research-signal[data-assessment=likely] {background:#f9e3b6;}
.research-meta {display:flex;flex-wrap:wrap;gap:12px 20px;padding:14px 0;border-top:2px solid #201b18;}
.research-meta span {font-size:13px;}
.research-section {padding:17px 0;border-top:2px solid #201b18;min-width:0;}
.research-section h3 {font-size:14px;letter-spacing:.08em;margin:0 0 11px;}
.research-section p {line-height:1.55;white-space:pre-line;overflow-wrap:anywhere;}
.research-chips {display:flex;gap:7px;flex-wrap:wrap;}
.research-chips span {padding:7px 10px;border:2px solid #201b18;font-weight:800;font-size:12px;}
.research-line {padding:8px 0;border-bottom:1px solid #c2b5a5;display:flex;flex-wrap:wrap;gap:5px 12px;}
.research-line p {flex-basis:100%;margin:4px 0;}
.research-line small {font-size:12px;}
.research-sources {display:grid;gap:11px;}
.research-sources a {display:flex;flex-direction:column;gap:6px;border:2px solid #201b18;padding:12px;text-decoration:none;}
.research-sources span {color:#201b18;line-height:1.45;font-weight:400;font-size:13px;}
.research-sources small {overflow-wrap:anywhere;color:#786357;}
.research-no-reports {padding:45px 24px;}
.research-no-reports strong {font-size:24px;}
.research-no-reports p {line-height:1.55;}
@media(max-width:750px){.research-workspace{grid-template-columns:minmax(0,1fr);}.research-list{max-height:270px;}.research-hero-links{gap:12px;}}
@media(max-width:400px){.research-page{padding:0 4px 40px;}.research-overview{gap:5px;}.research-overview>div{padding:8px 6px;border-width:2px;}.research-overview span{font-size:10px;}.research-index{padding:10px;}.research-detail{padding:12px;box-shadow:2px 2px 0 #201b18;}}
</style>
