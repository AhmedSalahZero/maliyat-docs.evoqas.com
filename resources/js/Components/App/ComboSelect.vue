<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ComboSelect.vue
//  Location: resources/js/Components/App/ComboSelect.vue
//
//  Dumb-ish combo box: renders `options` as a <select> matching the
//  prototype's .blank-select styling, with a trailing "+ Add new…"
//  option (omit it entirely with :allow-create="false", for pickers
//  where every option must already exist elsewhere — e.g.
//  Production's Raw Material list, which only ever lists items
//  already purchased). Picking it reveals an inline text input; submitting it
//  emits @create(name) and shows a small "…" state (`creating`
//  prop) while the parent does the real POST. This component never
//  talks to the network itself — the four lookup types (Customer/
//  Vendor/Category/Item) each need a different extra field or none
//  at all, so that logic stays in whichever page uses this.
//
//  v-model binds the selected id (or null). `option-label` picks
//  which field to display — defaults to "name".
// ══════════════════════════════════════════════════════════════════

import { ref, computed, nextTick } from 'vue';
import { useAppTranslations } from '@/composables/useAppTranslations';

const props = defineProps({
    modelValue: { type: [Number, String, null], default: null },
    options: { type: Array, default: () => [] },
    optionLabel: { type: String, default: 'name' },
    placeholder: { type: String, default: '—' },
    addNewLabel: { type: String, default: '+ Add new…' },
    creating: { type: Boolean, default: false },
    inline: { type: Boolean, default: true }, // .blank-select (sentence) vs .form-select (plain field)
    allowCreate: { type: Boolean, default: true }, // false hides "+ Add new…" entirely — for pickers where every option must already exist (e.g. Production's Raw Material list)
});

const emit = defineEmits(['update:modelValue', 'create']);

const { locale } = useAppTranslations();

// Categories (and anything else that ships a name_ar column) are
// stored bilingually — Customers/Vendors/Items currently aren't, so
// this falls back to the plain `optionLabel` field for those. This
// is the ONE place that decides which language an option shows in,
// so every dropdown built on ComboSelect gets Arabic labels for
// free the moment its options include a `name_ar`, with nothing to
// wire up per page.
function optionText(option) {
    if (locale.value === 'ar' && option?.name_ar) {
        return option.name_ar;
    }

    return option?.[props.optionLabel];
}

const addingNew = ref(false);
const newName = ref('');
const inputRef = ref(null);

const selectValue = computed({
    get: () => props.modelValue ?? '',
    set: (val) => {
        if (val === '__new__') {
            addingNew.value = true;
            newName.value = '';
            nextTick(() => inputRef.value?.focus());
            return;
        }
        emit('update:modelValue', val === '' ? null : Number(val));
    },
});

function confirmNewName() {
    const name = newName.value.trim();
    if (!name) {
        addingNew.value = false;
        return;
    }
    emit('create', name);
}

function cancelNewName() {
    addingNew.value = false;
    newName.value = '';
}

// Called by the parent once the new option has been created and
// pushed into `options` — closes the inline input back to the select.
function finishAdding() {
    addingNew.value = false;
    newName.value = '';
}

defineExpose({ finishAdding });
</script>

<template>
    <span v-if="!addingNew" class="combo-select-wrap">
        <select v-model="selectValue" :class="inline ? 'blank-select' : 'form-select'" :disabled="creating">
            <option value="">{{ placeholder }}</option>
            <option v-for="opt in options" :key="opt.id" :value="opt.id">{{ optionText(opt) }}</option>
            <option value="__new__" v-if="allowCreate">{{ addNewLabel }}</option>
        </select>
    </span>
    <span v-else class="combo-select-new">
        <input
            ref="inputRef"
            v-model="newName"
            type="text"
            class="blank-input"
            :disabled="creating"
            @keydown.enter.prevent="confirmNewName"
            @keydown.esc.prevent="cancelNewName"
            @blur="newName.trim() ? confirmNewName() : cancelNewName()"
        >
        <span v-if="creating" class="combo-select-spinner">…</span>
    </span>
</template>

<style scoped>
.combo-select-wrap,
.combo-select-new {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.combo-select-spinner {
    font-size: 12px;
    color: var(--color-text-muted);
}
</style>
