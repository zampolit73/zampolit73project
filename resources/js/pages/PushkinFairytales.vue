<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

const tales = [
    {
        key: 'fish',
        kicker: 'МОРЕ / ЖЕЛАНИЯ / ЧУДО',
        title: 'Сказка о рыбаке\nи рыбке',
        note: 'Синее море шумит у самого корешка, а золотая рыбка появляется между строк.',
    },
    {
        key: 'saltan',
        kicker: 'ОСТРОВ / ЛЕБЕДЬ / ГРАД',
        title: 'Сказка о царе\nСалтане',
        note: 'Над страницей поднимается сказочный город, а белая лебедь скользит по ночной воде.',
    },
    {
        key: 'princess',
        kicker: 'ЗЕРКАЛО / ЯБЛОКО / ЛЕС',
        title: 'Сказка о мёртвой\nцаревне',
        note: 'Старинное зеркало ловит свет, красное яблоко лежит на полях, и лес хранит тайну.',
    },
    {
        key: 'cockerel',
        kicker: 'ЗВЕЗДА / БАШНЯ / ДОЗОР',
        title: 'Сказка о золотом\nпетушке',
        note: 'На высокой башне золотой дозорный смотрит в даль, где вспыхивает тревожная звезда.',
    },
    {
        key: 'balda',
        kicker: 'ЯРМАРКА / МОРЕ / СМЕКАЛКА',
        title: 'Сказка о попе\nи работнике Балде',
        note: 'Весёлая ярмарочная страница — с морским ветром, хитростью и чуть заметной улыбкой автора.',
    },
];

const index = ref(0);
const opened = ref(false);
const turning = ref(false);
const direction = ref('next');
const autoPlay = ref(true);
const reducedMotion = ref(false);
let timer = null;

const current = computed(() => tales[index.value]);
const pageNumber = computed(() => String(index.value + 1).padStart(2, '0'));

function scheduleAutoTurn() {
    window.clearInterval(timer);
    timer = null;

    if (!autoPlay.value || reducedMotion.value) {
        return;
    }

    timer = window.setInterval(() => turn(1), 5200);
}

function turn(step) {
    if (turning.value) {
        return;
    }

    direction.value = step > 0 ? 'next' : 'prev';

    if (reducedMotion.value) {
        index.value = (index.value + step + tales.length) % tales.length;
        return;
    }

    turning.value = true;

    window.setTimeout(() => {
        index.value = (index.value + step + tales.length) % tales.length;
    }, 290);

    window.setTimeout(() => {
        turning.value = false;
    }, 620);
}

function next() {
    turn(1);
    scheduleAutoTurn();
}

function previous() {
    turn(-1);
    scheduleAutoTurn();
}

function selectTale(nextIndex) {
    if (nextIndex === index.value || turning.value) {
        return;
    }

    direction.value = nextIndex > index.value ? 'next' : 'prev';

    if (reducedMotion.value) {
        index.value = nextIndex;
    } else {
        turning.value = true;
        window.setTimeout(() => { index.value = nextIndex; }, 290);
        window.setTimeout(() => { turning.value = false; }, 620);
    }

    scheduleAutoTurn();
}

function toggleAutoPlay() {
    autoPlay.value = !autoPlay.value;
    scheduleAutoTurn();
}

onMounted(() => {
    reducedMotion.value = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    window.setTimeout(() => {
        opened.value = true;
    }, reducedMotion.value ? 0 : 240);

    scheduleAutoTurn();
});

onBeforeUnmount(() => {
    window.clearInterval(timer);
});
</script>

