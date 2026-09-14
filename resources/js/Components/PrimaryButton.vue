<script setup>
defineProps({
    type: {
        type: String,
        default: 'submit',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
})
</script>

<template>
    <button
        :type="type"
        :disabled="disabled"
        class="mm-btn mm-btn--primary"
    >
        <slot />
    </button>
</template>

<style scoped>
.fk-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    font-family: 'Nunito', sans-serif;
    font-weight: 900;
    font-size: 0.875rem;
    border-radius: var(--radius-pill);
    padding: 0 1.25rem;
    min-height: var(--touch-target);
    border: 2px solid transparent;
    cursor: pointer;
    transition: transform var(--transition-fast), box-shadow var(--transition-fast), background var(--transition-fast);
    white-space: nowrap;
    letter-spacing: 0.02em;
}
.fk-btn:active  { transform: scale(0.97); }
.fk-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
    pointer-events: none;
}

.fk-btn--primary {
    background: var(--color-primary);
    color: #ffffff;
    box-shadow: var(--shadow-button);
}
.fk-btn--primary:hover:not(:disabled) {
    background: var(--color-primary-dark);
    box-shadow: var(--shadow-float);
}
</style>