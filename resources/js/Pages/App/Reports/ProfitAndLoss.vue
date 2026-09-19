<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Reports/ProfitAndLoss.vue
//  Location: resources/js/Pages/App/Reports/ProfitAndLoss.vue
//
//  Revenue earned vs. expenses incurred, ACCRUAL basis — see
//  ReportDataService::profitAndLoss()'s doc comment for why. ONE
//  table, read top to bottom like a real income statement: a
//  Revenue section (each product, then Total Revenue), a Cost of
//  Goods Sold section (Trading/Production companies only, then
//  Total COGS, then Gross Profit), an Operating Expenses section
//  (each category, then Total Operating Expenses), then Net Profit.
//  Every row carries its % of total revenue, so this reads as a
//  standard common-size income statement. No separate summary cards
//  — the totals live inline, in the one table, where they belong.
//
//  Cash movement (what actually came into/left the till or bank) is
//  a different question, answered on the Cash Flow report instead —
//  see Reports/CashFlow.vue.
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
    revenue: { type: Number, default: 0 },
    revenue_percent: { type: Number, default: 0 },
    cost_of_goods_sold: { type: Number, default: 0 },
    cost_of_goods_sold_percent: { type: Number, default: 0 },
    gross_profit: { type: Number, default: 0 },
    gross_profit_percent: { type: Number, default: 0 },
    operating_expenses: { type: Number, default: 0 },
    operating_expenses_percent: { type: Number, default: 0 },
    net_profit: { type: Number, default: 0 },
    net_profit_percent: { type: Number, default: 0 },
    owners_profit_pay: { type: Number, default: 0 },
    owners_profit_pay_percent: { type: Number, default: 0 },
    net_profit_after_owners_draw: { type: Number, default: 0 },
    net_profit_after_owners_draw_percent: { type: Number, default: 0 },
    expenses_by_category: { type: Array, default: () => [] },
    income_by_item: { type: Array, default: () => [] },
    cost_of_goods_sold_by_item: { type: Array, default: () => [] },
    show_cost_of_goods_sold_by_item: { type: Boolean, default: false },
});

const page = usePage();
const { t, locale } = useAppTranslations();
const { currency, money } = useMoneyFormat();

const fromInput = ref(props.from);
const toInput = ref(props.to);

function applyFilters() {
    router.get(route('app.reports.profit-loss'), { from: fromInput.value, to: toInput.value }, {
        preserveState: true, preserveScroll: true, replace: true,
    });
}

function pct(value) {
    return `${Number(value ?? 0).toFixed(1)}%`;
}

// One flat list of rows drives the whole table — each row is either
// a section heading (no amount), a plain child line (a product or a
// category), or a bold total/result line. The template just walks
// this list, so the table's actual shape lives in one place instead
// of being duplicated between markup and export.
const rows = computed(() => {
    const list = [];

    list.push({ kind: 'heading', label: t('revenueLbl') });
    for (const row of props.income_by_item) {
        list.push({ kind: 'child', label: row.item, amount: row.total, percent: row.percent_of_revenue });
    }
    list.push({ kind: 'total', label: t('totalRevenueLbl'), amount: props.revenue, percent: props.revenue_percent });

    if (props.show_cost_of_goods_sold_by_item) {
        list.push({ kind: 'heading', label: t('costOfGoodsSoldLbl') });
        for (const row of props.cost_of_goods_sold_by_item) {
            list.push({ kind: 'child', label: row.item, amount: row.total, percent: row.percent_of_revenue });
        }
        list.push({ kind: 'total', label: t('totalCostOfGoodsSoldLbl'), amount: props.cost_of_goods_sold, percent: props.cost_of_goods_sold_percent });
        list.push({ kind: 'result', label: t('grossProfitLbl'), amount: props.gross_profit, percent: props.gross_profit_percent });
    }

    list.push({ kind: 'heading', label: t('operatingExpensesLbl') });
    for (const row of props.expenses_by_category) {
        list.push({ kind: 'child', label: row.category, amount: row.total, percent: row.percent_of_revenue });
    }
    list.push({ kind: 'total', label: t('totalOperatingExpensesLbl'), amount: props.operating_expenses, percent: props.operating_expenses_percent });

    list.push({ kind: 'result', label: t('netProfitLbl'), amount: props.net_profit, percent: props.net_profit_percent });

    // Appropriation of the period's profit, not a component of it —
    // Net Profit above is what the business earned; these two rows
    // show what it then paid owners out of that. Only ever shown
    // when something was actually paid out, so a company that's
    // never used Owner Injection/Withdrawal sees the P&L exactly as
    // it always looked.
    if (props.owners_profit_pay !== 0) {
        list.push({ kind: 'child', label: t('ownersProfitPayLbl'), amount: -props.owners_profit_pay, percent: props.owners_profit_pay_percent });
        list.push({ kind: 'result', label: t('netProfitAfterOwnersDrawLbl'), amount: props.net_profit_after_owners_draw, percent: props.net_profit_after_owners_draw_percent });
    }

    return list;
});

