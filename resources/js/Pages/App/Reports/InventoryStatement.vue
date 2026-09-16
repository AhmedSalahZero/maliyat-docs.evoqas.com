<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Reports/InventoryStatement.vue
//  Location: resources/js/Pages/App/Reports/InventoryStatement.vue
//
//  Quantity and value per SKU, using weighted-average cost — see
//  Item::averagePurchaseCost() / ReportDataService::inventoryStatement().
//  Both purchases/sales AND Production Order output/consumption feed
//  the numbers here now, straight from the same Item model methods
//  Production and Sales already rely on for their own stock checks —
//  see the class doc comment on Item.php.
//
//  Two views, both server-rendered by the same page (no client
//  math): pick a product from the dropdown to see its own stat
//  boxes + full purchase/sale transaction history (the drill-down
//  ledger-prototype-v8.html had — restored here); leave it on
//  "All products" for the summary table. The Quantity/Value toggle
//  doesn't change what's fetched, only which columns lead in the
//  summary table, since most users care about one or the other at
//  a time, not all six numbers at once.
// ══════════════════════════════════════════════════════════════════

import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppIcon from '@/Components/App/AppIcon.vue';
import ReportToolbar from '@/Components/App/ReportToolbar.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';

const props = defineProps({
    items: { type: Array, required: true },
    total_stock_value: { type: Number, default: 0 },
    product_options: { type: Array, required: true },
    selected_item_id: { type: [Number, null], default: null },
    selected: { type: [Object, null], default: null },
    history: { type: Array, default: () => [] },
    mode: { type: String, default: 'quantity' },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
});

// Opt-in, starting empty. Stock on hand TODAY is the default
// question; a range answers "what moved during this period" without
// pretending purchases made before it never happened — the closing
// figure still counts everything up to the end of the window (see
// ReportDataService::inventoryStatement()).
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

function qty(v) {
    return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        maximumFractionDigits: 2,
    }).format(v || 0);
}

