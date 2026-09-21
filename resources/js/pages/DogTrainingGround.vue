<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

const running = ref(true);
const pace = ref('training');
const reducedMotion = ref(false);
const runKey = ref(0);

const paces = {
    calm: { label: 'СПОКОЙНО', multiplier: 1.28 },
    training: { label: 'ТРЕНИРОВКА', multiplier: 1 },
    turbo: { label: 'ТУРБО', multiplier: 0.72 },
};

const dogs = [
    { name: 'РЭЙ', breed: 'овчарка', tone: 'dark', duration: 8.8, delay: -1.4, lane: 1 },
    { name: 'ЛУНА', breed: 'бордер-колли', tone: 'light', duration: 9.8, delay: -5.2, lane: 2 },
    { name: 'БОБ', breed: 'джек-рассел', tone: 'red', duration: 7.9, delay: -3.6, lane: 3 },
];

const courseStyle = computed(() => ({
    '--pace': paces[pace.value].multiplier,
}));

function dogStyle(dog) {
    return {
        '--dog-duration': `${dog.duration * paces[pace.value].multiplier}s`,
        '--dog-delay': `${dog.delay * paces[pace.value].multiplier}s`,
    };
}

function toggleRun() {
    if (reducedMotion.value) {
        running.value = false;
        return;
    }

    running.value = !running.value;
}

function restart() {
    runKey.value += 1;
    running.value = !reducedMotion.value;
}

function setPace(nextPace) {
    pace.value = nextPace;

    if (!reducedMotion.value) {
        running.value = true;
    }
}

onMounted(() => {
    reducedMotion.value = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reducedMotion.value) {
        running.value = false;
    }
});
</script>

<template>
    <AppLayout>
        <Head title="Кинологическая площадка" />

        <main class="dog-project">
            <Link href="/projects" class="dog-back">← ПРОЕКТЫ</Link>

            <header class="dog-header">
                <div>
                    <p class="dog-eyebrow">PROJECT 04 / AGILITY GROUND</p>
                    <h1>КИНОЛОГИЧЕСКАЯ<br><span>ПЛОЩАДКА</span></h1>
                </div>

                <p class="dog-intro">
                    Маленькая тренировочная площадка прямо в браузере:
                    собаки бегут по трассе, берут барьеры и ныряют в тоннель.
                    Никаких внешних картинок — всё собрано из Vue и CSS.
                </p>
            </header>

            <section
                class="dog-course"
                :class="{ 'is-paused': !running || reducedMotion }"
                :style="courseStyle"
                aria-label="Анимированная кинологическая трасса"
            >
                <div class="dog-sky" aria-hidden="true">
                    <span class="dog-sun"></span>
                    <span class="dog-cloud dog-cloud--one"></span>
                    <span class="dog-cloud dog-cloud--two"></span>
                    <span class="dog-bird dog-bird--one">⌁</span>
                    <span class="dog-bird dog-bird--two">⌁</span>
                </div>

                <div class="dog-field" aria-hidden="true">
                    <span class="dog-tree dog-tree--one"></span>
                    <span class="dog-tree dog-tree--two"></span>
                    <span class="dog-fence"></span>

                    <div class="dog-obstacle dog-obstacle--jump-one">
                        <i></i><b></b><span></span>
                    </div>

                    <div class="dog-obstacle dog-obstacle--slalom">
                        <i></i><i></i><i></i><i></i><i></i>
                    </div>

                    <div class="dog-obstacle dog-obstacle--jump-two">
                        <i></i><b></b><span></span>
                    </div>

                    <div class="dog-tunnel">
                        <span></span>
                    </div>

                    <div class="dog-paw-trail dog-paw-trail--one">• • • • •</div>
                    <div class="dog-paw-trail dog-paw-trail--two">• • • •</div>
                </div>

                <div class="dog-lanes">
                    <div
                        v-for="dog in dogs"
                        :key="`${dog.name}-${runKey}`"
                        class="dog-runner"
                        :class="`dog-runner--lane-${dog.lane}`"
                        :style="dogStyle(dog)"
                    >
                        <div class="dog-nameplate">
                            <strong>{{ dog.name }}</strong>
                            <span>{{ dog.breed }}</span>
                        </div>

                        <div class="dog-shadow"></div>

                        <div class="dog-body" :class="`dog-body--${dog.tone}`" aria-hidden="true">
                            <span class="dog-tail"></span>
                            <span class="dog-torso"></span>
                            <span class="dog-neck"></span>
                            <span class="dog-head"></span>
                            <span class="dog-ear dog-ear--back"></span>
                            <span class="dog-ear dog-ear--front"></span>
                            <span class="dog-muzzle"></span>
                            <span class="dog-eye"></span>
                            <span class="dog-leg dog-leg--one"></span>
                            <span class="dog-leg dog-leg--two"></span>
                            <span class="dog-leg dog-leg--three"></span>
                            <span class="dog-leg dog-leg--four"></span>
                        </div>
                    </div>
                </div>

                <div class="dog-course-label">
                    <span>СТАРТ</span>
                    <b>AGILITY / 04</b>
                    <span>ФИНИШ</span>
                </div>
            </section>

            <section class="dog-console">
                <div class="dog-console__main">
                    <button
                        type="button"
                        class="dog-action dog-action--primary"
                        :disabled="reducedMotion"
                        @click="toggleRun"
                    >
                        {{ running ? 'ПАУЗА' : 'СТАРТ' }}
                    </button>

                    <button type="button" class="dog-action" @click="restart">
                        ЗАНОВО
                    </button>
                </div>

                <div class="dog-pace" aria-label="Темп тренировки">
                    <span>ТЕМП</span>
                    <button
                        v-for="(item, key) in paces"
                        :key="key"
                        type="button"
                        :class="{ 'is-active': pace === key }"
                        @click="setPace(key)"
                    >
                        {{ item.label }}
                    </button>
                </div>

                <div class="dog-stats">
                    <article>
                        <strong>03</strong>
                        <span>собаки</span>
                    </article>
                    <article>
                        <strong>04</strong>
                        <span>зоны трассы</span>
                    </article>
                    <article>
                        <strong>∞</strong>
                        <span>кругов</span>
                    </article>
                </div>
            </section>

            <p v-if="reducedMotion" class="dog-motion-note">
                В системе включено уменьшение движения — трасса показана в спокойном режиме без автоматического забега.
            </p>

            <footer class="dog-footer">
                <span>БАРЬЕРЫ</span>
                <span>СЛАЛОМ</span>
                <span>ТОННЕЛЬ</span>
                <span>ХВОСТЫ ВВЕРХ</span>
            </footer>
        </main>
    </AppLayout>
