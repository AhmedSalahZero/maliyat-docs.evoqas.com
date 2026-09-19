<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Reports/CashFlow.vue
//  Location: resources/js/Pages/App/Reports/CashFlow.vue
//
//  Money in vs. money out over the period, broken down by payment
//  method, plus the full list of individual movements.
//  ReportDataService::cashFlow() returns `by_method` as flat
//  (method, direction, total) rows straight from the SQL group-by —
//  this page pivots that into one row per method for display.
// ══════════════════════════════════════════════════════════════════

import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReportToolbar from '@/Components/App/ReportToolbar.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';
import { useMoneyFormat } from '@/composables/useMoneyFormat';

const props = defineProps({
    from: { type: String, required: true },
    to: { type: String, required: true },
    cash_in: { type: Number, default: 0 },
    cash_out: { type: Number, default: 0 },
    net_flow: { type: Number, default: 0 },
    by_method: { type: Array, default: () => [] },
    movements: { type: Array, default: () => [] },
});

const page = usePage();
const { t, locale } = useAppTranslations();
const { currency, money } = useMoneyFormat();

const fromInput = ref(props.from);
const toInput = ref(props.to);

function applyFilters() {
    router.get(route('app.reports.cash-flow'), { from: fromInput.value, to: toInput.value }, {
        preserveState: true, preserveScroll: true, replace: true,
    });
}

const METHOD_KEYS = { cash: 'cashLbl', bank: 'bankLbl', visa: 'visaLbl', instapay: 'instapayShortLbl', wallet: 'walletShortLbl' };

const methodRows = computed(() => {
    const byMethod = {};
    for (const row of props.by_method) {
        byMethod[row.method] ??= { method: row.method, in: 0, out: 0 };
        byMethod[row.method][row.direction] = Number(row.total) || 0;
    }
    return Object.values(byMethod);
});

const excelHref = computed(() => route('app.reports.cash-flow.export', { format: 'excel', from: props.from, to: props.to }));
const pdfHref = computed(() => route('app.reports.cash-flow.export', { format: 'pdf', from: props.from, to: props.to }));
</script>

<template>
    <Head :title="t('report_cashflow')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('report_cashflow') }}</h1>
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
                <div class="pl-label">{{ t('cashInLbl') }}</div>
                <div class="pl-value income">{{ currency }} {{ money(props.cash_in) }}</div>
            </div>
            <div class="pl-box">
                <div class="pl-label">{{ t('cashOutLbl') }}</div>
                <div class="pl-value expense">{{ currency }} {{ money(props.cash_out) }}</div>
            </div>
            <div class="pl-box">
                <div class="pl-label">{{ t('netFlowLbl') }}</div>
                <div class="pl-value net" :style="{ color: props.net_flow >= 0 ? 'var(--color-success-dark)' : 'var(--color-danger-dark)' }">
                    {{ currency }} {{ money(props.net_flow) }}
                </div>
            </div>
        </div>

        <div class="card card--primary">
            <h2>{{ t('byMethodLbl') }}</h2>
            <div v-if="methodRows.length === 0" class="empty">{{ t('report_no_entries') }}</div>
            <div v-else class="report-table-wrap">
                <table class="report-table report-table--stack">
                    <thead>
                        <tr>
                            <th>{{ t('methodLbl') }}</th>
                            <th class="num">{{ t('cashInLbl') }}</th>
                            <th class="num">{{ t('cashOutLbl') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in methodRows" :key="row.method">
                            <td :data-label="t('methodLbl')" class="stack-primary">{{ t(METHOD_KEYS[row.method] ?? 'methodLbl') }}</td>
                            <td class="num" :data-label="t('cashInLbl')">{{ currency }} {{ money(row.in) }}</td>
                            <td class="num" :data-label="t('cashOutLbl')">{{ currency }} {{ money(row.out) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <h3 class="sub">{{ t('movementsLbl') }}</h3>
        <div v-if="props.movements.length === 0" class="empty">{{ t('report_no_entries') }}</div>
        <div v-else class="report-table-wrap card">
            <table class="report-table report-table--stack">
                <thead>
                    <tr>
                        <th>{{ t('dateLbl') }}</th>
                        <th>{{ t('partyLbl') }}</th>
                        <th>{{ t('methodLbl') }}</th>
                        <th>{{ t('directionLbl') }}</th>
                        <th class="num">{{ t('amountLbl') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(m, idx) in props.movements" :key="idx">
                        <td :data-label="t('dateLbl')" class="stack-primary">{{ m.date }}</td>
                        <td :data-label="t('partyLbl')">{{ m.party }}</td>
                        <td :data-label="t('methodLbl')">{{ t(METHOD_KEYS[m.method] ?? 'methodLbl') }}</td>
                        <td :data-label="t('directionLbl')">
                            <span class="badge" :class="m.direction === 'in' ? 'in' : 'out'">{{ m.direction === 'in' ? t('cashInLbl') : t('cashOutLbl') }}</span>
                        </td>
                        <td class="num" :data-label="t('amountLbl')" :style="{ color: m.direction === 'in' ? 'var(--color-money-in)' : 'var(--color-money-out)' }">
                            {{ m.direction === 'in' ? '+' : '-' }}{{ currency }} {{ money(m.amount) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
