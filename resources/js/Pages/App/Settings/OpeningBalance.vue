<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Settings/OpeningBalance.vue
//  Location: resources/js/Pages/App/Settings/OpeningBalance.vue
//
//  A one-time setup screen for a company just starting to use
//  Maliyat Docs with real, pre-existing balances. Plain questions,
//  no accounting words anywhere on this page ("debit"/"credit"/
//  "journal"/"equity" never appear) — the double-entry bookkeeping
//  behind it lives entirely in OpeningBalanceService/JournalService.
//
//  Once posted, this screen goes read-only and shows what was
//  entered (read back from the real Sale/Expense/InventoryPurchase/
//  EquipmentPurchase records it created — see
//  OpeningBalanceController::index()). A company_admin can reset it
//  to redo the whole thing if the first attempt had mistakes.
// ══════════════════════════════════════════════════════════════════

import { ref, computed } from 'vue';
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormInstructions from '@/Components/App/FormInstructions.vue';
import AppIcon from '@/Components/App/AppIcon.vue';
import ComboSelect from '@/Components/App/ComboSelect.vue';
import ConfirmDialog from '@/Components/App/ConfirmDialog.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';
import { useBusinessType } from '@/composables/useBusinessType';

const props = defineProps({
    header: { type: Object, required: true },
    lines: { type: [Object, null], default: null },
    customers: { type: Array, default: () => [] },
    vendors: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    equipmentCategories: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const page = usePage();
const { t, locale } = useAppTranslations();
const currency = computed(() => page.props.auth?.user?.company?.currency ?? 'EGP');

function money(v) {
    return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(v || 0);
}

const isPosted = computed(() => props.header.status === 'posted');
const { isProduction } = useBusinessType();

// ── Quick-add lookup lists (customer / vendor / item) ─────────────
const customerList = ref([...props.customers]);
const vendorList = ref([...props.vendors]);
const itemList = ref([...props.items]);
const categoryList = ref([...props.equipmentCategories]);
const creatingCustomer = ref(null); // row index currently creating, or null
const creatingVendor = ref(null);
const creatingItem = ref(null);

// Transient — kept outside form state so it's never submitted as
// part of the opening balance itself. Read once, at the moment a
// brand-new item is actually created; meaningless once an existing
// item is picked instead. Same reasoning as the identical flag on
// the Inventory Purchase page.
const newItemIsRawMaterial = ref({});

async function quickAdd(kind, name, list, routeName, onDone, extra = {}) {
    const { data } = await axios.post(route(routeName), { name, ...extra }, { headers: { Accept: 'application/json' } });
    list.value.push(data);
    onDone(data.id);
}

// ── Form state ─────────────────────────────────────────────────
const form = useForm({
    opening_date: props.header.opening_date,
    cash_amount: props.header.cash_amount || null,
    bank_amount: props.header.bank_amount || null,
    customers: [{ customer_id: null, amount: null }],
    suppliers: [{ vendor_id: null, amount: null }],
    inventory: [{ item_id: null, qty: null, unit_price: null }],
    equipment: [{ name: '', category_id: null, amount: null, date: props.header.opening_date }],
});

function addRow(list, blank) {
    list.push({ ...blank });
}
function removeRow(list, idx) {
    if (list.length > 1) list.splice(idx, 1);
}

const inventoryLineTotal = (row) => (Number(row.qty) || 0) * (Number(row.unit_price) || 0);
const inventoryTotal = computed(() => form.inventory.reduce((sum, row) => sum + inventoryLineTotal(row), 0));

const showResetConfirm = ref(false);

function submit() {
    form.post(route('app.opening-balance.store'), { preserveScroll: true });
}

function confirmReset() {
    showResetConfirm.value = true;
}
function doReset() {
    router.delete(route('app.opening-balance.reset'), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('opening_balance_title')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('opening_balance_title') }}</h1>
        </div>

        <FormInstructions
            form-key="opening"
            :steps="['howto_opening_1', 'howto_opening_2', 'howto_opening_3', 'howto_opening_4', 'howto_opening_5', 'howto_opening_6']"
            tip-key="howto_opening_tip"
        />
        <p class="ob-subtitle">{{ t('opening_balance_subtitle') }}</p>

        <!-- ══════════════ POSTED — read-only summary ══════════════ -->
        <template v-if="isPosted">
            <div class="card ob-posted-banner">
                <AppIcon name="box" />
                <span>{{ t('opening_balance_posted_notice') }}</span>
            </div>

            <div class="card ob-section">
                <h3 class="sub">{{ t('ob_cash_bank_title') }}</h3>
                <div class="ob-summary-row"><span>{{ t('ob_cash_label') }}</span><strong>{{ currency }} {{ money(header.cash_amount) }}</strong></div>
                <div class="ob-summary-row"><span>{{ t('ob_bank_label') }}</span><strong>{{ currency }} {{ money(header.bank_amount) }}</strong></div>
            </div>

            <div v-if="lines.customers.length" class="card ob-section">
                <h3 class="sub">{{ t('ob_customers_title') }}</h3>
                <div v-for="row in lines.customers" :key="row.id" class="ob-summary-row">
                    <span>{{ row.customer_name }}</span><strong>{{ currency }} {{ money(row.amount) }}</strong>
                </div>
            </div>

            <div v-if="lines.suppliers.length" class="card ob-section">
                <h3 class="sub">{{ t('ob_suppliers_title') }}</h3>
                <div v-for="row in lines.suppliers" :key="row.id" class="ob-summary-row">
                    <span>{{ row.vendor_name }}</span><strong>{{ currency }} {{ money(row.amount) }}</strong>
                </div>
            </div>

            <div v-if="lines.inventory.length" class="card ob-section">
                <h3 class="sub">{{ t('ob_inventory_title') }}</h3>
                <div v-for="row in lines.inventory" :key="row.id" class="ob-summary-row">
                    <span>{{ row.item_name }} — {{ row.qty }}</span><strong>{{ currency }} {{ money(row.line_total) }}</strong>
                </div>
            </div>

            <div v-if="lines.equipment.length" class="card ob-section">
                <h3 class="sub">{{ t('ob_equipment_title') }}</h3>
                <div v-for="row in lines.equipment" :key="row.id" class="ob-summary-row">
                    <span>{{ row.name }} — {{ row.category_name }}</span><strong>{{ currency }} {{ money(row.amount) }}</strong>
                </div>
            </div>

            <div v-if="canManage" class="ob-reset-wrap">
                <button type="button" class="btn btn-secondary" @click="confirmReset">{{ t('ob_reset_button') }}</button>
                <p class="ob-reset-hint">{{ t('ob_reset_hint') }}</p>
            </div>
        </template>

        <!-- ══════════════ DRAFT — the wizard ══════════════ -->
        <form v-else @submit.prevent="submit">
            <div v-if="!canManage" class="empty">{{ t('ob_admin_only') }}</div>

            <template v-else>
                <div class="form-group">
                    <label class="form-label">{{ t('ob_date_label') }}</label>
                    <input type="date" class="form-input" v-model="form.opening_date" style="max-width: 220px;" />
                </div>

                <!-- Cash & Bank -->
                <div class="card ob-section">
                    <h3 class="sub">{{ t('ob_cash_bank_title') }}</h3>
                    <p class="ob-help">{{ t('ob_cash_bank_help') }}</p>
                    <div class="field-row">
                        <div class="field">
                            <label>{{ t('ob_cash_label') }}</label>
                            <input type="number" step="0.01" min="0" class="form-input inp-money" v-model="form.cash_amount" placeholder="0.00" />
                        </div>
                        <div class="field">
                            <label>{{ t('ob_bank_label') }}</label>
                            <input type="number" step="0.01" min="0" class="form-input inp-money" v-model="form.bank_amount" placeholder="0.00" />
                        </div>
                    </div>
                </div>

                <!-- Customers -->
                <div class="card ob-section">
                    <h3 class="sub">{{ t('ob_customers_title') }}</h3>
                    <p class="ob-help">{{ t('ob_customers_help') }}</p>

                    <div v-for="(row, idx) in form.customers" :key="idx" class="ob-row">
                        <ComboSelect
                            v-model="row.customer_id"
                            :options="customerList"
                            :placeholder="t('selectCustomerLbl')"
                            :creating="creatingCustomer === idx"
                            :inline="false"
                            @create="(name) => { creatingCustomer = idx; quickAdd('customer', name, customerList, 'app.customers.store', (id) => { row.customer_id = id; creatingCustomer = null; }); }"
                        />
                        <input type="number" step="0.01" min="0" class="form-input inp-money" v-model="row.amount" placeholder="0.00" />
                        <button type="button" class="ob-row-remove" @click="removeRow(form.customers, idx)" :aria-label="t('ob_remove_row')">
                            <AppIcon name="close" />
                        </button>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" @click="addRow(form.customers, { customer_id: null, amount: null })">
                        + {{ t('ob_add_customer') }}
                    </button>
                </div>

                <!-- Suppliers -->
                <div class="card ob-section">
                    <h3 class="sub">{{ t('ob_suppliers_title') }}</h3>
                    <p class="ob-help">{{ t('ob_suppliers_help') }}</p>

                    <div v-for="(row, idx) in form.suppliers" :key="idx" class="ob-row">
                        <ComboSelect
                            v-model="row.vendor_id"
                            :options="vendorList"
                            :placeholder="t('selectVendorLbl')"
                            :creating="creatingVendor === idx"
                            :inline="false"
                            @create="(name) => { creatingVendor = idx; quickAdd('vendor', name, vendorList, 'app.vendors.store', (id) => { row.vendor_id = id; creatingVendor = null; }); }"
                        />
                        <input type="number" step="0.01" min="0" class="form-input inp-money" v-model="row.amount" placeholder="0.00" />
                        <button type="button" class="ob-row-remove" @click="removeRow(form.suppliers, idx)" :aria-label="t('ob_remove_row')">
                            <AppIcon name="close" />
                        </button>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" @click="addRow(form.suppliers, { vendor_id: null, amount: null })">
                        + {{ t('ob_add_supplier') }}
                    </button>
                </div>

                <!-- Inventory -->
                <div class="card ob-section">
                    <h3 class="sub">{{ t('ob_inventory_title') }}</h3>
                    <p class="ob-help">{{ t('ob_inventory_help') }}</p>

                    <div v-for="(row, idx) in form.inventory" :key="idx" class="ob-row ob-row--inventory">
                        <div class="ob-item-cell">
                            <ComboSelect
                                v-model="row.item_id"
                                :options="itemList"
                                :placeholder="t('selectProductLbl')"
                                :creating="creatingItem === idx"
                                :inline="false"
                                @create="(name) => { creatingItem = idx; quickAdd('item', name, itemList, 'app.items.store', (id) => { row.item_id = id; creatingItem = null; delete newItemIsRawMaterial[idx]; }, { type: newItemIsRawMaterial[idx] ? 'raw_material' : 'trading' }); }"
                            />
                            <label v-if="isProduction" class="raw-material-check" @mousedown.prevent>
                                <input type="checkbox" v-model="newItemIsRawMaterial[idx]">
                                {{ t('newItemIsRawMaterialLbl') }}
                            </label>
                        </div>
                        <input type="number" step="0.01" min="0" class="form-input inp-count" v-model="row.qty" :placeholder="t('ob_qty_placeholder')" />
                        <input type="number" step="0.01" min="0" class="form-input inp-money" v-model="row.unit_price" :placeholder="t('ob_unit_cost_placeholder')" />
                        <span class="ob-line-total">{{ currency }} {{ money(inventoryLineTotal(row)) }}</span>
                        <button type="button" class="ob-row-remove" @click="removeRow(form.inventory, idx)" :aria-label="t('ob_remove_row')">
                            <AppIcon name="close" />
                        </button>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" @click="addRow(form.inventory, { item_id: null, qty: null, unit_price: null })">
                        + {{ t('ob_add_item') }}
                    </button>
                    <div class="ob-summary-row ob-summary-row--total">
                        <span>{{ t('ob_inventory_total_label') }}</span><strong>{{ currency }} {{ money(inventoryTotal) }}</strong>
                    </div>
                </div>

                <!-- Equipment -->
                <div class="card ob-section">
                    <h3 class="sub">{{ t('ob_equipment_title') }}</h3>
                    <p class="ob-help">{{ t('ob_equipment_help') }}</p>

                    <div v-for="(row, idx) in form.equipment" :key="idx" class="ob-row ob-row--equipment">
                        <input type="text" class="form-input" v-model="row.name" :placeholder="t('ob_equipment_name_placeholder')" />
                        <select class="form-select" v-model="row.category_id">
                            <option :value="null">{{ t('ob_equipment_type_placeholder') }}</option>
                            <option v-for="c in categoryList" :key="c.id" :value="c.id">
                                {{ locale === 'ar' && c.name_ar ? c.name_ar : c.name }}
                            </option>
                        </select>
                        <input type="number" step="0.01" min="0" class="form-input inp-money" v-model="row.amount" placeholder="0.00" />
                        <input type="date" class="form-input inp-date" v-model="row.date" />
                        <button type="button" class="ob-row-remove" @click="removeRow(form.equipment, idx)" :aria-label="t('ob_remove_row')">
                            <AppIcon name="close" />
                        </button>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" @click="addRow(form.equipment, { name: '', category_id: null, amount: null, date: form.opening_date })">
                        + {{ t('ob_add_equipment') }}
                    </button>
                </div>

                <div class="ob-submit-row">
                    <button type="submit" class="btn btn-primary btn-block" :disabled="form.processing">
                        {{ t('ob_submit_button') }}
                    </button>
                    <p class="ob-submit-hint">{{ t('ob_submit_hint') }}</p>
                </div>
            </template>
        </form>

        <ConfirmDialog
            v-model:open="showResetConfirm"
            :title="t('ob_reset_confirm_title')"
            :message="t('ob_reset_confirm_message')"
            :confirm-label="t('ob_reset_button')"
            :cancel-label="t('cancelBtn')"
            danger
            @confirm="doReset"
        />
    </AppLayout>
</template>

<style scoped>
.ob-subtitle { color: var(--color-text-muted); font-size: 13.5px; margin: -12px 0 18px; }
.ob-section { margin-bottom: 16px; }
.ob-help { font-size: 12.5px; color: var(--color-text-muted); margin: -4px 0 12px; }

.ob-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}
.ob-row > :first-child { flex: 1 1 auto; min-width: 0; }
.ob-item-cell { display: flex; flex-direction: column; gap: 2px; flex: 1 1 auto; min-width: 0; }
.raw-material-check {
    display: flex; align-items: center; gap: 4px;
    font-size: 11.5px; color: var(--color-text-secondary);
    white-space: nowrap;
}
.raw-material-check input[type="checkbox"] {
    margin: 0 !important; padding: 0; width: 14px; height: 14px; flex-shrink: 0;
}
.ob-row .inp-money { width: 130px; flex-shrink: 0; }
.ob-row--inventory .inp-count { width: 90px; flex-shrink: 0; }
.ob-row--inventory .ob-line-total { width: 110px; flex-shrink: 0; text-align: end; font-weight: 600; color: var(--color-primary-dark); font-size: 13px; }
.ob-row--equipment { flex-wrap: wrap; }
.ob-row--equipment .form-input { flex: 1 1 160px; }
.ob-row--equipment .form-select { flex: 1 1 140px; }
.ob-row--equipment .inp-date { width: 150px; flex-shrink: 0; }

.ob-row-remove {
    flex-shrink: 0;
    width: 28px; height: 28px;
    border-radius: 50%;
    border: none;
    background: var(--color-surface-alt);
    color: var(--color-text-muted);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
}
.ob-row-remove:hover { background: var(--color-danger); color: #fff; }
.ob-row-remove svg { width: 12px; height: 12px; }

.ob-summary-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid var(--color-border);
    font-size: 13.5px;
}
.ob-summary-row:last-child { border-bottom: none; }
.ob-summary-row--total { border-top: 2px solid var(--color-border); margin-top: 4px; font-weight: 600; color: var(--color-primary-dark); }

.ob-submit-row { margin: 24px 0 40px; }
.ob-submit-hint { text-align: center; font-size: 12px; color: var(--color-text-muted); margin-top: 8px; }

.ob-posted-banner {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 16px; margin-bottom: 16px;
    color: var(--color-primary-dark);
    font-size: 13.5px;
}
.ob-posted-banner svg { width: 20px; height: 20px; flex-shrink: 0; }

.ob-reset-wrap { text-align: center; margin: 24px 0 40px; }
.ob-reset-hint { font-size: 12px; color: var(--color-text-muted); margin-top: 8px; max-width: 360px; margin-inline: auto; }
</style>
