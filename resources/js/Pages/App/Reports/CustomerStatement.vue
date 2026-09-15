<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Reports/CustomerStatement.vue
//  Location: resources/js/Pages/App/Reports/CustomerStatement.vue
//
//  What one customer owes you: every sale (debit) and payment
//  (credit) in date order with a running balance, ending in the
//  total they currently owe. ReportController::customerStatement()
//  already returns everything pre-sorted with running_balance
//  computed — this page just renders it and lets the person switch
//  customers.
// ══════════════════════════════════════════════════════════════════

import { computed, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReportToolbar from '@/Components/App/ReportToolbar.vue';
import { useAppTranslations } from '@/Composables/useAppTranslations';

const props = defineProps({
    customers: { type: Array, required: true },
    customer: { type: [Object, null], default: null },
    entries: { type: Array, default: () => [] },
    balance: { type: Number, default: 0 },
    opening_balance: { type: Number, default: 0 },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
});

// The range is opt-in and starts empty — the default question a
// statement answers is "what do they owe me", which is the whole
// history. Narrowing it is a deliberate act, and when it happens the
// server sends a brought-forward figure so the running balance still
// means something (see ReportDataService::windowStatement()).
const from = ref(props.from ?? '');
const to   = ref(props.to ?? '');

const page = usePage();
const { t, locale } = useAppTranslations();
const currency = computed(() => page.props.auth?.user?.company?.currency ?? 'EGP');

function money(v) {
    return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(v || 0);
}

const rangeQuery = computed(() => ({
    ...(from.value ? { from: from.value } : {}),
    ...(to.value ? { to: to.value } : {}),
}));

function reload(customerId = props.customer?.id) {
    router.get(
        customerId
            ? route('app.reports.customer-statement', customerId)
            : route('app.reports.customer-statement'),
        rangeQuery.value,
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function onCustomerChange(e) {
    reload(e.target.value || null);
}

function clearRange() {
    from.value = '';
    to.value = '';
    reload();
}

const hasRange = computed(() => !!(props.from || props.to));

// The export must cover the range the screen is showing, or the
// downloaded file and the page disagree about the same customer.
const excelHref = computed(() => props.customer
    ? route('app.reports.customer-statement.export', { customer: props.customer.id, format: 'excel', ...rangeQuery.value })
    : '#');
const pdfHref = computed(() => props.customer
    ? route('app.reports.customer-statement.export', { customer: props.customer.id, format: 'pdf', ...rangeQuery.value })
    : '#');
</script>

<template>
    <Head :title="t('report_customer_statement')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('report_customer_statement') }}</h1>
        </div>

        <ReportToolbar v-if="props.customer" :excel-href="excelHref" :pdf-href="pdfHref" />

        <div class="report-filters no-print">
            <div class="field">
                <label>{{ t('selectCustomerLbl') }}</label>
                <select class="form-select" :value="props.customer?.id ?? ''" @change="onCustomerChange">
                    <option value="">{{ t('selectCustomerLbl') }}</option>
                    <option v-for="c in props.customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>

            <div class="field">
                <label for="cust-from">{{ t('fromLbl') }}</label>
                <input id="cust-from" v-model="from" type="date" class="inp-date" @change="reload()">
            </div>
            <div class="field">
                <label for="cust-to">{{ t('toLbl') }}</label>
                <input id="cust-to" v-model="to" type="date" class="inp-date" @change="reload()">
            </div>
            <div v-if="hasRange" class="field field--action">
                <button type="button" class="btn btn-ghost btn-sm" @click="clearRange">{{ t('wholeHistoryLbl') }}</button>
            </div>
        </div>

        <div v-if="!props.customer" class="empty">{{ t('chooseCustomerPrompt') }}</div>

        <template v-else>
            <div class="balance-strip">
                <span class="label">{{ t('owedToYouLbl') }} — {{ props.customer.name }}</span>
                <span class="value">{{ currency }} {{ money(props.balance) }}</span>
            </div>

            <div v-if="props.entries.length === 0 && !hasRange" class="empty">{{ t('report_no_entries') }}</div>

            <div v-else class="report-table-wrap card">
                <table class="report-table report-table--stack">
                    <thead>
                        <tr>
                            <th>{{ t('dateLbl') }}</th>
                            <th>{{ t('referenceLbl') }}</th>
                            <th class="num">{{ t('debitLbl') }}</th>
                            <th class="num">{{ t('creditLbl') }}</th>
                            <th class="num">{{ t('runningBalanceLbl') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Where the balance stood going into the
                             range. Without it the first running total
                             below looks like it came from nowhere. -->
                        <tr v-if="hasRange" class="brought-forward">
                            <td :data-label="t('dateLbl')" class="stack-primary">{{ props.from || '—' }}</td>
                            <td :data-label="t('referenceLbl')">{{ t('broughtForwardLbl') }}</td>
                            <td class="num" :data-label="t('debitLbl')">—</td>
                            <td class="num" :data-label="t('creditLbl')">—</td>
                            <td class="num" :data-label="t('runningBalanceLbl')"><strong>{{ currency }} {{ money(props.opening_balance) }}</strong></td>
                        </tr>
                        <tr v-for="(entry, idx) in props.entries" :key="idx">
                            <td :data-label="t('dateLbl')" class="stack-primary">{{ entry.date }}</td>
                            <td :data-label="t('referenceLbl')">{{ entry.ref }}</td>
                            <td class="num" :data-label="t('debitLbl')">{{ entry.debit > 0 ? `${currency} ${money(entry.debit)}` : '—' }}</td>
                            <td class="num" :data-label="t('creditLbl')">{{ entry.credit > 0 ? `${currency} ${money(entry.credit)}` : '—' }}</td>
                            <td class="num" :data-label="t('runningBalanceLbl')"><strong>{{ currency }} {{ money(entry.running_balance) }}</strong></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="grandtotal-label">{{ t('totalBalanceLbl') }}</td>
                            <td class="num" :data-label="t('totalBalanceLbl')"><strong>{{ currency }} {{ money(props.balance) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </template>
    </AppLayout>
</template>

<style scoped>
.field--action { flex: 0 0 auto; justify-content: flex-end; }
.brought-forward { color: var(--color-text-muted); font-style: italic; }
</style>

<style scoped>
.grandtotal-label { text-align: end; font-weight: 600; color: var(--color-primary-dark); }
@media (max-width: 640px) { .grandtotal-label { display: none; } }
</style>