</template>

<style scoped>
.dog-project {
    --dog-ink: #17130f;
    --dog-cream: #f4ead0;
    --dog-red: #a6222c;
    --dog-green: #70804e;
    --dog-green-dark: #405036;
    --dog-blue: #bcd8db;
    width: min(100%, 1380px);
    margin: 8px auto 0;
}

.dog-back {
    display: inline-flex;
    margin-bottom: 18px;
    color: var(--dog-ink);
    font-size: 11px;
    font-weight: 900;
    letter-spacing: .12em;
    text-decoration: none;
}

.dog-header {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(260px, .55fr);
    gap: 42px;
    align-items: end;
    margin-bottom: 26px;
}

.dog-eyebrow {
    margin: 0 0 12px;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: .17em;
    text-transform: uppercase;
}

.dog-header h1 {
    margin: 0;
    font-family: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
    font-size: clamp(60px, 7.8vw, 116px);
    font-weight: 900;
    line-height: .78;
    letter-spacing: -.015em;
    text-transform: uppercase;
}

.dog-header h1 span {
    color: var(--dog-red);
}

.dog-intro {
    margin: 0 0 3px;
    padding: 18px 0 0;
    border-top: 4px solid var(--dog-ink);
    color: #585045;
    font-size: 13px;
    font-weight: 750;
    line-height: 1.55;
}

.dog-course {
    position: relative;
    min-height: 570px;
    overflow: hidden;
    border: 6px solid var(--dog-ink);
    background: var(--dog-blue);
    box-shadow: 14px 14px 0 var(--dog-red);
}

.dog-sky {
    position: absolute;
    inset: 0 0 42%;
    overflow: hidden;
    background:
        linear-gradient(rgba(255,255,255,.16) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.16) 1px, transparent 1px),
        var(--dog-blue);
    background-size: 48px 48px;
}

.dog-sun {
    position: absolute;
    top: 42px;
    right: 8%;
    width: 96px;
    height: 96px;
    border: 4px solid var(--dog-ink);
    border-radius: 50%;
    background: #e2bd54;
    box-shadow: 9px 9px 0 rgba(23,19,15,.16);
}

