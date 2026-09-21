<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const now = ref(new Date());
let timer = null;

const clocks = [
    { label: 'Москва', zone: 'Europe/Moscow', number: '01' },
    { label: 'Ульяновск', zone: 'Europe/Ulyanovsk', number: '02' },
    { label: 'Берлин', zone: 'Europe/Berlin', number: '03' },
];

const formatTime = (zone) => new Intl.DateTimeFormat('ru-RU', {
    timeZone: zone,
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
}).format(now.value);

const formatDate = (zone) => new Intl.DateTimeFormat('ru-RU', {
    timeZone: zone,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
}).format(now.value);

onMounted(() => {
    timer = window.setInterval(() => {
        now.value = new Date();
    }, 1000);
});

onUnmounted(() => {
    if (timer) window.clearInterval(timer);
});
</script>

<template>
    <AppLayout>
        <Head title="Главная" />

        <main class="home-page">
            <section class="home-card home-card--compact" aria-labelledby="home-title">
                <div class="home-card__top-rule"></div>
                <div class="home-card__diagonal"></div>
                <div class="home-card__bottom-rule"></div>

                <p class="home-card__eyebrow">ZAMPOLIT73 / PROJECT</p>

                <h1 id="home-title" class="home-card__title">
                    ПРИВЕТ,<br>ВАЛЕРА!
                </h1>

                <p class="home-card__brand">ZAMPOLIT73PROJECT</p>

                <p class="home-card__description">
                    Laravel · Vue · PWA · HTTPS
                </p>

                <Link v-if="!user" href="/login" class="home-card__login">Войти</Link>
            </section>

            <section class="home-status home-clocks" aria-label="Мировое время">
                <article v-for="clock in clocks" :key="clock.zone" class="home-status__card home-clock">
                    <p class="home-status__label">{{ clock.number }} / {{ clock.label }}</p>
                    <time class="home-status__value home-clock__time">{{ formatTime(clock.zone) }}</time>
                    <p class="home-status__meta">{{ formatDate(clock.zone) }}</p>
                </article>
            </section>
        </main>
    </AppLayout>
</template>
