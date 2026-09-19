<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — OwnerTransactions/Index.vue
//  Location: resources/js/Pages/App/OwnerTransactions/Index.vue
//
//  "Receive {amount} from {owner}" / "Pay {amount} to {owner}" — the
//  simplest sentence-form in the app: a direction toggle, a date, an
//  owner (with inline add-new, same ComboSelect pattern as every
//  other picker), an amount, and a category select whose options
//  depend on direction (capital_injection/repay_withdrawal for
//  "receive", withdrawal/profit_distribution for "pay" — see
//  OwnerTransaction::inCategories()/outCategories()).
//
//  No settle-later step the way Custody has one — see
//  OwnerTransactionController's class doc comment for why. Edit and
//  Delete both work on every row, same as every other simple form
//  (Custody's give, Equipment purchase, ...).
// ══════════════════════════════════════════════════════════════════

import { ref, computed } from 'vue';
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormInstructions from '@/Components/App/FormInstructions.vue';
import AppIcon from '@/Components/App/AppIcon.vue';
import ComboSelect from '@/Components/App/ComboSelect.vue';
import PaymentMethodField from '@/Components/App/PaymentMethodField.vue';
import ConfirmDialog from '@/Components/App/ConfirmDialog.vue';
import RenameModal from '@/Components/App/RenameModal.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';
import { useMoneyFormat } from '@/composables/useMoneyFormat';
import { todayIso } from '@/Utils/date';
import { scrollToForm } from '@/composables/useScrollToForm';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({
    owners:          { type: Array, required: true },
    paymentChannels: { type: Array, required: true },
    transactions:    { type: Object, required: true },
});

const page = usePage();
const { t } = useAppTranslations();
const { canDelete } = usePermissions();
const { currency, money } = useMoneyFormat();

function paginationLabel(label) {
    return label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»');
}

const formCard = ref(null);
const ownerList = ref([...props.owners]);
const channelList = ref([...props.paymentChannels]);
const creatingOwner = ref(false);
const creatingChannel = ref(false);

// Which categories are offered depends only on direction — see the
// model's inCategories()/outCategories(), mirrored here so the
// dropdown never even offers an invalid combination (the backend
// still re-checks this — see StoreOwnerTransactionRequest).
const IN_CATEGORIES = [
    { value: 'capital_injection', labelKey: 'owner_category_capital_injection' },
    { value: 'repay_withdrawal',  labelKey: 'owner_category_repay_withdrawal' },
];
const OUT_CATEGORIES = [
    { value: 'withdrawal',           labelKey: 'owner_category_withdrawal' },
    { value: 'profit_distribution',  labelKey: 'owner_category_profit_distribution' },
];

const editingTransaction = ref(null);

const defaultFormState = () => ({
    direction: 'in',
    category: 'capital_injection',
    owner_id: null,
    amount: null,
    method: 'cash',
    payment_channel_id: null,
    date: todayIso(),
    note: '',
});

const form = useForm(defaultFormState());

const categoryOptions = computed(() => (form.direction === 'in' ? IN_CATEGORIES : OUT_CATEGORIES));

// Switching direction while the previously-selected category belongs
// to the OTHER direction resets it to that direction's first option,
// rather than silently submitting a mismatched pair.
function setDirection(direction) {
    if (form.direction === direction) return;
    form.direction = direction;
    form.category = direction === 'in' ? IN_CATEGORIES[0].value : OUT_CATEGORIES[0].value;
}

const selectedOwner = computed(() => ownerList.value.find((o) => o.id === form.owner_id) ?? null);

const showRenameOwner = ref(false);
const renaming = ref(false);

async function saveRenameOwner(newName) {
    if (!selectedOwner.value) return;
    renaming.value = true;
    try {
        const { data } = await axios.patch(route('app.owners.update', selectedOwner.value.id), { name: newName }, { headers: { Accept: 'application/json' } });
        const idx = ownerList.value.findIndex((o) => o.id === data.id);
        if (idx !== -1) ownerList.value[idx] = data;
        showRenameOwner.value = false;
    } finally {
        renaming.value = false;
    }
}

