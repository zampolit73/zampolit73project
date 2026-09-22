<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

const STORAGE_KEY = 'zampolit73.reading-diary.v1';

const starterBook = {
    id: 'pushkin-fairytales',
    title: 'Сказки Пушкина',
    author: 'Александр Пушкин',
    finishedAt: '',
    rating: 0,
    note: '',
    starter: true,
};

const palette = [
    ['#9f2630', '#e0bf68'],
    ['#314f46', '#d8c79c'],
    ['#263a59', '#e8d7a5'],
    ['#8a552f', '#f0d9a5'],
    ['#5f394f', '#e0b866'],
    ['#4e5b30', '#e4d2aa'],
    ['#303030', '#c44b42'],
];

const books = ref([{ ...starterBook }]);
const selectedId = ref(starterBook.id);
const hydrated = ref(false);
const adding = ref(false);

const newBook = reactive({
    title: '',
    author: '',
    finishedAt: '',
});

const selectedBook = computed(() => (
    books.value.find(book => book.id === selectedId.value) ?? books.value[0] ?? null
));

const rows = computed(() => {
    const result = [];

    for (let index = 0; index < books.value.length; index += 8) {
        result.push(books.value.slice(index, index + 8));
    }

    return result.length ? result : [[]];
});

const ratedCount = computed(() => books.value.filter(book => Number(book.rating) > 0).length);

const averageRating = computed(() => {
    const rated = books.value.filter(book => Number(book.rating) > 0);

    if (!rated.length) {
        return '—';
    }

    return (rated.reduce((sum, book) => sum + Number(book.rating), 0) / rated.length).toFixed(1);
});

function hashBook(book) {
    const source = `${book.id}${book.title}${book.author}`;
    let hash = 0;

    for (let index = 0; index < source.length; index += 1) {
        hash = ((hash << 5) - hash) + source.charCodeAt(index);
        hash |= 0;
    }

    return Math.abs(hash);
}

function bookStyle(book) {
    const hash = hashBook(book);
    const [color, accent] = book.starter ? palette[0] : palette[hash % palette.length];

    return {
        '--book-color': color,
        '--book-accent': accent,
        '--book-height': `${154 + (hash % 4) * 14}px`,
        '--book-width': `${58 + (hash % 3) * 9}px`,
        '--book-lean': `${book.starter ? 0 : ((hash % 5) - 2) * 0.65}deg`,
    };
}

function persist() {
    if (!hydrated.value) {
        return;
    }

    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(books.value));
}

function selectBook(book) {
    selectedId.value = book.id;
}

function setRating(value) {
    if (!selectedBook.value) {
        return;
    }

    selectedBook.value.rating = value;
}

function addBook() {
    const title = newBook.title.trim();
    const author = newBook.author.trim();

    if (!title || !author) {
        return;
    }

    const id = typeof window.crypto?.randomUUID === 'function'
        ? window.crypto.randomUUID()
        : `book-${Date.now()}`;

    books.value.push({
        id,
        title,
        author,
        finishedAt: newBook.finishedAt,
        rating: 0,
        note: '',
        starter: false,
    });

    selectedId.value = id;
    newBook.title = '';
    newBook.author = '';
    newBook.finishedAt = '';
    adding.value = false;
}

function removeSelected() {
    const book = selectedBook.value;

    if (!book || book.starter) {
        return;
    }

    const currentIndex = books.value.findIndex(item => item.id === book.id);
    books.value.splice(currentIndex, 1);

    const fallback = books.value[Math.max(0, currentIndex - 1)] ?? books.value[0] ?? null;
    selectedId.value = fallback?.id ?? null;
}

