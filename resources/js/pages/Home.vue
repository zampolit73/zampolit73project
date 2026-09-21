<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
</script>

<template>
    <AppLayout>
        <Head title="Главная" />

        <main class="home-page">
            <section class="home-card" aria-labelledby="home-title">
                <div class="home-card__top-rule"></div>
                <div class="home-card__diagonal"></div>
                <div class="home-card__bottom-rule"></div>

                <p class="home-card__eyebrow">ZAMPOLIT73 / PROJECT</p>

                <h1 id="home-title" class="home-card__title">ПРИВЕТ,<br>ПРОЕКТ!</h1>

                <p class="home-card__brand">ZAMPOLIT73PROJECT</p>

                <p class="home-card__description">
                    Laravel + Inertia + Vue на нативном Ubuntu-сервере. Общая дизайн-система и адаптивная оболочка уже подключены.
                </p>

                <Link v-if="!user" href="/login" class="home-card__login">Войти</Link>
            </section>

            <section class="home-status" aria-label="Состояние проекта">
                <article class="home-status__card">
                    <p class="home-status__label">01 / Core</p>
                    <p class="home-status__value">Ready</p>
                    <p class="home-status__meta">Laravel + Inertia + Vue</p>
                </article>
                <article class="home-status__card">
                    <p class="home-status__label">02 / Server</p>
                    <p class="home-status__value">Native</p>
                    <p class="home-status__meta">Nginx + PHP-FPM + SQLite</p>
                </article>
                <article class="home-status__card">
                    <p class="home-status__label">03 / HTTPS</p>
                    <p class="home-status__value">Later</p>
                    <p class="home-status__meta">После подключения поддомена</p>
                </article>
            </section>
        </main>
    </AppLayout>
</template>
