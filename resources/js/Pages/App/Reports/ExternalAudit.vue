<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Reports/ExternalAudit.vue
//  Location: resources/js/Pages/App/Reports/ExternalAudit.vue
//
//  The Trial Balance and the Journal, behind one entry point. These
//  two replaced the separate TrialBalance.vue and Journal.vue pages;
//  see ReportController::externalAudit() for why they were merged.
//
//  The toggle is the same one the payments screen uses for
//  Receive/Pay (.toggle-btns), so a user who has met one has met
//  both.
//
//  Switching tabs is a real navigation, not a local ref: the server
//  builds only the half being looked at, and this way the URL always
//  says which half that is — so an auditor can bookmark or send a
//  link to exactly what they were reading, and Back behaves.
// ══════════════════════════════════════════════════════════════════

import { computed, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReportToolbar from '@/Components/App/ReportToolbar.vue';
import { useAppTranslations } from '@/Composables/useAppTranslations';

const props = defineProps({
    view:         { type: String, required: true },   // 'trial-balance' | 'journal'
    as_of:        { type: String, required: true },
    from:         { type: String, required: true },
    to:           { type: String, required: true },
    trialBalance: { type: Object, default: null },    // only when view = trial-balance
    entries:      { type: Array,  default: null },    // only when view = journal
});

const page = usePage();
const { t, locale } = useAppTranslations();
const currency = computed(() => page.props.auth?.user?.company?.currency ?? 'EGP');

function money(v) {
    return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(v || 0);
}

// ── Filters (one per half — see the controller for why) ─────────
const asOf = ref(props.as_of);
const from = ref(props.from);
const to   = ref(props.to);

// A redirect from one of the old URLs arrives with the other half's
// filters still in the query string; keep the local refs in step
// with whatever the server settled on.
watch(() => [props.as_of, props.from, props.to], ([a, f, tt]) => {
    asOf.value = a;
    from.value = f;
    to.value   = tt;
});

function go(view) {
    router.get(route('app.reports.external-audit'), {
        view,
        ...(view === 'trial-balance'
            ? { as_of: asOf.value }
            : { from: from.value, to: to.value }),
    }, { preserveState: true, preserveScroll: true, replace: true });
}

const isTrialBalance = computed(() => props.view === 'trial-balance');

// ── Export links follow whichever half is showing ───────────────
const excelHref = computed(() => isTrialBalance.value
    ? route('app.reports.trial-balance.export', { as_of: asOf.value, format: 'excel' })
    : route('app.reports.journal.export', { from: from.value, to: to.value, format: 'excel' }));

const pdfHref = computed(() => isTrialBalance.value
    ? route('app.reports.trial-balance.export', { as_of: asOf.value, format: 'pdf' })
    : route('app.reports.journal.export', { from: from.value, to: to.value, format: 'pdf' }));

function accountName(row) {
    return locale.value === 'ar' && row.name_ar ? row.name_ar : row.name;
}

function lineAccountName(line) {
    return locale.value === 'ar' && line.account_name_ar ? line.account_name_ar : line.account_name;
}
</script>

<template>
    <Head :title="t('report_external_audit')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('report_external_audit') }}</h1>
        </div>

        <div class="toggle-btns no-print" style="margin-bottom: 18px;">
            <button type="button" :class="{ active: isTrialBalance }" @click="go('trial-balance')">
                {{ t('report_trial_balance') }}
            </button>
            <button type="button" :class="{ active: !isTrialBalance }" @click="go('journal')">
                {{ t('report_journal') }}
            </button>
        </div>

        <ReportToolbar :excel-href="excelHref" :pdf-href="pdfHref" />

        <!-- ══════════════════════ TRIAL BALANCE ══════════════════════ -->
        <template v-if="isTrialBalance && props.trialBalance">
            <div class="report-filters no-print">
                <div class="field">
                    <label for="ea-as-of">{{ t('asOfLbl') }}</label>
                    <input id="ea-as-of" v-model="asOf" type="date" class="inp-date" @change="go('trial-balance')">
                </div>
            </div>

            <div v-if="!props.trialBalance.is_balanced" class="tb-warning">{{ t('tbUnbalancedWarning') }}</div>

            <div v-if="props.trialBalance.rows.length === 0" class="empty">{{ t('report_no_entries') }}</div>

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
                        <tr v-for="row in props.trialBalance.rows" :key="row.code">
                            <td :data-label="t('accountCodeLbl')">{{ row.code }}</td>
                            <td :data-label="t('accountNameLbl')" class="stack-primary">{{ accountName(row) }}</td>
                            <td class="num" :data-label="t('accDebitLbl')">
                                {{ row.debit_balance > 0 ? `${currency} ${money(row.debit_balance)}` : '—' }}
                            </td>
                            <td class="num" :data-label="t('accCreditLbl')">
                                {{ row.credit_balance > 0 ? `${currency} ${money(row.credit_balance)}` : '—' }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="grandtotal-label">{{ t('totalBalanceLbl') }}</td>
                            <td class="num"><strong>{{ currency }} {{ money(props.trialBalance.total_debit) }}</strong></td>
                            <td class="num"><strong>{{ currency }} {{ money(props.trialBalance.total_credit) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </template>

        <!-- ═══════════════════════════ JOURNAL ═══════════════════════ -->
        <template v-else-if="props.entries">
            <div class="report-filters no-print">
                <div class="field">
                    <label for="ea-from">{{ t('fromLbl') }}</label>
                    <input id="ea-from" v-model="from" type="date" class="inp-date" @change="go('journal')">
                </div>
                <div class="field">
                    <label for="ea-to">{{ t('toLbl') }}</label>
                    <input id="ea-to" v-model="to" type="date" class="inp-date" @change="go('journal')">
                </div>
            </div>

            <div v-if="props.entries.length === 0" class="empty">{{ t('report_no_entries') }}</div>

            <div v-else class="journal-list">
                <div v-for="entry in props.entries" :key="entry.id" class="card journal-entry">
                    <div class="journal-entry__head">
                        <span class="journal-entry__date">{{ entry.date }}</span>
                        <span class="journal-entry__memo">
                            {{ entry.memo }}
                            <span v-if="entry.is_reversal" class="badge badge--muted">{{ t('reversalLbl') }}</span>
                        </span>
                    </div>

                    <table class="report-table journal-entry__lines">
                        <thead>
                            <tr>
                                <th>{{ t('accountNameLbl') }}</th>
                                <th class="num">{{ t('accDebitLbl') }}</th>
                                <th class="num">{{ t('accCreditLbl') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, idx) in entry.lines" :key="idx">
                                <td>{{ lineAccountName(line) }}</td>
                                <td class="num">{{ line.debit > 0 ? `${currency} ${money(line.debit)}` : '—' }}</td>
                                <td class="num">{{ line.credit > 0 ? `${currency} ${money(line.credit)}` : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
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

.journal-list { display: flex; flex-direction: column; gap: 12px; }
.journal-entry { padding: 14px 16px; }

.journal-entry__head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 10px;
    margin-bottom: 8px;
    flex-wrap: wrap;
}

.journal-entry__date { font-family: var(--font-mono); font-size: 12.5px; color: var(--color-text-muted); }
.journal-entry__memo { font-size: 13.5px; font-weight: 600; color: var(--color-text-primary); }
.journal-entry__lines { margin-top: 0; }
.journal-entry__lines th { font-size: 11px; padding-bottom: 4px; }
.journal-entry__lines td { padding: 6px 8px; font-size: 13px; }

.badge--muted {
    background: var(--color-surface-alt);
    color: var(--color-text-muted);
    border-radius: var(--radius-pill);
    padding: 2px 8px;
    font-size: 10.5px;
    font-weight: 500;
    margin-inline-start: 6px;
}

@media (max-width: 640px) { .grandtotal-label { display: none; } }
</style>
