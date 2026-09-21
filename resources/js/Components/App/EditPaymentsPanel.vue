<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — EditPaymentsPanel.vue
//  Location: resources/js/Components/App/EditPaymentsPanel.vue
//
//  The payment half of an edit form. When a record is created you
//  can settle it in the same submit; before this panel existed the
//  edit view hid payments entirely, so a wrong amount entered at
//  creation could never be corrected from the record itself.
//
//  Deliberately NOT a mirror of the create form's payment-mode
//  picker ("pay now / later / partial / installment"). Those modes
//  describe how a brand-new record should be settled. Once the
//  record exists, what's real is a list of payments — so this shows
//  that list, lets a wrong one be removed, and lets another be
//  added. Each action is its own request against the same endpoints
//  the Receive/Pay screen uses, rather than a bulk re-write of
//  payment history.
//
//  Used by the Sales, Expenses, Inventory and Equipment forms.
// ══════════════════════════════════════════════════════════════════

import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import PaymentMethodField from '@/Components/App/PaymentMethodField.vue';
import ConfirmDialog from '@/Components/App/ConfirmDialog.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';
import { useMoneyFormat } from '@/composables/useMoneyFormat';
import { todayIso } from '@/Utils/date';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({
    payments: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    currency: { type: String, default: 'EGP' },
    // 'in' settles a sale; 'out' settles a bill.
    direction: { type: String, default: 'in' },
    // For direction 'out' only — which bill table this record is in.
    payableType: { type: String, default: null },
    payableId: { type: [Number, String], required: true },
    channels: { type: Array, default: () => [] },
    creatingChannel: { type: Boolean, default: false },
});

const emit = defineEmits(['create-channel']);

const { t, locale } = useAppTranslations();

// Removing a payment deletes real cash history — company-admin only,
// same rule the server applies in PaymentController::destroy().
const { canDelete } = usePermissions();


const { money } = useMoneyFormat();

const paidTotal = computed(() =>
    props.payments.reduce((sum, payment) => sum + Number(payment.amount || 0), 0)
);

const remaining = computed(() => Math.round((props.total - paidTotal.value) * 100) / 100);

const methodLabels = {
    cash: 'cashLbl', bank: 'bankLbl', visa: 'visaLbl',
    instapay: 'instapayLbl', wallet: 'walletLbl',
};
function methodLabel(method) {
    return methodLabels[method] ? t(methodLabels[method]) : method;
}

// ── Add or correct a payment ─────────────────────────────────────
//
// One form serves both. `editingId` is null while adding and holds a
// payment id while correcting, which is the only difference between
// the two — the fields, the validation and the layout are identical,
// so duplicating the form would only create somewhere for them to
// drift apart.
const adding = ref(false);
const editingId = ref(null);
const submitting = ref(false);
const draft = ref({ date: todayIso(), amount: null, method: 'cash', payment_channel_id: null });

const formOpen = computed(() => adding.value || editingId.value !== null);

function openAdd() {
    editingId.value = null;
    draft.value = {
        date: todayIso(),
        // Default to whatever is still outstanding — the common case.
        amount: remaining.value > 0 ? remaining.value : null,
        method: 'cash',
        payment_channel_id: null,
    };
    adding.value = true;
}

function openEdit(payment) {
    adding.value = false;
    editingId.value = payment.id;
    draft.value = {
        date: payment.date,
        amount: Number(payment.amount),
        method: payment.method ?? 'cash',
        payment_channel_id: payment.payment_channel_id ?? null,
    };
}

function closeForm() {
    adding.value = false;
    editingId.value = null;
}

// Opening the record for editing (Sale/Purchase/Expense/Equipment)
// already brings you here — if there's only ONE payment on it, that
// is almost always the one thing you actually came to correct, so
// this skips the second "Edit" click and opens it straight away.
// With two or more payments there's no way to guess which one you
// mean, so those are left as a plain list to choose from.
//
// Keyed on payableId rather than firing on every props.payments
// change: the record itself only changes to another one when
// payableId changes (switching which Sale/Purchase you're editing),
// so this runs once per record — never re-firing (and re-opening
// the form) just because submitting or removing a payment updated
// the list while you're already looking at it.
watch(
    () => props.payableId,
    () => {
        if (props.payments.length === 1) {
            openEdit(props.payments[0]);
        } else {
            closeForm();
        }
    },
    { immediate: true }
);

function submitPayment() {
    if (!draft.value.amount || draft.value.amount <= 0) return;

    submitting.value = true;

    const options = {
        preserveScroll: true,
        onSuccess: () => closeForm(),
        onFinish: () => { submitting.value = false; },
    };

    // Correcting an existing payment.
    if (editingId.value !== null) {
        router.patch(route('app.payments.update', editingId.value), {
            date: draft.value.date,
            amount: draft.value.amount,
            method: draft.value.method,
            payment_channel_id: draft.value.payment_channel_id,
        }, options);

        return;
    }

    // Recording a new one — goes through the same endpoints the
    // Receive/Pay screen uses.
    const isReceipt = props.direction === 'in';
    const url = isReceipt ? route('app.payments.receive') : route('app.payments.pay');
    const payload = isReceipt
        ? { sale_id: props.payableId }
        : { payable_type: props.payableType, payable_id: props.payableId };

    router.post(url, {
        ...payload,
        date: draft.value.date,
        amount: draft.value.amount,
        method: draft.value.method,
        payment_channel_id: draft.value.payment_channel_id,
    }, options);
}

// ── Remove a payment ─────────────────────────────────────────────
const confirmOpen = ref(false);
const pendingPayment = ref(null);

const confirmMessage = computed(() =>
    pendingPayment.value
        ? t('removePaymentConfirm', {
            amount: `${props.currency} ${money(pendingPayment.value.amount)}`,
            date: pendingPayment.value.date,
        })
        : ''
);

