<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import JSZip from 'jszip';
import AppLayout from '../layouts/AppLayout.vue';
import {
    archiveName,
    convertRgbaToMip,
    planQuakeResize,
    reserveTextureName,
    validateTextureDimensions,
} from '../lib/quakeMip.js';

const fileInput = ref(null);
const files = ref([]);
const errors = ref([]);
const resizeNotes = ref([]);
const converting = ref(false);
const progress = ref(0);
const progressLabel = ref('');
const convertedCount = ref(0);
const lastArchive = ref('');

const hasFiles = computed(() => files.value.length > 0);
const canConvert = computed(() => hasFiles.value && !converting.value);

function resetResult() {
    errors.value = [];
    resizeNotes.value = [];
    progress.value = 0;
    progressLabel.value = '';
    convertedCount.value = 0;
    lastArchive.value = '';
}

function setSelectedFiles(list) {
    const selected = Array.from(list)
        .filter((file) => /\.bmp$/i.test(file.name))
        .sort((a, b) => a.name.localeCompare(b.name, 'ru'));

    files.value = selected;
    resetResult();

    if (selected.length === 0) {
        errors.value = [{ file: 'Папка', message: 'BMP-файлы не найдены.' }];
    }
}

async function collectDirectory(handle, output = []) {
    for await (const entry of handle.values()) {
        if (entry.kind === 'file' && /\.bmp$/i.test(entry.name)) {
            output.push(await entry.getFile());
        } else if (entry.kind === 'directory') {
            await collectDirectory(entry, output);
        }
    }

    return output;
}

async function chooseImages() {
    if (converting.value) {
        return;
    }

    if ('showDirectoryPicker' in window) {
        try {
            const directory = await window.showDirectoryPicker({ mode: 'read' });
            setSelectedFiles(await collectDirectory(directory));
            return;
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }
        }
    }

    if (fileInput.value) {
        fileInput.value.value = '';
        fileInput.value.click();
    }
}

function handleFileInput(event) {
    setSelectedFiles(event.target.files || []);
}

async function loadBmp(file) {
    let source;
    let cleanup = () => {};

    if ('createImageBitmap' in window) {
        source = await createImageBitmap(file);
        cleanup = () => source.close();
    } else {
        const url = URL.createObjectURL(file);
        const image = new Image();

        await new Promise((resolve, reject) => {
            image.onload = resolve;
            image.onerror = () => reject(new Error('Браузер не смог прочитать BMP.'));
            image.src = url;
        });

        source = image;
        cleanup = () => URL.revokeObjectURL(url);
    }

    return { source, cleanup };
}

