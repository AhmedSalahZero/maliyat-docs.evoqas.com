<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Reports/TrialBalance.vue
//  Location: resources/js/Pages/App/Reports/TrialBalance.vue
//
//  Every account's debit/credit balance as of a date, straight from
//  the general ledger. This is the one report page in the app meant
//  for the company's auditor, not the shop owner — real account
//  names/codes, not simplified language, since an auditor already
//  expects this exact shape.
// ══════════════════════════════════════════════════════════════════

import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReportToolbar from '@/Components/App/ReportToolbar.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';

const props = defineProps({
    as_of: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    total_debit: { type: Number, default: 0 },
    total_credit: { type: Number, default: 0 },
    is_balanced: { type: Boolean, default: true },
});

const page = usePage();
const { t, locale } = useAppTranslations();
const currency = computed(() => page.props.auth?.user?.company?.currency ?? 'EGP');

function money(v) {
    return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(v || 0);
}

const asOf = ref(props.as_of);

function applyDate() {
    router.get(route('app.reports.trial-balance'), { as_of: asOf.value }, { preserveState: true, preserveScroll: true, replace: true });
}

const excelHref = computed(() => route('app.reports.trial-balance.export', { as_of: asOf.value, format: 'excel' }));
const pdfHref = computed(() => route('app.reports.trial-balance.export', { as_of: asOf.value, format: 'pdf' }));

function accountName(row) {
    return locale.value === 'ar' && row.name_ar ? row.name_ar : row.name;
}
</script>

<template>
    <Head :title="t('report_trial_balance')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('report_trial_balance') }}</h1>
        </div>

        <ReportToolbar :excel-href="excelHref" :pdf-href="pdfHref" />

        <div class="report-filters no-print">
            <div class="field">
                <label>{{ t('asOfLbl') }}</label>
                <input type="date" class="form-input" v-model="asOf" @change="applyDate" />
            </div>
        </div>

        <div v-if="!props.is_balanced" class="tb-warning">{{ t('tbUnbalancedWarning') }}</div>

        <div v-if="props.rows.length === 0" class="empty">{{ t('report_no_entries') }}</div>

        <div v-else class="report-table-wrap card">
            <table class="report-table report-table--stack">
                <thead>
                    <tr>
                        <th>{{ t('accountCodeLbl') }}</th>
                        <th>{{ t('accountNameLbl') }}</th>
                        <th class="num">{{ t('accDebitLbl') }}</th>
                        <th class="num">{{ t('accCreditLbl') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in props.rows" :key="row.code">
                        <td :data-label="t('accountCodeLbl')">{{ row.code }}</td>
                        <td :data-label="t('accountNameLbl')" class="stack-primary">{{ accountName(row) }}</td>
                        <td class="num" :data-label="t('accDebitLbl')">{{ row.debit_balance > 0 ? `${currency} ${money(row.debit_balance)}` : '—' }}</td>
                        <td class="num" :data-label="t('accCreditLbl')">{{ row.credit_balance > 0 ? `${currency} ${money(row.credit_balance)}` : '—' }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" class="grandtotal-label">{{ t('totalBalanceLbl') }}</td>
                        <td class="num"><strong>{{ currency }} {{ money(props.total_debit) }}</strong></td>
                        <td class="num"><strong>{{ currency }} {{ money(props.total_credit) }}</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
.grandtotal-label { text-align: end; font-weight: 600; color: var(--color-primary-dark); }
.tb-warning {
    background: var(--color-danger-soft, rgba(220, 38, 38, 0.08));
    color: var(--color-danger-dark, var(--color-danger));
    border-radius: var(--radius-md);
    padding: 10px 14px;
    font-size: 13px;
    margin-bottom: 14px;
}
@media (max-width: 640px) { .grandtotal-label { display: none; } }
</style>
