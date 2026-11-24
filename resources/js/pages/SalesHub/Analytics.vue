<script setup>
import { Head } from '@inertiajs/vue3';import AppLayout from '../../layouts/AppLayout.vue';
import HubNav from './HubNav.vue';
import './hub.css';
defineProps({statuses:Array,owners:Array,groups:Array,classes:Array,quality:Number,duplicates:Number});
</script>
<template><Head title="Аналитика"/><AppLayout><div class="saleshub"><HubNav/><div class="page-head"><div><p class="eyebrow">КОНТРОЛЬ РАБОТЫ</p><h1>Аналитика</h1><p class="muted">Текущие статусы, не историческая конверсия.</p></div></div><div class="metrics"><div class="metric"><small>Возможные дубли</small><strong>{{duplicates}}</strong></div><div class="metric"><small>Проверка качества</small><strong>{{quality}}</strong></div></div><div class="analytics-grid"><section v-for="[title,rows,key] in [['Статусы',statuses,'status'],['Ответственные',owners,'owner'],['Группы ЛПР',groups,'name'],['Классы рейтинга',classes,'class']]" :key="title" class="panel"><h2>{{title}}</h2><div v-for="r in rows" :key="r[key]" class="analytics-row"><span>{{r[key]}}</span><strong>{{r.total}}</strong></div><p v-if="!rows.length" class="muted">Нет данных</p></section></div></div></AppLayout></template>
