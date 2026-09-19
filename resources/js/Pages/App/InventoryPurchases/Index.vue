<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — InventoryPurchases/Index.vue
//  Location: resources/js/Pages/App/InventoryPurchases/Index.vue
//
//  Ports ledger-prototype-v8.html's renderInventoryForm() — same
//  UOM-aware line table as the prototype: Item, Qty (in purchase
//  units, e.g. "how many cartons"), UOM name (e.g. "Carton"),
//  "= [N] [base unit]" (e.g. "= 24 unit" — how many base units per
//  purchase unit), Unit cost (price PER PURCHASE UNIT, not per
//  base unit), Line total.
//
//  Selecting an item auto-fills its last-used UOM definition (see
//  InventoryPurchaseController::createLines() — the item remembers
//  it after each purchase), matching the prototype's
//  handleItemChange() behavior exactly.
//
//  Inventory value uses weighted-average cost — see the Inventory
//  Statement report for the quantity/value per SKU this feeds.
//
//  Same Edit/Delete/rename patterns as Sales/Expenses.
// ══════════════════════════════════════════════════════════════════

import { ref, computed } from 'vue';
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormInstructions from '@/Components/App/FormInstructions.vue';
import AppIcon from '@/Components/App/AppIcon.vue';
import ComboSelect from '@/Components/App/ComboSelect.vue';
import PaymentMethodField from '@/Components/App/PaymentMethodField.vue';
import EditPaymentsPanel from '@/Components/App/EditPaymentsPanel.vue';
import ConfirmDialog from '@/Components/App/ConfirmDialog.vue';
import RenameModal from '@/Components/App/RenameModal.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';
import { useMoneyFormat } from '@/composables/useMoneyFormat';
import { todayIso } from '@/Utils/date';
import { scrollToForm } from '@/composables/useScrollToForm';
import { usePermissions } from '@/composables/usePermissions';
import { useBusinessType } from '@/composables/useBusinessType';

const props = defineProps({
    vendors:  { type: Array, required: true },
    items:    { type: Array, required: true }, // each: {id, name, uom, qty_per_uom, base_unit_name}
    paymentChannels: { type: Array, required: true },
    purchases: { type: Object, required: true },
});

const page = usePage();
const { t, locale } = useAppTranslations();

// The form card, so pressing Edit can bring the FORM into view
// rather than the top of the document — see useScrollToForm.
const formCard = ref(null);

// Delete is company-admin only — mirrors Controller::authorizeDelete().
const { canDelete } = usePermissions();
const { isProduction } = useBusinessType();
const { currency, money } = useMoneyFormat();

// Laravel's pagination links come as e.g. "&laquo; Previous" / "Next
// &raquo;" — decoding just these two known-safe arrow entities lets
// us render the label as plain (auto-escaped) text instead of
// v-html, which is unsafe by default (see QA audit L-1).
function paginationLabel(label) {
    return label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»');
}

function fmt(template, repl) {
    return Object.entries(repl).reduce((s, [k, v]) => s.replace(`:${k}`, v), template);
}

const vendorList = ref([...props.vendors]);
const itemList = ref([...props.items]);
const channelList = ref([...props.paymentChannels]);
const creatingVendor = ref(false);
const creatingItemForRow = ref(null);
const creatingChannel = ref(false);

// Transient — deliberately kept outside form.lines so it's never
// submitted as part of the purchase itself. Read once, at the
// moment a brand-new item is actually created (see createItem());
// meaningless once an existing item is picked instead.
const newItemIsRawMaterial = ref({});

const editingPurchase = ref(null);

function newLine() {
    return { item_id: null, qty: null, uom: 'Carton', qty_per_uom: 1, base_unit_name: 'unit', unit_price: null };
}

const defaultFormState = () => ({
    vendor_id: null,
    date: todayIso(),
    lines: [newLine()],
    vat_rate: 0,
    mode: 'now',
    method: 'cash',
    payment_channel_id: null,
    amount_now: null,
    due_in_days: 14,
    installment_count: 3,
    installment_interval_days: 30,
});

