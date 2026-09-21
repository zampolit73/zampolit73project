<script setup>
defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: (value) => ['primary', 'secondary'].includes(value),
    },
    type: {
        type: String,
        default: 'button',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <button
        :type="type"
        class="ds-button"
        :class="{ 'ds-button--secondary': variant === 'secondary' }"
        :disabled="disabled"
    >
        <slot />
    </button>
</template>