function prettyDate(value) {
    if (!value) {
        return 'не указана';
    }

    const date = new Date(`${value}T12:00:00`);

    return new Intl.DateTimeFormat('ru-RU', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(date);
}

onMounted(() => {
    const stored = window.localStorage.getItem(STORAGE_KEY);

    if (stored !== null) {
        try {
            const parsed = JSON.parse(stored);

            if (Array.isArray(parsed)) {
                const normalized = parsed
                    .filter(book => book && typeof book.title === 'string' && typeof book.author === 'string')
                    .map(book => ({
                        id: String(book.id || `book-${Date.now()}-${Math.random()}`),
                        title: book.title,
                        author: book.author,
                        finishedAt: typeof book.finishedAt === 'string' ? book.finishedAt : '',
                        rating: Math.min(5, Math.max(0, Number(book.rating) || 0)),
                        note: typeof book.note === 'string' ? book.note : '',
                        starter: book.id === starterBook.id,
                    }));

                const pushkin = normalized.find(book => book.id === starterBook.id);

                books.value = pushkin
                    ? normalized
                    : [{ ...starterBook }, ...normalized];
            }
        } catch {
            books.value = [{ ...starterBook }];
        }
    }

    selectedId.value = books.value[0]?.id ?? starterBook.id;
    hydrated.value = true;
    persist();
});

watch(books, persist, { deep: true });
</script>

<template>
    <AppLayout>
        <Head title="Читательский дневник" />

        <main class="reading-page">
            <a href="/projects" class="reading-back">← ПРОЕКТЫ</a>

            <header class="reading-header">
                <div>
                    <p class="reading-eyebrow">PROJECT 04 / READING LOG</p>
                    <h1>ЧИТАТЕЛЬСКИЙ<br><span>ДНЕВНИК</span></h1>
                </div>

                <div class="reading-header__note">
                    <span class="reading-header__mark">✦</span>
                    <p>
                        Книги живут прямо на полке. Выбери корешок, поставь оценку,
                        запиши дату и оставь пару строк — всё сохранится в этом браузере.
                    </p>
                </div>
            </header>

            <section class="reading-stats" aria-label="Статистика дневника">
                <article>
                    <strong>{{ String(books.length).padStart(2, '0') }}</strong>
                    <span>книг на полке</span>
                </article>
                <article>
                    <strong>{{ String(ratedCount).padStart(2, '0') }}</strong>
                    <span>с оценкой</span>
                </article>
                <article>
                    <strong>{{ averageRating }}</strong>
                    <span>средняя оценка</span>
                </article>
                <button type="button" class="reading-add-trigger" @click="adding = !adding">
                    {{ adding ? 'ЗАКРЫТЬ' : '+ ДОБАВИТЬ КНИГУ' }}
                </button>
            </section>

            <form v-if="adding" class="reading-add-form" @submit.prevent="addBook">
                <div class="reading-add-form__title">
                    <span>НОВАЯ КНИГА</span>
                    <b>Поставим ещё один корешок на полку.</b>
                </div>

                <label>
                    <span>Название</span>
                    <input v-model="newBook.title" type="text" maxlength="120" required placeholder="Например: Мастер и Маргарита">
                </label>

                <label>
                    <span>Автор</span>
                    <input v-model="newBook.author" type="text" maxlength="100" required placeholder="Имя автора">
                </label>

                <label>
                    <span>Дата прочтения</span>
                    <input v-model="newBook.finishedAt" type="date">
                </label>

                <button type="submit">ПОСТАВИТЬ НА ПОЛКУ →</button>
            </form>

            <div class="reading-workspace">
                <section class="reading-library" aria-label="Книжная полка">
                    <div class="reading-library__top">
                        <span>ЛИЧНАЯ БИБЛИОТЕКА</span>
                        <span>{{ books.length }} {{ books.length === 1 ? 'КНИГА' : 'КНИГ' }}</span>
                    </div>

                    <div
                        v-for="(row, rowIndex) in rows"
                        :key="rowIndex"
                        class="reading-shelf"
                    >
                        <div class="reading-shelf__back">
                            <div v-if="!row.length" class="reading-empty">
                                <span>ПОЛКА ЖДЁТ НОВЫХ КНИГ</span>
                            </div>

                            <button
                                v-for="book in row"
                                :key="book.id"
                                type="button"
                                class="reading-book"
                                :class="{
                                    'is-selected': selectedBook?.id === book.id,
                                    'is-starter': book.starter,
                                }"
                                :style="bookStyle(book)"
                                :aria-label="`${book.title}, ${book.author}`"
                                :aria-pressed="selectedBook?.id === book.id"
                                @click="selectBook(book)"
                            >
                                <span class="reading-book__ornament">◆</span>
                                <strong>{{ book.title }}</strong>
                                <small>{{ book.author }}</small>
                                <span v-if="book.rating" class="reading-book__rating">{{ book.rating }}★</span>
                                <span v-else class="reading-book__rating">—</span>
                            </button>
                        </div>
                        <div class="reading-shelf__plank" aria-hidden="true"></div>
                    </div>

                    <div class="reading-library__floor" aria-hidden="true">
                        <span></span><span></span><span></span>
                    </div>
                </section>

                <aside v-if="selectedBook" class="reading-card">
                    <div class="reading-card__cover" :style="bookStyle(selectedBook)">
                        <span class="reading-card__seal">{{ selectedBook.starter ? 'АСП' : 'READ' }}</span>
                        <small>{{ selectedBook.author }}</small>
                        <strong>{{ selectedBook.title }}</strong>
                        <span class="reading-card__ornament">✦ ◆ ✦</span>
                    </div>

                    <div class="reading-card__content">
                        <div class="reading-card__heading">
                            <div>
                                <p>{{ selectedBook.starter ? 'ПРЕДУСТАНОВЛЕННАЯ КНИГА' : 'КНИГА С ПОЛКИ' }}</p>
                                <h2>{{ selectedBook.title }}</h2>
                                <span>{{ selectedBook.author }}</span>
                            </div>
                            <span class="reading-card__number">
                                {{ String(books.findIndex(book => book.id === selectedBook.id) + 1).padStart(2, '0') }}
                            </span>
                        </div>

                        <div class="reading-rating">
                            <span>МОЯ ОЦЕНКА</span>
                            <div class="reading-stars" role="group" aria-label="Оценка от 1 до 5">
                                <button
                                    v-for="star in 5"
                                    :key="star"
                                    type="button"
                                    :class="{ 'is-active': star <= selectedBook.rating }"
                                    :aria-label="`Поставить ${star} из 5`"
                                    @click="setRating(star)"
                                >
                                    ★
                                </button>
                            </div>
                            <b>{{ selectedBook.rating ? `${selectedBook.rating} / 5` : 'без оценки' }}</b>
                        </div>

                        <label class="reading-field">
                            <span>Когда прочитана</span>
                            <input v-model="selectedBook.finishedAt" type="date">
                            <small>{{ prettyDate(selectedBook.finishedAt) }}</small>
                        </label>

                        <label class="reading-field reading-field--note">
                            <span>Что осталось после книги</span>
                            <textarea
                                v-model="selectedBook.note"
                                rows="7"
                                maxlength="1200"
                                placeholder="Любимая мысль, впечатление, цитата своими словами, что хочется запомнить…"
                            ></textarea>
                            <small>{{ selectedBook.note.length }} / 1200</small>
                        </label>

                        <a
                            v-if="selectedBook.starter"
                            href="/projects/pushkin-fairytales"
                            class="reading-pushkin-link"
                        >
                            ОТКРЫТЬ «СКАЗКИ ПУШКИНА» →
                        </a>

                        <button
                            v-if="!selectedBook.starter"
                            type="button"
                            class="reading-remove"
                            @click="removeSelected"
                        >
                            УБРАТЬ КНИГУ С ПОЛКИ
                        </button>
                    </div>
                </aside>
            </div>

            <footer class="reading-footer">
                <span>ЧИТАТЬ</span>
                <span>ЗАПОМИНАТЬ</span>
                <span>ОЦЕНИВАТЬ</span>
                <span>ВОЗВРАЩАТЬСЯ</span>
            </footer>
        </main>
    </AppLayout>