const form = useForm(defaultFormState());

const subtotal = computed(() =>
    form.lines.reduce((sum, line) => sum + (Number(line.qty) || 0) * (Number(line.unit_price) || 0), 0)
);
const vatAmount = computed(() => subtotal.value * (Number(form.vat_rate) || 0) / 100);
const total = computed(() => subtotal.value + vatAmount.value);

const installmentSchedule = computed(() => {
    if (form.mode !== 'installment' || total.value <= 0) return [];
    const count = Math.max(2, Number(form.installment_count) || 2);
    const interval = Math.max(1, Number(form.installment_interval_days) || 1);
    const per = Math.round((total.value / count) * 100) / 100;
    let allocated = 0;
    const today = new Date();
    return Array.from({ length: count }, (_, i) => {
        const isLast = i === count - 1;
        const amt = isLast ? Math.round((total.value - allocated) * 100) / 100 : per;
        allocated += amt;
        const due = new Date(today);
        due.setDate(due.getDate() + interval * (i + 1));
        return { sequence: i + 1, due_date: due.toISOString().slice(0, 10), amount: amt };
    });
});

function addLine() { form.lines.push(newLine()); }
function removeLine(index) {
    if (form.lines.length <= 1) return;
    form.lines.splice(index, 1);
}

// Selecting an item pre-fills its last-used UOM definition.
function onItemChange(index, itemId) {
    form.lines[index].item_id = itemId;
    const item = itemList.value.find((i) => i.id === itemId);
    if (item) {
        form.lines[index].uom = item.uom ?? 'Carton';
        form.lines[index].qty_per_uom = Number(item.qty_per_uom) || 1;
        form.lines[index].base_unit_name = item.base_unit_name ?? 'unit';
    }
}

async function createVendor(name) {
    creatingVendor.value = true;
    try {
        const { data } = await axios.post(route('app.vendors.store'), { name, type: 'vendor' }, { headers: { Accept: 'application/json' } });
        vendorList.value.push(data);
        form.vendor_id = data.id;
    } finally {
        creatingVendor.value = false;
    }
}

async function createItem(name, rowIndex) {
    creatingItemForRow.value = rowIndex;
    try {
        const type = newItemIsRawMaterial.value[rowIndex] ? 'raw_material' : 'trading';
        const { data } = await axios.post(route('app.items.store'), { name, type }, { headers: { Accept: 'application/json' } });
        itemList.value.push(data);
        onItemChange(rowIndex, data.id);
    } finally {
        creatingItemForRow.value = null;
        delete newItemIsRawMaterial.value[rowIndex];
    }
}

async function createChannel(name) {
    creatingChannel.value = true;
    try {
        const { data } = await axios.post(route('app.payment-channels.store'), { name }, { headers: { Accept: 'application/json' } });
        channelList.value.push(data);
        form.payment_channel_id = data.id;
    } finally {
        creatingChannel.value = false;
    }
}

// ── Rename vendor (pencil) ───────────────────────────────────────
const selectedVendor = computed(() => vendorList.value.find((v) => v.id === form.vendor_id) ?? null);
const showRenameVendor = ref(false);
const renaming = ref(false);

async function saveRenameVendor(newName) {
    if (!selectedVendor.value) return;
    renaming.value = true;
    try {
        const { data } = await axios.patch(route('app.vendors.update', selectedVendor.value.id), { name: newName }, { headers: { Accept: 'application/json' } });
        const idx = vendorList.value.findIndex((v) => v.id === data.id);
        if (idx !== -1) vendorList.value[idx] = data;
        showRenameVendor.value = false;
    } finally {
        renaming.value = false;
    }
}