// ── Filters (product + mode) — both round-trip to the server so
//    the drill-down history is always real data, never recomputed
//    on the client from a partial dataset. ──────────────────────
function goTo(itemId, mode) {
    router.get(route('app.reports.inventory-statement'), {
        item_id: itemId || undefined,
        mode,
        ...rangeQuery.value,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

const rangeQuery = computed(() => ({
    ...(from.value ? { from: from.value } : {}),
    ...(to.value ? { to: to.value } : {}),
}));

const hasRange = computed(() => !!(props.from || props.to));

function applyRange() {
    goTo(props.selected_item_id, props.mode);
}

function clearRange() {
    from.value = '';
    to.value = '';
    applyRange();
}

function onProductChange(e) {
    goTo(e.target.value || null, props.mode);
}

function setMode(newMode) {
    if (newMode === props.mode) return;
    goTo(props.selected_item_id, newMode);
}

function backToAll() {
    goTo(null, props.mode);
}

// ── Export/print links carry the same filters as the screen ────
const excelHref = computed(() => route('app.reports.inventory-statement.export', {
    format: 'excel', item_id: props.selected_item_id || undefined, ...rangeQuery.value,
}));
const pdfHref = computed(() => route('app.reports.inventory-statement.export', {
    format: 'pdf', item_id: props.selected_item_id || undefined, ...rangeQuery.value,
}));

function stockBadge(item) {
    if (item.is_negative) return { cls: 'stock-negative', label: t('negativeStockBadge') };
    if (item.is_out_of_stock) return { cls: 'stock-out', label: t('outOfStockBadge') };
    return null;
}

function historyTypeLabel(type) {
    return {
        purchase: t('purchaseLbl'),
        sale: t('saleLbl'),
        produced: t('producedLbl'),
        consumed: t('consumedLbl'),
    }[type] ?? type;
}
</script>

<template>
    <Head :title="t('report_inventory_statement')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('report_inventory_statement') }}</h1>
        </div>

        <ReportToolbar :excel-href="excelHref" :pdf-href="pdfHref" />

        <div v-if="props.product_options.length === 0" class="empty">{{ t('noItemsYet') }}</div>

        <template v-else>
            <!-- ── Filters: product selector + quantity/value toggle ── -->
            <div class="report-filters no-print">
                <div class="field">
                    <label>{{ t('selectProductLbl') }}</label>
                    <select class="form-select" :value="props.selected_item_id ?? ''" @change="onProductChange">
                        <option value="">{{ t('allProductsLbl') }}</option>
                        <option v-for="opt in props.product_options" :key="opt.id" :value="opt.id">{{ opt.name }}</option>
                    </select>
                </div>
                <div class="field">
                    <label for="inv-from">{{ t('fromLbl') }}</label>
                    <input id="inv-from" v-model="from" type="date" class="inp-date" @change="applyRange">
                </div>
                <div class="field">
                    <label for="inv-to">{{ t('toLbl') }}</label>
                    <input id="inv-to" v-model="to" type="date" class="inp-date" @change="applyRange">
                </div>
                <div v-if="hasRange" class="field field--action">
                    <button type="button" class="btn btn-ghost btn-sm" @click="clearRange">{{ t('allTimeLbl') }}</button>
                </div>

                <div class="report-mode-toggle">
                    <span class="report-mode-toggle__label">{{ t('quantityModeLbl') }} / {{ t('valueModeLbl') }}</span>
                    <div class="toggle-btns">
                        <button type="button" :class="{ active: props.mode === 'quantity' }" @click="setMode('quantity')">{{ t('quantityModeLbl') }}</button>
                        <button type="button" :class="{ active: props.mode === 'value' }" @click="setMode('value')">{{ t('valueModeLbl') }}</button>
                    </div>
                </div>
            </div>

            <!-- ══════════════ ONE PRODUCT SELECTED: drill-down ══════════════ -->
            <template v-if="props.selected">
                <button type="button" class="inv-detail-back no-print" @click="backToAll">
                    <AppIcon name="chevron" class="inv-detail-back__icon" />
                    {{ t('backToAllProductsLbl') }}
                </button>

                <div class="card card--stock">
                    <h2>{{ props.selected.name }}</h2>

                    <div class="stats-row">
                        <div class="stat-box">
                            <div class="stat-box__label">{{ t('totalInLbl') }}</div>
                            <div class="stat-box__value">{{ qty(props.selected.total_in_base) }} {{ props.selected.base_unit_name }}</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-box__label">{{ t('totalOutLbl') }}</div>
                            <div class="stat-box__value">{{ qty(props.selected.total_out_base) }} {{ props.selected.base_unit_name }}</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-box__label">{{ t('currentStockLbl') }}</div>
                            <div class="stat-box__value">{{ qty(props.selected.current_stock) }} {{ props.selected.base_unit_name }}</div>
                        </div>
                        <div class="stat-box" v-if="props.mode === 'value'">
                            <div class="stat-box__label">{{ t('stockValueLbl') }}</div>
                            <div class="stat-box__value">{{ currency }} {{ money(props.selected.stock_value) }}</div>
                        </div>
                    </div>
                </div>

                <h3 class="sub">{{ t('transactionHistoryLbl') }}</h3>

                <div v-if="props.history.length === 0" class="empty">{{ t('report_no_entries') }}</div>
                <div v-else class="report-table-wrap card">
                    <table class="report-table report-table--stack inv-history-table">
                        <thead>
                            <tr>
                                <th>{{ t('dateLbl') }}</th>
                                <th>{{ t('typeLbl') }}</th>
                                <th class="num">{{ t('qtyLbl') }}</th>
                                <th class="num">{{ t('unitPriceLbl') }}</th>
                                <th class="num">{{ t('stockAfterLbl') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, idx) in props.history" :key="idx">
                                <td :data-label="t('dateLbl')">{{ row.date }}</td>
                                <td :data-label="t('typeLbl')">
                                    <span class="badge" :class="row.qty >= 0 ? 'in' : 'out'">
                                        {{ historyTypeLabel(row.type) }}
                                    </span>
                                </td>
                                <td class="num" :data-label="t('qtyLbl')" :style="{ color: row.qty >= 0 ? 'var(--color-money-in)' : 'var(--color-money-out)' }">
                                    {{ row.qty >= 0 ? '+' : '' }}{{ qty(row.qty) }} {{ props.selected.base_unit_name }}
                                </td>
                                <td class="num" :data-label="t('unitPriceLbl')">{{ currency }} {{ money(row.unit_price) }}</td>
                                <td class="num" :data-label="t('stockAfterLbl')"><strong>{{ qty(row.stock_after) }} {{ props.selected.base_unit_name }}</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- ══════════════ ALL PRODUCTS: summary table ══════════════ -->
            <template v-else>
                <div class="stats-row">
                    <div class="stat-box">
                        <div class="stat-box__label">{{ t('totalStockValueLbl') }}</div>
                        <div class="stat-box__value">{{ currency }} {{ money(props.total_stock_value) }}</div>
                    </div>
                </div>

                <div class="report-table-wrap card">
                    <table class="report-table report-table--stack inv-statement-table">
                        <thead>
                            <tr>
                                <th>{{ t('itemLbl') }}</th>
                                <template v-if="props.mode === 'quantity'">
                                    <th class="num">{{ t('totalInLbl') }}</th>
                                    <th class="num">{{ t('totalOutLbl') }}</th>
                                    <th class="num">{{ t('currentStockLbl') }}</th>
                                </template>
                                <template v-else>
                                    <th class="num">{{ t('avgPurchaseCostLbl') }}</th>
                                    <th class="num">{{ t('currentStockLbl') }}</th>
                                    <th class="num">{{ t('stockValueLbl') }}</th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in props.items" :key="item.id">
                                <td :data-label="t('itemLbl')" class="inv-name stack-primary">
                                    <button type="button" class="inv-name-btn" @click="goTo(item.id, props.mode)">{{ item.name }}</button>
                                    <span v-if="stockBadge(item)" class="badge" :class="stockBadge(item).cls">{{ stockBadge(item).label }}</span>
                                </td>

                                <template v-if="props.mode === 'quantity'">
                                    <td class="num" :data-label="t('totalInLbl')">{{ qty(item.total_in_base) }} {{ item.base_unit_name }}</td>
                                    <td class="num" :data-label="t('totalOutLbl')">{{ qty(item.total_out_base) }} {{ item.base_unit_name }}</td>
                                    <td class="num" :data-label="t('currentStockLbl')"><strong>{{ qty(item.current_stock) }} {{ item.base_unit_name }}</strong></td>
                                </template>
                                <template v-else>
                                    <td class="num" :data-label="t('avgPurchaseCostLbl')">
                                        {{ item.avg_purchase_cost !== null ? `${currency} ${money(item.avg_purchase_cost)}` : '—' }}
                                    </td>
                                    <td class="num" :data-label="t('currentStockLbl')">{{ qty(item.current_stock) }} {{ item.base_unit_name }}</td>
                                    <td class="num" :data-label="t('stockValueLbl')"><strong>{{ currency }} {{ money(item.stock_value) }}</strong></td>
                                </template>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </template>
    </AppLayout>
</template>

<style scoped>
.field--action { flex: 0 0 auto; justify-content: flex-end; }

.inv-name { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.inv-name-btn {
    background: none; border: none; padding: 0; cursor: pointer;
    font: inherit; color: var(--color-primary-dark); font-weight: 600;
    text-decoration: underline; text-underline-offset: 2px;
}
.inv-name-btn:hover { color: var(--color-primary); }
</style>
