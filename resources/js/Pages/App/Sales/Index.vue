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

import { ref, computed, watch } from 'vue';
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
import { todayIso, addDaysIso } from '@/Utils/date';
import { scrollToForm } from '@/composables/useScrollToForm';
import { usePermissions } from '@/composables/usePermissions';
import { useBusinessType } from '@/composables/useBusinessType';

const props = defineProps({
    customers: { type: Array, required: true },
    items:     { type: Array, required: true }, // each: {id, name, uom, qty_per_uom, base_unit_name}
    paymentChannels: { type: Array, required: true },
    salesChannels: { type: Array, required: true },
    // The channel a new sale starts on, by id (SalesChannel::defaultChannel()).
    defaultSalesChannelId: { type: [Number, null], default: null },
    sales:     { type: Object, required: true }, // paginator: { data, links, ... }
    // Unfinished sales shared by the team — see SaleDraftController.
    // Each: { id, data, created_by, updated_by, updated_at }.
    drafts:    { type: Array, default: () => [] },
});

const page = usePage();
const { t, locale } = useAppTranslations();

// The form card, so pressing Edit can bring the FORM into view
// rather than the top of the document — see useScrollToForm.
const formCard = ref(null);

// Delete is company-admin only — mirrors Controller::authorizeDelete().
const { canDelete } = usePermissions();

const { currency, money, intlLocale } = useMoneyFormat();