function drawWithEdgePadding(source, plan) {
    const drawWidth = Math.max(1, Math.round(plan.drawWidth));
    const drawHeight = Math.max(1, Math.round(plan.drawHeight));
    const offsetX = Math.floor((plan.targetWidth - drawWidth) / 2);
    const offsetY = Math.floor((plan.targetHeight - drawHeight) / 2);

    const scaled = document.createElement('canvas');
    scaled.width = drawWidth;
    scaled.height = drawHeight;

    const scaledContext = scaled.getContext('2d', { willReadFrequently: true });
    if (!scaledContext) {
        throw new Error('Canvas недоступен в этом браузере.');
    }

    scaledContext.imageSmoothingEnabled = true;
    scaledContext.imageSmoothingQuality = 'high';
    scaledContext.drawImage(source, 0, 0, drawWidth, drawHeight);

    const canvas = document.createElement('canvas');
    canvas.width = plan.targetWidth;
    canvas.height = plan.targetHeight;

    const context = canvas.getContext('2d', { willReadFrequently: true });
    if (!context) {
        throw new Error('Canvas недоступен в этом браузере.');
    }

    context.drawImage(scaled, offsetX, offsetY);

    const right = plan.targetWidth - offsetX - drawWidth;
    const bottom = plan.targetHeight - offsetY - drawHeight;

    if (offsetX > 0) {
        context.drawImage(scaled, 0, 0, 1, drawHeight, 0, offsetY, offsetX, drawHeight);
    }
    if (right > 0) {
        context.drawImage(scaled, drawWidth - 1, 0, 1, drawHeight, offsetX + drawWidth, offsetY, right, drawHeight);
    }
    if (offsetY > 0) {
        context.drawImage(scaled, 0, 0, drawWidth, 1, offsetX, 0, drawWidth, offsetY);
    }
    if (bottom > 0) {
        context.drawImage(scaled, 0, drawHeight - 1, drawWidth, 1, offsetX, offsetY + drawHeight, drawWidth, bottom);
    }

    if (offsetX > 0 && offsetY > 0) {
        context.drawImage(scaled, 0, 0, 1, 1, 0, 0, offsetX, offsetY);
    }
    if (right > 0 && offsetY > 0) {
        context.drawImage(scaled, drawWidth - 1, 0, 1, 1, offsetX + drawWidth, 0, right, offsetY);
    }
    if (offsetX > 0 && bottom > 0) {
        context.drawImage(scaled, 0, drawHeight - 1, 1, 1, 0, offsetY + drawHeight, offsetX, bottom);
    }
    if (right > 0 && bottom > 0) {
        context.drawImage(scaled, drawWidth - 1, drawHeight - 1, 1, 1, offsetX + drawWidth, offsetY + drawHeight, right, bottom);
    }

    return context.getImageData(0, 0, plan.targetWidth, plan.targetHeight).data;
}

async function decodeBmp(file) {
    const { source, cleanup } = await loadBmp(file);

    try {
        const sourceWidth = source.width || source.naturalWidth;
        const sourceHeight = source.height || source.naturalHeight;
        const plan = planQuakeResize(sourceWidth, sourceHeight);

        validateTextureDimensions(plan.targetWidth, plan.targetHeight);

        return {
            width: plan.targetWidth,
            height: plan.targetHeight,
            sourceWidth,
            sourceHeight,
            resized: plan.resized,
            rgba: drawWithEdgePadding(source, plan),
        };
    } finally {
        cleanup();
    }
}

function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();

    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

async function convertAll() {
    if (!canConvert.value) {
        return;
    }

    converting.value = true;
    errors.value = [];
    resizeNotes.value = [];
    convertedCount.value = 0;
    lastArchive.value = '';
    progress.value = 0;

    const zip = new JSZip();
    const usedNames = new Set();
    let success = 0;

    try {
        for (let index = 0; index < files.value.length; index += 1) {
            const file = files.value[index];

            progressLabel.value = 'Конвертация: ' + file.name;
            progress.value = Math.round((index / files.value.length) * 85);

            try {
                const decoded = await decodeBmp(file);
                const textureName = reserveTextureName(file.name, usedNames);
                const mip = convertRgbaToMip(decoded.rgba, decoded.width, decoded.height, textureName);

                zip.file(textureName + '.mip', mip);
                success += 1;

                if (decoded.resized) {
                    resizeNotes.value.push({
                        file: file.name,
                        from: decoded.sourceWidth + '×' + decoded.sourceHeight,
                        to: decoded.width + '×' + decoded.height,
                    });
                }
            } catch (error) {
                errors.value.push({
                    file: file.name,
                    message: error instanceof Error ? error.message : 'Неизвестная ошибка конвертации.',
                });
            }

            if ((index & 3) === 3) {
                await new Promise((resolve) => requestAnimationFrame(resolve));
            }
        }

        if (success === 0) {
            progress.value = 0;
            progressLabel.value = 'Нет файлов, пригодных для конвертации.';
            return;
        }

        progressLabel.value = 'Упаковка ZIP…';

        const blob = await zip.generateAsync(
            {
                type: 'blob',
                compression: 'DEFLATE',
                compressionOptions: { level: 6 },
            },
            (metadata) => {
                progress.value = 85 + Math.round(metadata.percent * 0.15);
            },
        );

        const filename = archiveName();
        downloadBlob(blob, filename);

        convertedCount.value = success;
        lastArchive.value = filename;
        progress.value = 100;
        progressLabel.value = 'Готово.';
    } finally {
        converting.value = false;
    }
}
</script>

