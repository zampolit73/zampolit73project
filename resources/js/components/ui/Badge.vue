<script setup>
defineProps({
    variant: { type: String, default: 'neutral' },
});
</script>

<template>
    <span class="ds-badge" :class="'ds-badge--' + variant"><slot /></span>
</template>
