<script setup>
defineProps({
    modelValue: { type: [String, Number], default: '' },
    type: { type: String, default: 'text' },
    id: { type: String, default: undefined },
    name: { type: String, default: undefined },
    autocomplete: { type: String, default: undefined },
    placeholder: { type: String, default: undefined },
    disabled: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
</script>
<template>
    <input :id="id" :name="name" :type="type" :value="modelValue" :autocomplete="autocomplete" :placeholder="placeholder" :disabled="disabled" :required="required" class="ds-input" @input="emit('update:modelValue', $event.target.value)">
</template>
