<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
</script>

<template>
<AppLayout><Head title="Проекты" />
<main class="projects-page">
<header class="projects-header"><p class="projects-header__eyebrow">TOOLS / PROJECTS</p><h1>ПРОЕКТЫ</h1><p>Отдельные рабочие инструменты внутри одного приложения. Выбирай проект — существующие инструменты остаются независимыми друг от друга.</p></header>
<section class="projects-grid" aria-label="Список проектов">
<Link href="/projects/bmp-to-mip" class="project-card"><span class="project-card__index">01</span><div><p class="project-card__meta">QUAKE 1 / TEXTURES</p><h2>BMP → MIP</h2><p>Пакетная конвертация BMP в Quake 1 miptex с оригинальной палитрой и ZIP-выгрузкой.</p></div><span class="project-card__arrow" aria-hidden="true">→</span></Link>
<Link href="/projects/cio-presentations" class="project-card project-card--internal"><span class="project-card__index">02</span><div><p class="project-card__meta">SALES / OPEN SOURCES / ACCOUNT</p><h2>CIO PRESENTATIONS</h2><p>Каталог публичных презентаций ИТ-директоров: источники, лёгкий поиск прямых PDF/PPT/PPTX ссылок и ручная квалификация лидов.</p></div><span class="project-card__arrow" aria-hidden="true">→</span></Link>
</section></main>
</AppLayout>
</template>
