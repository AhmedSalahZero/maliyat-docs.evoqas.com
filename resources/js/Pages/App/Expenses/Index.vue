<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Expenses/Index.vue
//  Location: resources/js/Pages/App/Expenses/Index.vue
//
//  Ports ledger-prototype-v8.html's renderExpenseForm()/
//  renderRecurringForm() as one page with a One-time/Recurring
//  toggle (the prototype used two separate tabs; combined here
//  since they're the same sentence with 2 extra fields added on).
//
//  Same Edit/Delete/rename patterns as Sales/Index.vue — see that
//  file's header comment for the full reasoning; not repeated here.
//
//  NOT ported: installment mode for recurring expenses — only
//  now/later/partial (StoreRecurringExpenseRequest doesn't accept
//  installment; a recurring series is already its own kind of
//  payment plan, stacking a second one on top isn't attempted).
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
import { todayIso } from '@/Utils/date';
import { useBusinessType } from '@/composables/useBusinessType';
import { scrollToForm } from '@/composables/useScrollToForm';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({
    vendors:         { type: Array, required: true },
    categories:      { type: Array, required: true },
    paymentChannels: { type: Array, required: true },
    expenses:        { type: Object, required: true },
    recurringSeries: { type: Array, required: true },
});

const page = usePage();
const { t, locale } = useAppTranslations();
const { isProduction } = useBusinessType();

// The form card, so pressing Edit can bring the FORM into view
// rather than the top of the document — see useScrollToForm.
const formCard = ref(null);

// Delete is company-admin only — mirrors Controller::authorizeDelete().
const { canDelete } = usePermissions();
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

// ── Lookup lists ─────────────────────────────────────────────────
const vendorList = ref([...props.vendors]);
const categoryList = ref([...props.categories]);
const channelList = ref([...props.paymentChannels]);
const creatingVendor = ref(false);
const creatingCategory = ref(false);
const creatingChannel = ref(false);

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

const selectedVendor = computed(() => vendorList.value.find((v) => v.id === form.vendor_id) ?? null);
const selectedCategory = computed(() => categoryList.value.find((c) => c.id === form.category_id) ?? null);

// ── Rename (pencil) ──────────────────────────────────────────────
const renameTarget = ref(null); // 'vendor' | 'category' | null
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

// ── Form ─────────────────────────────────────────────────────────
// Three kinds: One-time (full mode picker: now/later/partial/
// installment), Recurring (existing series setup, unchanged), and
// Cash Expense — a real, fully-accounted Expense record like
// One-time, just always paid in full immediately, so it skips
// straight past the mode picker (see the watcher below, and
// isRecurring/isCashExpense kept as computed so the rest of this
// file's existing `isRecurring.value` checks don't need to change).
const expenseKind = ref('oneTime'); // 'oneTime' | 'recurring' | 'cash'
const isRecurring = computed(() => expenseKind.value === 'recurring');
const isCashExpense = computed(() => expenseKind.value === 'cash');
const editingExpense = ref(null);

const defaultFormState = () => ({
    vendor_id: null,
    category_id: null,
    date: todayIso(),
    amount: null,
    frequency: 'monthly',
    count: 12,
    mode: 'now',
    method: 'cash',
    payment_channel_id: null,
    amount_now: null,
    due_in_days: 14,
    installment_count: 3,
    installment_interval_days: 30,
    is_production_labor: false,
});

const form = useForm(defaultFormState());

watch(expenseKind, (kind) => {
    if (kind === 'cash') form.mode = 'now';
});

