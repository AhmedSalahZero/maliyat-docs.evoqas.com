<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ConfirmDialog.vue
//  Location: resources/js/Components/App/ConfirmDialog.vue
//
//  <ConfirmDialog v-model:open="showConfirm" :title="..." :message="..."
//                  danger @confirm="doTheThing" />
//
//  Replaces window.confirm()/window.alert() for every consequential
//  action in the app (delete, an edit that creates a deficit/
//  surplus, etc.). Native confirm() is blocked or behaves
//  inconsistently in some embedded/preview browser contexts, and
//  doesn't match the app's visual language — this does, and is
//  reliable everywhere.
// ══════════════════════════════════════════════════════════════════

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    message: { type: String, required: true },
    confirmLabel: { type: String, default: 'Continue' },
    cancelLabel: { type: String, default: 'Cancel' },
    danger: { type: Boolean, default: false },
});

const emit = defineEmits(['update:open', 'confirm']);

function cancel() {
    emit('update:open', false);
}

function confirm() {
    emit('update:open', false);
    emit('confirm');
}
</script>

<template>
    <transition name="fade-in">
        <div v-if="props.open" class="modal-backdrop" @click.self="cancel">
            <div class="modal-sheet confirm-dialog slide-up">
                <div class="modal-sheet__handle"></div>
                <h2 class="confirm-dialog__title" :class="{ 'confirm-dialog__title--danger': props.danger }">{{ props.title }}</h2>
                <p class="confirm-dialog__message">{{ props.message }}</p>
                <div class="confirm-dialog__actions">
                    <button type="button" class="btn btn-ghost" @click="cancel">{{ props.cancelLabel }}</button>
                    <button type="button" class="btn" :class="props.danger ? 'btn-danger' : 'btn-primary'" @click="confirm">{{ props.confirmLabel }}</button>
                </div>
            </div>
        </div>
    </transition>
</template>

<style scoped>
.confirm-dialog { max-width: 400px; margin: 0 auto; }
.confirm-dialog__title { font-family: var(--font-heading); font-size: 16.5px; font-weight: 700; color: var(--color-text-primary); margin-bottom: 8px; }
.confirm-dialog__title--danger { color: var(--color-danger); }
.confirm-dialog__message { font-size: 13.5px; color: var(--color-text-secondary); line-height: 1.55; margin-bottom: 18px; }
.confirm-dialog__actions { display: flex; gap: 10px; }
.confirm-dialog__actions .btn { flex: 1; }
</style>
