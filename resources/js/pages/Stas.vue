<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
</script>

<template>
    <AppLayout>
        <Head title="Привет, Стас!" />

        <main class="stas-page">
            <section class="stas-poster" aria-labelledby="stas-title">
                <div class="stas-poster__stamp" aria-hidden="true">HELLO / 73</div>

                <div class="stas-copy">
                    <p class="stas-copy__eyebrow">SPECIAL / GREETING</p>
                    <h1 id="stas-title">ПРИВЕТ,<br><span>СТАС!</span></h1>
                    <p class="stas-copy__note">Маленькая персональная страница. Просто потому что можем.</p>
                </div>

                <div class="stas-stage" aria-label="Анимированный человечек дружелюбно машет рукой">
                    <div class="stas-stage__sun" aria-hidden="true"></div>

                    <div class="stas-person" aria-hidden="true">
                        <div class="stas-person__head"></div>
                        <div class="stas-person__body"></div>
                        <div class="stas-person__arm stas-person__arm--left"></div>
                        <div class="stas-person__arm stas-person__arm--wave">
                            <div class="stas-person__forearm">
                                <div class="stas-person__hand"></div>
                            </div>
                        </div>
                        <div class="stas-person__leg stas-person__leg--left"></div>
                        <div class="stas-person__leg stas-person__leg--right"></div>
                    </div>

                    <div class="stas-stage__hello" aria-hidden="true">ПРИВЕТ!</div>
                </div>
            </section>
        </main>
    </AppLayout>
</template>