// Laravel's pagination links come as e.g. "&laquo; Previous" / "Next
// &raquo;" — decoding just these two known-safe arrow entities lets
// us render the label as plain (auto-escaped) text instead of
// v-html, which is unsafe by default (see QA audit L-1).
function paginationLabel(label) {
    return label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»');
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
const salesChannelList = ref([...props.salesChannels]);
const creatingCustomer = ref(false);
const creatingItemForRow = ref(null);
const creatingChannel = ref(false);
const creatingSalesChannel = ref(false);

// Every new sale starts on the company's default channel ("Direct
// Sales" to begin with). The server says WHICH one by id — see
// SalesChannel::defaultChannel() — so renaming or translating that
// channel changes nothing (audit finding M11). It used to be looked
// up here by its English name.
function defaultSalesChannelId() {
    const known = salesChannelList.value.some((c) => c.id === props.defaultSalesChannelId);
    return known ? props.defaultSalesChannelId : (salesChannelList.value[0]?.id ?? null);
}

// A line with no item picked is a SERVICE line: no stock is taken
// and no cost of goods is recorded. Only companies that offer
// services may use one; a goods-only company must pick the item
// (the server enforces this too — audit finding M8).
const { isService } = useBusinessType();
function isServiceLine(line) {
    return !line.item_id && (Number(line.qty) > 0 || (line.unit_price !== null && line.unit_price !== ''));
}

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

// ── Sale kind — Sales Invoice vs Cash Sales ───────────────────────
// Both create a real, fully-accounted Sale record (line items,
// customer, journal entries, appears in P&L) — the only difference
// is Cash Sales skips straight to "paid in full now" and hides the
// later/partial/installment payment options, since a cash sale by
// definition isn't sold on credit. See the watcher below.
const saleKind = ref('invoice'); // 'invoice' | 'cash'
const isCashSale = computed(() => saleKind.value === 'cash');

watch(saleKind, (kind) => {
    if (kind === 'cash') form.mode = 'now';
});

// Every field with its own message under it (date, customer_id,
// the "lines" rule, amount_now, installment_count, due_date) is
// listed here so it isn't shown twice. Any OTHER problem the server
// reports is still shown at the bottom of the form — as a plain
// message, never with its technical field name (audit finding M11)
// — so a failed save is never silent.
const shownErrorKeys = ['date', 'customer_id', 'sales_channel_id', 'lines', 'amount_now', 'installment_count', 'due_date'];
// Quantity problems on individual lines (e.g. "Only 0 pc of Rice
// were in stock on 5 Sep…") are shown in plain words under the lines
// table. Grouped rather than placed on each row because blank rows
// are dropped before sending, so the server's line numbers do not
// always match the rows on screen. Duplicates (two lines of the same
// item) are shown once.
const isLineQtyError = (key) => /^lines\.\d+\.(qty|item_id)$/.test(key);
const lineQtyErrors = computed(() =>
    [...new Set(Object.entries(form.errors).filter(([key]) => isLineQtyError(key)).map(([, message]) => message))]
);
const otherErrors = computed(() =>
    [...new Set(Object.entries(form.errors)
        .filter(([key]) => !shownErrorKeys.includes(key) && !isLineQtyError(key))
        .map(([, message]) => message))]
);

// ── Edit state ───────────────────────────────────────────────────
const editingSale = ref(null); // the full sale row being edited, or null when creating

// A fresh line defaults to "1 base unit" (qty_per_uom 1, uom/
// base_unit_name 'unit') — meaningless until an item is picked, at
// which point onItemChange() below fills in that item's real base
// unit (e.g. "kg"). Matches how a free-text/service line (no item
// ever picked) behaves: qty is simply read as-is, same as before
// this feature existed.
function newSaleLine() {
    return {
        item_id: null, qty: null, uom: 'unit', qty_per_uom: 1, base_unit_name: 'unit', unit_price: null,
        // VAT % and Debit Withholding Tax % of THIS line.
        vat_rate: 0, withholding_rate: 0,
    };
}

const defaultFormState = () => ({
    customer_id: null,
    sales_channel_id: defaultSalesChannelId(),
    date: todayIso(),
    lines: [newSaleLine()],
    mode: 'now',
    method: 'cash',
    payment_channel_id: null,
    amount_now: null,
    due_in_days: 14,
    installment_count: 3,
    installment_interval_days: 30,
});

const form = useForm(defaultFormState());

// ── VAT and Debit Withholding Tax — per product line ─────────────
// Same rules as the server (App\Support\LineTax):
//   line net         = qty x unit price
//   line VAT         = line net x VAT %
//   line withholding = line net x Withholding %   (before VAT)
// Each line is rounded on its own, and the invoice is the sum of the
// rounded lines. `total` is what the customer actually owes:
// subtotal + VAT - withholding.
const r2 = (n) => Math.round((Number(n) + 1e-9) * 100) / 100;
const lineNet = (line) => r2((Number(line.qty) || 0) * (Number(line.unit_price) || 0));
const lineVat = (line) => r2(lineNet(line) * (Number(line.vat_rate) || 0) / 100);
const lineWithholding = (line) => r2(lineNet(line) * (Number(line.withholding_rate) || 0) / 100);

const subtotal = computed(() => r2(form.lines.reduce((sum, line) => sum + lineNet(line), 0)));
const vatAmount = computed(() => r2(form.lines.reduce((sum, line) => sum + lineVat(line), 0)));
const withholdingAmount = computed(() => r2(form.lines.reduce((sum, line) => sum + lineWithholding(line), 0)));
const grossTotal = computed(() => r2(subtotal.value + vatAmount.value));
const total = computed(() => r2(grossTotal.value - withholdingAmount.value));

// The small line under each product: what its VAT and withholding come to.
function lineTaxText(line) {
    const parts = [];
    if (lineVat(line) > 0) parts.push(`${t('vatAmountLbl')} ${currency.value} ${money(lineVat(line))}`);
    if (lineWithholding(line) > 0) parts.push(`${t('lineWhtAmtLbl')} ${currency.value} ${money(lineWithholding(line))}`);
    return parts.join(' · ');
}

const installmentSchedule = computed(() => {
    if (form.mode !== 'installment' || total.value <= 0) return [];
    const count = Math.max(2, Number(form.installment_count) || 2);
    const interval = Math.max(1, Number(form.installment_interval_days) || 1);
    const per = Math.round((total.value / count) * 100) / 100;
    let allocated = 0;
    // Counted from the document's own date, not today — the server
    // does the same, so the preview matches what is saved (audit M13).
    const startDate = form.date || todayIso();
    return Array.from({ length: count }, (_, i) => {
        const isLast = i === count - 1;
        const amount = isLast ? Math.round((total.value - allocated) * 100) / 100 : per;
        allocated += amount;
        return { sequence: i + 1, due_date: addDaysIso(startDate, interval * (i + 1)), amount };
    });
});

function addLine() {
    // A new line starts with the same VAT % as the line above it
    // (most invoices use one rate); Withholding % always starts at 0.
    const previous = form.lines[form.lines.length - 1];
    const line = newSaleLine();
    if (previous) line.vat_rate = Number(previous.vat_rate) || 0;
    form.lines.push(line);
}

function removeLine(index) {
    if (form.lines.length <= 1) return;
    form.lines.splice(index, 1);
}

// Selecting an item defaults the line to selling in that item's
// BASE unit (e.g. "kg", factor 1) — the same unit every sale used
// implicitly before this feature existed, so nothing changes for an
// item that's only ever been bought/sold one way. The dropdown next
// to qty (see saleUnitOptions()) is how the user switches it to the
// item's packaging unit (e.g. "Carton") instead.
function onItemChange(index, itemId) {
    form.lines[index].item_id = itemId;
    const item = itemList.value.find((i) => i.id === itemId);
    form.lines[index].uom = item?.base_unit_name ?? 'unit';
    form.lines[index].qty_per_uom = 1;
    form.lines[index].base_unit_name = item?.base_unit_name ?? 'unit';
}

// The unit choices for one line: always the item's base unit (kg),
// plus its packaging unit (Carton) too — but only when that's
// actually a different, meaningfully-sized unit (qty_per_uom > 1
// just means "no packaging unit has ever been recorded for this
// item"). A free-text line with no item has nothing to choose from.
function saleUnitOptions(line) {
    const item = itemList.value.find((i) => i.id === line.item_id);
    if (!item) return [];

    const base = { factor: 1, label: item.base_unit_name || 'unit' };
    const pack = Number(item.qty_per_uom) > 1 ? { factor: Number(item.qty_per_uom), label: item.uom } : null;

    return pack ? [base, pack] : [base];
}

// Switching a line's unit only changes what its qty is COUNTED in
// (e.g. "5 Carton" vs "5 kg") — it deliberately leaves the qty
// number itself untouched, same as re-picking a currency wouldn't
// rewrite an amount already typed in.
function onUnitChange(index, factor) {
    const line = form.lines[index];
    const item = itemList.value.find((i) => i.id === line.item_id);
    const options = saleUnitOptions(line);
    const chosen = options.find((o) => o.factor === Number(factor));

    line.qty_per_uom = chosen?.factor ?? 1;
    line.uom = chosen?.label ?? (item?.base_unit_name ?? 'unit');
    line.base_unit_name = item?.base_unit_name ?? 'unit';
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

async function createSalesChannel(name) {
    creatingSalesChannel.value = true;
    try {
        const { data } = await axios.post(route('app.sales-channels.store'), { name }, { headers: { Accept: 'application/json' } });
        salesChannelList.value.push(data);
        form.sales_channel_id = data.id;
    } finally {
        creatingSalesChannel.value = false;
    }
}

function startEdit(sale) {
    activeDraftId.value = null;
    lastRecorded.value = null;
    editingSale.value = sale;
    saleKind.value = 'invoice';
    form.customer_id = sale.customer_id;
    form.sales_channel_id = sale.sales_channel_id ?? defaultSalesChannelId();
    form.date = sale.date;
    form.lines = sale.lines.length
        ? sale.lines.map((l) => ({
            item_id: l.item_id,
            qty: l.qty,
            uom: l.uom ?? 'unit',
            qty_per_uom: Number(l.qty_per_uom) || 1,
            base_unit_name: l.base_unit_name ?? 'unit',
            unit_price: l.unit_price,
            vat_rate: Number(l.vat_rate) || 0,
            withholding_rate: Number(l.withholding_rate) || 0,
        }))
        : [newSaleLine()];
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
    // Captured now, because the form is emptied the moment the sale
    // is saved and the "… recorded" line still needs to say what.
    const recordedSummary = {
        name: isCashSale.value ? t('saleKindCashLbl') : (selectedCustomer.value?.name ?? t('saleKindCashLbl')),
        total: total.value,
    };

    form.transform((data) => ({
        ...data,
        // A line only needs qty + unit price to be real — the item
        // is optional (a lump-sum/no-catalog-item line is valid;
        // see postCogsForLines()'s doc comment). Previously this
        // also required item_id, which silently deleted any line
        // where no specific product was picked — exactly the case
        // for a Cash Sale with no item selected, so the sale ended
        // up with zero lines and was rejected.
        lines: data.lines.filter((line) => Number(line.qty) > 0 && line.unit_price !== null && line.unit_price !== ''),
        // Recording a saved draft — the server removes the draft
        // once the sale is saved (SaleController::store()).
        draft_id: activeDraftId.value,
    })).post(route('app.sales.store'), {
        preserveScroll: true,
        // Keep this screen's own state (sale kind, the chosen date)
        // across the save, so resetForNextSale() below decides what
        // is cleared — not a page reload.
        preserveState: true,
        onSuccess: () => {
            lastRecorded.value = recordedSummary;
            resetForNextSale();
        },
    });
}

function doSubmitEdit(saleId) {
    form.transform((data) => ({
        customer_id: data.customer_id,
        sales_channel_id: data.sales_channel_id,
        date: data.date,
        lines: data.lines.filter((line) => Number(line.qty) > 0 && line.unit_price !== null && line.unit_price !== ''),
            due_date: data.due_date || null,
    })).put(route('app.sales.update', saleId), {
        preserveScroll: true,
        onSuccess: () => cancelEdit(),
    });
}

// ── Confirm dialogs (delete / edit-deficit-surplus) ─────────────
const confirmDialog = ref({ open: false, title: '', message: '', danger: false, action: null });

// ── After a sale is recorded: empty the form for the next one ────
// Everything is cleared EXCEPT the date (owner's decision, Sep
// 2026: somebody entering a batch of past sales keeps the same
// date) and the Invoice / Cash choice at the top, which is how
// they work rather than part of one sale.
const lastRecorded = ref(null); // { name, total } of the sale just saved

function resetForNextSale() {
    const keepDate = form.date || todayIso();
    Object.assign(form, { ...defaultFormState(), date: keepDate, lines: [newSaleLine()] });
    form.clearErrors();
    activeDraftId.value = null;
}

// ── Drafts: unfinished sales ─────────────────────────────────────
// A draft is parked in its own table (sale_drafts) and has NO effect
// on the accounts — no stock, no customer balance, no journal, no
// report — until somebody presses Record. Shared by the whole team.
const activeDraftId = ref(null); // the draft currently loaded in the form, if any
const savingDraft = ref(false);

const activeDraft = computed(() =>
    props.drafts.find((d) => d.id === activeDraftId.value) ?? null
);

// Is there anything in the form worth keeping?
const formHasContent = computed(() =>
    Boolean(form.customer_id)
    || form.lines.some((l) => l.item_id || Number(l.qty) > 0 || (l.unit_price !== null && l.unit_price !== ''))
);

function draftSnapshot() {
    return {
        sale_kind: saleKind.value,
        customer_id: form.customer_id,
        sales_channel_id: form.sales_channel_id,
        date: form.date,
        lines: form.lines.map((l) => ({
            item_id: l.item_id, qty: l.qty, uom: l.uom, qty_per_uom: l.qty_per_uom,
            base_unit_name: l.base_unit_name, unit_price: l.unit_price,
            vat_rate: l.vat_rate, withholding_rate: l.withholding_rate,
        })),
        mode: form.mode,
        method: form.method,
        payment_channel_id: form.payment_channel_id,
        amount_now: form.amount_now,
        due_in_days: form.due_in_days,
        installment_count: form.installment_count,
        installment_interval_days: form.installment_interval_days,
    };
}

// Saving parks the sale and empties the form (date kept), so the
// next customer can be served straight away.
function saveDraft() {
    if (!formHasContent.value || savingDraft.value) return;

    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { savingDraft.value = true; },
        onFinish: () => { savingDraft.value = false; },
        onSuccess: () => {
            lastRecorded.value = null;
            resetForNextSale();
        },
    };
    const payload = { data: draftSnapshot() };

    if (activeDraftId.value && activeDraft.value) {
        router.put(route('app.sale-drafts.update', activeDraftId.value), payload, options);
    } else {
        router.post(route('app.sale-drafts.store'), payload, options);
    }
}

function loadDraft(draft) {
    const data = draft.data || {};
    editingSale.value = null;
    saleKind.value = data.sale_kind === 'cash' ? 'cash' : 'invoice';

    const fresh = defaultFormState();
    const picked = {};
    for (const key of Object.keys(fresh)) {
        if (key !== 'lines' && data[key] !== undefined) picked[key] = data[key];
    }
    // A draft saved on another day may carry a date that is now
    // fine, or none at all; never let it be later than today.
    if (!picked.date || picked.date > todayIso()) picked.date = fresh.date;

    Object.assign(form, {
        ...fresh,
        ...picked,
        lines: (data.lines && data.lines.length)
            // A draft saved before VAT became per line has one VAT %
            // for the whole sale (data.vat_rate) — give it to each line.
            ? data.lines.map((l) => ({
                ...newSaleLine(),
                ...(data.vat_rate && l.vat_rate === undefined ? { vat_rate: Number(data.vat_rate) || 0 } : {}),
                ...l,
            }))
            : [newSaleLine()],
    });
    form.clearErrors();
    lastRecorded.value = null;
    activeDraftId.value = draft.id;
    scrollToForm(formCard);
}

function continueDraft(draft) {
    // Don't silently throw away something typed but not saved.
    if (formHasContent.value && activeDraftId.value !== draft.id) {
        confirmDialog.value = {
            open: true,
            title: t('continueDraftTitle'),
            message: t('replaceFormConfirm'),
            danger: false,
            action: () => loadDraft(draft),
        };
        return;
    }
    loadDraft(draft);
}

// Leave the draft as it was saved and give back an empty form.
function closeDraft() {
    lastRecorded.value = null;
    resetForNextSale();
}

function confirmDeleteDraft(draft) {
    confirmDialog.value = {
        open: true,
        title: t('deleteDraftTitle'),
        message: t('deleteDraftConfirm'),
        danger: true,
        action: () => router.delete(route('app.sale-drafts.destroy', draft.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                if (activeDraftId.value === draft.id) activeDraftId.value = null;
            },
        }),
    };
}

