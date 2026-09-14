<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — RenameModal.vue
//  Location: resources/js/Components/App/RenameModal.vue
//
//  <RenameModal v-model:open="show" :current-name="customer.name"
//               :saving="renaming" @save="doRename" />
//
//  Generic on purpose — Sales/Index.vue uses it for the Customer
//  combo today; the same component works for renaming a Vendor or
//  Item later without changes, the caller just wires up its own
//  PATCH endpoint and local list update.
// ══════════════════════════════════════════════════════════════════

import { ref, watch, nextTick } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Rename' },
    currentName: { type: String, default: '' },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(['update:open', 'save']);

const name = ref(props.currentName);
const inputRef = ref(null);

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        name.value = props.currentName;
        nextTick(() => inputRef.value?.focus());
    }
});

function cancel() {
    emit('update:open', false);
}

function save() {
    const trimmed = name.value.trim();
    if (!trimmed) return;
    emit('save', trimmed);
}
</script>

<template>
    <transition name="fade-in">
        <div v-if="props.open" class="modal-backdrop" @click.self="cancel">
            <div class="modal-sheet rename-modal slide-up">
                <div class="modal-sheet__handle"></div>
                <h2 class="rename-modal__title">{{ props.title }}</h2>
                <div class="form-group">
                    <input
                        ref="inputRef"
                        v-model="name"
                        type="text"
                        class="form-input"
                        :disabled="props.saving"
                        @keydown.enter.prevent="save"
                    >
                </div>
                <div class="rename-modal__actions">
                    <button type="button" class="btn btn-ghost" @click="cancel">Cancel</button>
                    <button type="button" class="btn btn-primary" :disabled="props.saving || !name.trim()" @click="save">
                        {{ props.saving ? 'Saving…' : 'Save' }}
                    </button>
                </div>
            </div>
        </div>
    </transition>
</template>

<style scoped>
.rename-modal { max-width: 360px; margin: 0 auto; }
.rename-modal__title { font-family: var(--font-heading); font-size: 16px; font-weight: 700; color: var(--color-text-primary); margin-bottom: 12px; }
.rename-modal__actions { display: flex; gap: 10px; margin-top: 6px; }
.rename-modal__actions .btn { flex: 1; }
</style>
