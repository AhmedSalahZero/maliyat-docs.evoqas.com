<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Reports/ProfitAndLoss.vue
//  Location: resources/js/Pages/App/Reports/ProfitAndLoss.vue
//
//  Placeholder for the "Profit & loss" report. ReportController::profitAndLoss()
//  already computes and passes the real data — only the table/
//  chart that renders it still needs to be built. See
//  ComingNextPanel.vue for why this exists instead of a 404.
// ══════════════════════════════════════════════════════════════════

import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ComingNextPanel from '@/Components/App/ComingNextPanel.vue';
import { useAppTranslations } from '@/Composables/useAppTranslations';

defineOptions({ inheritAttrs: false }); // ignore the real report data already passed — not rendered yet
const { t } = useAppTranslations();
</script>

<template>
    <Head :title="t('report_pl')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('report_pl') }}</h1>
        </div>

        <ComingNextPanel icon="pl" :title="t('report_pl')" />

        <p class="reports-back">
            <Link :href="route('app.reports.index')">← {{ t('reports_title') }}</Link>
        </p>
    </AppLayout>
</template>

<style scoped>
.reports-back { margin-top: 14px; text-align: center; font-size: 13px; }
.reports-back a { color: var(--color-primary); text-decoration: none; }
.reports-back a:hover { text-decoration: underline; }
</style>
