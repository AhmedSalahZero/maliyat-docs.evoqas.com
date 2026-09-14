<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ComingNextPanel.vue
//  Location: resources/js/Components/App/ComingNextPanel.vue
//
//  <ComingNextPanel icon="sale" :title="t('action_sale_title')" />
//
//  Used by every placeholder feature page that has a real route,
//  a real controller, and a real link pointing to it (from Home,
//  the New Record sheet, or the Menu), but whose actual form/table
//  hasn't been built yet. Nothing in the app links to a dead end —
//  it links here, clearly labelled, until the real page replaces it.
// ══════════════════════════════════════════════════════════════════

import { Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/App/AppIcon.vue';
import { useAppTranslations } from '@/Composables/useAppTranslations';

defineProps({
    icon:  { type: String, required: true },
    title: { type: String, required: true },
});

const { t } = useAppTranslations();
</script>

<template>
    <div class="card coming-next">
        <span class="coming-next__icon"><AppIcon :name="icon" /></span>
        <h2 class="coming-next__title">{{ title }}</h2>
        <span class="badge info coming-next__badge">{{ t('coming_next_badge') }}</span>
        <p class="coming-next__note">{{ t('coming_next_note') }}</p>
        <Link :href="route('app.dashboard')" class="btn btn-secondary btn-sm">
            {{ t('back_to_home') }}
        </Link>
    </div>
</template>

<style scoped>
.coming-next {
    text-align: center;
    padding: 40px 24px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}

.coming-next__icon {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: var(--color-primary-soft);
    color: var(--color-primary-dark);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 4px;
}

.coming-next__icon svg { width: 28px; height: 28px; }

.coming-next__note {
    color: var(--color-text-muted);
    font-size: 13.5px;
    max-width: 380px;
    margin: 4px 0 10px;
}
</style>
