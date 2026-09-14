<script setup>
defineProps({
    message: {
        type: String,
    },
})
</script>

<template>
    <div v-show="message">
        <p class="fk-error">{{ message }}</p>
    </div>
</template>

<style scoped>
.fk-error {
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--color-danger);
    margin-top: 0.3rem;
    line-height: 1.4;
}
</style>