const installmentSchedule = computed(() => {
    if (isRecurring.value || form.mode !== 'installment' || !form.amount) return [];
    const count = Math.max(2, Number(form.installment_count) || 2);
    const interval = Math.max(1, Number(form.installment_interval_days) || 1);
    const total = Number(form.amount);
    const per = Math.round((total / count) * 100) / 100;
    let allocated = 0;
    const today = new Date();
    return Array.from({ length: count }, (_, i) => {
        const isLast = i === count - 1;
        const amt = isLast ? Math.round((total - allocated) * 100) / 100 : per;
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
        const { data } = await axios.post(route('app.categories.store'), { name, kind: 'expense' }, { headers: { Accept: 'application/json' } });
        categoryList.value.push(data);
        form.category_id = data.id;
    } finally {
        creatingCategory.value = false;
    }
}

function startEdit(expense) {
    editingExpense.value = expense;
    expenseKind.value = 'oneTime';
    form.vendor_id = expense.vendor_id;
    form.category_id = expense.category_id;
    form.date = expense.date;
    form.amount = expense.amount;
    form.due_date = editingExpense.value?.due_date ?? null;
    form.is_production_labor = expense.is_production_labor ?? false;
    form.clearErrors();
    scrollToForm(formCard);
}

function cancelEdit() {
    editingExpense.value = null;
    Object.assign(form, defaultFormState());
    form.clearErrors();
}

function submit() {
    if (editingExpense.value) {
        prepareEditSubmit();
    } else if (isRecurring.value) {
        submitRecurring();
    } else {
        submitCreate();
    }
}

function submitCreate() {
    form.post(route('app.expenses.store'), {
        preserveScroll: true,
        onSuccess: () => Object.assign(form, defaultFormState()),
    });
}

function submitRecurring() {
    form.transform((data) => ({
        vendor_id: data.vendor_id,
        category_id: data.category_id,
        date: data.date,
        amount: data.amount,
        frequency: data.frequency,
        count: data.count,
        mode: data.mode === 'installment' ? 'later' : data.mode, // recurring has no installment mode
        method: data.method,
        payment_channel_id: data.payment_channel_id,
        amount_now: data.amount_now,
        due_date: null,
    })).post(route('app.expenses.recurring.store'), {
        preserveScroll: true,
        onSuccess: () => Object.assign(form, defaultFormState()),
    });
}

function doSubmitEdit(id) {
    form.transform((data) => ({
        vendor_id: data.vendor_id,
        category_id: data.category_id,
        date: data.date,
        amount: data.amount,
            due_date: data.due_date || null,
        is_production_labor: data.is_production_labor,
    })).put(route('app.expenses.update', id), {
        preserveScroll: true,
        onSuccess: () => cancelEdit(),
    });
}

// ── Confirm dialogs ─────────────────────────────────────────────
const confirmDialog = ref({ open: false, title: '', message: '', danger: false, action: null });

function prepareEditSubmit() {
    const expense = editingExpense.value;
    const newTotal = Number(form.amount) || 0;
    const paid = expense.paid_amount;

    if (paid <= 0.004) {
        doSubmitEdit(expense.id);
        return;
    }

    const diff = newTotal - paid;
    const message = diff >= 0
        ? fmt(t('editConfirmDeficitPayable'), { paid: `${currency.value} ${money(paid)}`, total: `${currency.value} ${money(newTotal)}`, amount: `${currency.value} ${money(diff)}` })
        : fmt(t('editConfirmSurplusPayable'), { paid: `${currency.value} ${money(paid)}`, total: `${currency.value} ${money(newTotal)}`, amount: `${currency.value} ${money(Math.abs(diff))}` });

    confirmDialog.value = { open: true, title: t('saveChangesBtn'), message, danger: false, action: () => doSubmitEdit(expense.id) };
}

function confirmDelete(expense) {
    const message = expense.payments_count > 0
        ? fmt(t('deleteConfirmWithPayments'), { count: expense.payments_count, amount: `${currency.value} ${money(expense.paid_amount)}` })
        : t('deleteConfirmSimple');

    confirmDialog.value = {
        open: true, title: t('deleteBtn'), message, danger: true,
        action: () => router.delete(route('app.expenses.destroy', expense.id), { preserveScroll: true }),
    };
}

function confirmCancelRemaining(series) {
    confirmDialog.value = {
        open: true, title: t('cancelRemainingBtn'), message: t('cancelRemainingConfirm'), danger: true,
        action: () => router.delete(route('app.expenses.recurring.cancel', series.recurring_id), { preserveScroll: true }),
    };
}

function onConfirmDialogConfirm() {
    confirmDialog.value.action?.();
}

function freqLabel(freq) {
    return { weekly: t('freqWeekly'), monthly: t('freqMonthly'), q3: t('freqQ3'), h6: t('freqH6') }[freq] ?? freq;
}
</script>

<template>
    <Head :title="t('action_expense_title')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('action_expense_title') }}</h1>
        </div>

        <FormInstructions
            v-if="!editingExpense"
            form-key="expense"
            :steps="['howto_expense_1', 'howto_expense_2', 'howto_expense_3', 'howto_expense_4', 'howto_expense_5']"
            tip-key="howto_expense_tip"
        />

        <div ref="formCard" class="card card--out">
            <div v-if="editingExpense" class="alert info">{{ t('editingBanner') }}</div>

            <div v-if="!editingExpense" class="toggle-btns" style="margin-bottom: 16px;">
                <button type="button" :class="{ active: expenseKind === 'oneTime' }" @click="expenseKind = 'oneTime'">{{ t('oneTimeLbl') }}</button>
                <button type="button" :class="{ active: expenseKind === 'recurring' }" @click="expenseKind = 'recurring'">{{ t('recurringLbl') }}</button>
                <button type="button" :class="{ active: expenseKind === 'cash' }" @click="expenseKind = 'cash'">{{ t('cashExpenseLbl') }}</button>
            </div>

            <div class="field-row" style="margin-bottom: 4px;">
                <div class="field">
                    <label>{{ t('dateLbl') }}</label>
                    <input v-model="form.date" type="date" :max="todayIso()" class="inp-date" style="width: 15rem;">
                </div>
            </div>
            <div v-if="form.errors.date" class="form-error">{{ form.errors.date }}</div>

            <div class="sentence">
                <template v-if="!isCashExpense">
                    {{ t('vendorLbl') }}
                    <ComboSelect v-model="form.vendor_id" :options="vendorList" :creating="creatingVendor"
                                 :placeholder="t('selectPlaceholder')" :add-new-label="t('addNewVendor')" @create="createVendor" />
                    <button v-if="selectedVendor" type="button" class="inline-icon-btn" title="Rename" @click="renameTarget = 'vendor'">
                        <AppIcon name="pencil" />
                    </button>
                </template>
                <span class="muted-inline">{{ currency }}</span>
                <input v-model.number="form.amount" type="number" class="blank-input narrow" step="0.01" placeholder="0.00">
                {{ t('forLbl') }}
                <ComboSelect v-model="form.category_id" :options="categoryList" :creating="creatingCategory"
                             :placeholder="t('selectPlaceholder')" :add-new-label="t('addNewCategory')" @create="createCategory" />
                <button v-if="selectedCategory" type="button" class="inline-icon-btn" title="Rename" @click="renameTarget = 'category'">
                    <AppIcon name="pencil" />
                </button>
            </div>

            <label v-if="isProduction" class="checkbox-row" style="margin-top: 10px; display: flex; align-items: center; gap: 8px;">
                <input v-model="form.is_production_labor" type="checkbox">
                <span>{{ t('isProductionLaborLbl') }}</span>
            </label>
            <p v-if="isProduction && form.is_production_labor" class="form-hint">{{ t('isProductionLaborHint') }}</p>
            <div v-if="!isCashExpense && form.errors.vendor_id" class="form-error">{{ form.errors.vendor_id }}</div>
            <div v-if="form.errors.category_id" class="form-error">{{ form.errors.category_id }}</div>
            <div v-if="form.errors.amount" class="form-error">{{ form.errors.amount }}</div>

            <div v-if="isRecurring && !editingExpense" class="field-row">
                <div class="field">
                    <label>{{ t('recurFreqLbl') }}</label>
                    <select v-model="form.frequency">
                        <option value="weekly">{{ t('freqWeekly') }}</option>
                        <option value="monthly">{{ t('freqMonthly') }}</option>
                        <option value="q3">{{ t('freqQ3') }}</option>
                        <option value="h6">{{ t('freqH6') }}</option>
                    </select>
                </div>
                <div class="field field--narrow">
                    <label>{{ t('howManyTimesLbl') }}</label>
                    <input v-model.number="form.count" type="number" min="2" max="60">
                </div>
            </div>

            <!-- Payment mode — creation only -->
            <div v-if="!editingExpense" class="paymode-block">
                <div class="muted-inline" style="margin-bottom: 8px;">{{ t('paymentLbl') }}</div>
                <div v-if="!isCashExpense" class="toggle-btns">
                    <button type="button" :class="{ active: form.mode === 'now' }" @click="form.mode = 'now'">{{ t('payNowLbl') }}</button>
                    <button type="button" :class="{ active: form.mode === 'later' }" @click="form.mode = 'later'">{{ t('payLaterLbl') }}</button>
                    <button type="button" :class="{ active: form.mode === 'partial' }" @click="form.mode = 'partial'">{{ t('payPartialLbl') }}</button>
                    <button v-if="!isRecurring" type="button" :class="{ active: form.mode === 'installment' }" @click="form.mode = 'installment'">{{ t('payInstallmentLbl') }}</button>
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

                <div v-if="!isRecurring && form.mode === 'installment'" class="paymode-sub">
                    <span>{{ t('numInstallmentsLbl') }}</span>
                    <input v-model.number="form.installment_count" type="number" min="2" class="inp-count">
                    <span>{{ t('everyDaysLbl') }}</span>
                    <input v-model.number="form.installment_interval_days" type="number" min="1" class="inp-days">
                    <span>{{ t('daysLbl') }}</span>
                </div>
                <ul v-if="!isRecurring && form.mode === 'installment' && installmentSchedule.length" class="installment-preview">
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
            <div v-if="editingExpense" class="field-row edit-due">
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
                v-if="editingExpense"
                :payments="editingExpense.payments || []"
                :total="Number(editingExpense.amount)"
                :currency="currency"
                direction="out"
                payable-type="expense"
                :payable-id="editingExpense.id"
                :channels="channelList"
                :creating-channel="creatingChannel"
                @create-channel="createChannel"
            />

            <div class="submit-row" style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-ghost" v-if="editingExpense" @click="cancelEdit">{{ t('cancelEditBtn') }}</button>
                <button type="button" :disabled="form.processing" @click="submit">
                    {{ editingExpense ? t('saveChangesBtn') : (isRecurring ? t('setupRecurringBtn') : (isCashExpense ? t('recordCashExpenseBtn') : t('recordExpenseBtn'))) }}
                </button>
            </div>
            <div v-if="form.recentlySuccessful" class="status-line">
                {{ selectedVendor?.name ?? t('cashExpenseLbl') }} — {{ currency }} {{ money(form.amount) }} {{ t('recordedNote') }}
            </div>
        </div>

        <!-- ── Recurring plans ──────────────────────────────────── -->
        <h3 class="sub">{{ t('recurringPlansTitle') }}</h3>
        <div v-if="props.recurringSeries.length === 0" class="empty">{{ t('noRecurringYet') }}</div>
        <div v-else class="settle-list">
            <div v-for="series in props.recurringSeries" :key="series.recurring_id" class="settle-item">
                <div class="settle-top">
                    <div>
                        <div class="who">{{ series.vendor }} — {{ series.category }}</div>
                        <div class="meta">
                            {{ currency }} {{ money(series.amount) }} · {{ freqLabel(series.frequency) }} ·
                            <span v-if="!series.has_unpaid" class="badge ok">{{ t('allPaidLbl') }}</span>
                            <span v-else class="badge partial">{{ series.paid_count }}/{{ series.total_count }} {{ t('paidOfLbl') }}</span>
                            <span v-if="series.next_due"> · {{ t('nextDueLbl') }} {{ series.next_due }}</span>
                        </div>
                    </div>
                    <div>
                        <button v-if="series.has_unpaid && canDelete" type="button" class="btn btn-ghost btn-sm" @click="confirmCancelRemaining(series)">
                            {{ t('cancelRemainingBtn') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Recent expenses — Edit / Delete ─────────────────── -->
        <h3 class="sub">{{ t('recentExpensesTitle') }}</h3>
        <div v-if="props.expenses.data.length === 0" class="empty">{{ t('noExpensesYet') }}</div>
        <div v-else class="settle-list">
            <div v-for="expense in props.expenses.data" :key="expense.id" class="settle-item">
                <div class="settle-top">
                    <div>
                        <div class="who">
                            {{ expense.vendor }} — {{ expense.category }}
                            <span v-if="expense.is_recurring" class="badge info">{{ expense.recurring_index }}/{{ expense.recurring_count }}</span>
                            <span v-if="expense.is_production_labor" class="badge info">{{ t('isProductionLaborBadge') }}</span>
                        </div>
                        <div class="meta">
                            {{ expense.date }} ·
                            <span :class="expense.is_paid ? 'text-success' : 'text-warning'">
                                {{ t('paidLbl') }} {{ currency }} {{ money(expense.paid_amount) }} / {{ currency }} {{ money(expense.amount) }}
                            </span>
                        </div>
                    </div>
                    <div>
                        <span class="amt">{{ currency }} {{ money(expense.amount) }}</span>
                        <button type="button" class="btn btn-ghost btn-sm" @click="startEdit(expense)">{{ t('editBtn') }}</button>
                        <button v-if="canDelete" type="button" class="btn btn-ghost btn-sm" style="color: var(--color-danger);" @click="confirmDelete(expense)">{{ t('deleteBtn') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="props.expenses.links?.length > 3" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 16px;">
            <template v-for="(link, i) in props.expenses.links" :key="i">
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