</template>

<style scoped>
.reading-page {
    --rd-ink: #17130f;
    --rd-paper: #f2e7ce;
    --rd-red: #9f2630;
    --rd-gold: #c19b4d;
    --rd-wood: #6d442b;
    --rd-wood-dark: #3f291e;
    width: min(100%, 1420px);
    margin: 8px auto 0;
}

.reading-back {
    display: inline-flex;
    margin-bottom: 18px;
    color: var(--rd-ink);
    font-size: 11px;
    font-weight: 900;
    letter-spacing: .12em;
    text-decoration: none;
}

.reading-header {
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(280px, .55fr);
    gap: 44px;
    align-items: end;
    margin-bottom: 26px;
}

.reading-eyebrow {
    margin: 0 0 12px;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: .17em;
}

.reading-header h1 {
    margin: 0;
    font-family: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
    font-size: clamp(62px, 7.5vw, 116px);
    line-height: .78;
    letter-spacing: -.015em;
}

.reading-header h1 span {
    color: var(--rd-red);
}

.reading-header__note {
    display: grid;
    grid-template-columns: 46px 1fr;
    gap: 14px;
    align-items: start;
    padding-top: 17px;
    border-top: 4px solid var(--rd-ink);
}

.reading-header__note p {
    margin: 0;
    color: #5b5144;
    font-size: 13px;
    font-weight: 750;
    line-height: 1.55;
}