.dog-cloud {
    position: absolute;
    width: 120px;
    height: 34px;
    border: 3px solid var(--dog-ink);
    border-radius: 40px;
    background: var(--dog-cream);
}

.dog-cloud::before,
.dog-cloud::after {
    position: absolute;
    bottom: -3px;
    border: 3px solid var(--dog-ink);
    border-radius: 50%;
    background: var(--dog-cream);
    content: "";
}

.dog-cloud::before {
    left: 19px;
    width: 48px;
    height: 48px;
}

.dog-cloud::after {
    right: 15px;
    width: 58px;
    height: 58px;
}

.dog-cloud--one {
    top: 64px;
    left: 8%;
}

.dog-cloud--two {
    top: 145px;
    left: 38%;
    transform: scale(.72);
}

.dog-bird {
    position: absolute;
    color: var(--dog-ink);
    font-family: Georgia, serif;
    font-size: 36px;
    font-weight: 900;
    transform: rotate(8deg);
}

.dog-bird--one { top: 56px; left: 62%; }
.dog-bird--two { top: 118px; left: 68%; transform: scale(.7) rotate(-5deg); }

.dog-field {
    position: absolute;
    inset: 36% 0 0;
    overflow: hidden;
    border-top: 5px solid var(--dog-ink);
    background:
        repeating-linear-gradient(0deg, rgba(255,255,255,.06) 0 3px, transparent 3px 30px),
        var(--dog-green);
}

.dog-field::after {
    position: absolute;
    right: 0;
    bottom: 0;
    left: 0;
    height: 32%;
    border-top: 3px solid rgba(23,19,15,.48);
    background: #7f744f;
    content: "";
}

.dog-tree {
    position: absolute;
    z-index: 1;
    bottom: 40%;
    width: 58px;
    height: 132px;
    border: 4px solid var(--dog-ink);
    background: #795a3a;
}

.dog-tree::before {
    position: absolute;
    top: -74px;
    left: -45px;
    width: 140px;
    height: 110px;
    border: 4px solid var(--dog-ink);
    border-radius: 50%;
    background: var(--dog-green-dark);
    content: "";
}

.dog-tree--one { left: 3%; transform: scale(.82); }
.dog-tree--two { right: 4%; transform: scale(.68); }

.dog-fence {
    position: absolute;
    z-index: 1;
    right: 0;
    bottom: 34%;
    left: 0;
    height: 50px;
    border-top: 4px solid var(--dog-ink);
    border-bottom: 4px solid var(--dog-ink);
    background: repeating-linear-gradient(90deg, transparent 0 64px, var(--dog-ink) 64px 70px);
    opacity: .56;
}

.dog-obstacle {
    position: absolute;
    z-index: 3;
    bottom: 17%;
    width: 90px;
    height: 92px;
}

.dog-obstacle--jump-one { left: 28%; }
.dog-obstacle--jump-two { left: 59%; }

.dog-obstacle > i,
.dog-obstacle > b {
    position: absolute;
    bottom: 0;
    width: 12px;
    height: 92px;
    border: 3px solid var(--dog-ink);
    background: var(--dog-cream);
}

.dog-obstacle > i { left: 0; }
.dog-obstacle > b { right: 0; }

.dog-obstacle > span {
    position: absolute;
    top: 32px;
    left: 5px;
    width: 80px;
    height: 12px;
    border: 3px solid var(--dog-ink);
    background: var(--dog-red);
    transform: rotate(-2deg);
}

.dog-obstacle--slalom {
    left: 43%;
    display: flex;
    width: 118px;
    align-items: flex-end;
    justify-content: space-between;
}

.dog-obstacle--slalom i {
    position: static;
    width: 8px;
    height: 82px;
    border: 2px solid var(--dog-ink);
    background: var(--dog-cream);
    transform: rotate(3deg);
}

.dog-obstacle--slalom i:nth-child(even) {
    background: var(--dog-red);
    transform: rotate(-3deg);
}

