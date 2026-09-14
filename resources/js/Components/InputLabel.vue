<script setup>
defineProps({
    value: {
        type: String,
    },
})
</script>

<template>
    <label class="fk-label">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>

<style scoped>
.fk-label {
    display: block;
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--color-primary);
    margin-bottom: 0.4rem;
    transition: color var(--transition-slow);
}
</style>