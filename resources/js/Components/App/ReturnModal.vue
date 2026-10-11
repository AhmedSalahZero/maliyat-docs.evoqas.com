<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ReturnModal.vue
//  Location: resources/js/Components/App/ReturnModal.vue
//
//  <ReturnModal v-model:open="showReturn" kind="sale" :doc="saleRow"
//               :channels="channelList" />
//
//  The "Apply quantity return" pop-up, shared by the Sales screen
//  (kind="sale": goods a customer sent back) and the Inventory
//  Purchase screen (kind="purchase": goods sent back to a supplier).
//
//  It lists every line of the sale/purchase with how much can still
//  be returned; the user types a quantity on the lines that came
//  back, picks the return date, and — if the goods were already paid
//  for — how the money is given back (cash / bank / kept as credit).
//  The server does the real work and the real checks
//  (SaleReturnService / PurchaseReturnService); the "value" shown
//  here is only a preview.
// ══════════════════════════════════════════════════════════════════

import { computed, nextTick, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useAppTranslations } from '@/composables/useAppTranslations';
import { useMoneyFormat } from '@/composables/useMoneyFormat';
import { todayIso } from '@/Utils/date';

const props = defineProps({
    open: { type: Boolean, default: false },
    kind: { type: String, required: true }, // 'sale' | 'purchase'
    // The sale / purchase row from the list: id, date, lines[], balance, ...
    doc: { type: Object, default: null },
    channels: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:open']);

const { t } = useAppTranslations();
const { currency, money } = useMoneyFormat();

const isSale = computed(() => props.kind === 'sale');
const lineKey = computed(() => (isSale.value ? 'sale_line_id' : 'purchase_line_id'));

// The pop-up scrolls on its own; the error box is at its top, the Save
// button at its bottom, so after a refusal we scroll back up to show why.
const sheet = ref(null);

// Selecting text in a box and letting go of the mouse outside the pop-up
// counts as a click on the dark background and used to close it, losing
// everything typed. Only close when the press also STARTED on the background.
const pressedOnBackdrop = ref(false);

function showErrors() {
    nextTick(() => sheet.value?.scrollTo({ top: 0, behavior: 'smooth' }));
}

const form = useForm({
    date: todayIso(),
    lines: [],
    refund_method: 'cash',
    payment_channel_id: null,
    note: '',
});

// Lines that still have something left to return.
const returnable = computed(() =>
    (props.doc?.lines ?? [])
        .map((line) => ({
            ...line,
            remaining: Math.max(0, Math.round((Number(line.qty) - Number(line.returned_qty || 0)) * 100) / 100),
        }))
        .filter((line) => line.remaining > 0)
);

watch(() => props.open, (isOpen) => {
    if (!isOpen) return;
    form.reset();
    form.clearErrors();
    form.date = todayIso();
    form.refund_method = 'cash';
    form.payment_channel_id = null;
    form.lines = returnable.value.map((line) => ({ [lineKey.value]: line.id, qty: '' }));
});

// Preview only: what the typed quantities are worth, with VAT and
// withholding worked out the way the document did.
const preview = computed(() => {
    let subtotal = 0; let vat = 0; let wht = 0;

    returnable.value.forEach((line, i) => {
        const qty = Math.min(Number(form.lines[i]?.qty) || 0, line.remaining);
        if (qty <= 0) return;
        const net = Math.round(qty * Number(line.unit_price) * 100) / 100;
        subtotal += net;
        vat += Math.round(net * (Number(line.vat_rate) || 0)) / 100;
        wht += Math.round(net * (Number(line.withholding_rate) || 0)) / 100;
    });

    const amount = Math.round((subtotal + vat - wht) * 100) / 100;
    const owed = Math.max(0, Number(props.doc?.balance) || 0);
    const applied = Math.min(amount, owed);

    return { amount, refund: Math.round((amount - applied) * 100) / 100 };
});

const needsRefundChoice = computed(() => preview.value.refund > 0);

const errors = computed(() => [...new Set(Object.values(form.errors))]);

const hasQty = computed(() => form.lines.some((l) => Number(l.qty) > 0));

function close() {
    emit('update:open', false);
}

function submit() {
    form.clearErrors();

    // The server ignores a line whose quantity is not above zero, so a
    // typed -3 next to a valid line would be dropped without a word
    // while the return is still recorded. Say so instead.
    if (form.lines.some((l) => Number(l.qty) < 0)) {
        form.setError('lines', t('returnQtyNegative'));
        showErrors();
        return;
    }

    // Money going back by bank / Visa / InstaPay / wallet must say which
    // bank or operator (the server checks this too).
    if (needsRefundChoice.value
        && !['cash', 'credit'].includes(form.refund_method)
        && !form.payment_channel_id) {
        form.setError('payment_channel_id', t('returnChannelRequired'));
        showErrors();
        return;
    }

    const url = isSale.value
        ? route('app.sales.returns.store', props.doc.id)
        : route('app.inventory-purchases.returns.store', props.doc.id);

    form.transform((data) => ({
        ...data,
        // Money-back details only matter when something is refunded.
        payment_channel_id: ['cash', 'credit'].includes(data.refund_method) ? null : data.payment_channel_id,
        note: data.note || null,
    })).post(url, {
        preserveScroll: true,
        onSuccess: () => close(),
        onError: () => showErrors(),
    });
}

const methods = ['cash', 'bank', 'visa', 'instapay', 'wallet'];
</script>

<template>
    <transition name="fade-in">
        <div v-if="props.open && props.doc" class="modal-backdrop" @mousedown="pressedOnBackdrop = $event.target === $event.currentTarget" @click.self="pressedOnBackdrop && close()">
            <div ref="sheet" class="modal-sheet return-modal slide-up">
                <div class="modal-sheet__handle"></div>

                <h2 class="return-modal__title">{{ isSale ? t('returnSaleTitle') : t('returnPurchaseTitle') }}</h2>
                <p class="return-modal__help">{{ isSale ? t('returnSaleHelp') : t('returnPurchaseHelp') }}</p>

                <div v-if="errors.length" class="alert danger return-modal__errors">
                    <div v-for="(message, i) in errors" :key="i">{{ message }}</div>
                </div>

                <div v-if="returnable.length === 0" class="empty">{{ t('returnNothingLeft') }}</div>

                <template v-else>
                    <div class="return-lines">
                        <div v-for="(line, i) in returnable" :key="line.id" class="return-line">
                            <div class="return-line__info">
                                <div class="return-line__name">{{ line.item || t('returnFreeTextLine') }}</div>
                                <div class="return-line__meta">
                                    {{ isSale ? t('returnSoldLbl') : t('returnBoughtLbl') }} {{ Number(line.qty) }} {{ line.uom }}
                                    <template v-if="Number(line.returned_qty) > 0"> · {{ t('returnAlreadyLbl') }} {{ Number(line.returned_qty) }}</template>
                                    · {{ t('returnCanLbl') }} <strong>{{ line.remaining }}</strong>
                                </div>
                            </div>
                            <input
                                v-model="form.lines[i].qty"
                                type="number" inputmode="decimal" step="0.01" min="0" :max="line.remaining"
                                class="form-input return-line__qty"
                                :placeholder="t('returnQtyPlaceholder')"
                                :aria-label="t('returnQtyLbl')"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="return-modal__label">{{ t('returnDateLbl') }}</label>
                        <input v-model="form.date" type="date" class="form-input" :min="props.doc.date" :max="todayIso()">
                    </div>

                    <div v-if="preview.amount > 0" class="return-preview">
                        <div>{{ t('returnValueLbl') }}: <strong>{{ currency }} {{ money(preview.amount) }}</strong></div>
                        <div v-if="preview.refund > 0" class="return-preview__refund">
                            {{ (isSale ? t('returnRefundSale') : t('returnRefundPurchase')).replace(':amount', `${currency} ${money(preview.refund)}`) }}
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="return-modal__label">{{ isSale ? t('returnRefundHowSale') : t('returnRefundHowPurchase') }}</label>
                        <select v-model="form.refund_method" class="form-input">
                            <option v-for="m in methods" :key="m" :value="m">{{ t(`${m}Lbl`) }}</option>
                            <option value="credit">{{ isSale ? t('returnKeepCreditSale') : t('returnKeepCreditPurchase') }}</option>
                        </select>
                        <p class="return-modal__hint">{{ t('returnRefundOnlyIfPaid') }}</p>
                    </div>

                    <div v-if="!['cash', 'credit'].includes(form.refund_method)" class="form-group">
                        <label class="return-modal__label">{{ t('bankOperatorLbl') }}</label>
                        <select v-model="form.payment_channel_id" class="form-input">
                            <option :value="null">{{ t('selectPlaceholder') }}</option>
                            <option v-for="c in props.channels" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="return-modal__label">{{ t('returnNoteLbl') }}</label>
                        <input v-model="form.note" type="text" maxlength="255" class="form-input">
                    </div>
                </template>

                <div class="return-modal__actions">
                    <button type="button" class="btn btn-ghost" @click="close">{{ t('cancelBtn') }}</button>
                    <button type="button" class="btn btn-primary" :disabled="form.processing || !hasQty || returnable.length === 0" @click="submit">
                        {{ form.processing ? t('returnSavingLbl') : t('returnSaveBtn') }}
                    </button>
                </div>
            </div>
        </div>
    </transition>
</template>

<style scoped>
.return-modal { max-width: 520px; margin: 0 auto; max-height: 90vh; overflow-y: auto; }
.return-modal__title { font-family: var(--font-heading); font-size: 16.5px; font-weight: 700; color: var(--color-text-primary); margin-bottom: 4px; }
.return-modal__help { font-size: 13px; color: var(--color-text-secondary); margin-bottom: 12px; line-height: 1.5; }
.return-modal__label { display: block; font-size: 12.5px; font-weight: 600; color: var(--color-text-secondary); margin-bottom: 4px; }
.return-modal__hint { font-size: 12px; color: var(--color-text-muted); margin-top: 4px; }
.return-modal__errors { margin-bottom: 10px; }
.return-modal__actions { display: flex; gap: 10px; margin-top: 10px; }
.return-modal__actions .btn { flex: 1; }
.return-lines { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
.return-line { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 10px; border: 1px solid var(--color-border-input); border-radius: var(--radius-md, 8px); }
.return-line__info { min-width: 0; flex: 1; }
.return-line__name { font-weight: 600; font-size: 13.5px; color: var(--color-text-primary); }
.return-line__meta { font-size: 12px; color: var(--color-text-muted); }
.return-line__qty { width: 96px; text-align: end; flex: none; }
.return-preview { font-size: 13px; margin: 4px 0 12px; padding: 8px 10px; border-radius: var(--radius-md, 8px); background: var(--color-surface-alt, rgba(0, 0, 0, 0.04)); }
.return-preview__refund { margin-top: 2px; color: var(--color-warning, #b45309); }
</style>