// For the Drafts list.
function draftCustomerLabel(draft) {
    const data = draft.data || {};
    if (data.sale_kind === 'cash') return t('saleKindCashLbl');
    return customerList.value.find((c) => c.id === data.customer_id)?.name ?? t('draftNoCustomer');
}

function draftItemsLabel(draft) {
    return (draft.data?.lines || [])
        .map((l) => itemList.value.find((i) => i.id === l.item_id)?.name)
        .filter(Boolean)
        .join(', ');
}

function draftTotal(draft) {
    const data = draft.data || {};
    // What the customer would owe: subtotal + VAT - withholding, per
    // line. Old drafts have one VAT % for the whole sale.
    return r2((data.lines || []).reduce((s, l) => {
        const line = { ...l, vat_rate: l.vat_rate ?? data.vat_rate };
        return s + lineNet(line) + lineVat(line) - lineWithholding(line);
    }, 0));
}

function draftSavedLabel(draft) {
    const who = draft.updated_by || draft.created_by || '—';
    let when = '';
    try {
        when = draft.updated_at
            ? new Intl.DateTimeFormat(intlLocale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(draft.updated_at))
            : '';
    } catch (e) { when = ''; }
    return `${t('draftSavedBy', { name: who })}${when ? ' · ' + when : ''}`;
}

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

