<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Sales/Index.vue
//  Location: resources/js/Pages/App/Sales/Index.vue
//
//  The guided-sentence create form (ported from ledger-prototype-
//  v8.html's renderSalesForm()/submitSale()) plus a recent-sales
//  list with Edit/Delete.
//
//  Edit reuses this same form. Payment mode isn't editable there
//  (see UpdateSaleRequest's doc comment for why).
//
//  Delete and "edit after payments exist" both go through
//  ConfirmDialog.vue (not window.confirm() — native browser confirm
//  dialogs are unreliable in some embedded/preview contexts, which
//  is the most likely reason Delete looked broken before) and both
//  only act after the user explicitly confirms.
//
//  The pencil icon next to "Sell to {customer}" opens RenameModal
//  to fix a typo without leaving the page — PATCHes the real
//  customer record, not just local state.
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
import { useAppTranslations } from '@/Composables/useAppTranslations';
import { scrollToForm } from '@/Composables/useScrollToForm';
import { usePermissions } from '@/Composables/usePermissions';

const props = defineProps({
    customers: { type: Array, required: true },
    items:     { type: Array, required: true },
    paymentChannels: { type: Array, required: true },
    sales:     { type: Object, required: true }, // paginator: { data, links, ... }
});

const page = usePage();
const { t, locale } = useAppTranslations();

// The form card, so pressing Edit can bring the FORM into view
// rather than the top of the document — see useScrollToForm.
const formCard = ref(null);

// Delete is company-admin only — mirrors Controller::authorizeDelete().
const { canDelete } = usePermissions();

const currency = computed(() => page.props.auth?.user?.company?.currency ?? 'EGP');

function todayIso() {
    return new Date().toISOString().slice(0, 10);
}

function money(value) {
    return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(value || 0);
}

function fmt(template, replacements) {
    return Object.entries(replacements).reduce(
        (str, [key, val]) => str.replace(`:${key}`, val),
        template
    );
}

// ── Lookup lists — start from server props, grow when "+ Add new…" is used ──
const customerList = ref([...props.customers]);
const itemList = ref([...props.items]);
const channelList = ref([...props.paymentChannels]);
const creatingCustomer = ref(false);
const creatingItemForRow = ref(null);
const creatingChannel = ref(false);

const selectedCustomer = computed(() =>
    customerList.value.find((c) => c.id === form.customer_id) ?? null
);

// ── Rename customer (pencil icon) ───────────────────────────────
const showRename = ref(false);
const renaming = ref(false);

async function saveRename(newName) {
    if (!selectedCustomer.value) return;
    renaming.value = true;
    try {
        const { data } = await axios.patch(
            route('app.customers.update', selectedCustomer.value.id),
            { name: newName },
            { headers: { Accept: 'application/json' } }
        );
        const idx = customerList.value.findIndex((c) => c.id === data.id);
        if (idx !== -1) customerList.value[idx] = data;
        showRename.value = false;
    } finally {
        renaming.value = false;
    }
}

// ── Edit state ───────────────────────────────────────────────────
const editingSale = ref(null); // the full sale row being edited, or null when creating

const defaultFormState = () => ({
    customer_id: null,
    date: todayIso(),
    lines: [{ item_id: null, qty: null, unit_price: null }],
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
        const amount = isLast ? Math.round((total.value - allocated) * 100) / 100 : per;
        allocated += amount;
        const due = new Date(today);
        due.setDate(due.getDate() + interval * (i + 1));
        return { sequence: i + 1, due_date: due.toISOString().slice(0, 10), amount };
    });
});

function addLine() {
    form.lines.push({ item_id: null, qty: null, unit_price: null });
}

function removeLine(index) {
    if (form.lines.length <= 1) return;
    form.lines.splice(index, 1);
}

async function createCustomer(name) {
    creatingCustomer.value = true;
    try {
        const { data } = await axios.post(route('app.customers.store'), { name }, { headers: { Accept: 'application/json' } });
        customerList.value.push(data);
        form.customer_id = data.id;
    } finally {
        creatingCustomer.value = false;
    }
}

