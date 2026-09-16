<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ProductionOrders/Index.vue
//  Location: resources/js/Pages/App/ProductionOrders/Index.vue
//
//  "Day Production" — made {qty} {product} today: used
//  {materials...}, labor {X}. Same sentence-form + repeater-table +
//  recent-list pattern as InventoryPurchases and Expenses (see those
//  files' header comments) — no payment mode here though, since a
//  production run isn't a bill: raw materials were already paid for
//  when purchased, and labor is settled later through the Expense
//  screen's "Production Labor" checkbox (see Expenses/Index.vue).
//
//  This app is for WORKSHOPS, not factories, so a run is materials
//  + labor and nothing else. The "other costs" repeater that used to
//  sit at the bottom of this form is parked (commented out, not
//  deleted) throughout this file — anything beyond those two is a
//  normal Expense, entered on the Expenses screen where it can be
//  marked unpaid or given a due date. See
//  app/Services/ProductionOrderService.php for the full reasoning.
//
//  Only reachable via a tab that only appears once a company has
//  Production checked in its Business Type — see
//  composables/useBusinessType.js.
// ══════════════════════════════════════════════════════════════════

import { ref, computed } from 'vue';
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormInstructions from '@/Components/App/FormInstructions.vue';
import ComboSelect from '@/Components/App/ComboSelect.vue';
import ConfirmDialog from '@/Components/App/ConfirmDialog.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';
import { scrollToForm } from '@/composables/useScrollToForm';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({
    products:         { type: Array, required: true }, // {id, name, base_unit_name}
    rawMaterials:     { type: Array, required: true },  // {id, name, base_unit_name}
    // PARKED with the repeater — the category picker it fed is gone.
    // expenseCategories: { type: Array, required: true }, // {id, name, name_ar}
    orders:           { type: Object, required: true },
});

const page = usePage();
const { t, locale } = useAppTranslations();
const { canDelete } = usePermissions();
const formCard = ref(null);
const currency = computed(() => page.props.auth?.user?.company?.currency ?? 'EGP');

function todayIso() { return new Date().toISOString().slice(0, 10); }
function money(v) {
    return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(v || 0);
}

const productList = ref([...props.products]);
const materialList = ref([...props.rawMaterials]);
const creatingProduct = ref(false);
// PARKED with the repeater.
// const categoryList = ref([...props.expenseCategories]);
// const creatingCategoryForRow = ref(null);

function newMaterialLine() { return { item_id: null, qty: null }; }
// function newOtherCostLine() { return { category_id: null, amount: null }; }

const defaultFormState = () => ({
    item_id: null,
    date: todayIso(),
    qty_produced: null,
    materials: [newMaterialLine()],
    labor_cost: null,
    // PARKED with the repeater.
    // other_costs: [],
});

const form = useForm(defaultFormState());

// The run being corrected, or null when recording a new one.
//
// There was no edit path at all: fixing a mistyped quantity meant
// deleting the run and entering it again, and deleting is
// company-admin only — so an employee could not fix their own typo.
const editingOrder = ref(null);

const selectedProduct = computed(() => productList.value.find((p) => p.id === form.item_id) ?? null);

function addMaterialLine() { form.materials.push(newMaterialLine()); }
function removeMaterialLine(index) {
    if (form.materials.length <= 1) return;
    form.materials.splice(index, 1);
}
// PARKED with the repeater.
// function addOtherCostLine() { form.other_costs.push(newOtherCostLine()); }
// function removeOtherCostLine(index) { form.other_costs.splice(index, 1); }

async function createProduct(name) {
    creatingProduct.value = true;
    try {
        const { data } = await axios.post(route('app.items.store'), { name, type: 'product' }, { headers: { Accept: 'application/json' } });
        productList.value.push(data);
        form.item_id = data.id;
    } finally {
        creatingProduct.value = false;
    }
}

// PARKED with the repeater.
// async function createCategory(name, rowIndex) {
//     creatingCategoryForRow.value = rowIndex;
//     try {
//         const { data } = await axios.post(route('app.categories.store'), { name, kind: 'expense' }, { headers: { Accept: 'application/json' } });
//         categoryList.value.push(data);
//         form.other_costs[rowIndex].category_id = data.id;
//     } finally {
//         creatingCategoryForRow.value = null;
//     }
// }

