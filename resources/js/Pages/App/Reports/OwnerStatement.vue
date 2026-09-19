<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Reports/OwnerStatement.vue
//  Location: resources/js/Pages/App/Reports/OwnerStatement.vue
//
//  Two different questions behind one toggle:
//    - Withdrawals Statement: capital paid in (capital_injection,
//      repay_withdrawal) and capital drawn out (withdrawal) — what
//      an owner has put in and taken out of the business.
//    - Profit Pay Statement: only profit_distribution rows — what's
//      actually been paid out against profit, separate from capital
//      movements entirely.
//
//  Unlike Customer/Supplier Statement, "All Owners" is a real,
//  selectable option here (not just "nothing chosen yet") — with it
//  selected, each row shows which owner it belongs to and no single
//  running balance is shown (mixing owners into one running number
//  would be meaningless — see ReportDataService::ownerStatement()'s
//  doc comment). Selecting one specific owner narrows to just them
//  and adds the running-balance column.
// ══════════════════════════════════════════════════════════════════

import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';
import { useMoneyFormat } from '@/composables/useMoneyFormat';

const props = defineProps({
    owners: { type: Array, required: true },
    owner: { type: [Object, null], default: null },
    type: { type: String, default: 'withdrawals' },
    entries: { type: Array, default: () => [] },
    total_in: { type: Number, default: 0 },
    total_out: { type: Number, default: 0 },
    net: { type: Number, default: 0 },
    running_balance: { type: Boolean, default: false },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
});

const from = ref(props.from ?? '');
const to   = ref(props.to ?? '');

const { t } = useAppTranslations();
const { currency, money } = useMoneyFormat();

const rangeQuery = computed(() => ({
    type: props.type,
    ...(from.value ? { from: from.value } : {}),
    ...(to.value ? { to: to.value } : {}),
}));

function reload(ownerId = props.owner?.id, type = props.type) {
    router.get(
        ownerId
            ? route('app.reports.owner-statement', ownerId)
            : route('app.reports.owner-statement'),
        { ...rangeQuery.value, type },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function onOwnerChange(e) {
    reload(e.target.value || null);
}

function setType(type) {
    if (type === props.type) return;
    reload(props.owner?.id, type);
}

function clearRange() {
    from.value = '';
    to.value = '';
    reload();
}

const hasRange = computed(() => !!(props.from || props.to));
</script>

<template>
    <Head :title="t('report_owner_statement')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('report_owner_statement') }}</h1>
        </div>

        <div class="direction-toggle no-print" style="margin-bottom: 14px;">
            <button
                type="button"
                class="toggle-btn"
                :class="{ 'toggle-btn--active': props.type === 'withdrawals' }"
                @click="setType('withdrawals')"
            >
                {{ t('owner_withdrawals_statement_toggle') }}
            </button>
            <button
                type="button"
                class="toggle-btn"
                :class="{ 'toggle-btn--active': props.type === 'profit' }"
                @click="setType('profit')"
            >
                {{ t('owner_profit_statement_toggle') }}
            </button>
        </div>

        <div class="report-filters no-print">
            <div class="field">
                <label>{{ t('selectOwnerLbl') }}</label>
                <select class="form-select" :value="props.owner?.id ?? ''" @change="onOwnerChange">
                    <option value="">{{ t('allOwnersLbl') }}</option>
                    <option v-for="o in props.owners" :key="o.id" :value="o.id">{{ o.name }}</option>
                </select>
            </div>

            <div class="field">
                <label for="owner-from">{{ t('fromLbl') }}</label>
                <input id="owner-from" v-model="from" type="date" class="inp-date" @change="reload()">
            </div>
            <div class="field">
                <label for="owner-to">{{ t('toLbl') }}</label>
                <input id="owner-to" v-model="to" type="date" class="inp-date" @change="reload()">
            </div>
            <div v-if="hasRange" class="field field--action">
                <button type="button" class="btn btn-ghost btn-sm" @click="clearRange">{{ t('wholeHistoryLbl') }}</button>
            </div>
        </div>

        <div class="balance-strip">
            <span class="label">
                {{ props.type === 'withdrawals' ? t('owner_net_contributed_lbl') : t('owner_total_profit_paid_lbl') }}
                <template v-if="props.owner"> — {{ props.owner.name }}</template>
            </span>
            <span class="value">{{ currency }} {{ money(props.type === 'withdrawals' ? props.net : props.total_out) }}</span>
        </div>

        <div v-if="props.entries.length === 0" class="empty">{{ t('report_no_entries') }}</div>

        <div v-else class="report-table-wrap card">
            <table class="report-table report-table--stack">
                <thead>
                    <tr>
                        <th>{{ t('dateLbl') }}</th>
                        <th v-if="!props.owner">{{ t('ownerLbl') }}</th>
                        <th>{{ t('referenceLbl') }}</th>
                        <th class="num">{{ t('owner_amountInLbl') }}</th>
                        <th class="num">{{ t('owner_amountOutLbl') }}</th>
                        <th v-if="props.running_balance" class="num">{{ t('runningBalanceLbl') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(entry, idx) in props.entries" :key="idx">
                        <td :data-label="t('dateLbl')" class="stack-primary">{{ entry.date }}</td>
                        <td v-if="!props.owner" :data-label="t('ownerLbl')">{{ entry.owner }}</td>
                        <td :data-label="t('referenceLbl')">{{ entry.ref }}<span v-if="entry.note"> — {{ entry.note }}</span></td>
                        <td class="num" :data-label="t('owner_amountInLbl')">{{ entry.amount_in > 0 ? `${currency} ${money(entry.amount_in)}` : '—' }}</td>
                        <td class="num" :data-label="t('owner_amountOutLbl')">{{ entry.amount_out > 0 ? `${currency} ${money(entry.amount_out)}` : '—' }}</td>
                        <td v-if="props.running_balance" class="num" :data-label="t('runningBalanceLbl')"><strong>{{ currency }} {{ money(entry.balance) }}</strong></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td :colspan="props.owner ? 2 : 3" class="grandtotal-label">{{ t('totalsLbl') }}</td>
                        <td class="num"><strong>{{ currency }} {{ money(props.total_in) }}</strong></td>
                        <td class="num"><strong>{{ currency }} {{ money(props.total_out) }}</strong></td>
                        <td v-if="props.running_balance" class="num"><strong>{{ currency }} {{ money(props.net) }}</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
.field--action { flex: 0 0 auto; justify-content: flex-end; }
.grandtotal-label { text-align: end; font-weight: 600; color: var(--color-primary-dark); }
@media (max-width: 640px) { .grandtotal-label { display: none; } }

.direction-toggle { display: flex; gap: 8px; }
.toggle-btn {
    padding: 8px 16px;
    border-radius: var(--radius-md);
    border: 1px solid var(--color-border);
    background: var(--color-surface);
    color: var(--color-text);
    cursor: pointer;
    font-weight: 600;
}
.toggle-btn--active {
    background: var(--color-primary);
    border-color: var(--color-primary);
    color: var(--color-on-primary, #fff);
}
</style>
