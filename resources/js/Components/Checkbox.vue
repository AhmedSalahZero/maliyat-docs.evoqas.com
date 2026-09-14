<script setup>
import { computed } from 'vue'

const emit = defineEmits(['update:checked'])

const props = defineProps({
    checked: {
        type: [Array, Boolean],
        required: true,
    },
    value: {
        default: null,
    },
})

const proxyChecked = computed({
    get() {
        return props.checked
    },
    set(val) {
        emit('update:checked', val)
    },
})
</script>

<template>
    <button
        type="button"
        class="fk-checkbox"
        :class="{ 'fk-checkbox--checked': proxyChecked }"
        role="checkbox"
        :aria-checked="!!proxyChecked"
        @click="proxyChecked = !proxyChecked"
    >
        <svg
            v-if="proxyChecked"
            class="fk-check-icon"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
            stroke-width="3.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <polyline points="20 6 9 17 4 12"/>
        </svg>
    </button>
</template>

<style scoped>
.fk-checkbox {
    width: 20px;
    height: 20px;
    border-radius: 5px;
    background: var(--color-surface);
    border: 2px solid var(--color-border-input);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background var(--transition-fast), border-color var(--transition-fast);
    flex-shrink: 0;
    padding: 0;
    color: #ffffff;
}
.fk-checkbox--checked {
    background: var(--color-primary);
    border-color: var(--color-primary);
}
.fk-checkbox:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px var(--color-primary-soft);
}
.fk-check-icon {
    width: 12px;
    height: 12px;
}
</style>