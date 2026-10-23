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
const confidenceLabels = { high: 'Высокая', medium: 'Средняя', low: 'Низкая' };
function formatAmount(value) {
    return value == null ? 'Нет данных' : new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(value);
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
                        <h3>02 / ЮРЛИЦО, ГРУППА, ФИНАНСЫ</h3>
                        <div class="research-kv">
                            <div><b>Компания</b><span>{{ selected.company.name }}</span></div>
                            <div v-if="selected.company.inn"><b>ИНН</b><span>{{ selected.company.inn }}</span></div>
                            <div v-if="selected.company.ogrn"><b>ОГРН</b><span>{{ selected.company.ogrn }}</span></div>
                            <div v-if="selected.legal_profile.group"><b>Группа</b><span>{{ selected.legal_profile.group }}</span></div>
                            <div v-if="selected.legal_profile.contracting_entity"><b>Договорное юрлицо</b><span>{{ selected.legal_profile.contracting_entity }}</span></div>
                        </div>
                        <p v-if="selected.legal_profile.description">{{ selected.legal_profile.description }}</p>
                        <a v-if="selected.legal_profile.source_url" :href="selected.legal_profile.source_url" target="_blank" rel="noopener noreferrer">Основание по юрлицу ↗</a>
                        <div v-if="selected.financials.length" class="research-table-wrap">
                            <table class="research-financial-table">
                                <thead><tr><th>Год</th><th>Выручка, ₽</th><th>Прибыль, ₽</th><th>Источник</th></tr></thead>
                                <tbody>
                                    <tr v-for="item in selected.financials" :key="item.year">
                                        <td>{{ item.year }}</td>
                                        <td>{{ formatAmount(item.revenue_rub) }}</td>
                                        <td>{{ formatAmount(item.profit_rub) }}</td>
                                        <td><a :href="item.source_url" target="_blank" rel="noopener noreferrer">Проверить ↗</a></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="research-muted">Финансовые показатели пока не подтверждены достоверными публичными источниками.</p>
                    </section>



                    <section class="research-section">
                        <h3>03 / ВНЕШНИЕ IT-КОМАНДЫ И ПРИЗНАКИ СПРОСА</h3>
                        <p>{{ selected.outstaff_demand.explanation || 'Недостаточно данных для вывода.' }}</p>

                    <div v-if="selected.buying_signals.length" class="research-evidence-grid">
                        <div v-for="(item,index) in selected.buying_signals" :key="index" class="research-evidence-card">
                            <span class="research-level">ДОКАЗАТЕЛЬСТВО {{ item.evidence_level }}</span>
                            <strong>{{ item.signal }}</strong>
                            <p>{{ item.details }}</p>
                            <a :href="item.source_url" target="_blank" rel="noopener noreferrer">Источник ↗</a>
                        </div>
                    </div>

                    </section>


                    <section class="research-section">
                        <h3>04 / ТЕНДЕРЫ, СТАВКИ И УСЛОВИЯ</h3>
                        <div v-if="selected.tenders.length" class="research-evidence-grid">
                            <div v-for="(tender,index) in selected.tenders" :key="index" class="research-evidence-card">
                                <strong>{{ tender.title }}</strong>
                                <p><b>Площадка:</b> {{ tender.platform || 'Не указана' }} <span v-if="tender.number"> · № {{ tender.number }}</span></p>
                                <p><b>Публикация:</b> {{ tender.published_at || 'Не указана' }} · <b>Завершение:</b> {{ tender.closed_at || 'Не указано' }}</p>
                                <p><b>Статус:</b> {{ tender.status || 'Не установлен' }}</p>
                                <p v-if="tender.terms">{{ tender.terms }}</p>
                                <div v-if="tender.roles.length" class="research-chips">
                                    <span v-for="role in tender.roles" :key="role">{{ role }}</span>
                                </div>
                                <a :href="tender.url" target="_blank" rel="noopener noreferrer">Открыть закупку ↗</a>
                            </div>
                        </div>
                        <p v-else class="research-muted">Публичные закупки за проверенный период не установлены.</p>
                    </section>

                    <section v-if="selected.technologies.length" class="research-section">
                        <h3>07 / СТЕК И ПОТЕНЦИАЛЬНЫЕ РОЛИ</h3>
                        <div class="research-chips">
                            <span v-for="tech in selected.technologies" :key="tech">{{ tech }}</span>
                        </div>
                    </section>

                    <section class="research-section">
                        <h3>05 / ПОДРЯДЧИКИ, ПРОДУКТЫ И ПРОЕКТЫ</h3>
                        <p v-if="!selected.contractors.length && !selected.projects.length" class="research-muted">Подрядчики и проекты пока не установлены.</p>

                        <div v-if="selected.projects.length" class="research-evidence-grid">
                            <div v-for="(project,index) in selected.projects" :key="index" class="research-evidence-card">
                                <strong>{{ project.name }}</strong>
                                <p>{{ project.details }}</p>
                                <a :href="project.source_url" target="_blank" rel="noopener noreferrer">Подтверждение проекта ↗</a>
                            </div>
                        </div>

                        <div v-for="(item,index) in selected.contractors" :key="index" class="research-line">
                            <strong>{{ item.name }}</strong>
                            <small>{{ item.relationship === 'confirmed' ? 'Связь подтверждена' : 'Гипотеза' }}</small>
                            <p v-if="item.note">{{ item.note }}</p>
                        </div>
                    </section>



                    <section class="research-section">
                        <h3>06 / КЛЮЧЕВЫЕ ЛПР — IT, ПРОДУКТ, ЗАКУПКИ</h3>
                        <p class="research-help">Приоритет показывает полезность для выхода на заказчика, а не подтверждает полномочия на подписание договора. У каждого контакта должен быть открытый источник.</p>
                        <div v-if="selected.decision_makers.length" class="research-people">
                            <div v-for="(person,index) in selected.decision_makers" :key="index" class="research-person">
                                <div class="research-person-head">
                                    <div><strong>{{ person.name }}</strong><p>{{ person.role }}<span v-if="person.company_unit"> · {{ person.company_unit }}</span></p></div>
                                    <span class="research-priority">ПРИОРИТЕТ {{ person.priority }}</span>
                                </div>
                                <p class="research-person-status">
                                    {{ person.role_status === 'current' ? 'Актуальная роль подтверждена источником' : person.role_status === 'former' ? 'Бывший сотрудник / прежняя роль' : 'Должность требует перепроверки' }}
                                    <span v-if="person.last_checked_at"> · Проверено {{ person.last_checked_at }}</span>
                                </p>
                                <p v-if="person.responsibility"><b>Зона ответственности:</b> {{ person.responsibility }}</p>
                                <p v-if="person.outreach_angle"><b>Что предложить:</b> {{ person.outreach_angle }}</p>
                                <div class="research-contact-actions">
                                    <a v-if="person.public_url" :href="person.public_url" target="_blank" rel="noopener noreferrer">Профессиональный профиль ↗</a>
                                    <a :href="person.source_url" target="_blank" rel="noopener noreferrer">Подтверждение роли ↗</a>
                                    <a v-if="person.work_email" :href="'mailto:' + person.work_email">{{ person.work_email }}</a>
                                    <a v-if="person.work_phone" :href="'tel:' + person.work_phone.replace(/[^+\d]/g,'')">{{ person.work_phone }}</a>
                                </div>
                            </div>
                        </div>
                        <p v-else>Подтверждённые IT-ЛПР пока не установлены. Не подменяем их контактами продавцов или службы поддержки.</p>
                    </section>

                    <section class="research-section">
                        <h3>06A / КОРПОРАТИВНЫЕ КОНТАКТЫ И КАНАЛЫ</h3>
                        <div v-if="selected.business_contacts?.length" class="research-people">
                            <div v-for="(channel,index) in selected.business_contacts" :key="index" class="research-person">
                                <div class="research-person-head">
                                    <div>
                                        <strong>{{ channel.department || 'Корпоративный контакт' }}</strong>
                                        <p v-if="channel.contact_name">{{ channel.contact_name }}</p>
                                    </div>
                                </div>
                                <p v-if="channel.purpose">{{ channel.purpose }}</p>
                                <div class="research-contact-actions">
                                    <a v-if="channel.work_email" :href="'mailto:' + channel.work_email">{{ channel.work_email }}</a>
                                    <a v-if="channel.work_phone" :href="'tel:' + channel.work_phone.replace(/[^+\d]/g,'')">{{ channel.work_phone }}</a>
                                    <a :href="channel.source_url" target="_blank" rel="noopener noreferrer">Официальный источник ↗</a>
                                </div>
                                <small v-if="channel.last_checked_at">Проверено: {{ channel.last_checked_at }}</small>
                            </div>
                        </div>
                        <p v-else>Официальные деловые контакты пока не найдены.</p>
                    </section>


                    <section class="research-section">
                        <h3>08 / ЗАКУПОЧНЫЙ МАРШРУТ</h3>
                        <div v-if="selected.procurement.platforms.length" class="research-chips">
                            <span v-for="platform in selected.procurement.platforms" :key="platform">{{ platform }}</span>
                        </div>
                        <p v-if="selected.procurement.route">{{ selected.procurement.route }}</p>
                        <div v-if="selected.procurement.requirements.length">
                            <b>Опубликованные требования / ориентиры</b>
                            <ul><li v-for="(req,index) in selected.procurement.requirements" :key="index">{{ req }}</li></ul>
                        </div>
                        <div v-if="selected.procurement.unknowns.length">
                            <b>Что ещё требуется выяснить</b>
                            <ul><li v-for="(q,index) in selected.procurement.unknowns" :key="index">{{ q }}</li></ul>
                        </div>
                        <p v-if="!selected.procurement.route && !selected.procurement.platforms.length" class="research-muted">Порядок квалификации поставщиков пока не установлен.</p>
                    </section>
                    <section class="research-section">
                        <h3>09 / СТРАТЕГИЯ ВЫХОДА</h3>
                        <p v-if="selected.recommendation">{{ selected.recommendation }}</p>
                        <div v-if="selected.sales_plan.length" class="research-evidence-grid">
                            <div v-for="item in selected.sales_plan" :key="item.step" class="research-evidence-card">
                                <strong>ШАГ {{ item.step }} — {{ item.target }}</strong>
                                <p>{{ item.action }}</p>
                            </div>
                        </div>
                        <p v-if="!selected.sales_plan.length && !selected.recommendation" class="research-muted">Маршрут выхода ещё не сформирован.</p>
                    </section>
                    <section class="research-section">
                        <h3>10 / КОММЕРЧЕСКИЙ РЕЙТИНГ И CRM</h3>
                        <div class="research-rating-row">
                            <div><span class="research-muted">Потенциал</span><strong class="research-grade">{{ selected.sales_assessment.grade }}</strong></div>
                            <div><span class="research-muted">Уверенность</span><strong>{{ confidenceLabels[selected.sales_assessment.confidence] }}</strong></div>
                        </div>
                        <p v-if="selected.sales_assessment.rationale">{{ selected.sales_assessment.rationale }}</p>
                        <div v-if="selected.sales_assessment.opportunities.length">
                            <b>Возможности</b>
                            <ul><li v-for="item in selected.sales_assessment.opportunities" :key="item">{{ item }}</li></ul>
                        </div>
                        <div v-if="selected.sales_assessment.risks.length">
                            <b>Риски</b>
                            <ul><li v-for="item in selected.sales_assessment.risks" :key="item">{{ item }}</li></ul>
                        </div>
                        <p v-if="selected.crm_summary.recommended_status"><b>Предлагаемый статус:</b> {{ selected.crm_summary.recommended_status }}</p>
                        <p v-if="selected.crm_summary.next_action"><b>Следующее действие:</b> {{ selected.crm_summary.next_action }}</p>
                        <div v-if="selected.crm_summary.focus_roles.length" class="research-chips">
                            <span v-for="role in selected.crm_summary.focus_roles" :key="role">{{ role }}</span>
                        </div>
                        <p class="research-muted">Это аналитическая рекомендация, а не автоматическое изменение CRM-карточки.</p>
                    </section>


                    <section v-if="selected.open_questions.length" class="research-section">
                        <h3>ВОПРОСЫ ДЛЯ ПРОВЕРКИ</h3>
                        <ul><li v-for="(question,index) in selected.open_questions" :key="index">{{ question }}</li></ul>
                    </section>

                    <section class="research-section">
                        <h3>ПОДТВЕРЖДАЮЩИЕ ИСТОЧНИКИ ({{ selected.evidence.length }})</h3>
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
.research-help {font-size:13px;color:#786357;margin:0 0 12px;}
.research-people{display:grid;gap:12px;}
.research-person{border:2px solid #201b18;padding:14px;min-width:0;}
.research-person-head{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;}
.research-person-head strong{font-size:18px;overflow-wrap:anywhere;}
.research-person-head p{margin:4px 0;}
.research-priority{font-size:11px;font-weight:900;background:#f1e5d8;border:1px solid #201b18;padding:6px 8px;}
.research-person-status{font-size:12px;color:#786357;margin:6px 0;}
.research-person>p{margin:9px 0;line-height:1.5;}
.research-contact-actions{display:flex;flex-wrap:wrap;gap:10px 18px;margin-top:10px;}
.research-contact-actions a{font-size:13px;overflow-wrap:anywhere;min-height:40px;display:inline-flex;align-items:center;}
.research-person small{color:#786357;}
@media(max-width:400px){.research-person{padding:10px;}.research-contact-actions{flex-direction:column;gap:0;}}

.research-kv{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:12px;}
.research-kv>div{display:grid;gap:5px;padding:10px;border:1px solid #d0bdae;min-width:0;}
.research-kv b{font-size:11px;text-transform:uppercase;}
.research-kv span{overflow-wrap:anywhere;}
.research-table-wrap{max-width:100%;overflow-x:auto;margin:16px 0;}
.research-financial-table{width:100%;border-collapse:collapse;text-align:left;font-size:13px;}
.research-financial-table th,.research-financial-table td{padding:8px;border:1px solid #201b18;white-space:nowrap;}
.research-evidence-grid{display:grid;gap:10px;margin:12px 0;}
.research-evidence-card{padding:12px;border:2px solid #201b18;display:grid;gap:7px;}
.research-evidence-card p{margin:0;}
.research-evidence-card strong{overflow-wrap:anywhere;}
.research-level{font-weight:900;font-size:11px;color:#a82a22;}
.research-muted{color:#786357;font-size:13px;line-height:1.45;}
.research-rating-row{display:flex;gap:22px;align-items:center;margin:12px 0;flex-wrap:wrap;}
.research-rating-row>div{display:grid;gap:3px;}
.research-grade{font-size:42px;line-height:1;font-weight:1000;color:#a82a22;}
@media(max-width:400px){.research-kv{grid-template-columns:minmax(0,1fr);}}
</style>
