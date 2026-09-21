<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    eyebrow: { type: String, default: '' },
});
</script>
<template>
    <header class="ds-page-header">
        <div>
            <p v-if="eyebrow" class="ds-label">{{ eyebrow }}</p>
            <h1 class="ds-page-title">{{ title }}</h1>
            <p v-if="description" class="ds-page-description">{{ description }}</p>
        </div>
        <div v-if="$slots.actions" class="ds-page-header__actions"><slot name="actions" /></div>
    </header>
</template>