function startEdit(purchase) {
    editingPurchase.value = purchase;
    form.vendor_id = purchase.vendor_id;
    form.date = purchase.date;
    form.lines = purchase.lines.length
        ? purchase.lines.map((l) => ({ ...l }))
        : [newLine()];
    form.vat_rate = purchase.vat_rate;
    form.due_date = editingPurchase.value?.due_date ?? null;
    form.clearErrors();
    scrollToForm(formCard);
}

function cancelEdit() {
    editingPurchase.value = null;
    Object.assign(form, defaultFormState());
    form.clearErrors();
}

function submit() {
    if (editingPurchase.value) {
        prepareEditSubmit();
    } else {
        submitCreate();
    }
}

function submitCreate() {
    form.transform((data) => ({
        ...data,
        lines: data.lines.filter((l) => l.item_id && Number(l.qty) > 0),
    })).post(route('app.inventory-purchases.store'), {
        preserveScroll: true,
        onSuccess: () => Object.assign(form, defaultFormState()),
    });
}

function doSubmitEdit(id) {
    form.transform((data) => ({
        vendor_id: data.vendor_id,
        date: data.date,
        vat_rate: data.vat_rate,
        lines: data.lines.filter((l) => l.item_id && Number(l.qty) > 0),
            due_date: data.due_date || null,
    })).put(route('app.inventory-purchases.update', id), {
        preserveScroll: true,
        onSuccess: () => cancelEdit(),
    });
}

// ── Confirm dialogs ─────────────────────────────────────────────
const confirmDialog = ref({ open: false, title: '', message: '', danger: false, action: null });

function prepareEditSubmit() {
    const purchase = editingPurchase.value;
    const newTotal = total.value;
    const paid = purchase.paid_amount;

    if (paid <= 0.004) {
        doSubmitEdit(purchase.id);
        return;
    }

    const diff = newTotal - paid;
    const message = diff >= 0
        ? fmt(t('editConfirmDeficitPayable'), { paid: `${currency.value} ${money(paid)}`, total: `${currency.value} ${money(newTotal)}`, amount: `${currency.value} ${money(diff)}` })
        : fmt(t('editConfirmSurplusPayable'), { paid: `${currency.value} ${money(paid)}`, total: `${currency.value} ${money(newTotal)}`, amount: `${currency.value} ${money(Math.abs(diff))}` });

    confirmDialog.value = { open: true, title: t('saveChangesBtn'), message, danger: false, action: () => doSubmitEdit(purchase.id) };
}

function confirmDelete(purchase) {
    const message = purchase.payments_count > 0
        ? fmt(t('deleteConfirmWithPayments'), { count: purchase.payments_count, amount: `${currency.value} ${money(purchase.paid_amount)}` })
        : t('deleteConfirmSimple');

    confirmDialog.value = {
        open: true, title: t('deleteBtn'), message, danger: true,
        action: () => router.delete(route('app.inventory-purchases.destroy', purchase.id), { preserveScroll: true }),
    };
}

function onConfirmDialogConfirm() {
    confirmDialog.value.action?.();
}
</script>