function startEdit(order) {
    editingOrder.value = order;

    form.clearErrors();
    form.item_id      = order.item_id;
    form.date         = order.date;
    form.qty_produced = Number(order.qty_produced);
    form.labor_cost   = Number(order.labor_cost) || null;
    form.materials    = order.materials.length
        ? order.materials.map((m) => ({ item_id: m.item_id, qty: Number(m.qty) }))
        : [newMaterialLine()];
    // PARKED with the repeater — the controller no longer sends
    // order.other_costs, so there is nothing to map back in.
    // form.other_costs  = order.other_costs.map((o) => ({
    //     category_id: o.category_id,
    //     amount: Number(o.amount),
    // }));

    scrollToForm(formCard);
}

function cancelEdit() {
    editingOrder.value = null;
    form.clearErrors();
    Object.assign(form, defaultFormState());
}

function submit() {
    const shaped = form.transform((data) => ({
        ...data,
        materials: data.materials.filter((m) => m.item_id && Number(m.qty) > 0),
        // PARKED with the repeater.
        // other_costs: data.other_costs.filter((o) => o.category_id && Number(o.amount) > 0),
    }));

    const done = {
        preserveScroll: true,
        onSuccess: () => {
            editingOrder.value = null;
            Object.assign(form, defaultFormState());
        },
    };

    if (editingOrder.value) {
        shaped.put(route('app.production-orders.update', editingOrder.value.id), done);
        return;
    }

    shaped.post(route('app.production-orders.store'), done);
}

// ── Confirm delete ───────────────────────────────────────────────
const confirmDialog = ref({ open: false, title: '', message: '', danger: false, action: null });

function confirmDelete(order) {
    confirmDialog.value = {
        open: true,
        title: t('deleteBtn'),
        message: t('deleteConfirmSimple'),
        danger: true,
        action: () => router.delete(route('app.production-orders.destroy', order.id), { preserveScroll: true }),
    };
}

function onConfirmDialogConfirm() {
    confirmDialog.value.action?.();
}
</script>