<template>
    <AppLayout>
        <Head title="BMP → MIP / Quake 1" />

        <main class="mip-page">
            <div class="quake-watermark" aria-hidden="true">
                <div class="quake-watermark__ring"></div>
                <div class="quake-watermark__word">QUAKE</div>
            </div>

            <Link href="/projects" class="mip-back">← ПРОЕКТЫ</Link>

            <section class="mip-converter" aria-labelledby="mip-title">
                <p class="mip-converter__eyebrow">QUAKE 1 / MIP TEXTURE CONVERTER</p>
                <h1 id="mip-title">BMP → MIP</h1>

                <p class="mip-converter__intro">
                    Массовая конвертация выполняется прямо в браузере. Некратные 16 размеры автоматически
                    вписываются в ближайший допустимый размер без искажения пропорций.
                </p>

                <input
                    ref="fileInput"
                    class="mip-file-input"
                    type="file"
                    accept=".bmp,image/bmp"
                    multiple
                    webkitdirectory
                    directory
                    @change="handleFileInput"
                >

                <button
                    type="button"
                    class="mip-upload"
                    :disabled="converting"
                    @click="chooseImages"
                >
                    Загрузить изображения
                </button>

                <div v-if="hasFiles" class="mip-selection" aria-live="polite">
                    <strong>{{ files.length }} BMP</strong>
                    <span>{{ files.slice(0, 3).map((file) => file.name).join(' · ') }}{{ files.length > 3 ? ' · …' : '' }}</span>
                </div>

                <button
                    v-if="hasFiles"
                    type="button"
                    class="mip-convert"
                    :disabled="!canConvert"
                    @click="convertAll"
                >
                    {{ converting ? 'Конвертация…' : 'Конвертировать' }}
                </button>

                <div v-if="converting || progress > 0" class="mip-progress" aria-live="polite">
                    <div class="mip-progress__line">
                        <span :style="{ width: progress + '%' }"></span>
                    </div>
                    <div class="mip-progress__text">{{ progress }}% — {{ progressLabel }}</div>
                </div>

                <p v-if="convertedCount" class="mip-success">
                    Готово: {{ convertedCount }} файлов → <strong>{{ lastArchive }}</strong>
                </p>

                <div v-if="resizeNotes.length" class="mip-resize-notes">
                    <strong>Авто-ресайз: {{ resizeNotes.length }}</strong>
                    <ul>
                        <li v-for="item in resizeNotes.slice(0, 8)" :key="item.file">
                            <b>{{ item.file }}</b> — {{ item.from }} → {{ item.to }}
                        </li>
                    </ul>
                    <p v-if="resizeNotes.length > 8">И ещё {{ resizeNotes.length - 8 }} файлов.</p>
                </div>

                <div v-if="errors.length" class="mip-errors" role="status">
                    <strong>Пропущено: {{ errors.length }}</strong>
                    <ul>
                        <li v-for="error in errors.slice(0, 8)" :key="error.file + error.message">
                            <b>{{ error.file }}</b> — {{ error.message }}
                        </li>
                    </ul>
                    <p v-if="errors.length > 8">И ещё {{ errors.length - 8 }} ошибок.</p>
                </div>

                <div class="mip-rules">
                    <span>Авто-ресайз без искажения</span>
                    <span>Размеры: кратны 16</span>
                    <span>4 mip-уровня</span>
                    <span>Quake 1 palette / 256</span>
                </div>
            </section>
        </main>
    </AppLayout>
</template>
