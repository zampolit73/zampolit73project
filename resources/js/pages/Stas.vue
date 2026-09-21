<script setup>
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../layouts/AppLayout.vue';

const isArmRaised = ref(false);

const toggleArm = () => {
    isArmRaised.value = !isArmRaised.value;
};
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
                    <p class="stas-copy__note">
                        Маленькая персональная страница. Нажми на руку — человечек поднимет её для приветствия.
                    </p>
                </div>

                <div class="stas-stage" aria-label="Интерактивный человечек с поднимающейся рукой">
                    <div class="stas-stage__sun" aria-hidden="true"></div>

                    <div class="stas-person">
                        <div class="stas-person__head" aria-hidden="true"></div>
                        <div class="stas-person__body" aria-hidden="true"></div>
                        <div class="stas-person__arm stas-person__arm--left" aria-hidden="true"></div>

                        <button
                            type="button"
                            class="stas-person__arm-control"
                            :class="{ 'is-raised': isArmRaised }"
                            :aria-pressed="isArmRaised"
                            :aria-label="isArmRaised ? 'Опустить руку' : 'Поднять руку'"
                            :title="isArmRaised ? 'Опустить руку' : 'Поднять руку'"
                            @click="toggleArm"
                        >
                            <span class="stas-person__upper-arm" aria-hidden="true"></span>
                            <span class="stas-person__forearm" aria-hidden="true">
                                <span class="stas-person__hand"></span>
                            </span>
                        </button>

                        <div class="stas-person__leg stas-person__leg--left" aria-hidden="true"></div>
                        <div class="stas-person__leg stas-person__leg--right" aria-hidden="true"></div>
                    </div>

                    <div class="stas-stage__hello" aria-live="polite">
                        {{ isArmRaised ? 'ПРИВЕТ!' : 'НАЖМИ НА РУКУ' }}
                    </div>
                </div>
            </section>
        </main>
    </AppLayout>
</template>
