<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SettingsSheet.vue
//  Location: resources/js/Components/App/SettingsSheet.vue
//
//  The "Settings" sheet — the four setup/reference destinations that
//  used to live inside MenuSheet: Customers, Vendors, Items &
//  categories, Opening balances. Split out on its own so it can be
//  opened from two different entry points that now exist side by
//  side with MenuSheet's "Menu" (avatar) entry point:
//    - Desktop ("web view"): a "Settings" step-nav tab right after
//      the Payment ("Receive / Pay") tab.
//    - Mobile: a fourth bottom-nav button, "Settings".
//
//  Team, Profile, Theme/Language and Log out stay in MenuSheet — see
//  the comment there. All entries (Ledger) was dropped entirely, not
//  moved here: it already has its own Reports tab/tile everywhere,
//  so keeping a second entry point for it was pure duplication.
//
//  Every item here is visible to every role (company_admin and
//  employee alike) — none of these four are gated, unlike Team and
//  Opening balances used to be inside the old combined menu.
//
//  Same bottom-sheet pattern as QuickRecordSheet.vue/MenuSheet.vue
//  (shares .sheet-item / .sheet-header styles from app.css).
// ══════════════════════════════════════════════════════════════════

import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/App/AppIcon.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';
import { useBusinessType } from '@/composables/useBusinessType';

const props = defineProps({
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['update:open']);

const { t } = useAppTranslations();
const { needsInventory } = useBusinessType();

const links = computed(() => {
    const items = [
        { key: 'customers', icon: 'team',     route: 'app.customers.index', title: t('menu_customers'),        sub: t('menu_customers_sub') },
        { key: 'vendors',   icon: 'building', route: 'app.vendors.index',   title: t('menu_vendors'),          sub: t('menu_vendors_sub') },
    ];

    // A service-only company has nothing to name items for — see
    // useBusinessType's doc comment. Same gate MenuSheet used to
    // apply to this same tile.
    if (needsInventory.value) {
        items.push({ key: 'items', icon: 'tag', route: 'app.items.index', title: t('menu_items_categories'), sub: t('menu_items_categories_sub') });
    }

    items.push({ key: 'opening-balance', icon: 'box', route: 'app.opening-balance.index', title: t('menu_opening_balance'), sub: t('menu_opening_balance_sub') });

    return items;
});

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
                    <h2 class="sheet-header__title">{{ t('settings_sheet_title') }}</h2>
                    <button type="button" class="sheet-close-btn" @click="close" :aria-label="t('back_to_home')">
                        <AppIcon name="close" />
                    </button>
                </div>

                <div class="sheet-list">
                    <Link
                        v-for="item in links"
                        :key="item.key"
                        :href="route(item.route)"
                        class="sheet-item"
                        @click="close"
                    >
                        <span class="sheet-item__icon"><AppIcon :name="item.icon" /></span>
                        <span class="sheet-item__text">
                            <span class="sheet-item__title">{{ item.title }}</span>
                            <span class="sheet-item__sub">{{ item.sub }}</span>
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
