<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Reports/Journal.vue
//  Location: resources/js/Pages/App/Reports/Journal.vue
//
//  Every posted journal entry in the selected range, each with its
//  own debit/credit lines — what an auditor uses to trace a Trial
//  Balance figure back to the transaction(s) that produced it.
// ══════════════════════════════════════════════════════════════════

import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReportToolbar from '@/Components/App/ReportToolbar.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';
import { useMoneyFormat } from '@/composables/useMoneyFormat';

const props = defineProps({
    entries: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
});

const page = usePage();
const { t, locale } = useAppTranslations();
const { currency, money } = useMoneyFormat();

const from = ref(props.from);
const to = ref(props.to);

function applyRange() {
    router.get(route('app.reports.journal'), { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true, replace: true });
}

const excelHref = computed(() => route('app.reports.journal.export', { from: from.value, to: to.value, format: 'excel' }));
const pdfHref = computed(() => route('app.reports.journal.export', { from: from.value, to: to.value, format: 'pdf' }));

function accountName(line) {
    return locale.value === 'ar' && line.account_name_ar ? line.account_name_ar : line.account_name;
}
</script>

<template>
    <Head :title="t('report_journal')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('report_journal') }}</h1>
        </div>

        <ReportToolbar :excel-href="excelHref" :pdf-href="pdfHref" />

        <div class="report-filters no-print">
            <div class="field">
                <label>{{ t('fromLbl') }}</label>
                <input type="date" class="form-input" v-model="from" @change="applyRange" />
            </div>
            <div class="field">
                <label>{{ t('toLbl') }}</label>
                <input type="date" class="form-input" v-model="to" @change="applyRange" />
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
                            <td>{{ accountName(line) }}</td>
                            <td class="num">{{ line.debit > 0 ? `${currency} ${money(line.debit)}` : '—' }}</td>
                            <td class="num">{{ line.credit > 0 ? `${currency} ${money(line.credit)}` : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.journal-list { display: flex; flex-direction: column; gap: 12px; }
.journal-entry { padding: 14px 16px; }
.journal-entry__head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; margin-bottom: 8px; flex-wrap: wrap; }
.journal-entry__date { font-family: var(--font-mono); font-size: 12.5px; color: var(--color-text-muted); }
.journal-entry__memo { font-size: 13.5px; font-weight: 600; color: var(--color-text-primary); }
.journal-entry__lines { margin-top: 0; }
.journal-entry__lines th { font-size: 11px; padding-bottom: 4px; }
.journal-entry__lines td { padding: 6px 8px; font-size: 13px; }
.badge--muted { background: var(--color-surface-alt); color: var(--color-text-muted); border-radius: var(--radius-pill); padding: 2px 8px; font-size: 10.5px; font-weight: 500; margin-inline-start: 6px; }
</style>