.dog-tunnel {
    position: absolute;
    z-index: 3;
    right: 9%;
    bottom: 15%;
    width: 150px;
    height: 82px;
    overflow: hidden;
    border: 4px solid var(--dog-ink);
    border-radius: 80px 80px 12px 12px;
    background: repeating-linear-gradient(90deg, #c65239 0 18px, #e8be53 18px 36px);
}

.dog-tunnel span {
    position: absolute;
    top: 15px;
    right: 24px;
    bottom: -7px;
    left: 24px;
    border: 4px solid var(--dog-ink);
    border-radius: 50px 50px 0 0;
    background: #332c29;
}

.dog-paw-trail {
    position: absolute;
    z-index: 2;
    color: rgba(23,19,15,.38);
    font-size: 25px;
    font-weight: 900;
    letter-spacing: 13px;
    transform: rotate(-7deg);
}

.dog-paw-trail--one { left: 9%; bottom: 8%; }
.dog-paw-trail--two { right: 28%; bottom: 4%; transform: rotate(6deg); }

.dog-lanes {
    position: absolute;
    z-index: 5;
    inset: 0;
    pointer-events: none;
}

.dog-runner {
    position: absolute;
    left: -160px;
    width: 128px;
    height: 100px;
    animation: dog-course-run var(--dog-duration) linear var(--dog-delay) infinite;
    animation-play-state: running;
}

.dog-course.is-paused .dog-runner {
    animation-play-state: paused;
}

.dog-runner--lane-1 { bottom: 68px; }
.dog-runner--lane-2 { bottom: 134px; transform: scale(.91); }
.dog-runner--lane-3 { bottom: 198px; transform: scale(.78); }

.dog-nameplate {
    position: absolute;
    top: -36px;
    left: 28px;
    display: grid;
    min-width: 84px;
    padding: 5px 8px;
    border: 2px solid var(--dog-ink);
    background: var(--dog-cream);
    box-shadow: 3px 3px 0 var(--dog-ink);
    line-height: 1;
}

.dog-nameplate strong {
    font-size: 10px;
    letter-spacing: .08em;
}

.dog-nameplate span {
    margin-top: 4px;
    font-size: 7px;
    font-weight: 800;
    text-transform: uppercase;
}

.dog-shadow {
    position: absolute;
    left: 20px;
    bottom: 7px;
    width: 96px;
    height: 16px;
    border-radius: 50%;
    background: rgba(23,19,15,.24);
    filter: blur(2px);
    animation: dog-shadow-pulse .42s ease-in-out infinite alternate;
}

.dog-body {
    position: absolute;
    inset: 0;
    animation: dog-body-bob .42s ease-in-out infinite alternate;
}

.dog-torso {
    position: absolute;
    left: 32px;
    bottom: 33px;
    width: 66px;
    height: 39px;
    border: 3px solid var(--dog-ink);
    border-radius: 45% 52% 42% 48%;
    background: #47372f;
}

.dog-neck {
    position: absolute;
    right: 20px;
    bottom: 47px;
    width: 32px;
    height: 36px;
    border: 3px solid var(--dog-ink);
    border-radius: 50% 50% 30% 30%;
    background: #47372f;
    transform: rotate(-18deg);
}

.dog-head {
    position: absolute;
    right: 3px;
    bottom: 61px;
    width: 42px;
    height: 36px;
    border: 3px solid var(--dog-ink);
    border-radius: 52% 48% 45% 42%;
    background: #47372f;
}

.dog-muzzle {
    position: absolute;
    right: -8px;
    bottom: 66px;
    width: 24px;
    height: 18px;
    border: 3px solid var(--dog-ink);
    border-radius: 45%;
    background: #b59b7d;
}

.dog-eye {
    position: absolute;
    right: 23px;
    bottom: 82px;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--dog-cream);
    box-shadow: 0 0 0 2px var(--dog-ink);
}

.dog-ear {
    position: absolute;
    z-index: -1;
    bottom: 87px;
    width: 23px;
    height: 28px;
    border: 3px solid var(--dog-ink);
    background: #322821;
    clip-path: polygon(50% 0, 100% 100%, 0 82%);
}

.dog-ear--front { right: 19px; transform: rotate(17deg); }
.dog-ear--back { right: 37px; transform: rotate(-8deg); }

.dog-tail {
    position: absolute;
    left: 17px;
    bottom: 58px;
    width: 45px;
    height: 18px;
    border-top: 7px solid var(--dog-ink);
    border-radius: 50%;
    transform: rotate(-28deg);
    transform-origin: right center;
    animation: dog-tail-wag .24s ease-in-out infinite alternate;
}

.dog-leg {
    position: absolute;
    bottom: 13px;
    width: 9px;
    height: 31px;
    border: 3px solid var(--dog-ink);
    background: #47372f;
    transform-origin: top center;
}