<template>
    <Head :title="t('action_inventory_title')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('action_inventory_title') }}</h1>
        </div>

        <FormInstructions
            v-if="!editingPurchase"
            form-key="inventory"
            :steps="['howto_inventory_1', 'howto_inventory_2', 'howto_inventory_3', 'howto_inventory_4', 'howto_inventory_5']"
            tip-key="howto_inventory_tip"
        />

        <div ref="formCard" class="card card--stock">
            <div v-if="editingPurchase" class="alert info">{{ t('editingBanner') }}</div>

            <div class="field-row" style="margin-bottom: 4px;">
                <div class="field">
                    <label>{{ t('dateLbl') }}</label>
                    <input v-model="form.date" type="date" :max="todayIso()" class="inp-date" style="width: 15rem;">
                </div>
            </div>
            <div v-if="form.errors.date" class="form-error">{{ form.errors.date }}</div>

            <div class="sentence">
                {{ t('buyFrom') }}
                <ComboSelect v-model="form.vendor_id" :options="vendorList" :creating="creatingVendor"
                             :placeholder="t('selectPlaceholder')" :add-new-label="t('addNewVendor')" @create="createVendor" />
                <button v-if="selectedVendor" type="button" class="inline-icon-btn" title="Rename" @click="showRenameVendor = true">
                    <AppIcon name="pencil" />
                </button>
            </div>
            <div v-if="form.errors.vendor_id" class="form-error">{{ form.errors.vendor_id }}</div>

            <table class="lines">
                <colgroup>
                    <col class="col-item"><col class="col-qty"><col class="col-uom"><col class="col-equals"><col class="col-price"><col class="col-total"><col class="col-rm">
                </colgroup>
                <thead>
                    <tr>
                        <th>{{ t('itemLbl') }}</th>
                        <th>{{ t('qtyLbl') }}</th>
                        <th>{{ t('uomLbl') }}</th>
                        <th>{{ t('equalsLbl') }}</th>
                        <th>{{ t('unitCostLbl') }}</th>
                        <th>{{ t('lineTotalLbl') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, index) in form.lines" :key="index">
                        <td :data-label="t('itemLbl')">
                            <ComboSelect
                                :model-value="line.item_id"
                                :options="itemList"
                                :creating="creatingItemForRow === index"
                                :placeholder="t('selectPlaceholder')"
                                :add-new-label="t('addNewItem')"
                                :inline="false"
                                @update:model-value="(val) => onItemChange(index, val)"
                                @create="(name) => createItem(name, index)"
                            />
                            <label v-if="isProduction" class="raw-material-check" @mousedown.prevent>
                                <input type="checkbox" v-model="newItemIsRawMaterial[index]">
                                {{ t('newItemIsRawMaterialLbl') }}
                            </label>
                        </td>
                        <td :data-label="t('qtyLbl')"><input v-model.number="line.qty" type="number" min="0" step="0.01" placeholder="0"></td>
                        <td :data-label="t('uomLbl')"><input v-model="line.uom" type="text" placeholder="Carton"></td>
                        <td :data-label="t('equalsLbl')">
                            <span class="equals-cell">
                                <span>=</span>
                                <input v-model.number="line.qty_per_uom" data-role="qtyperuom" type="number" min="0" placeholder="1" style="width:5rem">
                                <input v-model="line.base_unit_name" data-role="baseunit" type="text" placeholder="unit">
                            </span>
                        </td>
                        <td :data-label="t('unitCostLbl')"><input v-model.number="line.unit_price" type="number" min="0" step="0.01" placeholder="0.00"></td>
                        <td class="linetotal" :data-label="t('lineTotalLbl')">{{ currency }} {{ money((line.qty || 0) * (line.unit_price || 0)) }}</td>
                        <td class="rm-cell"><button type="button" class="rm-line" @click="removeLine(index)">✕</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="addline-btn" @click="addLine">{{ t('addLineBtn') }}</button>
            <div v-if="form.errors.lines" class="form-error">{{ form.errors.lines }}</div>

            <div class="field-row" style="margin-top: 16px;">
                <div class="field field--narrow">
                    <label>{{ t('vatRateLbl') }}</label>
                    <input v-model.number="form.vat_rate" type="number" step="0.5" min="0">
                </div>
            </div>

            <div style="margin-top: 6px;">
                <div style="display: flex; justify-content: space-between; padding: 4px 0; color: var(--color-text-muted); font-size: 13.5px;">
                    <span>{{ t('subtotalLbl') }}</span><span class="mono">{{ currency }} {{ money(subtotal) }}</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 4px 0; color: var(--color-text-muted); font-size: 13.5px;">
                    <span>{{ t('vatAmountLbl') }} ({{ form.vat_rate || 0 }}%)</span><span class="mono">{{ currency }} {{ money(vatAmount) }}</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 6px 0 0; font-weight: 600; font-size: 17px; color: var(--color-primary-dark);">
                    <span>{{ t('totalInclVatLbl') }}</span><span class="mono">{{ currency }} {{ money(total) }}</span>
                </div>
            </div>

            <div v-if="!editingPurchase" class="paymode-block">
                <div class="muted-inline" style="margin-bottom: 8px;">{{ t('paymentLbl') }}</div>
                <div class="toggle-btns">
                    <button type="button" :class="{ active: form.mode === 'now' }" @click="form.mode = 'now'">{{ t('payNowLbl') }}</button>
                    <button type="button" :class="{ active: form.mode === 'later' }" @click="form.mode = 'later'">{{ t('payLaterLbl') }}</button>
                    <button type="button" :class="{ active: form.mode === 'partial' }" @click="form.mode = 'partial'">{{ t('payPartialLbl') }}</button>
                    <button type="button" :class="{ active: form.mode === 'installment' }" @click="form.mode = 'installment'">{{ t('payInstallmentLbl') }}</button>
                </div>

                <div v-if="form.mode === 'now'" class="paymode-sub">
                    <PaymentMethodField v-model="form.method" v-model:channel-id="form.payment_channel_id"
                        :channels="channelList" :creating-channel="creatingChannel" @create-channel="createChannel" />
                </div>
                <div v-else-if="form.mode === 'later'" class="paymode-sub">
                    <span>{{ t('dueInLbl') }}</span>
                    <input v-model.number="form.due_in_days" type="number" class="inp-days">
                    <span>{{ t('daysLbl') }}</span>
                </div>
                <div v-else-if="form.mode === 'partial'" class="paymode-sub">
                    <span>{{ t('amountNowLbl') }}</span>
                    <span class="muted-inline">{{ currency }}</span>
                    <input v-model.number="form.amount_now" type="number" step="0.01" class="inp-money">
                    <PaymentMethodField v-model="form.method" v-model:channel-id="form.payment_channel_id"
                        :channels="channelList" :creating-channel="creatingChannel" @create-channel="createChannel" />
                    <span>{{ t('remainderDueLbl') }}</span>
                    <input v-model.number="form.due_in_days" type="number" class="inp-days">
                    <span>{{ t('daysLbl') }}</span>
                </div>
                <div v-if="form.errors.amount_now" class="form-error">{{ form.errors.amount_now }}</div>

                <div v-if="form.mode === 'installment'" class="paymode-sub">
                    <span>{{ t('numInstallmentsLbl') }}</span>
                    <input v-model.number="form.installment_count" type="number" min="2" class="inp-count">
                    <span>{{ t('everyDaysLbl') }}</span>
                    <input v-model.number="form.installment_interval_days" type="number" min="1" class="inp-days">
                    <span>{{ t('daysLbl') }}</span>
                </div>
                <ul v-if="form.mode === 'installment' && installmentSchedule.length" class="installment-preview">
                    <li v-for="row in installmentSchedule" :key="row.sequence">
                        #{{ row.sequence }} — {{ currency }} {{ money(row.amount) }} {{ t('installmentPlanNote') }} {{ row.due_date }}
                    </li>
                </ul>
            </div>

            <!-- Due date, edit only. On a NEW record the date is
                 implied by the payment mode above ("due in 30 days"),
                 but once the record exists that mode is history — what
                 remains is a concrete date, and it has to be
                 correctable. -->
            <div v-if="editingPurchase" class="field-row edit-due">
                <div class="field">
                    <label for="edit-due-date">{{ t('dueDateLbl') }}</label>
                    <input id="edit-due-date" v-model="form.due_date" type="date" class="inp-date" style="width: 15rem;">
                    <p class="form-hint">{{ t('dueDateNoneHint') }}</p>
                </div>
            </div>
            <div v-if="form.errors.due_date" class="form-error">{{ form.errors.due_date }}</div>

            <!-- Payments on the saved record. The mode picker above is
                 creation-only by design (it describes how a NEW record
                 should be settled); once the record exists what's real
                 is its list of payments, which this panel shows and
                 lets you correct. -->
            <EditPaymentsPanel
                v-if="editingPurchase"
                :payments="editingPurchase.payments || []"
                :total="Number(editingPurchase.amount)"
                :currency="currency"
                direction="out"
                payable-type="inventory_purchase"
                :payable-id="editingPurchase.id"
                :channels="channelList"
                :creating-channel="creatingChannel"
                @create-channel="createChannel"
            />

            <div class="submit-row" style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-ghost" v-if="editingPurchase" @click="cancelEdit">{{ t('cancelEditBtn') }}</button>
                <button type="button" :disabled="form.processing" @click="submit">
                    {{ editingPurchase ? t('saveChangesBtn') : t('recordPurchaseBtn') }}
                </button>
            </div>
            <div v-if="form.recentlySuccessful" class="status-line">
                {{ selectedVendor?.name }} — {{ currency }} {{ money(total) }} {{ t('recordedNote') }}
            </div>
        </div>

        <h3 class="sub">{{ t('recentPurchasesTitle') }}</h3>
        <div v-if="props.purchases.data.length === 0" class="empty">{{ t('noPurchasesYet') }}</div>
        <div v-else class="settle-list">
            <div v-for="purchase in props.purchases.data" :key="purchase.id" class="settle-item">
                <div class="settle-top">
                    <div>
                        <div class="who">{{ purchase.vendor }}</div>
                        <div class="meta">
                            {{ purchase.date }} ·
                            <span :class="purchase.is_paid ? 'text-success' : 'text-warning'">
                                {{ t('paidLbl') }} {{ currency }} {{ money(purchase.paid_amount) }} / {{ currency }} {{ money(purchase.amount) }}
                            </span>
                        </div>
                    </div>
                    <div>
                        <span class="amt">{{ currency }} {{ money(purchase.amount) }}</span>
                        <button type="button" class="btn btn-ghost btn-sm" @click="startEdit(purchase)">{{ t('editBtn') }}</button>
                        <button v-if="canDelete" type="button" class="btn btn-ghost btn-sm" style="color: var(--color-danger);" @click="confirmDelete(purchase)">{{ t('deleteBtn') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="props.purchases.links?.length > 3" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 16px;">
            <template v-for="(link, i) in props.purchases.links" :key="i">
                <Link v-if="link.url" :href="link.url" class="btn btn-ghost btn-sm" :class="{ 'btn-primary': link.active }" preserve-scroll>{{ paginationLabel(link.label) }}</Link>
            </template>
        </div>

        <ConfirmDialog
            v-model:open="confirmDialog.open"
            :title="confirmDialog.title"
            :message="confirmDialog.message"
            :danger="confirmDialog.danger"
            @confirm="onConfirmDialogConfirm"
        />

        <RenameModal
            :open="showRenameVendor"
            title="Rename vendor/employee"
            :current-name="selectedVendor?.name ?? ''"
            :saving="renaming"
            @save="saveRenameVendor"
            @update:open="(val) => { showRenameVendor = val; }"
        />
    </AppLayout>
</template>

<style scoped>
.installment-preview {
    list-style: none; margin: 10px 0 0; padding: 0;
    display: flex; flex-direction: column; gap: 4px;
    font-size: 12.5px; color: var(--color-text-secondary); font-family: var(--font-mono);
}
.settle-top > div:last-child { display: flex; align-items: center; gap: 6px; }
.raw-material-check {
    display: flex; align-items: center; gap: 4px;
    margin-top: 4px; font-size: 11.5px; color: var(--color-text-secondary);
    white-space: nowrap;
}
.raw-material-check input[type="checkbox"] {
    margin: 0 !important; padding: 0; width: 14px; height: 14px; flex-shrink: 0;
}
</style>