function askRemove(payment) {
    pendingPayment.value = payment;
    confirmOpen.value = true;
}

function applyRemove() {
    const payment = pendingPayment.value;
    if (!payment) return;

    router.delete(route('app.payments.destroy', payment.id), {
        preserveScroll: true,
        onFinish: () => { pendingPayment.value = null; },
    });
}
</script>

<template>
    <div class="edit-payments">
        <h3 class="edit-payments__title">{{ t('paymentsHeading') }}</h3>

        <div class="edit-payments__summary">
            <span>{{ t('paidLbl') }}: <b>{{ props.currency }} {{ money(paidTotal) }}</b></span>
            <span :class="{ 'edit-payments__over': remaining < 0 }">
                {{ remaining < 0 ? t('overpaidLbl') : t('balanceLbl') }}:
                <b>{{ props.currency }} {{ money(Math.abs(remaining)) }}</b>
            </span>
        </div>

        <p v-if="props.payments.length === 0" class="edit-payments__empty">
            {{ t('noPaymentsYet') }}
        </p>

        <ul v-else class="edit-payments__list">
            <li v-for="payment in props.payments" :key="payment.id" class="edit-payments__row">
                <span class="edit-payments__date">{{ payment.date }}</span>
                <span class="edit-payments__method">{{ methodLabel(payment.method) }}</span>
                <span class="edit-payments__amount">{{ props.currency }} {{ money(payment.amount) }}</span>
                <button
                    type="button"
                    class="btn btn-ghost btn-sm"
                    :disabled="editingId === payment.id"
                    @click="openEdit(payment)"
                >
                    {{ t('editBtn') }}
                </button>
                <button
                    v-if="canDelete"
                    type="button"
                    class="btn btn-ghost btn-sm edit-payments__remove"
                    @click="askRemove(payment)"
                >
                    {{ t('removeBtn') }}
                </button>
            </li>
        </ul>

        <!-- One form, whether adding a payment or correcting one.
             Laid out with the same .field / <label> structure as
             every other form in the app — it used to be bare inputs
             next to bare <span> captions, which rendered as unstyled
             browser defaults dropped into a styled card. -->
        <div v-if="formOpen" class="edit-payments__add">
            <span class="edit-payments__form-label">
                {{ editingId !== null ? t('editPaymentHeading') : t('addPaymentBtn') }}
            </span>

            <div class="field-row edit-payments__fields">
                <div class="field field--auto">
                    <label for="edit-payment-date">{{ t('dateLbl') }}</label>
                    <input id="edit-payment-date" v-model="draft.date" type="date" :max="todayIso()" class="inp-date">
                </div>

                <div class="field field--auto">
                    <label for="edit-payment-amount">{{ t('amountNowLbl') }}</label>
                    <input id="edit-payment-amount" v-model.number="draft.amount" type="number" step="0.01" min="0.01" class="inp-money">
                </div>

                <div class="field field--auto edit-payments__method">
                    <label>{{ t('methodLbl') }}</label>
                    <PaymentMethodField
                        v-model="draft.method"
                        v-model:channel-id="draft.payment_channel_id"
                        :channels="props.channels"
                        :creating-channel="props.creatingChannel"
                        @create-channel="(name) => emit('create-channel', name)"
                    />
                </div>
            </div>

            <div class="edit-payments__actions">
                <button type="button" class="btn btn-primary btn-sm" :disabled="submitting" @click="submitPayment">
                    {{ t('confirmBtn') }}
                </button>
                <button type="button" class="btn btn-ghost btn-sm" @click="closeForm">
                    {{ t('cancelBtn') }}
                </button>
            </div>
        </div>

        <button v-else type="button" class="btn btn-ghost btn-sm edit-payments__add-btn" @click="openAdd">
            {{ t('addPaymentBtn') }}
        </button>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="t('removePaymentTitle')"
            :message="confirmMessage"
            :confirm-label="t('removeBtn')"
            :cancel-label="t('cancelBtn')"
            danger
            @confirm="applyRemove"
        />
    </div>
</template>

<style scoped>
.edit-payments {
    margin-top: 18px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border, #e5e7eb);
}

.edit-payments__title {
    font-size: 15px;
    font-weight: 600;
    margin: 0 0 10px;
}

.edit-payments__summary {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 22px;
    font-size: 13.5px;
    margin-bottom: 12px;
}

.edit-payments__over {
    color: var(--color-warning, #b45309);
}

.edit-payments__empty {
    margin: 0 0 12px;
    font-size: 13px;
    color: var(--color-text-muted, #6b7280);
}

.edit-payments__list {
    list-style: none;
    margin: 0 0 12px;
    padding: 0;
}

.edit-payments__row {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding: 8px 0;
    border-bottom: 1px solid var(--color-border, #e5e7eb);
    font-size: 13.5px;
}

.edit-payments__row:last-child {
    border-bottom: none;
}

.edit-payments__date {
    min-width: 92px;
}

.edit-payments__method {
    color: var(--color-text-muted, #6b7280);
}

.edit-payments__amount {
    font-weight: 600;
    margin-inline-start: auto;
}

.edit-payments__form-label {
    flex-basis: 100%;
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-muted);
}

.edit-payments__add {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 12px 0 4px;
}

/* The fields size to their content rather than stretching to the
   .field default of 200px — a date and a money box next to each
   other read better than two half-width columns. */
.edit-payments__fields { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
.edit-payments__fields .field--auto { flex: 0 0 auto; }
.edit-payments__method { min-width: 180px; }
.edit-payments__actions { display: flex; gap: 8px; flex-wrap: wrap; }
</style>
