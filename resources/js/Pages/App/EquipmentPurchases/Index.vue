<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — EquipmentPurchases/Index.vue
//  Location: resources/js/Pages/App/EquipmentPurchases/Index.vue
//
//  Ports ledger-prototype-v8.html's renderEquipmentForm(): "Buy from
//  {vendor}", Type (category) + Name side by side, then "Qty [n] ×
//  EGP [price]" as its own short sentence.
//
//  Depreciation runs entirely in the backend (see
//  EquipmentPurchaseController's class doc comment and
//  app/Console/Commands/RunDepreciation.php) — nothing about useful
//  life, accumulated depreciation, or a depreciation expense is
//  shown anywhere on this page, by design.
//
//  Same Edit/Delete/rename/date-field patterns as the other pages.
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
    vendors:  { type: Array, required: true },
    categories: { type: Array, required: true },
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
const currency = computed(() => page.props.auth?.user?.company?.currency ?? 'EGP');

function todayIso() { return new Date().toISOString().slice(0, 10); }
function money(v) {
    return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(v || 0);
}
function fmt(template, repl) {
    return Object.entries(repl).reduce((s, [k, v]) => s.replace(`:${k}`, v), template);
}

const vendorList = ref([...props.vendors]);
const categoryList = ref([...props.categories]);
const channelList = ref([...props.paymentChannels]);
const creatingVendor = ref(false);
const creatingCategory = ref(false);
const creatingChannel = ref(false);

const editingPurchase = ref(null);

const defaultFormState = () => ({
    vendor_id: null,
    category_id: null,
    name: '',
    date: todayIso(),
    qty: 1,
    unit_price: null,
    mode: 'now',
    method: 'cash',
    payment_channel_id: null,
    amount_now: null,
    due_in_days: 14,
    installment_count: 3,
    installment_interval_days: 30,
});

const form = useForm(defaultFormState());

const total = computed(() => (Number(form.qty) || 0) * (Number(form.unit_price) || 0));

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