async function createOwner(name) {
    creatingOwner.value = true;
    try {
        const { data } = await axios.post(route('app.owners.store'), { name }, { headers: { Accept: 'application/json' } });
        ownerList.value.push(data);
        form.owner_id = data.id;
    } finally {
        creatingOwner.value = false;
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

function startEdit(tx) {
    editingTransaction.value = tx;
    form.direction = tx.direction;
    form.category = tx.category;
    form.owner_id = tx.owner_id;
    form.amount = tx.amount;
    form.method = tx.method;
    form.payment_channel_id = tx.payment_channel_id;
    form.date = tx.date;
    form.note = tx.note ?? '';
    form.clearErrors();
    scrollToForm(formCard);
}

function cancelEdit() {
    editingTransaction.value = null;
    Object.assign(form, defaultFormState());
    form.clearErrors();
}

function submit() {
    if (editingTransaction.value) {
        form.put(route('app.owner-transactions.update', editingTransaction.value.id), {
            preserveScroll: true,
            onSuccess: () => cancelEdit(),
        });
    } else {
        form.post(route('app.owner-transactions.store'), {
            preserveScroll: true,
            onSuccess: () => Object.assign(form, defaultFormState()),
        });
    }
}

// ── Confirm dialog (delete) ──────────────────────────────────────
const confirmDialog = ref({ open: false, title: '', message: '', danger: false, action: null });

function confirmDelete(tx) {
    confirmDialog.value = {
        open: true,
        title: t('deleteBtn'),
        message: t('deleteConfirmSimple'),
        danger: true,
        action: () => router.delete(route('app.owner-transactions.destroy', tx.id), { preserveScroll: true }),
    };
}

function onConfirmDialogConfirm() {
    confirmDialog.value.action?.();
}

function categoryLabel(category) {
    const key = {
        capital_injection: 'owner_category_capital_injection',
        repay_withdrawal: 'owner_category_repay_withdrawal',
        withdrawal: 'owner_category_withdrawal',
        profit_distribution: 'owner_category_profit_distribution',
    }[category];
    return key ? t(key) : category;
}
</script>

<template>
    <Head :title="t('action_owner_title')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('action_owner_title') }}</h1>
        </div>

        <FormInstructions
            v-if="!editingTransaction"
            form-key="owner_transaction"
            :steps="['howto_owner_1', 'howto_owner_2', 'howto_owner_3', 'howto_owner_4']"
            tip-key="howto_owner_tip"
        />

        <div ref="formCard" class="card card--pending">
            <div v-if="editingTransaction" class="alert info">{{ t('editingBanner') }}</div>

            <div class="direction-toggle" role="group">
                <button
                    type="button"
                    class="toggle-btn"
                    :class="{ 'toggle-btn--active': form.direction === 'in' }"
                    @click="setDirection('in')"
                >
                    {{ t('owner_receive_toggle') }}
                </button>
                <button
                    type="button"
                    class="toggle-btn"
                    :class="{ 'toggle-btn--active': form.direction === 'out' }"
                    @click="setDirection('out')"
                >
                    {{ t('owner_pay_toggle') }}
                </button>
            </div>

            <div class="field-row" style="margin: 14px 0 4px;">
                <div class="field">
                    <label>{{ t('dateLbl') }}</label>
                    <input v-model="form.date" type="date" :max="todayIso()" class="inp-date" style="width: 15rem;">
                </div>
            </div>
            <div v-if="form.errors.date" class="form-error">{{ form.errors.date }}</div>

            <div class="sentence">
                {{ form.direction === 'in' ? t('owner_receiveFromLbl') : t('owner_payToLbl') }}
                <ComboSelect v-model="form.owner_id" :options="ownerList" :creating="creatingOwner"
                             :placeholder="t('selectPlaceholder')" :add-new-label="t('addNewOwner')" @create="createOwner" />
                <button v-if="selectedOwner" type="button" class="inline-icon-btn" title="Rename" @click="showRenameOwner = true">
                    <AppIcon name="pencil" />
                </button>
                <span class="muted-inline">{{ currency }}</span>
                <input v-model.number="form.amount" type="number" class="blank-input narrow" step="0.01">
                <span class="muted-inline">{{ t('owner_categoryLbl') }}</span>
                <select v-model="form.category" class="blank-select">
                    <option v-for="opt in categoryOptions" :key="opt.value" :value="opt.value">{{ t(opt.labelKey) }}</option>
                </select>
            </div>
            <div v-if="form.errors.owner_id" class="form-error">{{ form.errors.owner_id }}</div>
            <div v-if="form.errors.amount" class="form-error">{{ form.errors.amount }}</div>
            <div v-if="form.errors.category" class="form-error">{{ form.errors.category }}</div>

            <div class="paymode-sub" style="margin-top: 14px;">
                <PaymentMethodField v-model="form.method" v-model:channel-id="form.payment_channel_id"
                    :channels="channelList" :creating-channel="creatingChannel" @create-channel="createChannel" />
            </div>

            <div class="field-row" style="margin-top: 14px;">
                <div class="field" style="flex: 1 1 100%;">
                    <label>{{ t('noteLbl') }}</label>
                    <input v-model="form.note" type="text" :placeholder="t('ownerNotePlaceholder')">
                </div>
            </div>

            <div class="submit-row" style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-ghost" v-if="editingTransaction" @click="cancelEdit">{{ t('cancelEditBtn') }}</button>
                <button type="button" :disabled="form.processing" @click="submit">
                    {{ editingTransaction ? t('saveChangesBtn') : t('recordOwnerTxBtn') }}
                </button>
            </div>
            <div v-if="form.recentlySuccessful" class="status-line">
                {{ selectedOwner?.name }} — {{ currency }} {{ money(form.amount) }} {{ t('recordedNote') }}
            </div>
        </div>

        <h3 class="sub">{{ t('ownerIndexTitle') }}</h3>
        <div v-if="props.transactions.data.length === 0" class="empty">{{ t('noOwnerTxYet') }}</div>
        <div v-else class="settle-list">
            <div v-for="tx in props.transactions.data" :key="tx.id" class="settle-item">
                <div class="settle-top">
                    <div>
                        <div class="who">{{ tx.owner }}</div>
                        <div class="meta">
                            <span class="badge" :class="tx.direction === 'in' ? 'ok' : 'partial'">
                                {{ tx.direction === 'in' ? t('owner_receive_toggle') : t('owner_pay_toggle') }}
                            </span>
                            {{ categoryLabel(tx.category) }} · {{ currency }} {{ money(tx.amount) }} · {{ tx.date }}
                        </div>
                        <div v-if="tx.note" class="meta">{{ tx.note }}</div>
                    </div>
                    <div>
                        <button type="button" class="btn btn-ghost btn-sm" @click="startEdit(tx)">{{ t('editBtn') }}</button>
                        <button v-if="canDelete" type="button" class="btn btn-ghost btn-sm" style="color: var(--color-danger);" @click="confirmDelete(tx)">{{ t('deleteBtn') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="props.transactions.links?.length > 3" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 16px;">
            <template v-for="(link, i) in props.transactions.links" :key="i">
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
            :open="showRenameOwner"
            title="Rename owner"
            :current-name="selectedOwner?.name ?? ''"
            :saving="renaming"
            @save="saveRenameOwner"
            @update:open="(val) => { showRenameOwner = val; }"
        />
    </AppLayout>
</template>

<style scoped>
.settle-top > div:last-child { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

.direction-toggle { display: flex; gap: 8px; }
.toggle-btn {
    padding: 8px 16px;
    border-radius: var(--radius-md);
    border: 1px solid var(--color-border);
    background: var(--color-surface);
    color: var(--color-text);
    cursor: pointer;
    font-weight: 600;
}
.toggle-btn--active {
    background: var(--color-primary);
    border-color: var(--color-primary);
    color: var(--color-on-primary, #fff);
}
</style>