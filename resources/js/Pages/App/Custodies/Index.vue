<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Custodies/Index.vue
//  Location: resources/js/Pages/App/Custodies/Index.vue
//
//  Ports ledger-prototype-v8.html's renderCustodyForm() +
//  renderCustodySettleForm(): "Give to {holder} EGP [amount]
//  [method]", then a list of every custody handed out, each with a
//  Settle button that expands an inline settlement form (expense
//  lines: description, category, amount) with a live leftover/
//  extra-owed summary — same one-open-at-a-time behavior as the
//  prototype's custodyOpenId.
//
//  Edit is only offered for not-yet-settled custody (see
//  CustodyController's class doc comment for why). Delete works
//  either way, same confirm-dialog pattern as every other page.
// ══════════════════════════════════════════════════════════════════

import { ref, computed } from 'vue';
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppIcon from '@/Components/App/AppIcon.vue';
import ComboSelect from '@/Components/App/ComboSelect.vue';
import PaymentMethodField from '@/Components/App/PaymentMethodField.vue';
import ConfirmDialog from '@/Components/App/ConfirmDialog.vue';
import RenameModal from '@/Components/App/RenameModal.vue';
import { useAppTranslations } from '@/Composables/useAppTranslations';

const props = defineProps({
    vendors:         { type: Array, required: true },
    categories:      { type: Array, required: true },
    paymentChannels: { type: Array, required: true },
    custodies:       { type: Object, required: true },
});

const page = usePage();
const { t, locale } = useAppTranslations();
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
const creatingChannel = ref(false);

// ── Give-custody form (create / edit) ───────────────────────────
const editingCustody = ref(null);

const defaultFormState = () => ({
    holder_id: null,
    amount: null,
    method: 'cash',
    payment_channel_id: null,
    given_at: todayIso(),
});

const form = useForm(defaultFormState());
const selectedHolder = computed(() => vendorList.value.find((v) => v.id === form.holder_id) ?? null);

const showRenameHolder = ref(false);
const renaming = ref(false);

async function saveRenameHolder(newName) {
    if (!selectedHolder.value) return;
    renaming.value = true;
    try {
        const { data } = await axios.patch(route('app.vendors.update', selectedHolder.value.id), { name: newName }, { headers: { Accept: 'application/json' } });
        const idx = vendorList.value.findIndex((v) => v.id === data.id);
        if (idx !== -1) vendorList.value[idx] = data;
        showRenameHolder.value = false;
    } finally {
        renaming.value = false;
    }
}

