// ══════════════════════════════════════════════════════════════════
//  InPractice — useHubStore.js
//  Location: resources/js/Stores/useHubStore.js
//
//  Tracks which hub the member is currently viewing.
//  Finance | Marketing | Sales
//
//  Used by: AppLayout (hub switcher), CaseCard (border color),
//           ForumIndex (filtered posts), Dashboard (hero card)
// ══════════════════════════════════════════════════════════════════

import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';

export const useHubStore = defineStore('hub', () => {

    // ── State ──────────────────────────────────────────────────────
    const activeHub = ref('finance'); // 'finance' | 'marketing' | 'sales'

    const hubs = ref([
        {
            slug:     'finance',
            label_en: 'Finance',
            label_ar: 'المالية',
            icon:     'chart-bar',
            color:    'var(--color-hub-finance)',
            class:    'ip-hub--finance',
        },
        {
            slug:     'marketing',
            label_en: 'Marketing',
            label_ar: 'التسويق',
            icon:     'bullhorn',
            color:    'var(--color-hub-marketing)',
            class:    'ip-hub--marketing',
        },
        {
            slug:     'sales',
            label_en: 'Sales',
            label_ar: 'المبيعات',
            icon:     'target',
            color:    'var(--color-hub-sales)',
            class:    'ip-hub--sales',
        },
    ]);

    // ── Computed ───────────────────────────────────────────────────
    const activeHubData = computed(() =>
        hubs.value.find(h => h.slug === activeHub.value) ?? hubs.value[0]
    );

    const activeHubColor = computed(() => activeHubData.value.color);
    const activeHubClass = computed(() => activeHubData.value.class);

    // ── Actions ────────────────────────────────────────────────────
    function setHub(slug) {
        if (!hubs.value.find(h => h.slug === slug)) return;
        activeHub.value = slug;
    }

    function init(slug) {
        if (slug && hubs.value.find(h => h.slug === slug)) {
            activeHub.value = slug;
        }
    }

    // ── Return ─────────────────────────────────────────────────────
    return {
        activeHub,
        hubs,
        activeHubData,
        activeHubColor,
        activeHubClass,
        setHub,
        init,
    };
});
