<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Reports/ExternalAudit.vue
//  Location: resources/js/Pages/App/Reports/ExternalAudit.vue
//
//  The Trial Balance, the Balance Sheet, and the Journal, behind one
//  entry point. Trial Balance/Journal replaced the separate
//  TrialBalance.vue and Journal.vue pages; see
//  ReportController::externalAudit() for why they were merged, and
//  why Balance Sheet joined them here rather than getting its own
//  reports-hub tile.
//
//  The toggle is the same one the payments screen uses for
//  Receive/Pay (.toggle-btns), just with a third option.
//
//  Trial Balance is a date RANGE (tb_from/tb_to), defaulting to
//  "this year so far" — not a single as-of date, and not "since the
//  company's first-ever transaction". Each account still shows a
//  true Opening Balance (everything before tb_from, netted) so
//  balance-sheet accounts reconcile correctly; Total Debit/Credit is
//  just the selected period's movement; End Balance is the sum of
//  the two. See ReportDataService::trialBalance()'s doc comment.
//
//  Balance Sheet is a single date (bs_as_of, today by default) — a
//  snapshot has no "period", so there's no from/to here, just one
//  date picker. See ReportDataService::balanceSheet()'s doc comment
//  for why "Net Profit (to date)" shows up as its own line inside
//  Equity rather than as a real ledger account.
//
//  Switching tabs is a real navigation, not a local ref: the server
//  builds only the one being looked at, and this way the URL always
//  says which one that is — so an auditor can bookmark or send a
//  link to exactly what they were reading, and Back behaves.
// ══════════════════════════════════════════════════════════════════

