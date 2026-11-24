<script setup>
import {Link,usePage} from '@inertiajs/vue3';
const page=usePage();
const root='/projects/sales-hub';
</script>
<template><nav class="hub-nav"><Link href="/projects">← Проекты</Link><Link :href="root">Контакты</Link><Link :href="root+'?owner=mine'">Мои лиды</Link><Link :href="root+'?preset=due&owner=mine'">Задачи</Link><Link :href="root+'/companies'">Компании</Link><Link :href="root+'/analytics'">Аналитика</Link><Link v-if="page.props.auth.user.role==='admin'" :href="root+'/import'">Импорт</Link><Link v-if="page.props.auth.user.role==='admin'" :href="root+'/access'">Доступ</Link></nav></template>