// Same language fallback ComboSelect uses internally for any option
// that ships a name_ar — Arabic when the UI is in Arabic and a
// translation exists, otherwise the plain (English) name.
function saleChannelLabel(sale) {
    if (locale.value === 'ar' && sale.sales_channel_ar) {
        return sale.sales_channel_ar;
    }

    return sale.sales_channel;
}

// What was sold, for the Recent sales list — e.g. "Chair x2, Delivery".
// A line entered with no specific product/service picked (item_id
// left blank) contributes nothing rather than a blank placeholder.
function saleItemsLabel(sale) {
    return (sale.lines || [])
        .map((line) => line.item)
        .filter(Boolean)
        .join(', ');
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
            <div v-else-if="activeDraftId" class="alert info draft-banner">
                <span>{{ t('continuingDraftBanner') }}</span>
                <button type="button" class="btn btn-ghost btn-sm" @click="closeDraft">{{ t('closeDraftBtn') }}</button>
            </div>

            <div v-if="!editingSale" class="toggle-btns" style="margin-bottom: 16px;">
                <button type="button" :class="{ active: saleKind === 'invoice' }" @click="saleKind = 'invoice'">{{ t('saleKindInvoiceLbl') }}</button>
                <button type="button" :class="{ active: saleKind === 'cash' }" @click="saleKind = 'cash'">{{ t('saleKindCashLbl') }}</button>
            </div>

            <div class="field-row" style="margin-bottom: 4px;">
                <div class="field">
                    <label>{{ t('dateLbl') }}</label>
                    <input v-model="form.date" type="date" :max="todayIso()" class="inp-date" style="width: 15rem;">
                    <div class="form-hint">{{ t('saleDateNote') }}</div>
                </div>
                <div class="field">
                    <label>{{ t('salesChannelLbl') }}</label>
                    <ComboSelect
                        v-model="form.sales_channel_id"
                        :options="salesChannelList"
                        option-label="name"
                        :creating="creatingSalesChannel"
                        :placeholder="t('selectPlaceholder')"
                        :add-new-label="t('addNewSalesChannel')"
                        :inline="false"
                        @create="createSalesChannel"
                    />
                </div>
            </div>
            <div v-if="form.errors.date" class="form-error">{{ form.errors.date }}</div>
            <div v-if="form.errors.sales_channel_id" class="form-error">{{ form.errors.sales_channel_id }}</div>

            <div v-if="!isCashSale" class="sentence">
                {{ t('sellTo') }}
                <ComboSelect
                    v-model="form.customer_id"
                    :options="customerList"
                    :creating="creatingCustomer"
                    :placeholder="t('selectPlaceholder')"
                    :add-new-label="t('addNewCustomer')"
                    @create="createCustomer"
                />
                <button v-if="selectedCustomer" type="button" class="inline-icon-btn" :title="t('renameTitle')" :aria-label="t('renameTitle')" @click="showRename = true">
                    <AppIcon name="pencil" />
                </button>
            </div>
            <div v-if="!isCashSale && form.errors.customer_id" class="form-error">{{ form.errors.customer_id }}</div>

            <table class="lines">
                <colgroup>
                    <col class="col-item"><col class="col-qty"><col class="col-uom"><col class="col-price"><col class="col-total"><col class="col-rm">
                </colgroup>
                <thead>
                    <tr>
                        <th>{{ t('itemLbl') }}</th>
                        <th>{{ t('qtyLbl') }}</th>
                        <th>{{ t('unitLbl') }}</th>
                        <th>{{ t('unitPriceLbl') }}</th>
                        <th>{{ t('lineTotalLbl') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="(line, index) in form.lines" :key="index">
                    <tr>
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
                            <div v-if="isServiceLine(line)" class="line-hint" :class="{ 'line-hint--warn': !isService }">
                                {{ isService ? t('sale_service_line_hint') : t('sale_line_needs_item_hint') }}
                            </div>
                        </td>
                        <td :data-label="t('qtyLbl')">
                            <!-- Cartons/sacks: whole or half, like purchases. Base unit: to 0.01. -->
                            <input v-model.number="line.qty" type="number" min="0"
                                   :step="Number(line.qty_per_uom) > 1 ? 0.5 : 0.01" placeholder="0">
                        </td>
                        <td :data-label="t('unitLbl')">
                            <!-- Only worth a dropdown once the item actually has
                                 two known units (its base unit AND a packaging
                                 unit, e.g. kg vs Carton) — see saleUnitOptions().
                                 Anything else (no item picked, or an item with
                                 only one unit ever recorded) just shows the
                                 unit as plain text. -->
                            <select v-if="saleUnitOptions(line).length > 1"
                                    :value="line.qty_per_uom"
                                    @change="onUnitChange(index, $event.target.value)">
                                <option v-for="opt in saleUnitOptions(line)" :key="opt.factor" :value="opt.factor">
                                    {{ opt.label }}
                                </option>
                            </select>
                            <span v-else class="muted-inline">{{ line.uom || t('baseUnitLbl') }}</span>
                        </td>
                        <td :data-label="t('unitPriceLbl')"><input v-model.number="line.unit_price" type="number" min="0" step="0.01" placeholder="0.00"></td>
                        <td class="linetotal" :data-label="t('lineTotalLbl')">{{ currency }} {{ money((line.qty || 0) * (line.unit_price || 0)) }}</td>
                        <td class="rm-cell"><button type="button" class="rm-line" @click="removeLine(index)">✕</button></td>
                    </tr>
                    <!-- VAT % and Debit Withholding Tax % of this product line -->
                    <tr class="line-tax">
                        <td colspan="6">
                            <div class="line-tax__box">
                                <label class="line-tax__field">
                                    <span>{{ t('lineVatPctLbl') }}</span>
                                    <input v-model.number="line.vat_rate" type="number" min="0" max="100" step="0.5" placeholder="0">
                                </label>
                                <label class="line-tax__field">
                                    <span>{{ t('lineWhtPctSaleLbl') }}</span>
                                    <input v-model.number="line.withholding_rate" type="number" min="0" max="100" step="0.5" placeholder="0">
                                </label>
                                <span v-if="lineTaxText(line)" class="line-tax__amounts">{{ lineTaxText(line) }}</span>
                            </div>
                        </td>
                    </tr>
                    </template>
                </tbody>
            </table>
            <button type="button" class="addline-btn" @click="addLine">{{ t('addLineBtn') }}</button>
            <div v-if="form.errors.lines" class="form-error">{{ form.errors.lines }}</div>
            <div v-for="message in lineQtyErrors" :key="message" class="form-error">{{ message }}</div>

            <div class="form-hint" style="margin-top: 10px;">{{ t('lineTaxHintSale') }}</div>

            <div style="margin-top: 6px;">
                <div style="display: flex; justify-content: space-between; padding: 4px 0; color: var(--color-text-muted); font-size: 13.5px;">
                    <span>{{ t('subtotalLbl') }}</span><span class="mono">{{ currency }} {{ money(subtotal) }}</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 4px 0; color: var(--color-text-muted); font-size: 13.5px;">
                    <span>{{ t('vatAmountLbl') }}</span><span class="mono">{{ currency }} {{ money(vatAmount) }}</span>
                </div>
                <!-- Debit Withholding Tax: only shown when some line has one -->
                <template v-if="withholdingAmount > 0">
                    <div style="display: flex; justify-content: space-between; padding: 4px 0; color: var(--color-text-muted); font-size: 13.5px;">
                        <span>{{ t('totalInclVatLbl') }}</span><span class="mono">{{ currency }} {{ money(grossTotal) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 4px 0; color: var(--color-text-muted); font-size: 13.5px;">
                        <span>{{ t('lessWhtSaleLbl') }}</span><span class="mono">− {{ currency }} {{ money(withholdingAmount) }}</span>
                    </div>
                </template>
                <div style="display: flex; justify-content: space-between; padding: 6px 0 0; font-weight: 600; font-size: 17px; color: var(--color-primary-dark);">
                    <span>{{ withholdingAmount > 0 ? t('netDueFromCustomerLbl') : t('totalInclVatLbl') }}</span><span class="mono">{{ currency }} {{ money(total) }}</span>
                </div>
            </div>

            <!-- Payment mode — creation only, not editable (see UpdateSaleRequest) -->
            <div v-if="!editingSale" class="paymode-block">
                <div class="muted-inline" style="margin-bottom: 8px;">{{ t('paymentLbl') }}</div>
                <div v-if="!isCashSale" class="toggle-btns">
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

            <!-- Any problem not already shown next to its own field above,
                 in plain words, so a failed save is never silent. -->
            <div v-if="otherErrors.length" class="alert warning" style="margin-top: 12px;">
                <div v-for="message in otherErrors" :key="message">{{ message }}</div>
            </div>

            <div class="submit-row" style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-ghost" v-if="editingSale" @click="cancelEdit">{{ t('cancelEditBtn') }}</button>
                <button type="button" :disabled="form.processing" @click="submit">
                    {{ editingSale ? t('saveChangesBtn') : (isCashSale ? t('recordCashSaleBtn') : t('recordSaleBtn')) }}
                </button>
                <button v-if="!editingSale" type="button" class="btn btn-ghost"
                        :disabled="!formHasContent || savingDraft || form.processing" @click="saveDraft">
                    {{ activeDraftId ? t('updateDraftBtn') : t('saveDraftBtn') }}
                </button>
            </div>
            <div v-if="lastRecorded && form.recentlySuccessful" class="status-line">
                {{ lastRecorded.name }} — {{ currency }} {{ money(lastRecorded.total) }} {{ t('recordedNote') }}
                {{ t('nextSaleReadyNote') }}
            </div>
        </div>

        <!-- ── Drafts — unfinished sales, no accounting effect ─────── -->
        <template v-if="props.drafts.length">
            <h3 class="sub">{{ t('draftsTitle') }} ({{ props.drafts.length }})</h3>
            <div class="form-hint" style="margin: -4px 0 10px;">{{ t('draftsNote') }}</div>
            <div class="settle-list">
                <div v-for="draft in props.drafts" :key="draft.id" class="settle-item draft-item"
                     :class="{ 'draft-item--active': draft.id === activeDraftId }">
                    <div class="settle-top">
                        <div>
                            <div class="who">
                                {{ draftCustomerLabel(draft) }}
                                <span v-if="draftItemsLabel(draft)" class="who-items">— {{ draftItemsLabel(draft) }}</span>
                            </div>
                            <div class="meta">
                                {{ draft.data?.date }} · {{ draftSavedLabel(draft) }}
                            </div>
                        </div>
                        <div>
                            <span class="amt">{{ currency }} {{ money(draftTotal(draft)) }}</span>
                            <button type="button" class="btn btn-ghost btn-sm" @click="continueDraft(draft)">{{ t('continueDraftBtn') }}</button>
                            <button type="button" class="btn btn-ghost btn-sm" style="color: var(--color-danger);" @click="confirmDeleteDraft(draft)">{{ t('deleteBtn') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ── Recent sales — Edit / Delete ─────────────────────── -->
        <h3 class="sub">{{ t('recentSalesTitle') }}</h3>
        <div v-if="props.sales.data.length === 0" class="empty">{{ t('noSalesYet') }}</div>
        <div v-else class="settle-list">
            <div v-for="sale in props.sales.data" :key="sale.id" class="settle-item">
                <div class="settle-top">
                    <div>
                        <div class="who">
                            {{ sale.customer }}
                            <span v-if="saleItemsLabel(sale)" class="who-items">— {{ saleItemsLabel(sale) }}</span>
                        </div>
                        <div class="meta">
                            {{ sale.date }}
                            <span v-if="saleChannelLabel(sale)"> · {{ saleChannelLabel(sale) }}</span> ·
                            <span :class="sale.is_paid ? 'text-success' : 'text-warning'">
                                {{ t('paidLbl') }} {{ currency }} {{ money(sale.paid_amount) }} / {{ currency }} {{ money(sale.amount) }}
                            </span>
                            <span v-if="sale.withholding_amount > 0"> · {{ t('lineWhtAmtLbl') }} {{ currency }} {{ money(sale.withholding_amount) }}</span>
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
            v-model:open="showRename"
            :title="t('renameCustomerTitle')"
            :current-name="selectedCustomer?.name ?? ''"
            :saving="renaming"
            @save="saveRename"
        />
    </AppLayout>
</template>

<style scoped>
.line-hint { margin-top: 4px; font-size: 0.78rem; color: var(--color-text-muted); }
.line-hint--warn { color: var(--color-danger, #B91C2C); }
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

.draft-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
}

/* The draft currently open in the form. */
.draft-item--active {
    border-inline-start: 3px solid var(--color-primary);
    padding-inline-start: 10px;
}

.settle-top > div:last-child {
    display: flex;
    align-items: center;
    gap: 6px;
}
</style>