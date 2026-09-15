<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Reports/Index.vue (Reports hub)
//  Location: resources/js/Pages/App/Reports/Index.vue
//
//  Destination for the "Reports" tab in the bottom nav / sidebar.
//  Card grid linking to App\Http\Controllers\App\ReportController's
//  six report methods (ledger/profit-loss/customer-statement/
//  supplier-statement/inventory-statement/cash-flow) — all of
//  which compute their data server-side and now each have a real
//  page rendering it.
// ══════════════════════════════════════════════════════════════════

import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppIcon from '@/Components/App/AppIcon.vue';
import { useAppTranslations } from '@/Composables/useAppTranslations';
import { REPORTS } from '@/constants/reports';

const { t } = useAppTranslations();
</script>

<template>
    <Head :title="t('reports_title')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('reports_title') }}</h1>
        </div>
        <p class="reports-subtitle">{{ t('reports_subtitle') }}</p>

        <div class="reports-grid">
            <Link
                v-for="report in REPORTS"
                :key="report.key"
                :href="route(report.route)"
                class="card card--interactive report-tile"
            >
                <span class="report-tile__icon"><AppIcon :name="report.icon" /></span>
                <span class="report-tile__title">{{ t(report.titleKey) }}</span>
                <span class="report-tile__sub">{{ t(report.subKey) }}</span>
            </Link>
        </div>
    </AppLayout>
</template>

<style scoped>
.reports-subtitle {
    color: var(--color-text-muted);
    font-size: 13.5px;
    margin: -12px 0 18px;
}

.reports-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
}

.report-tile {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    padding: 18px;
}

.report-tile__icon {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-md);
    background: var(--color-primary-soft);
    color: var(--color-primary-dark);
    display: flex;
    align-items: center;
    justify-content: center;
}

.report-tile__icon svg { width: 20px; height: 20px; }

.report-tile__title { font-size: 14px; font-weight: 600; color: var(--color-text-primary); }
.report-tile__sub { font-size: 12px; color: var(--color-text-muted); line-height: 1.4; }
</style>