async function createVendor(name) {
    creatingVendor.value = true;
    try {
        const { data } = await axios.post(route('app.vendors.store'), { name, type: 'employee' }, { headers: { Accept: 'application/json' } });
        vendorList.value.push(data);
        form.holder_id = data.id;
    } finally {
        creatingVendor.value = false;
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

function startEdit(custody) {
    editingCustody.value = custody;
    form.holder_id = custody.holder_id;
    form.amount = custody.amount;
    form.method = custody.method;
    form.payment_channel_id = custody.payment_channel_id;
    form.given_at = custody.given_at;
    form.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function cancelEdit() {
    editingCustody.value = null;
    Object.assign(form, defaultFormState());
    form.clearErrors();
}

function submit() {
    if (editingCustody.value) {
        form.put(route('app.custodies.update', editingCustody.value.id), {
            preserveScroll: true,
            onSuccess: () => cancelEdit(),
        });
    } else {
        form.post(route('app.custodies.store'), {
            preserveScroll: true,
            onSuccess: () => Object.assign(form, defaultFormState()),
        });
    }
}

// ── Settlement (inline, one open at a time) ─────────────────────
const settlingId = ref(null);
const settleLines = ref([{ description: '', category_id: null, amount: null }]);
const creatingSettleCategoryForRow = ref(null);
const settling = ref(false);

function openSettle(custody) {
    settlingId.value = settlingId.value === custody.id ? null : custody.id;
    settleLines.value = [{ description: '', category_id: null, amount: null }];
}

function addSettleLine() {
    settleLines.value.push({ description: '', category_id: null, amount: null });
}
function removeSettleLine(index) {
    if (settleLines.value.length <= 1) return;
    settleLines.value.splice(index, 1);
}

async function createSettleCategory(name, rowIndex) {
    creatingSettleCategoryForRow.value = rowIndex;
    try {
        const { data } = await axios.post(route('app.categories.store'), { name, kind: 'expense' }, { headers: { Accept: 'application/json' } });
        categoryList.value.push(data);
        settleLines.value[rowIndex].category_id = data.id;
    } finally {
        creatingSettleCategoryForRow.value = null;
    }
}

function settleTotal() {
    return settleLines.value.reduce((sum, l) => sum + (Number(l.amount) || 0), 0);
}

function settleDiff(custody) {
    return custody.amount - settleTotal();
}

function submitSettle(custody) {
    const lines = settleLines.value.filter((l) => Number(l.amount) > 0);
    if (lines.length === 0) {
        alert(t('settlementIncompleteError'));
        return;
    }

    settling.value = true;
    router.patch(route('app.custodies.settle', custody.id), { lines }, {
        preserveScroll: true,
        onSuccess: () => { settlingId.value = null; },
        onFinish: () => { settling.value = false; },
    });
}

// ── Confirm dialogs ─────────────────────────────────────────────
const confirmDialog = ref({ open: false, title: '', message: '', danger: false, action: null });

function confirmDelete(custody) {
    confirmDialog.value = {
        open: true,
        title: t('deleteBtn'),
        message: t('deleteConfirmSimple'),
        danger: true,
        action: () => router.delete(route('app.custodies.destroy', custody.id), { preserveScroll: true }),
    };
}

function onConfirmDialogConfirm() {
    confirmDialog.value.action?.();
}
</script>

<template>
    <Head :title="t('action_custody_title')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('action_custody_title') }}</h1>
        </div>

        <div class="card">
            <div v-if="editingCustody" class="alert info">{{ t('editingBanner') }}</div>

            <div class="field-row" style="margin-bottom: 4px;">
                <div class="field">
                    <label>{{ t('dateLbl') }}</label>
                    <input v-model="form.given_at" type="date" :max="todayIso()" style="min-width: 0; width: 160px;">
                </div>
            </div>
            <div v-if="form.errors.given_at" class="form-error">{{ form.errors.given_at }}</div>

            <div class="sentence">
                {{ t('giveTo') }}
                <ComboSelect v-model="form.holder_id" :options="vendorList" :creating="creatingVendor"
                             :placeholder="t('selectPlaceholder')" :add-new-label="t('addNewVendor')" @create="createVendor" />
                <button v-if="selectedHolder" type="button" class="inline-icon-btn" title="Rename" @click="showRenameHolder = true">
                    <AppIcon name="pencil" />
                </button>
                <span class="muted-inline">{{ currency }}</span>
                <input v-model.number="form.amount" type="number" class="blank-input narrow" step="0.01">
            </div>
            <div v-if="form.errors.holder_id" class="form-error">{{ form.errors.holder_id }}</div>
            <div v-if="form.errors.amount" class="form-error">{{ form.errors.amount }}</div>

            <div class="paymode-sub" style="margin-top: 14px;">
                <PaymentMethodField v-model="form.method" v-model:channel-id="form.payment_channel_id"
                    :channels="channelList" :creating-channel="creatingChannel" @create-channel="createChannel" />
            </div>

            <div class="submit-row" style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-ghost" v-if="editingCustody" @click="cancelEdit">{{ t('cancelEditBtn') }}</button>
                <button type="button" :disabled="form.processing" @click="submit">
                    {{ editingCustody ? t('saveChangesBtn') : t('giveCustodyBtn') }}
                </button>
            </div>
            <div v-if="form.recentlySuccessful" class="status-line">
                {{ selectedHolder?.name }} — {{ currency }} {{ money(form.amount) }} {{ t('recordedNote') }}
            </div>
        </div>

        <h3 class="sub">{{ t('custodyIndexTitle') }}</h3>
        <div v-if="props.custodies.data.length === 0" class="empty">{{ t('noCustodyYet') }}</div>
        <div v-else class="settle-list">
            <div v-for="custody in props.custodies.data" :key="custody.id" class="settle-item">
                <div class="settle-top">
                    <div>
                        <div class="who">{{ custody.holder }}</div>
                        <div class="meta">
                            {{ t('custodyAmountLbl') }}: {{ currency }} {{ money(custody.amount) }} · {{ custody.given_at }}
                            <span class="badge" :class="custody.settled ? 'ok' : 'partial'">
                                {{ custody.settled ? t('settledLbl') : t('outstandingLbl') }}
                            </span>
                        </div>
                    </div>
                    <div>
                        <template v-if="!custody.settled">
                            <button type="button" class="btn btn-ghost btn-sm" @click="startEdit(custody)">{{ t('editBtn') }}</button>
                            <button type="button" class="open-settle" @click="openSettle(custody)">{{ t('settleCustodyBtn') }}</button>
                        </template>
                        <button type="button" class="btn btn-ghost btn-sm" style="color: var(--color-danger);" @click="confirmDelete(custody)">{{ t('deleteBtn') }}</button>
                    </div>
                </div>

                <!-- Already settled — summary -->
                <div v-if="custody.settled" class="meta" style="margin-top: 6px;">
                    {{ t('spentOnLbl') }}: {{ custody.settlement_lines.map(l => l.description || l.category).join(', ') }}
                    — {{ t('totalSpentLbl') }}: {{ currency }} {{ money(custody.settlement_total) }}
                </div>

                <!-- Inline settlement form -->
                <div v-if="settlingId === custody.id" class="settle-form" style="flex-direction: column; align-items: stretch;">
                    <table class="lines">
                        <colgroup><col class="col-item"><col class="col-price"><col class="col-total"><col class="col-rm"></colgroup>
                        <thead>
                            <tr>
                                <th>{{ t('descriptionLbl') }}</th>
                                <th>{{ t('categoryLbl') }}</th>
                                <th>{{ t('amountNowLbl') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, index) in settleLines" :key="index">
                                <td :data-label="t('descriptionLbl')"><input v-model="line.description" type="text" :placeholder="t('descriptionLbl')"></td>
                                <td :data-label="t('categoryLbl')">
                                    <ComboSelect
                                        v-model="line.category_id"
                                        :options="categoryList"
                                        :creating="creatingSettleCategoryForRow === index"
                                        :placeholder="t('selectPlaceholder')"
                                        :add-new-label="t('addNewCategory')"
                                        :inline="false"
                                        @create="(name) => createSettleCategory(name, index)"
                                    />
                                </td>
                                <td :data-label="t('amountNowLbl')"><input v-model.number="line.amount" type="number" step="0.01" placeholder="0.00"></td>
                                <td class="rm-cell"><button type="button" class="rm-line" @click="removeSettleLine(index)">✕</button></td>
                            </tr>
                        </tbody>
                    </table>
                    <button type="button" class="addline-btn" style="align-self: flex-start;" @click="addSettleLine">{{ t('addExpenseLineBtn') }}</button>

                    <div style="margin-top: 10px;">
                        <div style="display: flex; justify-content: space-between;">
                            <span>{{ t('custodyAmountLbl') }}</span><span class="mono">{{ currency }} {{ money(custody.amount) }}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span>{{ t('totalSpentLbl') }}</span><span class="mono">{{ currency }} {{ money(settleTotal()) }}</span>
                        </div>
                        <div v-if="Math.abs(settleDiff(custody)) <= 0.004" style="color: var(--color-text-muted);">
                            {{ t('fullySettledLbl') }}
                        </div>
                        <div v-else-if="settleDiff(custody) > 0" style="color: var(--color-accent-cyan); font-weight: 600;">
                            {{ t('leftoverLbl') }}: {{ currency }} {{ money(settleDiff(custody)) }}
                        </div>
                        <div v-else style="color: var(--color-danger); font-weight: 600;">
                            {{ t('extraOwedLbl') }}: {{ currency }} {{ money(-settleDiff(custody)) }}
                        </div>
                    </div>

                    <button type="button" class="btn btn-primary" style="align-self: flex-end; margin-top: 10px;" :disabled="settling" @click="submitSettle(custody)">
                        {{ t('confirmSettlementBtn') }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="props.custodies.links?.length > 3" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 16px;">
            <template v-for="(link, i) in props.custodies.links" :key="i">
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
            :open="showRenameHolder"
            title="Rename vendor/employee"
            :current-name="selectedHolder?.name ?? ''"
            :saving="renaming"
            @save="saveRenameHolder"
            @update:open="(val) => { showRenameHolder = val; }"
        />
    </AppLayout>
</template>

<style scoped>
.settle-top > div:last-child { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
</style>