<template>
    <AppLayout>
        <Head title="Сказки Пушкина" />

        <main class="pushkin-page">
            <Link href="/projects" class="pushkin-back">← ПРОЕКТЫ</Link>

            <section class="pushkin-hero">
                <div class="pushkin-copy">
                    <p class="pushkin-eyebrow">PROJECT 03 / LIVING BOOK</p>
                    <h1>СКАЗКИ<br><span>ПУШКИНА</span></h1>
                    <p class="pushkin-lead">
                        Маленькая живая книга: страницы перелистываются сами, но их можно листать и вручную.
                        Никаких картинок снаружи — только бумага, свет, типографика и немного волшебства.
                    </p>

                    <div class="pushkin-legend" aria-label="Подсказка">
                        <span>✦</span>
                        <p>Нажми на книгу или используй стрелки. Автоперелистывание можно остановить.</p>
                    </div>
                </div>

                <div class="pushkin-stage" :class="{ 'is-open': opened }">
                    <div class="pushkin-sky" aria-hidden="true">
                        <span v-for="star in 12" :key="star" :class="`pushkin-star pushkin-star--${star}`">✦</span>
                        <span class="pushkin-moon"></span>
                    </div>

                    <div class="pushkin-table" aria-hidden="true"></div>

                    <div
                        class="pushkin-book-wrap"
                        :class="{ 'is-turning': turning, 'is-prev': direction === 'prev' }"
                        role="button"
                        tabindex="0"
                        aria-label="Перелистнуть книгу"
                        @click="next"
                        @keydown.enter="next"
                        @keydown.space.prevent="next"
                    >
                        <div class="pushkin-book-shadow" aria-hidden="true"></div>

                        <div class="pushkin-book">
                            <div class="pushkin-cover pushkin-cover--back" aria-hidden="true"></div>

                            <article class="pushkin-page-sheet pushkin-page-sheet--left">
                                <div class="pushkin-page-inner">
                                    <p class="pushkin-folio">А. С. ПУШКИН</p>
                                    <div class="pushkin-portrait" aria-hidden="true">
                                        <span class="pushkin-portrait__oval"></span>
                                        <span class="pushkin-portrait__curl pushkin-portrait__curl--1"></span>
                                        <span class="pushkin-portrait__curl pushkin-portrait__curl--2"></span>
                                        <span class="pushkin-portrait__profile"></span>
                                    </div>
                                    <p class="pushkin-left-title">Пять сказок<br>в одной книге</p>
                                    <div class="pushkin-ornament" aria-hidden="true">◆ ✦ ◆</div>
                                    <small>Листай медленно.<br>У сказки своё время.</small>
                                    <span class="pushkin-page-number">00</span>
                                </div>
                            </article>

                            <article class="pushkin-page-sheet pushkin-page-sheet--right">
                                <div class="pushkin-page-inner">
                                    <p class="pushkin-folio">{{ current.kicker }}</p>

                                    <div class="pushkin-tale-art" :class="`pushkin-tale-art--${current.key}`" aria-hidden="true">
                                        <template v-if="current.key === 'fish'">
                                            <span class="pushkin-wave pushkin-wave--1"></span>
                                            <span class="pushkin-wave pushkin-wave--2"></span>
                                            <span class="pushkin-fish"><i></i></span>
                                            <span class="pushkin-sun-disc"></span>
                                        </template>

                                        <template v-else-if="current.key === 'saltan'">
                                            <span class="pushkin-city">
                                                <i></i><i></i><i></i>
                                            </span>
                                            <span class="pushkin-swan"></span>
                                            <span class="pushkin-sun-disc"></span>
                                        </template>

                                        <template v-else-if="current.key === 'princess'">
                                            <span class="pushkin-mirror"></span>
                                            <span class="pushkin-apple"><i></i></span>
                                            <span class="pushkin-branch"></span>
                                        </template>

                                        <template v-else-if="current.key === 'cockerel'">
                                            <span class="pushkin-tower"></span>
                                            <span class="pushkin-golden-bird">✦</span>
                                            <span class="pushkin-rays"></span>
                                        </template>

                                        <template v-else>
                                            <span class="pushkin-fair-tent"></span>
                                            <span class="pushkin-rope"></span>
                                            <span class="pushkin-sea-mark">≈ ≈ ≈</span>
                                        </template>
                                    </div>

                                    <h2>{{ current.title }}</h2>
                                    <p class="pushkin-note">{{ current.note }}</p>
                                    <div class="pushkin-ornament pushkin-ornament--small" aria-hidden="true">— ◆ —</div>
                                    <span class="pushkin-page-number">{{ pageNumber }}</span>
                                </div>
                            </article>

                            <div class="pushkin-turn-sheet" aria-hidden="true">
                                <span></span>
                            </div>

                            <div class="pushkin-spine" aria-hidden="true"></div>
                            <div class="pushkin-cover pushkin-cover--front" aria-hidden="true">
                                <span>СКАЗКИ</span>
                                <b>А. С. ПУШКИН</b>
                                <i>✦</i>
                            </div>
                        </div>
                    </div>

                    <div class="pushkin-controls">
                        <button type="button" class="pushkin-control" aria-label="Предыдущая сказка" @click.stop="previous">←</button>

                        <div class="pushkin-dots" aria-label="Выбор сказки">
                            <button
                                v-for="(tale, taleIndex) in tales"
                                :key="tale.key"
                                type="button"
                                :class="{ 'is-active': taleIndex === index }"
                                :aria-label="`Открыть: ${tale.title.replace('\n', ' ')}`"
                                :aria-current="taleIndex === index ? 'page' : undefined"
                                @click.stop="selectTale(taleIndex)"
                            ></button>
                        </div>

                        <button type="button" class="pushkin-control" aria-label="Следующая сказка" @click.stop="next">→</button>
                        <button type="button" class="pushkin-autoplay" @click.stop="toggleAutoPlay">
                            {{ autoPlay ? 'ПАУЗА' : 'ИГРАТЬ' }}
                        </button>
                    </div>
                </div>
            </section>

            <footer class="pushkin-footer">
                <span>1830-е</span>
                <p>БУМАГА • МОРЕ • ЗОЛОТО • НЕМНОГО МАГИИ</p>
                <span>{{ pageNumber }} / {{ String(tales.length).padStart(2, '0') }}</span>
            </footer>
        </main>
    </AppLayout>
</template>