async function createCategory(name) {
    creatingCategory.value = true;
    try {
        const { data } = await axios.post(route('app.categories.store'), { name, kind: 'equipment' }, { headers: { Accept: 'application/json' } });
        categoryList.value.push(data);
        form.category_id = data.id;
    } finally {
        creatingCategory.value = false;
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

// ── Rename (pencil) — vendor and category ────────────────────────
const selectedVendor = computed(() => vendorList.value.find((v) => v.id === form.vendor_id) ?? null);
const selectedCategory = computed(() => categoryList.value.find((c) => c.id === form.category_id) ?? null);
const renameTarget = ref(null);
const renaming = ref(false);

async function saveRename(newName) {
    renaming.value = true;
    try {
        if (renameTarget.value === 'vendor' && selectedVendor.value) {
            const { data } = await axios.patch(route('app.vendors.update', selectedVendor.value.id), { name: newName }, { headers: { Accept: 'application/json' } });
            const idx = vendorList.value.findIndex((v) => v.id === data.id);
            if (idx !== -1) vendorList.value[idx] = data;
        } else if (renameTarget.value === 'category' && selectedCategory.value) {
            const { data } = await axios.patch(route('app.categories.update', selectedCategory.value.id), { name: newName }, { headers: { Accept: 'application/json' } });
            const idx = categoryList.value.findIndex((c) => c.id === data.id);
            if (idx !== -1) categoryList.value[idx] = data;
        }
        renameTarget.value = null;
    } finally {
        renaming.value = false;
    }
}

function startEdit(purchase) {
    editingPurchase.value = purchase;
    form.vendor_id = purchase.vendor_id;
    form.category_id = purchase.category_id;
    form.name = purchase.name;
    form.date = purchase.date;
    form.qty = purchase.qty;
    form.unit_price = purchase.unit_price;
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
    form.post(route('app.equipment-purchases.store'), {
        preserveScroll: true,
        onSuccess: () => Object.assign(form, defaultFormState()),
    });
}

function doSubmitEdit(id) {
    form.transform((data) => ({
        vendor_id: data.vendor_id,
        category_id: data.category_id,
        name: data.name,
        date: data.date,
        qty: data.qty,
        unit_price: data.unit_price,
            due_date: data.due_date || null,
    })).put(route('app.equipment-purchases.update', id), {
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
        action: () => router.delete(route('app.equipment-purchases.destroy', purchase.id), { preserveScroll: true }),
    };
}

function onConfirmDialogConfirm() {
    confirmDialog.value.action?.();
}
</script>

<template>
    <Head :title="t('action_equipment_title')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('action_equipment_title') }}</h1>
        </div>

        <FormInstructions
            v-if="!editingPurchase"
            form-key="equipment"
            :steps="['howto_equipment_1', 'howto_equipment_2', 'howto_equipment_3', 'howto_equipment_4']"
            tip-key="howto_equipment_tip"
        />

        <div ref="formCard" class="card card--out">
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
                <button v-if="selectedVendor" type="button" class="inline-icon-btn" title="Rename" @click="renameTarget = 'vendor'">
                    <AppIcon name="pencil" />
                </button>
            </div>
            <div v-if="form.errors.vendor_id" class="form-error">{{ form.errors.vendor_id }}</div>

            <div class="field-row" style="margin-top: 16px;">
                <div class="field">
                    <label>{{ t('typeLbl') }}</label>
                    <span style="display: inline-flex; align-items: center; gap: 4px;">
                        <ComboSelect v-model="form.category_id" :options="categoryList" :creating="creatingCategory"
                                     :placeholder="t('selectPlaceholder')" :add-new-label="t('addNewCategory')" :inline="false" @create="createCategory" />
                        <button v-if="selectedCategory" type="button" class="inline-icon-btn" title="Rename" @click="renameTarget = 'category'">
                            <AppIcon name="pencil" />
                        </button>
                    </span>
                </div>
                <div class="field" style="flex: 1; min-width: 200px;">
                    <label>{{ t('nameLbl') }}</label>
                    <input v-model="form.name" type="text" class="form-input" style="min-width: 0;">
                </div>
            </div>
            <div v-if="form.errors.category_id" class="form-error">{{ form.errors.category_id }}</div>
            <div v-if="form.errors.name" class="form-error">{{ form.errors.name }}</div>

            <div class="sentence" style="font-size: 16px; margin-top: 18px;">
                {{ t('qtyLbl') }}
                <input v-model.number="form.qty" type="number" class="blank-input narrow" min="1">
                &times;
                <span class="muted-inline">{{ currency }}</span>
                <input v-model.number="form.unit_price" type="number" class="blank-input narrow" step="0.01">
            </div>
            <div v-if="form.errors.unit_price" class="form-error">{{ form.errors.unit_price }}</div>

            <div style="margin-top: 10px; font-weight: 600; font-size: 17px; color: var(--color-primary-dark);">
                {{ t('totalInclVatLbl') }}: <span class="mono">{{ currency }} {{ money(total) }}</span>
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
                payable-type="equipment_purchase"
                :payable-id="editingPurchase.id"
                :channels="channelList"
                :creating-channel="creatingChannel"
                @create-channel="createChannel"
            />

            <div class="submit-row" style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-ghost" v-if="editingPurchase" @click="cancelEdit">{{ t('cancelEditBtn') }}</button>
                <button type="button" :disabled="form.processing" @click="submit">
                    {{ editingPurchase ? t('saveChangesBtn') : t('recordEquipmentBtn') }}
                </button>
            </div>
            <div v-if="form.recentlySuccessful" class="status-line">
                {{ form.name }} — {{ currency }} {{ money(total) }} {{ t('recordedNote') }}
            </div>
        </div>

        <h3 class="sub">{{ t('recentEquipmentTitle') }}</h3>
        <div v-if="props.purchases.data.length === 0" class="empty">{{ t('noEquipmentYet') }}</div>
        <div v-else class="settle-list">
            <div v-for="purchase in props.purchases.data" :key="purchase.id" class="settle-item">
                <div class="settle-top">
                    <div>
                        <div class="who">{{ purchase.name }}</div>
                        <div class="meta">
                            {{ purchase.vendor }} · {{ purchase.category }} · {{ purchase.date }} ·
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
            :open="renameTarget !== null"
            :title="renameTarget === 'vendor' ? 'Rename vendor/employee' : 'Rename category'"
            :current-name="(renameTarget === 'vendor' ? selectedVendor?.name : selectedCategory?.name) ?? ''"
            :saving="renaming"
            @save="saveRename"
            @update:open="(val) => { if (!val) renameTarget = null; }"
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
</style>
