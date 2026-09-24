<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
</script>

<template>
<AppLayout><Head title="Проекты" />
<main class="projects-page">
<header class="projects-header"><p class="projects-header__eyebrow">TOOLS / PROJECTS</p><h1>ПРОЕКТЫ</h1><p>Отдельные инструменты и маленькие эксперименты внутри одного приложения. Выбирай проект — каждый живёт своей жизнью.</p></header>
<section class="projects-grid" aria-label="Список проектов">
<a href="/projects/bmp-to-mip" class="project-card"><span class="project-card__index">01</span><div><p class="project-card__meta">QUAKE 1 / TEXTURES</p><h2>BMP → MIP</h2><p>Пакетная конвертация BMP в Quake 1 miptex с оригинальной палитрой и ZIP-выгрузкой.</p></div><span class="project-card__arrow" aria-hidden="true">→</span></a>
<a href="/projects/cio-presentations" class="project-card project-card--internal"><span class="project-card__index">02</span><div><p class="project-card__meta">SALES / OPEN SOURCES / ACCOUNT</p><h2>CIO PRESENTATIONS</h2><p>Каталог публичных презентаций ИТ-директоров: источники, лёгкий поиск прямых PDF/PPT/PPTX ссылок и ручная квалификация лидов.</p></div><span class="project-card__arrow" aria-hidden="true">→</span></a>
<a href="/projects/pushkin-fairytales" class="project-card project-card--storybook"><span class="project-card__index">03</span><div><p class="project-card__meta">LITERATURE / MOTION / EXPERIMENT</p><h2>СКАЗКИ ПУШКИНА</h2><p>Живая книга с перелистыванием страниц, сказочными миниатюрами и атмосферой старой типографии.</p></div><span class="project-card__arrow" aria-hidden="true">→</span></a>
<a href="/projects/reading-diary" class="project-card project-card--reading"><span class="project-card__index">04</span><div><p class="project-card__meta">BOOKS / NOTES / RATINGS</p><h2>ЧИТАТЕЛЬСКИЙ ДНЕВНИК</h2><p>Личная книжная полка: добавляй прочитанные книги, ставь оценки и сохраняй впечатления.</p></div><span class="project-card__arrow" aria-hidden="true">→</span></a>
<a href="/projects/kommersant-ranking" class="project-card project-card--internal"><span class="project-card__index">05</span><div><p class="project-card__meta">MANAGERS / RANKING / WORKFLOW</p><h2>РЕЙТИНГ КОММЕРСАНТА</h2><p>Общая рабочая база топ-менеджеров: направления рейтинга, редактируемые LinkedIn-ссылки и ответственный «В работе у».</p></div><span class="project-card__arrow" aria-hidden="true">→</span></a>
</section></main>
</AppLayout>
</template>