.dog-leg::after {
    position: absolute;
    right: -8px;
    bottom: -5px;
    width: 16px;
    height: 7px;
    border: 3px solid var(--dog-ink);
    border-radius: 8px;
    background: inherit;
    content: "";
}

.dog-leg--one { left: 40px; transform: rotate(28deg); }
.dog-leg--two { left: 57px; transform: rotate(-22deg); }
.dog-leg--three { left: 83px; transform: rotate(23deg); }
.dog-leg--four { left: 96px; transform: rotate(-26deg); }

.dog-body--light .dog-torso,
.dog-body--light .dog-neck,
.dog-body--light .dog-head,
.dog-body--light .dog-leg {
    background: #eee4cf;
}

.dog-body--light .dog-head::after,
.dog-body--light .dog-torso::after {
    position: absolute;
    background: #26231f;
    content: "";
}

.dog-body--light .dog-head::after {
    top: 2px;
    right: 4px;
    width: 19px;
    height: 13px;
    border-radius: 50%;
}

.dog-body--light .dog-torso::after {
    top: 8px;
    left: 10px;
    width: 28px;
    height: 20px;
    border-radius: 50%;
    transform: rotate(-12deg);
}

.dog-body--red .dog-torso,
.dog-body--red .dog-neck,
.dog-body--red .dog-head,
.dog-body--red .dog-leg {
    background: #b85a35;
}

.dog-body--red .dog-ear {
    background: #7f341f;
}

.dog-course-label {
    position: absolute;
    z-index: 7;
    right: 18px;
    bottom: 16px;
    left: 18px;
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: 14px;
    color: var(--dog-cream);
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .13em;
}

.dog-course-label span:last-child {
    text-align: right;
}

.dog-console {
    display: grid;
    grid-template-columns: auto minmax(300px, 1fr) auto;
    gap: 18px;
    align-items: stretch;
    margin-top: 30px;
}

.dog-console__main,
.dog-pace,
.dog-stats {
    border: 4px solid var(--dog-ink);
    background: var(--dog-cream);
    box-shadow: 6px 6px 0 var(--dog-ink);
}

.dog-console__main {
    display: flex;
    gap: 8px;
    padding: 10px;
}

