<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Reports/InventoryStatement.vue
//  Location: resources/js/Pages/App/Reports/InventoryStatement.vue
//
//  Quantity and value per SKU, using weighted-average cost — see
//  Item::averagePurchaseCost() (ReportController::inventoryStatement()
//  supplies the numbers, this just renders them). Ported from
//  ledger-prototype-v8.html's renderInvStatement(); the per-item
//  transaction-history drill-down (renderInvItemDetail()) isn't
//  included in this pass — this is the summary table only.
// ══════════════════════════════════════════════════════════════════

import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useAppTranslations } from '@/Composables/useAppTranslations';

const props = defineProps({
    items: { type: Array, required: true },
    total_stock_value: { type: Number, default: 0 },
});

const page = usePage();
const { t, locale } = useAppTranslations();
const currency = computed(() => page.props.auth?.user?.company?.currency ?? 'EGP');

function money(v) {
    return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(v || 0);
}

function qty(v) {
    return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        maximumFractionDigits: 2,
    }).format(v || 0);
}
</script>

<template>
    <Head :title="t('report_inventory_statement')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('report_inventory_statement') }}</h1>
        </div>

        <div v-if="props.items.length === 0" class="empty">{{ t('noItemsYet') }}</div>

        <template v-else>
            <div class="stats-row">
                <div class="stat-box">
                    <div class="stat-box__label">{{ t('totalStockValueLbl') }}</div>
                    <div class="stat-box__value">{{ currency }} {{ money(props.total_stock_value) }}</div>
                </div>
            </div>

            <div class="report-table-wrap card">
                <table class="report-table inv-statement-table">
                    <thead>
                        <tr>
                            <th>{{ t('itemLbl') }}</th>
                            <th class="num">{{ t('totalPurchasedLbl') }}</th>
                            <th class="num">{{ t('totalSoldLbl') }}</th>
                            <th class="num">{{ t('currentStockLbl') }}</th>
                            <th class="num">{{ t('avgPurchaseCostLbl') }}</th>
                            <th class="num">{{ t('stockValueLbl') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in props.items" :key="item.id">
                            <td :data-label="t('itemLbl')" class="inv-name">{{ item.name }}</td>
                            <td class="num" :data-label="t('totalPurchasedLbl')">{{ qty(item.total_purchased_base) }} {{ item.base_unit_name }}</td>
                            <td class="num" :data-label="t('totalSoldLbl')">{{ qty(item.total_sold_base) }} {{ item.base_unit_name }}</td>
                            <td class="num" :data-label="t('currentStockLbl')">
                                <strong>{{ qty(item.current_stock) }} {{ item.base_unit_name }}</strong>
                            </td>
                            <td class="num" :data-label="t('avgPurchaseCostLbl')">
                                {{ item.avg_purchase_cost !== null ? `${currency} ${money(item.avg_purchase_cost)}` : '—' }}
                            </td>
                            <td class="num" :data-label="t('stockValueLbl')">
                                <strong>{{ currency }} {{ money(item.stock_value) }}</strong>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </AppLayout>
</template>

<style scoped>
/* Same table→card mobile treatment as table.lines — a 6-column
   report table doesn't fit a phone any better than a line-items
   table does. */
@media (max-width: 640px) {
    .inv-statement-table, .inv-statement-table tbody, .inv-statement-table tr, .inv-statement-table td {
        display: block; width: auto;
    }
    .inv-statement-table thead { display: none; }
    .inv-statement-table tr {
        border: 1.5px solid var(--color-border);
        border-radius: var(--radius-md);
        padding: 10px 12px;
        margin-bottom: 10px;
    }
    .inv-statement-table td {
        border-bottom: none;
        padding: 5px 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        text-align: end;
    }
    .inv-statement-table td::before {
        content: attr(data-label);
        font-size: 11px;
        font-weight: 600;
        color: var(--color-text-muted);
        flex-shrink: 0;
        text-align: start;
    }
    .inv-statement-table td.inv-name {
        font-weight: 700;
        font-size: 14.5px;
        justify-content: flex-start;
    }
    .inv-statement-table td.inv-name::before { content: none; }
}
</style>