const hasAnyRows = computed(() =>
    props.income_by_item.length > 0
    || props.expenses_by_category.length > 0
    || props.cost_of_goods_sold_by_item.length > 0
);

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

        <div class="card">
            <div v-if="!hasAnyRows" class="empty">{{ t('report_no_entries') }}</div>
            <table v-else class="pl-table">
                <thead>
                    <tr>
                        <th>{{ t('descriptionLbl') }}</th>
                        <th class="pl-table__num">{{ t('amountLbl') }}</th>
                        <th class="pl-table__num">{{ t('percentOfRevenueLbl') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="(row, i) in rows" :key="i">
                        <tr v-if="row.kind === 'heading'" class="pl-row--heading">
                            <td colspan="3">{{ row.label }}</td>
                        </tr>
                        <tr v-else-if="row.kind === 'child'" class="pl-row--child">
                            <td>{{ row.label }}</td>
                            <td class="pl-table__num">{{ currency }} {{ money(row.amount) }}</td>
                            <td class="pl-table__num">{{ pct(row.percent) }}</td>
                        </tr>
                        <tr v-else-if="row.kind === 'total'" class="pl-row--total">
                            <td>{{ row.label }}</td>
                            <td class="pl-table__num">{{ currency }} {{ money(row.amount) }}</td>
                            <td class="pl-table__num">{{ pct(row.percent) }}</td>
                        </tr>
                        <tr v-else class="pl-row--result" :class="row.amount >= 0 ? 'pl-row--positive' : 'pl-row--negative'">
                            <td>{{ row.label }}</td>
                            <td class="pl-table__num">{{ currency }} {{ money(row.amount) }}</td>
                            <td class="pl-table__num">{{ pct(row.percent) }}</td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
.pl-table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 13px; }
.pl-table th { text-align: start; font-weight: 600; color: var(--color-text-secondary); padding: 8px; border-bottom: 1px solid var(--color-border, #e5e7eb); }
.pl-table td { padding: 7px 8px; border-bottom: 1px solid var(--color-border, #eef1f5); }
.pl-table__num { text-align: end; font-family: var(--font-mono); white-space: nowrap; }

.pl-row--heading td { font-weight: 700; color: var(--color-text-secondary); background: var(--color-surface-alt); padding-top: 12px; }
.pl-row--child td:first-child { padding-inline-start: 22px; }
.pl-row--total td { font-weight: 600; border-top: 1px solid var(--color-border, #d7dce5); }
.pl-row--result td { font-weight: 700; font-size: 14px; border-top: 2px solid var(--color-accent-blue, #2D6CDF); background: var(--color-surface-alt); }
.pl-row--positive .pl-table__num:nth-child(2) { color: var(--color-success-dark); }
.pl-row--negative .pl-table__num:nth-child(2) { color: var(--color-danger-dark); }

@media (max-width: 480px) {
    .pl-table { font-size: 12px; }
    .pl-table th, .pl-table td { padding: 6px 4px; }
    .pl-row--child td:first-child { padding-inline-start: 14px; }
}
</style>
