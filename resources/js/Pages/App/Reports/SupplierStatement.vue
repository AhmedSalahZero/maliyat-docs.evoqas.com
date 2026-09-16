<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Reports/SupplierStatement.vue
//  Location: resources/js/Pages/App/Reports/SupplierStatement.vue
//
//  What you owe one vendor/employee: every bill (expense, inventory
//  purchase, equipment purchase) and payment in date order with a
//  running balance. Mirrors CustomerStatement.vue's shape — see
//  that file's comment — just with the debit/credit sense flipped
//  (paying the vendor reduces what you owe them, a new bill
//  increases it).
// ══════════════════════════════════════════════════════════════════

import { computed, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReportToolbar from '@/Components/App/ReportToolbar.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';

const props = defineProps({
    vendors: { type: Array, required: true },
    vendor: { type: [Object, null], default: null },
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

function reload(vendorId = props.vendor?.id) {
    router.get(
        vendorId
            ? route('app.reports.supplier-statement', vendorId)
            : route('app.reports.supplier-statement'),
        rangeQuery.value,
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function onVendorChange(e) {
    reload(e.target.value || null);
}

function clearRange() {
    from.value = '';
    to.value = '';
    reload();
}

const hasRange = computed(() => !!(props.from || props.to));

// The export must cover the range the screen is showing.
const excelHref = computed(() => props.vendor
    ? route('app.reports.supplier-statement.export', { vendor: props.vendor.id, format: 'excel', ...rangeQuery.value })
    : '#');
const pdfHref = computed(() => props.vendor
    ? route('app.reports.supplier-statement.export', { vendor: props.vendor.id, format: 'pdf', ...rangeQuery.value })
    : '#');
</script>

<template>
    <Head :title="t('report_supplier_statement')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('report_supplier_statement') }}</h1>
        </div>

        <ReportToolbar v-if="props.vendor" :excel-href="excelHref" :pdf-href="pdfHref" />

        <div class="report-filters no-print">
            <div class="field">
                <label>{{ t('selectVendorLbl') }}</label>
                <select class="form-select" :value="props.vendor?.id ?? ''" @change="onVendorChange">
                    <option value="">{{ t('selectVendorLbl') }}</option>
                    <option v-for="v in props.vendors" :key="v.id" :value="v.id">{{ v.name }}</option>
                </select>
            </div>

            <div class="field">
                <label for="supp-from">{{ t('fromLbl') }}</label>
                <input id="supp-from" v-model="from" type="date" class="inp-date" @change="reload()">
            </div>
            <div class="field">
                <label for="supp-to">{{ t('toLbl') }}</label>
                <input id="supp-to" v-model="to" type="date" class="inp-date" @change="reload()">
            </div>
            <div v-if="hasRange" class="field field--action">
                <button type="button" class="btn btn-ghost btn-sm" @click="clearRange">{{ t('wholeHistoryLbl') }}</button>
            </div>
        </div>

        <div v-if="!props.vendor" class="empty">{{ t('chooseVendorPrompt') }}</div>

        <template v-else>
            <div class="balance-strip">
                <span class="label">{{ t('owedByYouLbl') }} — {{ props.vendor.name }}</span>
                <span class="value">{{ currency }} {{ money(props.balance) }}</span>
            </div>

            <div v-if="props.entries.length === 0 && !hasRange" class="empty">{{ t('report_no_entries') }}</div>

            <div v-else class="report-table-wrap card">
                <table class="report-table report-table--stack">
                    <thead>
                        <tr>
                            <th>{{ t('dateLbl') }}</th>
                            <th>{{ t('referenceLbl') }}</th>
                            <th class="num">{{ t('paidLbl') }}</th>
                            <th class="num">{{ t('owedLbl') }}</th>
                            <th class="num">{{ t('runningBalanceLbl') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Where the balance stood going into the
                             range — see CustomerStatement.vue. -->
                        <tr v-if="hasRange" class="brought-forward">
                            <td :data-label="t('dateLbl')" class="stack-primary">{{ props.from || '—' }}</td>
                            <td :data-label="t('referenceLbl')">{{ t('broughtForwardLbl') }}</td>
                            <td class="num" :data-label="t('paidLbl')">—</td>
                            <td class="num" :data-label="t('owedLbl')">—</td>
                            <td class="num" :data-label="t('runningBalanceLbl')"><strong>{{ currency }} {{ money(props.opening_balance) }}</strong></td>
                        </tr>
                        <tr v-for="(entry, idx) in props.entries" :key="idx">
                            <td :data-label="t('dateLbl')" class="stack-primary">{{ entry.date }}</td>
                            <td :data-label="t('referenceLbl')">{{ entry.ref }}</td>
                            <td class="num" :data-label="t('paidLbl')">{{ entry.debit > 0 ? `${currency} ${money(entry.debit)}` : '—' }}</td>
                            <td class="num" :data-label="t('owedLbl')">{{ entry.credit_owed > 0 ? `${currency} ${money(entry.credit_owed)}` : '—' }}</td>
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

.grandtotal-label { text-align: end; font-weight: 600; color: var(--color-primary-dark); }
@media (max-width: 640px) { .grandtotal-label { display: none; } }
</style>
