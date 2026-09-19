<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Payments/Index.vue
//  Location: resources/js/Pages/App/Payments/Index.vue
//
//  Ports ledger-prototype-v8.html's renderReceiveForm()/
//  renderPayForm() as one page with a Receive/Pay tab toggle (the
//  prototype has these as two separate tabs; combined here since
//  they're mirror images of the same pattern).
//
//  Receive tab: open invoices (money owed TO you) with an inline
//  settle form per row, plus "or log one with no invoice" below.
//  Pay tab: same shape for open bills (money you owe).
//
//  Every settle/generic action has its own date field — settling an
//  invoice from 3 days ago should be dated 3 days ago, not today.
// ══════════════════════════════════════════════════════════════════

import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormInstructions from '@/Components/App/FormInstructions.vue';
import PaymentMethodField from '@/Components/App/PaymentMethodField.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';
import { useMoneyFormat } from '@/composables/useMoneyFormat';
import { todayIso } from '@/Utils/date';

const props = defineProps({
    paymentChannels: { type: Array, required: true },
});

const page = usePage();
const { t, locale } = useAppTranslations();
const { currency, money } = useMoneyFormat();

const activeTab = ref('receive'); // 'receive' | 'pay'

const channelList = ref([...props.paymentChannels]);
const creatingChannel = ref(false);

async function createChannel(name, target) {
    creatingChannel.value = true;
    try {
        const { data } = await axios.post(route('app.payment-channels.store'), { name }, { headers: { Accept: 'application/json' } });
        channelList.value.push(data);
        target.payment_channel_id = data.id;
    } finally {
        creatingChannel.value = false;
    }
}

// ── Open invoices / bills ────────────────────────────────────────
const openInvoices = ref([]);
const openBills = ref([]);
const loadingInvoices = ref(true);
const loadingBills = ref(true);

// The endpoints return the most urgent page of open items plus a
// has_more flag, rather than every open item ever — see
// PaymentController::OPEN_LIST_LIMIT. When has_more is set the
// search box is how the user reaches anything further down.
const invoicesHasMore = ref(false);
const billsHasMore = ref(false);
const invoiceSearch = ref('');
const billSearch = ref('');

let invoiceSearchTimer = null;
let billSearchTimer = null;

async function loadOpenInvoices() {
    loadingInvoices.value = true;
    try {
        const { data } = await axios.get(route('app.payments.open-invoices'), {
            params: invoiceSearch.value ? { q: invoiceSearch.value } : {},
        });
        openInvoices.value = data.data ?? [];
        invoicesHasMore.value = Boolean(data.has_more);
    } finally {
        loadingInvoices.value = false;
    }
}
async function loadOpenBills() {
    loadingBills.value = true;
    try {
        const { data } = await axios.get(route('app.payments.open-bills'), {
            params: billSearch.value ? { q: billSearch.value } : {},
        });
        openBills.value = data.data ?? [];
        billsHasMore.value = Boolean(data.has_more);
    } finally {
        loadingBills.value = false;
    }
}

// Debounced so typing a customer name doesn't fire a request per
// keystroke.
function onInvoiceSearch() {
    clearTimeout(invoiceSearchTimer);
    invoiceSearchTimer = setTimeout(loadOpenInvoices, 300);
}
function onBillSearch() {
    clearTimeout(billSearchTimer);
    billSearchTimer = setTimeout(loadOpenBills, 300);
}

onBeforeUnmount(() => {
    clearTimeout(invoiceSearchTimer);
    clearTimeout(billSearchTimer);
});

onMounted(() => {
    loadOpenInvoices();
    loadOpenBills();
});

// ── Inline settle (one open at a time, per tab) ──────────────────
const settlingInvoiceId = ref(null);
const settlingBillKey = ref(null); // `${payable_type}:${id}`
const settleForm = ref({ date: todayIso(), amount: null, method: 'cash', payment_channel_id: null });
const submitting = ref(false);

function openSettleInvoice(invoice) {
    settlingInvoiceId.value = settlingInvoiceId.value === invoice.id ? null : invoice.id;
    settleForm.value = { date: todayIso(), amount: invoice.balance, method: 'cash', payment_channel_id: null };
}
function openSettleBill(bill) {
    const key = `${bill.payable_type}:${bill.id}`;
    settlingBillKey.value = settlingBillKey.value === key ? null : key;
    settleForm.value = { date: todayIso(), amount: bill.balance, method: 'cash', payment_channel_id: null };
}

function confirmSettleInvoice(invoice) {
    if (!settleForm.value.amount || settleForm.value.amount <= 0) return;
    submitting.value = true;
    router.post(route('app.payments.receive'), {
        sale_id: invoice.id,
        date: settleForm.value.date,
        amount: settleForm.value.amount,
        method: settleForm.value.method,
        payment_channel_id: settleForm.value.payment_channel_id,
    }, {
        preserveScroll: true,
        onSuccess: () => { settlingInvoiceId.value = null; loadOpenInvoices(); },
        onFinish: () => { submitting.value = false; },
    });
}

function confirmSettleBill(bill) {
    if (!settleForm.value.amount || settleForm.value.amount <= 0) return;
    submitting.value = true;
    router.post(route('app.payments.pay'), {
        payable_type: bill.payable_type,
        payable_id: bill.id,
        date: settleForm.value.date,
        amount: settleForm.value.amount,
        method: settleForm.value.method,
        payment_channel_id: settleForm.value.payment_channel_id,
    }, {
        preserveScroll: true,
        onSuccess: () => { settlingBillKey.value = null; loadOpenBills(); },
        onFinish: () => { submitting.value = false; },
    });
}