<template>
    <Head :title="t('action_production_title')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('action_production_title') }}</h1>
        </div>

        <FormInstructions
            v-if="!editingOrder"
            form-key="production"
            :steps="['howto_production_1', 'howto_production_2', 'howto_production_3', 'howto_production_4']"
            tip-key="howto_production_tip"
        />

        <div ref="formCard" class="card card--stock">
            <div v-if="editingOrder" class="alert info">{{ t('editingBanner') }}</div>

            <div class="field-row" style="margin-bottom: 4px;">
                <div class="field">
                    <label>{{ t('dateLbl') }}</label>
                    <input v-model="form.date" type="date" :max="todayIso()" class="inp-date" style="width: 15rem;">
                </div>
            </div>
            <div v-if="form.errors.date" class="form-error">{{ form.errors.date }}</div>

            <div class="sentence">
                {{ t('madeProductLbl') }}
                <ComboSelect v-model="form.item_id" :options="productList" :creating="creatingProduct"
                             :placeholder="t('selectPlaceholder')" :add-new-label="t('addNewProduct')" @create="createProduct" />
                <span>{{ t('qtyProducedLbl') }}</span>
                <input v-model.number="form.qty_produced" type="number" min="0" step="0.01" class="blank-input narrow" placeholder="0">
                <span v-if="selectedProduct">{{ selectedProduct.base_unit_name }}</span>
            </div>
            <div v-if="form.errors.item_id" class="form-error">{{ form.errors.item_id }}</div>
            <div v-if="form.errors.qty_produced" class="form-error">{{ form.errors.qty_produced }}</div>

            <h3 class="sub">{{ t('materialsUsedLbl') }}</h3>
            <table class="lines">
                <thead>
                    <tr>
                        <th>{{ t('materialLbl') }}</th>
                        <th>{{ t('qtyLbl') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, index) in form.materials" :key="index">
                        <td :data-label="t('materialLbl')">
                            <ComboSelect
                                :model-value="line.item_id"
                                :options="materialList"
                                :placeholder="t('selectPlaceholder')"
                                :allow-create="false"
                                :inline="false"
                                @update:model-value="(val) => (line.item_id = val)"
                            />
                        </td>
                        <td :data-label="t('qtyLbl')"><input v-model.number="line.qty" type="number" min="0" step="0.01" placeholder="0"></td>
                        <td class="rm-cell"><button type="button" class="rm-line" @click="removeMaterialLine(index)">✕</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="addline-btn" @click="addMaterialLine">{{ t('addMaterialBtn') }}</button>
            <p class="form-hint">{{ t('materialsMustBePurchasedHint') }}</p>
            <div v-if="form.errors.materials" class="form-error">{{ form.errors.materials }}</div>

            <div class="field-row" style="margin-top: 16px;">
                <div class="field">
                    <label>{{ t('laborCostLbl') }}</label>
                    <span class="muted-inline">{{ currency }}</span>
                    <input v-model.number="form.labor_cost" type="number" min="0" step="0.01" class="inp-money" placeholder="0.00">
                </div>
            </div>
            <p class="form-hint">{{ t('laborCostHint') }}</p>
            <div v-if="form.errors.labor_cost" class="form-error">{{ form.errors.labor_cost }}</div>

            <!--
                PARKED: the "other costs" repeater.

                A workshop's run is materials + labor. Anything else
                belongs on the Expenses screen, which can say whether
                it was actually paid; this repeater could not, so
                every line was posted as cash leaving the till that
                day. Kept here, not deleted, in case a factory ever
                needs it back. See ProductionOrderService.

                <h3 class="sub">{{ t('otherCostsLbl') }}</h3>
                <table v-if="form.other_costs.length" class="lines">
                    <thead>
                        <tr>
                            <th>{{ t('categoryLbl') }}</th>
                            <th>{{ t('amountLbl') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in form.other_costs" :key="index">
                            <td :data-label="t('categoryLbl')">
                                <ComboSelect
                                    :model-value="line.category_id"
                                    :options="categoryList"
                                    :creating="creatingCategoryForRow === index"
                                    :placeholder="t('selectPlaceholder')"
                                    :add-new-label="t('addNewCategory')"
                                    :inline="false"
                                    @update:model-value="(val) => (line.category_id = val)"
                                    @create="(name) => createCategory(name, index)"
                                />
                            </td>
                            <td :data-label="t('amountLbl')"><input v-model.number="line.amount" type="number" min="0" step="0.01" placeholder="0.00"></td>
                            <td class="rm-cell"><button type="button" class="rm-line" @click="removeOtherCostLine(index)">✕</button></td>
                        </tr>
                    </tbody>
                </table>
                <button type="button" class="addline-btn" @click="addOtherCostLine">{{ t('addOtherCostBtn') }}</button>
            -->

            <div class="submit-row" style="display: flex; gap: 10px; margin-top: 20px;">
                <button v-if="editingOrder" type="button" class="btn btn-ghost" @click="cancelEdit">{{ t('cancelEditBtn') }}</button>
                <button type="button" :disabled="form.processing" @click="submit">
                    {{ editingOrder ? t('saveChangesBtn') : t('recordProductionBtn') }}
                </button>
            </div>
            <div v-if="form.recentlySuccessful" class="status-line">{{ t('recordedNote') }}</div>
        </div>

        <h3 class="sub">{{ t('recentProductionTitle') }}</h3>
        <div v-if="props.orders.data.length === 0" class="empty">{{ t('noProductionYet') }}</div>
        <div v-else class="settle-list">
            <div v-for="order in props.orders.data" :key="order.id" class="settle-item">
                <div class="settle-top">
                    <div>
                        <div class="who">{{ order.item }} — {{ order.qty_produced }} {{ order.base_unit_name }}</div>
                        <div class="meta">
                            {{ order.date }} ·
                            {{ t('unitCostLbl') }}: {{ currency }} {{ money(order.unit_cost) }}
                        </div>
                        <div class="meta">
                            {{ t('materialsUsedLbl') }}: {{ currency }} {{ money(order.material_cost) }} ·
                            {{ t('laborCostLbl') }}: {{ currency }} {{ money(order.labor_cost) }}
                            <!--
                                Shown only for a run entered before the
                                repeater was parked. A new run is always
                                0 here, and printing "Other costs: 0.00"
                                on every row would advertise a field the
                                form no longer has.
                            -->
                            <template v-if="order.other_cost_total > 0">
                                · {{ t('otherCostsLbl') }}: {{ currency }} {{ money(order.other_cost_total) }}
                            </template>
                        </div>
                    </div>
                    <div>
                        <span class="amt">{{ currency }} {{ money(order.total_cost) }}</span>
                        <button type="button" class="btn btn-ghost btn-sm" @click="startEdit(order)">{{ t('editBtn') }}</button>
                        <button v-if="canDelete" type="button" class="btn btn-ghost btn-sm" style="color: var(--color-danger);" @click="confirmDelete(order)">{{ t('deleteBtn') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="props.orders.links?.length > 3" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 16px;">
            <template v-for="(link, i) in props.orders.links" :key="i">
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
    </AppLayout>
</template>
