<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — QuickRecordSheet.vue
//  Location: resources/js/Components/App/QuickRecordSheet.vue
//
//  The "New record" bottom sheet — the quick-capture launcher
//  reachable from anywhere via the FAB in the bottom nav (mobile)
//  / sidebar (desktop). Shows the same six actions, in the same
//  order, as the Home page cards (both read from
//  constants/quickRecordActions.js so they can never drift apart).
//  Lives inside AppLayout.vue and is controlled by it via
//  v-model:open, so any page can trigger it without importing it.
// ══════════════════════════════════════════════════════════════════

import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppIcon from '@/Components/App/AppIcon.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';
import { QUICK_RECORD_ACTIONS } from '@/constants/quickRecordActions';
import { useBusinessType } from '@/composables/useBusinessType';

const { visibleFor } = useBusinessType();
const visibleQuickActions = computed(() => visibleFor(QUICK_RECORD_ACTIONS));

const props = defineProps({
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['update:open']);

const { t } = useAppTranslations();

function close() {
    emit('update:open', false);
}
</script>

<template>
    <transition name="fade-in">
        <div v-if="props.open" class="modal-backdrop" @click.self="close">
            <div class="modal-sheet slide-up">
                <div class="modal-sheet__handle"></div>

                <div class="sheet-header">
                    <h2 class="sheet-header__title">{{ t('new_record_sheet_title') }}</h2>
                    <button type="button" class="sheet-close-btn" @click="close" :aria-label="t('back_to_home')">
                        <AppIcon name="close" />
                    </button>
                </div>

                <div class="sheet-list">
                    <Link
                        v-for="action in visibleQuickActions"
                        :key="action.key"
                        :href="route(action.route)"
                        class="sheet-item"
                        @click="close"
                    >
                        <span class="sheet-item__icon">
                            <AppIcon :name="action.icon" />
                        </span>
                        <span class="sheet-item__text">
                            <span class="sheet-item__title">{{ t(action.titleKey) }}</span>
                            <span class="sheet-item__sub">{{ t(action.subKey) }}</span>
                        </span>
                        <AppIcon name="chevron" class="sheet-item__chevron" />
                    </Link>
                </div>
            </div>
        </div>
    </transition>
</template>

<style scoped>
.sheet-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
}

.sheet-header__title {
    font-family: var(--font-heading);
    font-weight: 700;
    font-size: 17px;
    color: var(--color-text-primary);
}

.sheet-list {
    display: flex;
    flex-direction: column;
    margin-top: 10px;
    max-height: 70vh;
    overflow-y: auto;
}
</style>
