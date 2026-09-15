<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — App/Lookups/Index.vue
//
//  The reference data behind every form: customers, suppliers, items
//  and expense/equipment categories.
//
//  These routes are linked from the app menu AND used by the sentence
//  forms' ComboSelect dropdowns — two different kinds of caller on the
//  same URL. The controllers tell them apart by the X-Inertia header
//  (present on every visit this page itself makes, absent on the
//  sentence forms' plain axios calls) rather than Accept/wantsJson(),
//  since Inertia's own requests satisfy wantsJson() too and were
//  landing users on a raw JSON page instead of this one.
//
//  Items get the fullest editor because they carry a unit setup, not
//  just a name: "1 Pallet = 40 Bags" is the conversion every stock
//  figure depends on, and it was previously impossible to correct.
// ══════════════════════════════════════════════════════════════════

import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormInstructions from '@/Components/App/FormInstructions.vue';
import { useAppTranslations } from '@/Composables/useAppTranslations';

const props = defineProps({
    tab:        { type: String, default: 'customers' },
    rows:       { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
});

const { t } = useAppTranslations();

const TABS = [
    { key: 'customers', route: 'app.customers.index', labelKey: 'lookup_customers' },
    { key: 'vendors',   route: 'app.vendors.index',   labelKey: 'lookup_vendors' },
    { key: 'items',     route: 'app.items.index',     labelKey: 'lookup_items' },
];

const isItems = computed(() => props.tab === 'items');

// ── Editing ──────────────────────────────────────────────────────
const editingId = ref(null);

const form = useForm({
    name: '',
    // Items only.
    uom: '',
    qty_per_uom: 1,
    base_unit_name: '',
    // Customers only.
    phone: '',
});

function startEdit(row) {
    form.reset();
    form.clearErrors();
    form.name = row.name ?? '';
    form.uom = row.uom ?? '';
    form.qty_per_uom = row.qty_per_uom ?? 1;
    form.base_unit_name = row.base_unit_name ?? '';
    form.phone = row.phone ?? '';
    editingId.value = row.id;
}

function cancelEdit() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

const updateRoute = computed(() => ({
    customers: 'app.customers.update',
    vendors:   'app.vendors.update',
    items:     'app.items.update',
}[props.tab]));

function save() {
    // Each endpoint validates only its own fields, so send only those
    // — posting an item's uom to the customer endpoint would just be
    // noise in the request.
    form.transform((data) => {
        if (props.tab === 'items') {
            return {
                name: data.name,
                uom: data.uom,
                qty_per_uom: data.qty_per_uom,
                base_unit_name: data.base_unit_name,
            };
        }

        if (props.tab === 'customers') {
            return { name: data.name, phone: data.phone || null };
        }

        return { name: data.name };
    }).patch(route(updateRoute.value, editingId.value), {
        preserveScroll: true,
        onSuccess: () => cancelEdit(),
    });
}

// ── Add new (Customers / Vendors / Items) ───────────────────────
const addingNew = ref(false);

const createForm = useForm({
    name: '',
    phone: '',        // customers
    type: 'vendor',   // vendors — required by StoreVendorRequest
    uom: '',          // items
    qty_per_uom: 1,   // items
    base_unit_name: '', // items
});

function startAddNew() {
    createForm.reset();
    createForm.clearErrors();
    addingNew.value = true;
}

function cancelAddNew() {
    addingNew.value = false;
    createForm.reset();
    createForm.clearErrors();
}

const createRoute = computed(() => ({
    customers: 'app.customers.store',
    vendors:   'app.vendors.store',
    items:     'app.items.store',
}[props.tab]));

function createRow() {
    createForm.transform((data) => {
        if (props.tab === 'items') {
            return {
                name: data.name,
                uom: data.uom,
                qty_per_uom: data.qty_per_uom,
                base_unit_name: data.base_unit_name,
            };
        }

        if (props.tab === 'customers') {
            return { name: data.name, phone: data.phone || null };
        }

        if (props.tab === 'vendors') {
            return { name: data.name, type: data.type };
        }

        return { name: data.name };
    }).post(route(createRoute.value), {
        preserveScroll: true,
        onSuccess: () => cancelAddNew(),
    });
}

// Switching tabs re-uses this same component instance (same Inertia
// page, different props) — close any open add/edit form left over
// from whichever tab the user was just on, rather than carrying it
// across silently.
watch(() => props.tab, () => {
    cancelEdit();
    cancelAddNew();
    cancelAddCategory();
});

// ── Categories (items tab only, rename in place + add new) ───────
const editingCategoryId = ref(null);
const categoryForm = useForm({ name: '' });

function startCategoryEdit(category) {
    categoryForm.reset();
    categoryForm.clearErrors();
    categoryForm.name = category.name;
    editingCategoryId.value = category.id;
}

function saveCategory() {
    categoryForm.patch(route('app.categories.update', editingCategoryId.value), {
        preserveScroll: true,
        onSuccess: () => { editingCategoryId.value = null; },
    });
}

const addingCategory = ref(false);
const createCategoryForm = useForm({ name: '', kind: 'expense' });

function startAddCategory() {
    createCategoryForm.reset();
    createCategoryForm.clearErrors();
    addingCategory.value = true;
}

function cancelAddCategory() {
    addingCategory.value = false;
    createCategoryForm.reset();
    createCategoryForm.clearErrors();
}

function createCategory() {
    createCategoryForm.post(route('app.categories.store'), {
        preserveScroll: true,
        onSuccess: () => cancelAddCategory(),
    });
}

function unitSummary(row) {
    const per = Number(row.qty_per_uom ?? 1);

    if (!row.uom || per === 1) {
        return row.base_unit_name || row.uom || '—';
    }

    return `1 ${row.uom} = ${per} ${row.base_unit_name || ''}`.trim();
}
</script>

<template>
    <Head :title="t(`lookup_${props.tab}`)" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t(`lookup_${props.tab}`) }}</h1>
        </div>

        <FormInstructions
            form-key="lookup"
            :steps="['howto_lookup_1', 'howto_lookup_2', 'howto_lookup_3']"
            tip-key="howto_lookup_tip"
        />

        <nav class="lookup-tabs">
            <Link
                v-for="tab in TABS"
                :key="tab.key"
                :href="route(tab.route)"
                class="lookup-tab"
                :class="{ 'lookup-tab--active': props.tab === tab.key }"
            >
                {{ t(tab.labelKey) }}
            </Link>
        </nav>

        <button v-if="!addingNew" type="button" class="addline-btn" @click="startAddNew">
            {{ t('lookup_add_new') }}
        </button>

        <div class="report-table-wrap card card--primary">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>{{ t('lookup_name') }}</th>
                        <th v-if="isItems">{{ t('lookup_unit') }}</th>
                        <th v-if="props.tab === 'customers'">{{ t('lookup_phone') }}</th>
                        <th v-if="props.tab === 'vendors'">{{ t('lookup_vendor_type') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- New-row form, always first so it's the first
                         thing seen after tapping "+ Add new" -->
                    <tr v-if="addingNew">
                        <td :colspan="isItems || props.tab === 'customers' || props.tab === 'vendors' ? 3 : 2">
                            <div class="lookup-edit">
                                <div class="field">
                                    <label for="lookup-new-name">{{ t('lookup_name') }}</label>
                                    <input id="lookup-new-name" v-model="createForm.name" type="text" required autofocus>
                                </div>

                                <template v-if="isItems">
                                    <div class="field">
                                        <label for="lookup-new-uom">{{ t('lookup_uom') }}</label>
                                        <input id="lookup-new-uom" v-model="createForm.uom" type="text" placeholder="Carton">
                                    </div>
                                    <div class="field">
                                        <label for="lookup-new-per">{{ t('lookup_qty_per_uom') }}</label>
                                        <input
                                            id="lookup-new-per"
                                            v-model.number="createForm.qty_per_uom"
                                            type="number" step="0.01" min="0.01" class="inp-money"
                                        >
                                    </div>
                                    <div class="field">
                                        <label for="lookup-new-base">{{ t('lookup_base_unit') }}</label>
                                        <input id="lookup-new-base" v-model="createForm.base_unit_name" type="text" placeholder="unit">
                                    </div>
                                </template>

                                <div v-if="props.tab === 'customers'" class="field">
                                    <label for="lookup-new-phone">{{ t('lookup_phone') }}</label>
                                    <input id="lookup-new-phone" v-model="createForm.phone" type="text">
                                </div>

                                <div v-if="props.tab === 'vendors'" class="field">
                                    <label for="lookup-new-type">{{ t('lookup_vendor_type') }}</label>
                                    <select id="lookup-new-type" v-model="createForm.type">
                                        <option value="vendor">{{ t('lookup_vendor_type_vendor') }}</option>
                                        <option value="employee">{{ t('lookup_vendor_type_employee') }}</option>
                                    </select>
                                </div>
                            </div>

                            <p v-if="isItems" class="form-hint lookup-hint">{{ t('lookup_unit_hint') }}</p>
                            <div v-if="createForm.errors.name" class="form-error">{{ createForm.errors.name }}</div>
                            <div v-if="createForm.errors.type" class="form-error">{{ createForm.errors.type }}</div>

                            <div class="lookup-actions" style="margin-top: 10px;">
                                <button type="button" class="btn btn-primary btn-sm" :disabled="createForm.processing" @click="createRow">
                                    {{ t('createBtn') }}
                                </button>
                                <button type="button" class="btn btn-ghost btn-sm" @click="cancelAddNew">
                                    {{ t('cancelBtn') }}
                                </button>
                            </div>
                        </td>
                    </tr>

                    <template v-for="row in props.rows" :key="row.id">
                        <tr v-if="editingId !== row.id">
                            <td class="lookup-name">{{ row.name }}</td>
                            <td v-if="isItems">{{ unitSummary(row) }}</td>
                            <td v-if="props.tab === 'customers'">{{ row.phone || '—' }}</td>
                            <td v-if="props.tab === 'vendors'">
                                {{ row.type === 'employee' ? t('lookup_vendor_type_employee') : t('lookup_vendor_type_vendor') }}
                            </td>
                            <td>
                                <button type="button" class="btn btn-ghost btn-sm" @click="startEdit(row)">
                                    {{ t('editBtn') }}
                                </button>
                            </td>
                        </tr>

                        <!-- Inline editor, in the row's own place so
                             it's obvious which record is being changed -->
                        <tr v-else>
                            <td :colspan="isItems || props.tab === 'customers' || props.tab === 'vendors' ? 3 : 2">
                                <div class="lookup-edit">
                                    <div class="field">
                                        <label :for="`lookup-name-${row.id}`">{{ t('lookup_name') }}</label>
                                        <input :id="`lookup-name-${row.id}`" v-model="form.name" type="text" required>
                                    </div>

                                    <template v-if="isItems">
                                        <div class="field">
                                            <label :for="`lookup-uom-${row.id}`">{{ t('lookup_uom') }}</label>
                                            <input :id="`lookup-uom-${row.id}`" v-model="form.uom" type="text">
                                        </div>
                                        <div class="field">
                                            <label :for="`lookup-per-${row.id}`">{{ t('lookup_qty_per_uom') }}</label>
                                            <input
                                                :id="`lookup-per-${row.id}`"
                                                v-model.number="form.qty_per_uom"
                                                type="number" step="0.01" min="0.01" class="inp-money"
                                            >
                                        </div>
                                        <div class="field">
                                            <label :for="`lookup-base-${row.id}`">{{ t('lookup_base_unit') }}</label>
                                            <input :id="`lookup-base-${row.id}`" v-model="form.base_unit_name" type="text">
                                        </div>
                                    </template>

                                    <div v-if="props.tab === 'customers'" class="field">
                                        <label :for="`lookup-phone-${row.id}`">{{ t('lookup_phone') }}</label>
                                        <input :id="`lookup-phone-${row.id}`" v-model="form.phone" type="text">
                                    </div>
                                </div>

                                <p v-if="isItems" class="form-hint lookup-hint">{{ t('lookup_unit_hint') }}</p>
                                <div v-if="form.errors.name" class="form-error">{{ form.errors.name }}</div>
                            </td>
                            <td>
                                <div class="lookup-actions">
                                    <button type="button" class="btn btn-primary btn-sm" :disabled="form.processing" @click="save">
                                        {{ t('profile_save') }}
                                    </button>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="cancelEdit">
                                        {{ t('cancelBtn') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>

                    <tr v-if="props.rows.length === 0 && !addingNew">
                        <td :colspan="4" class="lookup-empty">{{ t('lookup_empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- ── Expense categories, on the items tab ───────────── -->
        <div v-if="isItems" class="report-table-wrap card card--out">
            <h3 class="sub">{{ t('lookup_categories') }}</h3>

            <button v-if="!addingCategory" type="button" class="addline-btn" @click="startAddCategory">
                {{ t('lookup_add_new') }}
            </button>

            <table class="report-table">
                <thead>
                    <tr><th>{{ t('lookup_name') }}</th><th>{{ t('lookup_category_kind') }}</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-if="addingCategory">
                        <td colspan="2">
                            <div class="lookup-edit">
                                <div class="field">
                                    <label for="lookup-new-category-name">{{ t('lookup_name') }}</label>
                                    <input id="lookup-new-category-name" v-model="createCategoryForm.name" type="text" required autofocus>
                                </div>
                                <div class="field">
                                    <label for="lookup-new-category-kind">{{ t('lookup_category_kind') }}</label>
                                    <select id="lookup-new-category-kind" v-model="createCategoryForm.kind">
                                        <option value="expense">{{ t('lookup_category_kind_expense') }}</option>
                                        <option value="equipment">{{ t('lookup_category_kind_equipment') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div v-if="createCategoryForm.errors.name" class="form-error">{{ createCategoryForm.errors.name }}</div>
                        </td>
                        <td>
                            <div class="lookup-actions">
                                <button type="button" class="btn btn-primary btn-sm" :disabled="createCategoryForm.processing" @click="createCategory">
                                    {{ t('createBtn') }}
                                </button>
                                <button type="button" class="btn btn-ghost btn-sm" @click="cancelAddCategory">
                                    {{ t('cancelBtn') }}
                                </button>
                            </div>
                        </td>
                    </tr>

                    <tr v-for="category in props.categories" :key="category.id">
                        <td>
                            <span v-if="editingCategoryId !== category.id">{{ category.name }}</span>
                            <input v-else v-model="categoryForm.name" type="text" required>
                        </td>
                        <td>
                            {{ category.kind === 'equipment' ? t('lookup_category_kind_equipment') : t('lookup_category_kind_expense') }}
                        </td>
                        <td>
                            <div class="lookup-actions">
                                <template v-if="editingCategoryId === category.id">
                                    <button type="button" class="btn btn-primary btn-sm" :disabled="categoryForm.processing" @click="saveCategory">
                                        {{ t('profile_save') }}
                                    </button>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="editingCategoryId = null">
                                        {{ t('cancelBtn') }}
                                    </button>
                                </template>
                                <button v-else type="button" class="btn btn-ghost btn-sm" @click="startCategoryEdit(category)">
                                    {{ t('editBtn') }}
                                </button>
                            </div>
                        </td>
                    </tr>

                    <tr v-if="props.categories.length === 0 && !addingCategory">
                        <td colspan="3" class="lookup-empty">{{ t('lookup_empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
.lookup-tabs {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.lookup-tab {
    padding: 7px 16px;
    border-radius: var(--radius-pill);
    background: var(--color-surface-alt);
    color: var(--color-text-secondary);
    text-decoration: none;
    font-size: 13.5px;
    font-weight: 500;
}

.lookup-tab--active {
    background: var(--color-primary);
    color: var(--color-text-on-primary);
    font-weight: 600;
}

.lookup-name { font-weight: 600; }

.lookup-edit {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    padding: 4px 0;
}

.lookup-hint { margin: 6px 0 0; }

.lookup-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.lookup-empty {
    text-align: center;
    padding: 26px 12px;
    color: var(--color-text-muted);
}
</style>