async function createItem(name, rowIndex) {
    creatingItemForRow.value = rowIndex;
    try {
        const { data } = await axios.post(route('app.items.store'), { name }, { headers: { Accept: 'application/json' } });
        itemList.value.push(data);
        form.lines[rowIndex].item_id = data.id;
    } finally {
        creatingItemForRow.value = null;
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

function startEdit(sale) {
    editingSale.value = sale;
    form.customer_id = sale.customer_id;
    form.date = sale.date;
    form.lines = sale.lines.length
        ? sale.lines.map((l) => ({ item_id: l.item_id, qty: l.qty, unit_price: l.unit_price }))
        : [{ item_id: null, qty: null, unit_price: null }];
    form.vat_rate = sale.vat_rate;
    form.due_date = editingSale.value?.due_date ?? null;
    form.clearErrors();
    scrollToForm(formCard);
}

function cancelEdit() {
    editingSale.value = null;
    form.reset();
    Object.assign(form, defaultFormState());
    form.clearErrors();
}

function submit() {
    if (editingSale.value) {
        prepareEditSubmit();
    } else {
        submitCreate();
    }
}

function submitCreate() {
    form.transform((data) => ({
        ...data,
        lines: data.lines.filter((line) => line.item_id && Number(line.qty) > 0),
    })).post(route('app.sales.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            Object.assign(form, defaultFormState());
        },
    });
}

function doSubmitEdit(saleId) {
    form.transform((data) => ({
        customer_id: data.customer_id,
        date: data.date,
        vat_rate: data.vat_rate,
        lines: data.lines.filter((line) => line.item_id && Number(line.qty) > 0),
            due_date: data.due_date || null,
    })).put(route('app.sales.update', saleId), {
        preserveScroll: true,
        onSuccess: () => cancelEdit(),
    });
}

// ── Confirm dialogs (delete / edit-deficit-surplus) ─────────────
const confirmDialog = ref({ open: false, title: '', message: '', danger: false, action: null });

function prepareEditSubmit() {
    const sale = editingSale.value;
    const newTotal = total.value;
    const paid = sale.paid_amount;

    if (paid <= 0.004) {
        doSubmitEdit(sale.id);
        return;
    }

    const diff = newTotal - paid;
    const message = diff >= 0
        ? fmt(t('editConfirmDeficit'), { paid: `${currency.value} ${money(paid)}`, total: `${currency.value} ${money(newTotal)}`, amount: `${currency.value} ${money(diff)}` })
        : fmt(t('editConfirmSurplus'), { paid: `${currency.value} ${money(paid)}`, total: `${currency.value} ${money(newTotal)}`, amount: `${currency.value} ${money(Math.abs(diff))}` });

    confirmDialog.value = {
        open: true,
        title: t('saveChangesBtn'),
        message,
        danger: false,
        action: () => doSubmitEdit(sale.id),
    };
}

function confirmDelete(sale) {
    const message = sale.payments_count > 0
        ? fmt(t('deleteConfirmWithPayments'), { count: sale.payments_count, amount: `${currency.value} ${money(sale.paid_amount)}` })
        : t('deleteConfirmSimple');

    confirmDialog.value = {
        open: true,
        title: t('deleteBtn'),
        message,
        danger: true,
        action: () => router.delete(route('app.sales.destroy', sale.id), { preserveScroll: true }),
    };
}

function onConfirmDialogConfirm() {
    confirmDialog.value.action?.();
}
</script>

<template>
    <Head :title="t('action_sale_title')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('action_sale_title') }}</h1>
        </div>

        <FormInstructions
            v-if="!editingSale"
            form-key="sale"
            :steps="['howto_sale_1', 'howto_sale_2', 'howto_sale_3', 'howto_sale_4', 'howto_sale_5', 'howto_sale_6']"
            tip-key="howto_sale_tip"
        />

        <div ref="formCard" class="card card--in">
            <div v-if="editingSale" class="alert info">{{ t('editingBanner') }}</div>

            <div class="field-row" style="margin-bottom: 4px;">
                <div class="field">
                    <label>{{ t('dateLbl') }}</label>
                    <input v-model="form.date" type="date" :max="todayIso()" class="inp-date" style="width: 15rem;">
                </div>
            </div>
            <div v-if="form.errors.date" class="form-error">{{ form.errors.date }}</div>

            <div class="sentence">
                {{ t('sellTo') }}
                <ComboSelect
                    v-model="form.customer_id"
                    :options="customerList"
                    :creating="creatingCustomer"
                    :placeholder="t('selectPlaceholder')"
                    :add-new-label="t('addNewCustomer')"
                    @create="createCustomer"
                />
                <button v-if="selectedCustomer" type="button" class="inline-icon-btn" title="Rename" @click="showRename = true">
                    <AppIcon name="pencil" />
                </button>
            </div>
            <div v-if="form.errors.customer_id" class="form-error">{{ form.errors.customer_id }}</div>

            <table class="lines">
                <colgroup>
                    <col class="col-item"><col class="col-qty"><col class="col-price"><col class="col-total"><col class="col-rm">
                </colgroup>
                <thead>
                    <tr>
                        <th>{{ t('itemLbl') }}</th>
                        <th>{{ t('qtyLbl') }}</th>
                        <th>{{ t('unitPriceLbl') }}</th>
                        <th>{{ t('lineTotalLbl') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, index) in form.lines" :key="index">
                        <td :data-label="t('itemLbl')">
                            <ComboSelect
                                v-model="line.item_id"
                                :options="itemList"
                                :creating="creatingItemForRow === index"
                                :placeholder="t('selectPlaceholder')"
                                :add-new-label="t('addNewItem')"
                                :inline="false"
                                @create="(name) => createItem(name, index)"
                            />
                        </td>
                        <td :data-label="t('qtyLbl')"><input v-model.number="line.qty" type="number" min="0" step="0.01" placeholder="0"></td>
                        <td :data-label="t('unitPriceLbl')"><input v-model.number="line.unit_price" type="number" min="0" step="0.01" placeholder="0.00"></td>
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

            <!-- Payment mode — creation only, not editable (see UpdateSaleRequest) -->
            <div v-if="!editingSale" class="paymode-block">
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
                <div v-if="form.errors.installment_count" class="form-error">{{ form.errors.installment_count }}</div>

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
            <div v-if="editingSale" class="field-row edit-due">
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
                v-if="editingSale"
                :payments="editingSale.payments || []"
                :total="Number(editingSale.amount)"
                :currency="currency"
                direction="in"
                :payable-id="editingSale.id"
                :channels="channelList"
                :creating-channel="creatingChannel"
                @create-channel="createChannel"
            />

            <div class="submit-row" style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-ghost" v-if="editingSale" @click="cancelEdit">{{ t('cancelEditBtn') }}</button>
                <button type="button" :disabled="form.processing" @click="submit">
                    {{ editingSale ? t('saveChangesBtn') : t('recordSaleBtn') }}
                </button>
            </div>
            <div v-if="form.recentlySuccessful" class="status-line">
                {{ selectedCustomer?.name }} — {{ currency }} {{ money(total) }} {{ t('recordedNote') }}
            </div>
        </div>

        <!-- ── Recent sales — Edit / Delete ─────────────────────── -->
        <h3 class="sub">{{ t('recentSalesTitle') }}</h3>
        <div v-if="props.sales.data.length === 0" class="empty">{{ t('noSalesYet') }}</div>
        <div v-else class="settle-list">
            <div v-for="sale in props.sales.data" :key="sale.id" class="settle-item">
                <div class="settle-top">
                    <div>
                        <div class="who">{{ sale.customer }}</div>
                        <div class="meta">
                            {{ sale.date }} ·
                            <span :class="sale.is_paid ? 'text-success' : 'text-warning'">
                                {{ t('paidLbl') }} {{ currency }} {{ money(sale.paid_amount) }} / {{ currency }} {{ money(sale.amount) }}
                            </span>
                        </div>
                    </div>
                    <div>
                        <span class="amt">{{ currency }} {{ money(sale.amount) }}</span>
                        <button type="button" class="btn btn-ghost btn-sm" @click="startEdit(sale)">{{ t('editBtn') }}</button>
                        <button v-if="canDelete" type="button" class="btn btn-ghost btn-sm" style="color: var(--color-danger);" @click="confirmDelete(sale)">{{ t('deleteBtn') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="props.sales.links?.length > 3" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 16px;">
            <template v-for="(link, i) in props.sales.links" :key="i">
                <Link v-if="link.url" :href="link.url" class="btn btn-ghost btn-sm" :class="{ 'btn-primary': link.active }" v-html="link.label" preserve-scroll />
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
            v-model:open="showRename"
            title="Rename customer"
            :current-name="selectedCustomer?.name ?? ''"
            :saving="renaming"
            @save="saveRename"
        />
    </AppLayout>
</template>

<style scoped>
.installment-preview {
    list-style: none;
    margin: 10px 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12.5px;
    color: var(--color-text-secondary);
    font-family: var(--font-mono);
}

.settle-top > div:last-child {
    display: flex;
    align-items: center;
    gap: 6px;
}
</style>
