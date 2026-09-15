<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Reports/ProfitAndLoss.vue
//  Location: resources/js/Pages/App/Reports/ProfitAndLoss.vue
//
//  Income received vs. expenses paid, cash basis (see
//  ReportDataService's class doc comment for what "cash basis"
//  means here). Kept deliberately simple for a non-accountant: one
//  headline number (net profit) plus two short breakdowns, each
//  drawn with a plain CSS bar rather than a charting library — easy
//  to scan on a phone, nothing to configure.
// ══════════════════════════════════════════════════════════════════

import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReportToolbar from '@/Components/App/ReportToolbar.vue';
import { useAppTranslations } from '@/Composables/useAppTranslations';

const props = defineProps({
    from: { type: String, required: true },
    to: { type: String, required: true },
    income_received: { type: Number, default: 0 },
    expenses_paid: { type: Number, default: 0 },
    net_profit: { type: Number, default: 0 },
    expenses_by_category: { type: Array, default: () => [] },
    income_by_item: { type: Array, default: () => [] },
});

const page = usePage();
const { t, locale } = useAppTranslations();
const currency = computed(() => page.props.auth?.user?.company?.currency ?? 'EGP');

const fromInput = ref(props.from);
const toInput = ref(props.to);

function applyFilters() {
    router.get(route('app.reports.profit-loss'), { from: fromInput.value, to: toInput.value }, {
        preserveState: true, preserveScroll: true, replace: true,
    });
}

function money(v) {
    return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(v || 0);
}

function barWidth(value, rows) {
    const max = Math.max(...rows.map((r) => Number(r.total) || 0), 1);
    return `${Math.max((Number(value) / max) * 100, 3)}%`;
}

const excelHref = computed(() => route('app.reports.profit-loss.export', { format: 'excel', from: props.from, to: props.to }));
const pdfHref = computed(() => route('app.reports.profit-loss.export', { format: 'pdf', from: props.from, to: props.to }));
</script>

<template>
    <Head :title="t('report_pl')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('report_pl') }}</h1>
        </div>

        <ReportToolbar :excel-href="excelHref" :pdf-href="pdfHref" />

        <div class="report-filters no-print">
            <div class="field">
                <label>{{ t('fromLbl') }}</label>
                <input type="date" class="form-input" v-model="fromInput">
            </div>
            <div class="field">
                <label>{{ t('toLbl') }}</label>
                <input type="date" class="form-input" v-model="toInput">
            </div>
            <button type="button" class="btn btn-primary report-filters__apply" @click="applyFilters">{{ t('applyLbl') }}</button>
        </div>

        <div class="pl-summary">
            <div class="pl-box">
                <div class="pl-label">{{ t('incomeReceivedLbl') }}</div>
                <div class="pl-value income">{{ currency }} {{ money(props.income_received) }}</div>
            </div>
            <div class="pl-box">
                <div class="pl-label">{{ t('expensesPaidLbl') }}</div>
                <div class="pl-value expense">{{ currency }} {{ money(props.expenses_paid) }}</div>
            </div>
            <div class="pl-box">
                <div class="pl-label">{{ t('netProfitLbl') }}</div>
                <div class="pl-value net" :style="{ color: props.net_profit >= 0 ? 'var(--color-success-dark)' : 'var(--color-danger-dark)' }">
                    {{ currency }} {{ money(props.net_profit) }}
                </div>
            </div>
        </div>

        <div class="card card--in">
            <h2>{{ t('incomeByItemLbl') }}</h2>
            <div v-if="props.income_by_item.length === 0" class="empty">{{ t('report_no_entries') }}</div>
            <div v-else class="pl-bars">
                <div v-for="row in props.income_by_item" :key="row.item" class="pl-bar-row">
                    <div class="pl-bar-row__label">{{ row.item }}</div>
                    <div class="pl-bar-row__track">
                        <div class="pl-bar-row__fill pl-bar-row__fill--income" :style="{ width: barWidth(row.total, props.income_by_item) }"></div>
                    </div>
                    <div class="pl-bar-row__value">{{ currency }} {{ money(row.total) }}</div>
                </div>
            </div>
        </div>

        <div class="card card--out">
            <h2>{{ t('expensesByCategoryLbl') }}</h2>
            <div v-if="props.expenses_by_category.length === 0" class="empty">{{ t('report_no_entries') }}</div>
            <div v-else class="pl-bars">
                <div v-for="row in props.expenses_by_category" :key="row.category" class="pl-bar-row">
                    <div class="pl-bar-row__label">{{ row.category }}</div>
                    <div class="pl-bar-row__track">
                        <div class="pl-bar-row__fill pl-bar-row__fill--expense" :style="{ width: barWidth(row.total, props.expenses_by_category) }"></div>
                    </div>
                    <div class="pl-bar-row__value">{{ currency }} {{ money(row.total) }}</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.pl-bars { display: flex; flex-direction: column; gap: 12px; margin-top: 6px; }
.pl-bar-row { display: grid; grid-template-columns: minmax(90px, 140px) 1fr auto; align-items: center; gap: 10px; }
.pl-bar-row__label { font-size: 13px; color: var(--color-text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pl-bar-row__track { background: var(--color-surface-alt); border-radius: var(--radius-pill); height: 10px; overflow: hidden; }
.pl-bar-row__fill { height: 100%; border-radius: var(--radius-pill); }
.pl-bar-row__fill--income { background: var(--color-accent-green); }
.pl-bar-row__fill--expense { background: var(--color-accent-red); }
.pl-bar-row__value { font-family: var(--font-mono); font-size: 13px; font-weight: 600; white-space: nowrap; }

@media (max-width: 480px) {
    .pl-bar-row { grid-template-columns: 1fr; gap: 4px; }
    .pl-bar-row__value { text-align: end; }
}
</style>
