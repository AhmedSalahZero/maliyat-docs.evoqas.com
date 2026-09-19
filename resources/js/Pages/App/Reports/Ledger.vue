<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Reports/Ledger.vue
//  Location: resources/js/Pages/App/Reports/Ledger.vue
//
//  "All Entries" — every sale, expense, inventory/equipment
//  purchase in one chronological list. Data comes straight from
//  ReportDataService::ledger() via ReportController::ledger(); this
//  page only renders it, filters by date range, and exposes
//  print/export. Deliberately just one flat table — the point of
//  this report for a non-accountant is "show me everything, in
//  order", not a breakdown.
// ══════════════════════════════════════════════════════════════════

import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReportToolbar from '@/Components/App/ReportToolbar.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';
import { useMoneyFormat } from '@/composables/useMoneyFormat';

const props = defineProps({
    entries: { type: Array, required: true },
    from: { type: String, required: true },
    to: { type: String, required: true },
});

const page = usePage();
const { t, locale } = useAppTranslations();
const { currency, money } = useMoneyFormat();

const fromInput = ref(props.from);
const toInput = ref(props.to);

function applyFilters() {
    router.get(route('app.reports.ledger'), { from: fromInput.value, to: toInput.value }, {
        preserveState: true, preserveScroll: true, replace: true,
    });
}

const TYPE_KEYS = {
    sale: 'typeSaleLbl',
    expense: 'typeExpenseLbl',
    inventory_purchase: 'typeInventoryPurchaseLbl',
    equipment_purchase: 'typeEquipmentPurchaseLbl',
};

const STATUS_BADGE = {
    paid: 'ok',
    partial: 'partial',
    unpaid: 'warn',
};

const excelHref = computed(() => route('app.reports.ledger.export', { format: 'excel', from: props.from, to: props.to }));
const pdfHref = computed(() => route('app.reports.ledger.export', { format: 'pdf', from: props.from, to: props.to }));
</script>

<template>
    <Head :title="t('report_ledger')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('report_ledger') }}</h1>
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

        <div v-if="props.entries.length === 0" class="empty">{{ t('report_no_entries') }}</div>

        <div v-else class="report-table-wrap card">
            <table class="report-table report-table--stack">
                <thead>
                    <tr>
                        <th>{{ t('dateLbl') }}</th>
                        <th>{{ t('typeLbl') }}</th>
                        <th>{{ t('partyLbl') }}</th>
                        <th class="num">{{ t('amountLbl') }}</th>
                        <th class="num">{{ t('balanceLbl') }}</th>
                        <th>{{ t('statusLbl') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in props.entries" :key="`${entry.type}-${entry.id}`">
                        <td :data-label="t('dateLbl')" class="stack-primary">{{ entry.date }}</td>
                        <td :data-label="t('typeLbl')">{{ t(TYPE_KEYS[entry.type]) }}</td>
                        <td :data-label="t('partyLbl')">{{ entry.party ?? '—' }}</td>
                        <td class="num" :data-label="t('amountLbl')">{{ currency }} {{ money(entry.amount) }}</td>
                        <td class="num" :data-label="t('balanceLbl')">{{ currency }} {{ money(entry.balance) }}</td>
                        <td :data-label="t('statusLbl')">
                            <span class="badge" :class="STATUS_BADGE[entry.status]">{{ t(`status${entry.status.charAt(0).toUpperCase()}${entry.status.slice(1)}Lbl`) }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
