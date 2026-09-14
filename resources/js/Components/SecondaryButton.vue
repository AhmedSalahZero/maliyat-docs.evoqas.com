<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
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
        class="mm-btn mm-btn--outline"
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
    font-weight: 800;
    font-size: 0.875rem;
    border-radius: var(--radius-pill);
    padding: 0 1.25rem;
    min-height: var(--touch-target);
    cursor: pointer;
    transition: transform var(--transition-fast), background var(--transition-fast), border-color var(--transition-fast);
    white-space: nowrap;
}
.fk-btn:active   { transform: scale(0.97); }
.fk-btn:disabled { opacity: 0.55; cursor: not-allowed; pointer-events: none; }

.fk-btn--outline {
    background: transparent;
    border: 2px solid var(--color-primary);
    color: var(--color-primary);
}
.fk-btn--outline:hover:not(:disabled) {
    background: var(--color-primary-soft);
}
</style>