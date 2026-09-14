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

import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import PaymentMethodField from '@/Components/App/PaymentMethodField.vue';
import ConfirmDialog from '@/Components/App/ConfirmDialog.vue';
import { useAppTranslations } from '@/Composables/useAppTranslations';

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

function todayIso() { return new Date().toISOString().slice(0, 10); }

function money(v) {
    return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(v || 0);
}

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

// ── Add a payment ────────────────────────────────────────────────
const adding = ref(false);
const submitting = ref(false);
const newPayment = ref({ date: todayIso(), amount: null, method: 'cash', payment_channel_id: null });

function openAdd() {
    newPayment.value = {
        date: todayIso(),
        // Default to whatever is still outstanding — the common case.
        amount: remaining.value > 0 ? remaining.value : null,
        method: 'cash',
        payment_channel_id: null,
    };
    adding.value = true;
}

function submitPayment() {
    if (!newPayment.value.amount || newPayment.value.amount <= 0) return;

    submitting.value = true;

    const isReceipt = props.direction === 'in';
    const url = isReceipt ? route('app.payments.receive') : route('app.payments.pay');
    const payload = isReceipt
        ? { sale_id: props.payableId }
        : { payable_type: props.payableType, payable_id: props.payableId };

    router.post(url, {
        ...payload,
        date: newPayment.value.date,
        amount: newPayment.value.amount,
        method: newPayment.value.method,
        payment_channel_id: newPayment.value.payment_channel_id,
    }, {
        preserveScroll: true,
        onSuccess: () => { adding.value = false; },
        onFinish: () => { submitting.value = false; },
    });
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
                    class="btn btn-ghost btn-sm edit-payments__remove"
                    @click="askRemove(payment)"
                >
                    {{ t('removeBtn') }}
                </button>
            </li>
        </ul>

        <!-- Add another payment -->
        <div v-if="adding" class="edit-payments__add">
            <span>{{ t('dateLbl') }}</span>
            <input v-model="newPayment.date" type="date" :max="todayIso()" style="width: 140px;">
            <span>{{ t('amountNowLbl') }}</span>
            <input v-model.number="newPayment.amount" type="number" step="0.01" min="0.01" style="width: 110px;">
            <PaymentMethodField
                v-model="newPayment.method"
                v-model:channel-id="newPayment.payment_channel_id"
                :channels="props.channels"
                :creating-channel="props.creatingChannel"
                @create-channel="(name) => emit('create-channel', name)"
            />
            <button type="button" class="btn btn-primary btn-sm" :disabled="submitting" @click="submitPayment">
                {{ t('confirmBtn') }}
            </button>
            <button type="button" class="btn btn-ghost btn-sm" @click="adding = false">
                {{ t('team_cancel') }}
            </button>
        </div>

        <button v-else type="button" class="btn btn-ghost btn-sm edit-payments__add-btn" @click="openAdd">
            {{ t('addPaymentBtn') }}
        </button>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="t('removePaymentTitle')"
            :message="confirmMessage"
            :confirm-label="t('removeBtn')"
            :cancel-label="t('team_cancel')"
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

.edit-payments__add {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    padding: 10px 0;
}
</style>