.dog-action,
.dog-pace button {
    min-height: 48px;
    border: 3px solid var(--dog-ink);
    background: #fff9e9;
    color: var(--dog-ink);
    cursor: pointer;
    font: inherit;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.dog-action {
    min-width: 108px;
    padding: 9px 14px;
}

.dog-action--primary {
    background: var(--dog-red);
    color: #fff;
}

.dog-action:disabled {
    cursor: not-allowed;
    opacity: .5;
}

.dog-pace {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px;
}

.dog-pace > span {
    margin-right: 5px;
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .14em;
}

.dog-pace button {
    flex: 1 1 0;
    padding: 7px 10px;
}

.dog-pace button.is-active {
    background: var(--dog-ink);
    color: var(--dog-cream);
}

.dog-stats {
    display: grid;
    grid-template-columns: repeat(3, 86px);
}

.dog-stats article {
    display: grid;
    place-items: center;
    align-content: center;
    min-height: 72px;
    padding: 8px;
    border-left: 3px solid var(--dog-ink);
    text-align: center;
}

.dog-stats article:first-child {
    border-left: 0;
}

.dog-stats strong {
    font-family: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
    font-size: 28px;
    line-height: 1;
}

.dog-stats span {
    margin-top: 4px;
    font-size: 7px;
    font-weight: 900;
    text-transform: uppercase;
}

.dog-motion-note {
    margin: 20px 0 0;
    padding: 12px 14px;
    border: 3px solid var(--dog-ink);
    background: #e7dfbd;
    font-size: 11px;
    font-weight: 800;
}

.dog-footer {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 16px;
    margin-top: 26px;
    padding: 14px 0;
    border-top: 3px solid var(--dog-ink);
    border-bottom: 3px solid var(--dog-ink);
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .1em;
}

@keyframes dog-course-run {
    0% {
        left: -160px;
        transform: translateY(0) rotate(0);
    }
    21% {
        transform: translateY(0) rotate(0);
    }
    27% {
        transform: translateY(-92px) rotate(-6deg);
    }
    33% {
        transform: translateY(0) rotate(2deg);
    }
    51% {
        transform: translateY(0) rotate(0);
    }
    58% {
        transform: translateY(-105px) rotate(-5deg);
    }
    65% {
        transform: translateY(0) rotate(2deg);
    }
    78% {
        opacity: 1;
        transform: translateY(0);
    }
    91% {
        opacity: .28;
        transform: translateY(3px) scale(.93);
    }
    100% {
        left: calc(100% + 80px);
        opacity: 0;
        transform: translateY(0) scale(.93);
    }
}

@keyframes dog-body-bob {
    from { transform: translateY(0) rotate(-1deg); }
    to { transform: translateY(-5px) rotate(2deg); }
}

@keyframes dog-tail-wag {
    from { transform: rotate(-42deg); }
    to { transform: rotate(-12deg); }
}

@keyframes dog-shadow-pulse {
    from { transform: scaleX(.82); opacity: .18; }
    to { transform: scaleX(1); opacity: .3; }
}

@media (max-width: 1100px) {
    .dog-header {
        grid-template-columns: 1fr;
        gap: 22px;
    }

    .dog-intro {
        max-width: 680px;
    }

    .dog-console {
        grid-template-columns: 1fr 1fr;
    }

    .dog-stats {
        grid-column: 1 / -1;
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 760px) {
    .dog-header h1 {
        font-size: clamp(54px, 16vw, 82px);
    }

    .dog-course {
        min-height: 520px;
        border-width: 5px;
        box-shadow: 9px 9px 0 var(--dog-red);
    }

    .dog-sun {
        width: 68px;
        height: 68px;
    }

    .dog-cloud--two,
    .dog-tree--two,
    .dog-bird--two {
        display: none;
    }

    .dog-obstacle--slalom {
        left: 42%;
        transform: scale(.78);
        transform-origin: bottom left;
    }

    .dog-obstacle--jump-one {
        left: 24%;
        transform: scale(.82);
        transform-origin: bottom left;
    }

    .dog-obstacle--jump-two {
        left: 59%;
        transform: scale(.82);
        transform-origin: bottom left;
    }

    .dog-tunnel {
        right: 4%;
        width: 110px;
        height: 66px;
    }

    .dog-runner {
        transform-origin: left bottom;
    }

    .dog-runner--lane-1 { bottom: 60px; }
    .dog-runner--lane-2 { bottom: 124px; transform: scale(.82); }
    .dog-runner--lane-3 { bottom: 188px; transform: scale(.7); }

    .dog-console {
        grid-template-columns: 1fr;
    }

    .dog-console__main {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .dog-action {
        width: 100%;
        min-height: 54px;
    }

    .dog-pace {
        flex-wrap: wrap;
    }

    .dog-pace > span {
        width: 100%;
    }

    .dog-stats {
        grid-column: auto;
    }
}

@media (max-width: 430px) {
    .dog-course {
        min-height: 470px;
    }

    .dog-cloud--one {
        left: 4%;
        transform: scale(.7);
        transform-origin: left center;
    }

    .dog-tree--one {
        left: -18px;
        transform: scale(.58);
    }

    .dog-obstacle--slalom {
        display: none;
    }

    .dog-obstacle--jump-one { left: 27%; }
    .dog-obstacle--jump-two { left: 58%; }

    .dog-tunnel {
        right: -8px;
        transform: scale(.78);
        transform-origin: bottom right;
    }

    .dog-runner--lane-1 { bottom: 56px; transform: scale(.82); }
    .dog-runner--lane-2 { bottom: 120px; transform: scale(.72); }
    .dog-runner--lane-3 { bottom: 180px; transform: scale(.62); }

    .dog-nameplate {
        display: none;
    }

    .dog-course-label b {
        display: none;
    }

    .dog-stats article {
        min-width: 0;
    }

    .dog-pace button {
        min-width: calc(50% - 8px);
        flex: 1 1 calc(50% - 8px);
    }

    .dog-footer {
        display: grid;
        grid-template-columns: 1fr 1fr;
        text-align: center;
    }
}

@media (prefers-reduced-motion: reduce) {
    .dog-runner,
    .dog-body,
    .dog-tail,
    .dog-shadow {
        animation: none !important;
    }

    .dog-runner {
        left: 12%;
        opacity: 1;
    }

    .dog-runner--lane-2 { left: 39%; }
    .dog-runner--lane-3 { left: 66%; }
}
</style>
