<script setup>
import { onMounted, ref } from 'vue'

const model = defineModel({
    type: String,
    required: true,
})

const input = ref(null)

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus()
    }
})

defineExpose({ focus: () => input.value.focus() })
</script>

<template>
    <input
        class="fk-input"
        v-model="model"
        ref="input"
    />
</template>

<style scoped>
.fk-input {
    width: 100%;
    background: var(--color-surface);
    border: 2px solid var(--color-border-input);
    border-radius: var(--radius-md);
    padding: 0.7rem 0.9rem;
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--color-text-primary);
    font-family: 'Nunito', sans-serif;
    outline: none;
    transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
    min-height: var(--touch-target);
}
.fk-input::placeholder {
    color: var(--color-text-muted);
    font-weight: 700;
}
.fk-input:focus {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px var(--color-primary-soft);
}
.fk-input:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
</style>