.reading-header__mark {
    display: grid;
    width: 42px;
    height: 42px;
    place-items: center;
    border: 3px solid var(--rd-ink);
    background: var(--rd-red);
    color: #fff;
}

.reading-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(120px, 1fr)) minmax(180px, auto);
    border: 4px solid var(--rd-ink);
    background: var(--rd-paper);
    box-shadow: 7px 7px 0 var(--rd-ink);
}

.reading-stats article {
    display: grid;
    min-height: 82px;
    place-content: center;
    padding: 12px 18px;
    border-right: 3px solid var(--rd-ink);
    text-align: center;
}

.reading-stats strong {
    font-family: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
    font-size: 34px;
    line-height: 1;
}

.reading-stats span {
    margin-top: 5px;
    font-size: 8px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.reading-add-trigger {
    min-height: 82px;
    padding: 14px 22px;
    border: 0;
    background: var(--rd-red);
    color: #fff;
    cursor: pointer;
    font: inherit;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: .06em;
}

.reading-add-form {
    display: grid;
    grid-template-columns: 1.2fr 1.2fr 1fr .8fr;
    gap: 12px;
    align-items: end;
    margin-top: 20px;
    padding: 18px;
    border: 4px solid var(--rd-ink);
    background: #e8d8b8;
    box-shadow: 7px 7px 0 var(--rd-red);
}

.reading-add-form__title {
    grid-column: 1 / -1;
    display: flex;
    align-items: baseline;
    gap: 12px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--rd-ink);
}

.reading-add-form__title span {
    font-size: 12px;
    font-weight: 1000;
    letter-spacing: .1em;
}

.reading-add-form__title b {
    color: #665847;
    font-family: Georgia, serif;
    font-size: 12px;
    font-style: italic;
    font-weight: 500;
}

.reading-add-form label,
.reading-field {
    display: grid;
    gap: 6px;
}