import { computed, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReportToolbar from '@/Components/App/ReportToolbar.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';
import { useMoneyFormat } from '@/composables/useMoneyFormat';

const props = defineProps({
    view:         { type: String, required: true },   // 'trial-balance' | 'balance-sheet' | 'journal'
    tb_from:      { type: String, required: true },
    tb_to:        { type: String, required: true },
    bs_as_of:     { type: String, required: true },
    from:         { type: String, required: true },
    to:           { type: String, required: true },
    trialBalance: { type: Object, default: null },    // only when view = trial-balance
    balanceSheet: { type: Object, default: null },    // only when view = balance-sheet
    entries:      { type: Array,  default: null },    // only when view = journal
});

const page = usePage();
const { t, locale } = useAppTranslations();
const { currency, money } = useMoneyFormat();

// ── Filters (one set per view — see the controller for why) ─────
const tbFrom = ref(props.tb_from);
const tbTo   = ref(props.tb_to);
const bsAsOf = ref(props.bs_as_of);
const from   = ref(props.from);
const to     = ref(props.to);

// A redirect from one of the old URLs arrives with the other views'
// filters still in the query string; keep the local refs in step
// with whatever the server settled on.
watch(() => [props.tb_from, props.tb_to, props.bs_as_of, props.from, props.to], ([tf, tt2, bs, f, t2]) => {
    tbFrom.value = tf;
    tbTo.value   = tt2;
    bsAsOf.value = bs;
    from.value   = f;
    to.value     = t2;
});

function go(view) {
    router.get(route('app.reports.external-audit'), {
        view,
        ...(view === 'trial-balance' ? { tb_from: tbFrom.value, tb_to: tbTo.value } : {}),
        ...(view === 'balance-sheet' ? { bs_as_of: bsAsOf.value } : {}),
        ...(view === 'journal' ? { from: from.value, to: to.value } : {}),
    }, { preserveState: true, preserveScroll: true, replace: true });
}

const isTrialBalance = computed(() => props.view === 'trial-balance');
const isBalanceSheet = computed(() => props.view === 'balance-sheet');
const isJournal       = computed(() => props.view === 'journal');

// ── Export links follow whichever view is showing ────────────────
const excelHref = computed(() => {
    if (isTrialBalance.value) return route('app.reports.trial-balance.export', { tb_from: tbFrom.value, tb_to: tbTo.value, format: 'excel' });
    if (isBalanceSheet.value) return route('app.reports.balance-sheet.export', { bs_as_of: bsAsOf.value, format: 'excel' });
    return route('app.reports.journal.export', { from: from.value, to: to.value, format: 'excel' });
});

const pdfHref = computed(() => {
    if (isTrialBalance.value) return route('app.reports.trial-balance.export', { tb_from: tbFrom.value, tb_to: tbTo.value, format: 'pdf' });
    if (isBalanceSheet.value) return route('app.reports.balance-sheet.export', { bs_as_of: bsAsOf.value, format: 'pdf' });
    return route('app.reports.journal.export', { from: from.value, to: to.value, format: 'pdf' });
});

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
            <button type="button" :class="{ active: isBalanceSheet }" @click="go('balance-sheet')">
                {{ t('report_balance_sheet') }}
            </button>
            <button type="button" :class="{ active: isJournal }" @click="go('journal')">
                {{ t('report_journal') }}
            </button>
        </div>

        <ReportToolbar :excel-href="excelHref" :pdf-href="pdfHref" />

        <!-- ══════════════════════ TRIAL BALANCE ══════════════════════ -->
        <template v-if="isTrialBalance && props.trialBalance">
            <div class="report-filters no-print">
                <div class="field">
                    <label for="ea-tb-from">{{ t('fromLbl') }}</label>
                    <input id="ea-tb-from" v-model="tbFrom" type="date" class="inp-date" @change="go('trial-balance')">
                </div>
                <div class="field">
                    <label for="ea-tb-to">{{ t('toLbl') }}</label>
                    <input id="ea-tb-to" v-model="tbTo" type="date" class="inp-date" @change="go('trial-balance')">
                </div>
            </div>

            <div v-if="!props.trialBalance.is_balanced" class="tb-warning">{{ t('tbUnbalancedWarning') }}</div>

            <div v-if="props.trialBalance.rows.length === 0" class="empty">{{ t('report_no_entries') }}</div>

            <div v-else class="report-table-wrap card">
                <table class="report-table report-table--stack">
                    <thead>
                        <tr>
                            <th rowspan="2">{{ t('accountCodeLbl') }}</th>
                            <th rowspan="2">{{ t('accountNameLbl') }}</th>
                            <th colspan="2" class="num">{{ t('tbOpeningBalanceLbl') }}</th>
                            <th rowspan="2" class="num">{{ t('accDebitLbl') }}</th>
                            <th rowspan="2" class="num">{{ t('accCreditLbl') }}</th>
                            <th colspan="2" class="num">{{ t('tbEndBalanceLbl') }}</th>
                        </tr>
                        <tr>
                            <th class="num">{{ t('accDebitLbl') }}</th>
                            <th class="num">{{ t('accCreditLbl') }}</th>
                            <th class="num">{{ t('accDebitLbl') }}</th>
                            <th class="num">{{ t('accCreditLbl') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in props.trialBalance.rows" :key="row.code">
                            <td :data-label="t('accountCodeLbl')">{{ row.code }}</td>
                            <td :data-label="t('accountNameLbl')" class="stack-primary">{{ accountName(row) }}</td>
                            <td class="num" :data-label="`${t('tbOpeningBalanceLbl')} — ${t('accDebitLbl')}`">
                                {{ row.opening_debit > 0 ? `${currency} ${money(row.opening_debit)}` : '—' }}
                            </td>
                            <td class="num" :data-label="`${t('tbOpeningBalanceLbl')} — ${t('accCreditLbl')}`">
                                {{ row.opening_credit > 0 ? `${currency} ${money(row.opening_credit)}` : '—' }}
                            </td>
                            <td class="num" :data-label="t('accDebitLbl')">
                                {{ row.period_debit > 0 ? `${currency} ${money(row.period_debit)}` : '—' }}
                            </td>
                            <td class="num" :data-label="t('accCreditLbl')">
                                {{ row.period_credit > 0 ? `${currency} ${money(row.period_credit)}` : '—' }}
                            </td>
                            <td class="num" :data-label="`${t('tbEndBalanceLbl')} — ${t('accDebitLbl')}`">
                                {{ row.closing_debit > 0 ? `${currency} ${money(row.closing_debit)}` : '—' }}
                            </td>
                            <td class="num" :data-label="`${t('tbEndBalanceLbl')} — ${t('accCreditLbl')}`">
                                {{ row.closing_credit > 0 ? `${currency} ${money(row.closing_credit)}` : '—' }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="grandtotal-label">{{ t('totalBalanceLbl') }}</td>
                            <td class="num"><strong>{{ currency }} {{ money(props.trialBalance.total_opening_debit) }}</strong></td>
                            <td class="num"><strong>{{ currency }} {{ money(props.trialBalance.total_opening_credit) }}</strong></td>
                            <td class="num"><strong>{{ currency }} {{ money(props.trialBalance.total_period_debit) }}</strong></td>
                            <td class="num"><strong>{{ currency }} {{ money(props.trialBalance.total_period_credit) }}</strong></td>
                            <td class="num"><strong>{{ currency }} {{ money(props.trialBalance.total_closing_debit) }}</strong></td>
                            <td class="num"><strong>{{ currency }} {{ money(props.trialBalance.total_closing_credit) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </template>

        <!-- ═══════════════════════════ BALANCE SHEET ══════════════════ -->
        <template v-else-if="isBalanceSheet && props.balanceSheet">
            <div class="report-filters no-print">
                <div class="field">
                    <label for="ea-bs-as-of">{{ t('asOfLbl') }}</label>
                    <input id="ea-bs-as-of" v-model="bsAsOf" type="date" class="inp-date" @change="go('balance-sheet')">
                </div>
            </div>

            <div v-if="!props.balanceSheet.is_balanced" class="tb-warning">{{ t('bsUnbalancedWarning') }}</div>

            <!-- Assets -->
            <div class="card bs-section">
                <h3 class="sub">{{ t('bsAssetsLbl') }}</h3>
                <div v-if="props.balanceSheet.assets.length === 0" class="empty">{{ t('report_no_entries') }}</div>
                <table v-else class="report-table report-table--stack">
                    <thead>
                        <tr>
                            <th>{{ t('accountCodeLbl') }}</th>
                            <th>{{ t('accountNameLbl') }}</th>
                            <th class="num">{{ t('amountLbl') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in props.balanceSheet.assets" :key="row.code">
                            <td :data-label="t('accountCodeLbl')">{{ row.code }}</td>
                            <td :data-label="t('accountNameLbl')" class="stack-primary">{{ accountName(row) }}</td>
                            <td class="num" :data-label="t('amountLbl')">{{ currency }} {{ money(row.balance) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="grandtotal-label">{{ t('bsTotalAssetsLbl') }}</td>
                            <td class="num"><strong>{{ currency }} {{ money(props.balanceSheet.total_assets) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Liabilities -->
            <div class="card bs-section">
                <h3 class="sub">{{ t('bsLiabilitiesLbl') }}</h3>
                <div v-if="props.balanceSheet.liabilities.length === 0" class="empty">{{ t('report_no_entries') }}</div>
                <table v-else class="report-table report-table--stack">
                    <thead>
                        <tr>
                            <th>{{ t('accountCodeLbl') }}</th>
                            <th>{{ t('accountNameLbl') }}</th>
                            <th class="num">{{ t('amountLbl') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in props.balanceSheet.liabilities" :key="row.code">
                            <td :data-label="t('accountCodeLbl')">{{ row.code }}</td>
                            <td :data-label="t('accountNameLbl')" class="stack-primary">{{ accountName(row) }}</td>
                            <td class="num" :data-label="t('amountLbl')">{{ currency }} {{ money(row.balance) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="grandtotal-label">{{ t('bsLiabilitiesLbl') }}</td>
                            <td class="num"><strong>{{ currency }} {{ money(props.balanceSheet.total_liabilities) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Equity -->
            <div class="card bs-section">
                <h3 class="sub">{{ t('bsEquityLbl') }}</h3>
                <table class="report-table report-table--stack">
                    <thead>
                        <tr>
                            <th>{{ t('accountCodeLbl') }}</th>
                            <th>{{ t('accountNameLbl') }}</th>
                            <th class="num">{{ t('amountLbl') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in props.balanceSheet.equity" :key="row.code">
                            <td :data-label="t('accountCodeLbl')">{{ row.code }}</td>
                            <td :data-label="t('accountNameLbl')" class="stack-primary">{{ accountName(row) }}</td>
                            <td class="num" :data-label="t('amountLbl')">{{ currency }} {{ money(row.balance) }}</td>
                        </tr>
                        <!-- Not a real ledger account — see
                             ReportDataService::balanceSheet()'s doc
                             comment for why this line exists. -->
                        <tr>
                            <td data-label="—">—</td>
                            <td :data-label="t('accountNameLbl')" class="stack-primary">{{ t('bsNetProfitToDateLbl') }}</td>
                            <td class="num" :data-label="t('amountLbl')">{{ currency }} {{ money(props.balanceSheet.net_profit_to_date) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="grandtotal-label">{{ t('bsEquityLbl') }}</td>
                            <td class="num"><strong>{{ currency }} {{ money(props.balanceSheet.total_equity) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
                <p class="bs-hint">{{ t('bsNetProfitToDateHint') }}</p>
            </div>

            <!-- Assets = Liabilities + Equity, the headline check -->
            <div class="card bs-summary">
                <div class="bs-summary-row">
                    <span>{{ t('bsTotalAssetsLbl') }}</span>
                    <strong>{{ currency }} {{ money(props.balanceSheet.total_assets) }}</strong>
                </div>
                <div class="bs-summary-row bs-summary-row--total">
                    <span>{{ t('bsTotalLiabilitiesEquityLbl') }}</span>
                    <strong>{{ currency }} {{ money(props.balanceSheet.total_liabilities + props.balanceSheet.total_equity) }}</strong>
                </div>
            </div>
        </template>

        <!-- ═══════════════════════════ JOURNAL ═══════════════════════ -->
        <template v-else-if="isJournal && props.entries">
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

.bs-section { margin-bottom: 16px; }
.bs-hint { font-size: 12px; color: var(--color-text-muted); margin: 10px 0 0; }

.bs-summary { margin-top: 4px; }
.bs-summary-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid var(--color-border);
    font-size: 13.5px;
}
.bs-summary-row:last-child { border-bottom: none; }
.bs-summary-row--total { border-top: 2px solid var(--color-border); margin-top: 4px; font-weight: 600; color: var(--color-primary-dark); }

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