// Standalone "log one with no invoice/bill" (cash sale / cash
// expense) used to live here. It's been moved to the Sales tab
// ("Cash Sales" toggle) and the Expense tab ("Cash Expense" toggle)
// — see Sales/Index.vue and Expenses/Index.vue — where it creates a
// real, fully-accounted record instead of a standalone payment with
// no invoice/bill behind it. This page is now purely for settling
// an already-open invoice or bill.
</script>

<template>
    <Head :title="t('action_payment_title')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('action_payment_title') }}</h1>
        </div>

        <FormInstructions
            form-key="payment"
            :steps="['howto_payment_1', 'howto_payment_2', 'howto_payment_3', 'howto_payment_4']"
            tip-key="howto_payment_tip"
        />

        <div class="toggle-btns" style="margin-bottom: 18px;">
            <button type="button" :class="{ active: activeTab === 'receive' }" @click="activeTab = 'receive'">{{ t('receiveMoneyTab') }}</button>
            <button type="button" :class="{ active: activeTab === 'pay' }" @click="activeTab = 'pay'">{{ t('payMoneyTab') }}</button>
        </div>

        <!-- ══════════════════════════ RECEIVE ══════════════════════════ -->
        <template v-if="activeTab === 'receive'">
            <div class="card">
                <h3 class="sub" style="margin-top: 0;">{{ t('openInvoicesTitle') }}</h3>

                <div class="field settle-search">
                    <input
                        id="open-invoice-search"
                        v-model="invoiceSearch"
                        type="search"
                        :placeholder="t('searchCustomerPlaceholder')"
                        @input="onInvoiceSearch"
                    >
                </div>

                <div v-if="loadingInvoices" class="empty">{{ t('loadingLbl') }}</div>
                <div v-else-if="openInvoices.length === 0" class="empty">{{ t('noOpenInvoices') }}</div>
                <div v-else class="settle-list">
                    <div v-for="invoice in openInvoices" :key="invoice.id" class="settle-item">
                        <div class="settle-top">
                            <div>
                                <div class="who">{{ invoice.customer }}</div>
                                <div class="meta">{{ t('issuedLbl') }} {{ invoice.date }}<span v-if="invoice.due_date"> · {{ t('dueLbl') }} {{ invoice.due_date }}</span></div>
                            </div>
                            <div>
                                <span class="amt">{{ currency }} {{ money(invoice.balance) }}</span>
                                <button type="button" class="open-settle" @click="openSettleInvoice(invoice)">{{ t('settleBtn') }}</button>
                            </div>
                        </div>

                        <div v-if="settlingInvoiceId === invoice.id" class="settle-form">
                            <span>{{ t('dateLbl') }}</span>
                            <input v-model="settleForm.date" type="date" :max="todayIso()" class="inp-date" style="width: 15rem;">
                            <span>{{ t('amountNowLbl') }}</span>
                            <input v-model.number="settleForm.amount" type="number" step="0.01" class="inp-money">
                            <PaymentMethodField v-model="settleForm.method" v-model:channel-id="settleForm.payment_channel_id"
                                :channels="channelList" :creating-channel="creatingChannel" @create-channel="(name) => createChannel(name, settleForm)" />
                            <button class="confirm" :disabled="submitting" @click="confirmSettleInvoice(invoice)">{{ t('confirmBtn') }}</button>
                        </div>
                    </div>
                </div>

                <p v-if="invoicesHasMore" class="settle-more">{{ t('moreOpenItemsHint') }}</p>
            </div>
        </template>

        <!-- ══════════════════════════ PAY ══════════════════════════ -->
        <template v-else>
            <div class="card">
                <h3 class="sub" style="margin-top: 0;">{{ t('openBillsTitle') }}</h3>

                <div class="field settle-search">
                    <input
                        id="open-bill-search"
                        v-model="billSearch"
                        type="search"
                        :placeholder="t('searchVendorPlaceholder')"
                        @input="onBillSearch"
                    >
                </div>

                <div v-if="loadingBills" class="empty">{{ t('loadingLbl') }}</div>
                <div v-else-if="openBills.length === 0" class="empty">{{ t('noOpenBills') }}</div>
                <div v-else class="settle-list">
                    <div v-for="bill in openBills" :key="`${bill.payable_type}:${bill.id}`" class="settle-item">
                        <div class="settle-top">
                            <div>
                                <div class="who">{{ bill.party }}</div>
                                <div class="meta">{{ t('issuedLbl') }} {{ bill.date }}<span v-if="bill.due_date"> · {{ t('dueLbl') }} {{ bill.due_date }}</span></div>
                            </div>
                            <div>
                                <span class="amt">{{ currency }} {{ money(bill.balance) }}</span>
                                <button type="button" class="open-settle" @click="openSettleBill(bill)">{{ t('settleBtn') }}</button>
                            </div>
                        </div>

                        <div v-if="settlingBillKey === `${bill.payable_type}:${bill.id}`" class="settle-form">
                            <span>{{ t('dateLbl') }}</span>
                            <input v-model="settleForm.date" type="date" :max="todayIso()" class="inp-date" style="width: 15rem;">
                            <span>{{ t('amountNowLbl') }}</span>
                            <input v-model.number="settleForm.amount" type="number" step="0.01" class="inp-money">
                            <PaymentMethodField v-model="settleForm.method" v-model:channel-id="settleForm.payment_channel_id"
                                :channels="channelList" :creating-channel="creatingChannel" @create-channel="(name) => createChannel(name, settleForm)" />
                            <button class="confirm" :disabled="submitting" @click="confirmSettleBill(bill)">{{ t('confirmBtn') }}</button>
                        </div>
                    </div>
                </div>

                <p v-if="billsHasMore" class="settle-more">{{ t('moreOpenItemsHint') }}</p>
            </div>
        </template>
    </AppLayout>
</template>