.reading-add-form label > span,
.reading-field > span {
    font-size: 8px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.reading-add-form input,
.reading-field input,
.reading-field textarea {
    width: 100%;
    min-height: 48px;
    border: 3px solid var(--rd-ink);
    border-radius: 0;
    background: #fffaf0;
    color: var(--rd-ink);
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    outline: 0;
}

.reading-add-form input,
.reading-field input {
    padding: 0 12px;
}

.reading-add-form input:focus,
.reading-field input:focus,
.reading-field textarea:focus {
    box-shadow: 4px 4px 0 var(--rd-red);
}

.reading-add-form > button {
    min-height: 48px;
    padding: 8px 14px;
    border: 3px solid var(--rd-ink);
    background: var(--rd-ink);
    color: #fff;
    cursor: pointer;
    font: inherit;
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .05em;
}

.reading-workspace {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(340px, .65fr);
    gap: 28px;
    align-items: start;
    margin-top: 30px;
}

.reading-library {
    position: relative;
    overflow: hidden;
    border: 6px solid var(--rd-ink);
    background: #c49a69;
    box-shadow: 12px 12px 0 var(--rd-red);
}

.reading-library::before {
    position: absolute;
    inset: 0;
    background:
        repeating-linear-gradient(90deg, rgba(66,37,21,.08) 0 2px, transparent 2px 44px),
        linear-gradient(90deg, rgba(255,255,255,.12), transparent 25%, rgba(0,0,0,.08));
    content: "";
    pointer-events: none;
}

.reading-library__top {
    position: relative;
    z-index: 3;
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 13px 16px;
    border-bottom: 5px solid var(--rd-ink);
    background: var(--rd-paper);
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .1em;
}

.reading-shelf {
    position: relative;
    z-index: 2;
}

.reading-shelf__back {
    display: flex;
    min-height: 238px;
    align-items: end;
    gap: 5px;
    overflow-x: auto;
    padding: 30px 26px 0;
    background:
        linear-gradient(90deg, rgba(255,255,255,.08), transparent 22%, rgba(45,24,13,.11)),
        #a9784d;
    scrollbar-width: thin;
}

.reading-shelf__plank {
    height: 30px;
    border-top: 5px solid var(--rd-ink);
    border-bottom: 5px solid var(--rd-ink);
    background:
        repeating-linear-gradient(90deg, #765037 0 80px, #6b462f 80px 84px),
        var(--rd-wood);
    box-shadow: inset 0 8px 0 rgba(255,255,255,.06), 0 10px 12px rgba(40,22,12,.22);
}

.reading-book {
    position: relative;
    display: flex;
    width: var(--book-width);
    min-width: var(--book-width);
    height: var(--book-height);
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
    padding: 12px 8px 10px;
    border: 3px solid var(--rd-ink);
    border-bottom-width: 5px;
    border-radius: 3px 3px 0 0;
    background:
        linear-gradient(90deg, rgba(255,255,255,.12), transparent 18% 82%, rgba(0,0,0,.12)),
        var(--book-color);
    color: var(--book-accent);
    cursor: pointer;
    transform: rotate(var(--book-lean));
    transform-origin: bottom center;
    transition: transform .18s ease, translate .18s ease, box-shadow .18s ease;
    box-shadow: inset 4px 0 0 rgba(255,255,255,.09), inset -4px 0 0 rgba(0,0,0,.12);
}

.reading-book::before,
.reading-book::after {
    position: absolute;
    right: 5px;
    left: 5px;
    height: 2px;
    background: var(--book-accent);
    content: "";
    opacity: .72;
}

.reading-book::before { top: 28px; }
.reading-book::after { bottom: 31px; }

.reading-book:hover,
.reading-book.is-selected {
    z-index: 4;
    transform: rotate(0deg) translateY(-12px);
    box-shadow: 6px 6px 0 var(--rd-ink);
}

.reading-book.is-selected {
    outline: 4px solid var(--rd-gold);
    outline-offset: 2px;
}

.reading-book__ornament {
    font-size: 10px;
}

.reading-book strong {
    display: -webkit-box;
    overflow: hidden;
    font-family: Georgia, "Times New Roman", serif;
    font-size: 11px;
    line-height: 1.05;
    text-align: center;
    text-overflow: ellipsis;
    writing-mode: vertical-rl;
    transform: rotate(180deg);
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
}

.reading-book small {
    display: none;
}

.reading-book__rating {
    font-size: 9px;
    font-weight: 1000;
}

.reading-empty {
    display: grid;
    width: 100%;
    height: 175px;
    place-items: center;
    color: rgba(23,19,15,.58);
    font-size: 10px;
    font-weight: 900;
    letter-spacing: .12em;
}

.reading-library__floor {
    position: relative;
    z-index: 2;
    display: flex;
    gap: 20px;
    height: 44px;
    align-items: center;
    justify-content: center;
    background: var(--rd-wood-dark);
}

.reading-library__floor span {
    width: 42px;
    height: 5px;
    background: rgba(242,231,206,.25);
}

.reading-card {
    position: sticky;
    top: 20px;
    overflow: hidden;
    border: 5px solid var(--rd-ink);
    background: var(--rd-paper);
    box-shadow: 10px 10px 0 var(--rd-ink);
}

.reading-card__cover {
    position: relative;
    display: flex;
    min-height: 220px;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 28px;
    border-bottom: 5px solid var(--rd-ink);
    background:
        linear-gradient(90deg, rgba(255,255,255,.12), transparent 20% 80%, rgba(0,0,0,.15)),
        var(--book-color);
    color: var(--book-accent);
    text-align: center;
}

.reading-card__cover::before {
    position: absolute;
    inset: 14px;
    border: 2px solid var(--book-accent);
    content: "";
    opacity: .72;
}

.reading-card__seal {
    position: absolute;
    top: 27px;
    right: 27px;
    display: grid;
    width: 42px;
    height: 42px;
    place-items: center;
    border: 2px solid var(--book-accent);
    border-radius: 50%;
    font-family: Georgia, serif;
    font-size: 9px;
    font-weight: 900;
}

.reading-card__cover small {
    position: relative;
    z-index: 1;
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .12em;
    text-transform: uppercase;
}

.reading-card__cover strong {
    position: relative;
    z-index: 1;
    max-width: 270px;
    margin-top: 18px;
    font-family: Georgia, "Times New Roman", serif;
    font-size: clamp(28px, 3vw, 42px);
    line-height: .98;
}

.reading-card__ornament {
    position: relative;
    z-index: 1;
    margin-top: 22px;
    font-size: 11px;
    letter-spacing: .2em;
}

.reading-card__content {
    display: grid;
    gap: 22px;
    padding: 24px;
}

.reading-card__heading {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 14px;
}

.reading-card__heading p {
    margin: 0 0 6px;
    color: var(--rd-red);
    font-size: 8px;
    font-weight: 900;
    letter-spacing: .1em;
}

.reading-card__heading h2 {
    margin: 0;
    font-family: Georgia, "Times New Roman", serif;
    font-size: 27px;
    line-height: 1;
}

.reading-card__heading div > span {
    display: block;
    margin-top: 7px;
    color: #695d4d;
    font-size: 11px;
    font-weight: 800;
}

.reading-card__number {
    font-family: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
    font-size: 34px;
}

.reading-rating {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 8px 12px;
    align-items: center;
    padding: 14px;
    border: 3px solid var(--rd-ink);
    background: #e8d8b8;
}

.reading-rating > span {
    font-size: 8px;
    font-weight: 900;
    letter-spacing: .09em;
}

.reading-rating > b {
    grid-row: 1 / 3;
    grid-column: 2;
    font-size: 10px;
    text-transform: uppercase;
}

.reading-stars {
    display: flex;
    gap: 3px;
}

.reading-stars button {
    padding: 0 2px;
    border: 0;
    background: transparent;
    color: #b7a88b;
    cursor: pointer;
    font-size: 26px;
    line-height: 1;
}

.reading-stars button.is-active {
    color: var(--rd-red);
}

.reading-field textarea {
    min-height: 146px;
    resize: vertical;
    padding: 12px;
    line-height: 1.5;
}

.reading-field small {
    color: #776957;
    font-size: 9px;
    font-weight: 750;
    text-align: right;
}

.reading-pushkin-link,
.reading-remove {
    display: flex;
    min-height: 48px;
    align-items: center;
    justify-content: center;
    padding: 10px 14px;
    border: 3px solid var(--rd-ink);
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .05em;
    text-align: center;
    text-decoration: none;
}

.reading-pushkin-link {
    background: var(--rd-red);
    color: #fff;
}

.reading-remove {
    background: transparent;
    color: var(--rd-red);
    cursor: pointer;
    font: inherit;
    font-size: 9px;
    font-weight: 900;
}

.reading-footer {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 16px;
    margin-top: 32px;
    padding: 15px 0;
    border-top: 3px solid var(--rd-ink);
    border-bottom: 3px solid var(--rd-ink);
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .1em;
}

@media (max-width: 1080px) {
    .reading-header {
        grid-template-columns: 1fr;
        gap: 22px;
    }

    .reading-header__note {
        max-width: 700px;
    }

    .reading-workspace {
        grid-template-columns: 1fr;
    }

    .reading-card {
        position: static;
        display: grid;
        grid-template-columns: minmax(260px, .72fr) 1fr;
    }

    .reading-card__cover {
        min-height: 100%;
        border-right: 5px solid var(--rd-ink);
        border-bottom: 0;
    }
}

@media (max-width: 760px) {
    .reading-header h1 {
        font-size: clamp(52px, 16vw, 82px);
    }

    .reading-stats {
        grid-template-columns: repeat(3, 1fr);
    }

    .reading-stats article {
        min-width: 0;
        padding: 10px 6px;
    }

    .reading-stats article:nth-child(3) {
        border-right: 0;
    }

    .reading-add-trigger {
        grid-column: 1 / -1;
        border-top: 3px solid var(--rd-ink);
    }

    .reading-add-form {
        grid-template-columns: 1fr;
    }

    .reading-add-form__title {
        display: grid;
    }

    .reading-library {
        border-width: 5px;
        box-shadow: 8px 8px 0 var(--rd-red);
    }

    .reading-shelf__back {
        min-height: 220px;
        padding-right: 18px;
        padding-left: 18px;
    }

    .reading-card {
        display: block;
    }

    .reading-card__cover {
        min-height: 250px;
        border-right: 0;
        border-bottom: 5px solid var(--rd-ink);
    }
}

@media (max-width: 420px) {
    .reading-header__note {
        grid-template-columns: 36px 1fr;
    }

    .reading-header__mark {
        width: 34px;
        height: 34px;
    }

    .reading-stats strong {
        font-size: 28px;
    }

    .reading-stats span {
        font-size: 7px;
    }

    .reading-shelf__back {
        min-height: 206px;
    }

    .reading-book {
        --book-width: 54px !important;
        height: calc(var(--book-height) - 16px);
    }

    .reading-card__content {
        padding: 20px 16px;
    }

    .reading-footer {
        display: grid;
        grid-template-columns: 1fr 1fr;
        text-align: center;
    }
}
</style>
