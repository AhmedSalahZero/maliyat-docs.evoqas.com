<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Admin/Companies/Index.vue
//  Location: resources/js/Pages/Admin/Companies/Index.vue
//
//  Every company on the platform, for the super_admin. Props from
//  App\Http\Controllers\Admin\CompanyController::index() — a
//  standard Laravel paginator serialized by Inertia as
//  { data, links, ... }.
//
//  "Add company" creates the Company AND its first company_admin
//  user in one submit (CompanyController::store /
//  StoreCompanyRequest) — that's the whole onboarding flow for a
//  company that didn't come through public self-signup.
// ══════════════════════════════════════════════════════════════════

import { ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    companies: { type: Object, required: true }, // Laravel paginator: { data, links, ... }
});

const showAddSheet = ref(false);

const form = useForm({
    name: '',
    name_ar: '',
    currency: 'EGP',
    admin_name: '',
    admin_email: '',
    admin_password: '',
    admin_password_confirmation: '',
});

function openAddSheet() {
    form.reset();
    form.clearErrors();
    showAddSheet.value = true;
}

function submit() {
    form.post(route('admin.companies.store'), {
        preserveScroll: true,
        onSuccess: () => { showAddSheet.value = false; },
    });
}

function toggleActive(company) {
    if (!confirm(company.is_active ? `Deactivate ${company.name}?` : `Reactivate ${company.name}?`)) return;
    router.patch(route('admin.companies.toggle-active', company.id), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Companies" />

    <AdminLayout title="Companies">
        <div class="adm-companies">
            <div class="adm-companies__header">
                <div>
                    <h1 class="adm-companies__title">Companies</h1>
                    <p class="adm-companies__sub">{{ props.companies.total ?? props.companies.data.length }} on the platform</p>
                </div>
                <button type="button" class="btn btn-primary" @click="openAddSheet">+ Add company</button>
            </div>

            <div class="report-table-wrap card">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Currency</th>
                            <th class="num">Users</th>
                            <th>Created</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="company in props.companies.data" :key="company.id">
                            <td>
                                <div class="adm-companies__name">{{ company.name }}</div>
                                <div v-if="company.name_ar" class="adm-companies__name-ar">{{ company.name_ar }}</div>
                            </td>
                            <td>{{ company.currency }}</td>
                            <td class="num">{{ company.users_count }}</td>
                            <td>{{ company.created_at }}</td>
                            <td>
                                <span class="badge" :class="company.is_active ? 'ok' : 'warn'">
                                    {{ company.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td>
                                <button type="button" class="btn btn-ghost btn-sm" @click="toggleActive(company)">
                                    {{ company.is_active ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="props.companies.data.length === 0">
                            <td colspan="6" class="adm-companies__empty">No companies yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="props.companies.links?.length > 3" class="adm-companies__pager">
                <template v-for="(link, i) in props.companies.links" :key="i">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="adm-companies__page-link"
                        :class="{ 'adm-companies__page-link--active': link.active }"
                        v-html="link.label"
                        preserve-scroll
                    />
                    <span v-else class="adm-companies__page-link adm-companies__page-link--disabled" v-html="link.label"></span>
                </template>
            </div>
        </div>

        <!-- ── Add company sheet ────────────────────────────────── -->
        <transition name="fade-in">
            <div v-if="showAddSheet" class="modal-backdrop" @click.self="showAddSheet = false">
                <div class="modal-sheet slide-up adm-companies__sheet">
                    <div class="modal-sheet__handle"></div>
                    <h2 class="adm-companies__sheet-title">Add company</h2>

                    <form @submit.prevent="submit">
                        <div class="form-group">
                            <label class="form-label">Company name</label>
                            <input v-model="form.name" type="text" class="form-input" required>
                            <div v-if="form.errors.name" class="form-error">{{ form.errors.name }}</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Company name (Arabic) — optional</label>
                            <input v-model="form.name_ar" type="text" class="form-input" dir="rtl">
                            <div v-if="form.errors.name_ar" class="form-error">{{ form.errors.name_ar }}</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Currency</label>
                            <input v-model="form.currency" type="text" class="form-input" maxlength="8">
                            <div v-if="form.errors.currency" class="form-error">{{ form.errors.currency }}</div>
                        </div>

                        <hr class="divider">

                        <div class="form-group">
                            <label class="form-label">Admin name</label>
                            <input v-model="form.admin_name" type="text" class="form-input" required>
                            <div v-if="form.errors.admin_name" class="form-error">{{ form.errors.admin_name }}</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Admin email</label>
                            <input v-model="form.admin_email" type="email" class="form-input" required>
                            <div v-if="form.errors.admin_email" class="form-error">{{ form.errors.admin_email }}</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Admin password</label>
                            <input v-model="form.admin_password" type="password" class="form-input" required>
                            <div v-if="form.errors.admin_password" class="form-error">{{ form.errors.admin_password }}</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Confirm password</label>
                            <input v-model="form.admin_password_confirmation" type="password" class="form-input" required>
                        </div>

                        <div class="submit-row" style="display: flex; gap: 10px;">
                            <button type="button" class="btn btn-ghost" @click="showAddSheet = false">Cancel</button>
                            <button type="submit" class="btn btn-primary btn-block" :disabled="form.processing">
                                {{ form.processing ? 'Creating…' : 'Create company' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </transition>
    </AdminLayout>
</template>

<style scoped>
.adm-companies {
    max-width: 1000px;
    margin: 0 auto;
    padding: 32px 20px 48px;
}

.adm-companies__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.adm-companies__title {
    font-family: var(--font-heading);
    font-size: 22px;
    font-weight: 700;
    color: var(--color-text-primary);
    margin: 0 0 4px;
}

.adm-companies__sub { font-size: 13.5px; color: var(--color-text-muted); margin: 0; }

.adm-companies__name { font-weight: 600; color: var(--color-text-primary); }
.adm-companies__name-ar { font-size: 12px; color: var(--color-text-muted); }

.adm-companies__empty { text-align: center; color: var(--color-text-muted); padding: 32px !important; }

.adm-companies__pager { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 16px; }

.adm-companies__page-link {
    min-width: 34px;
    text-align: center;
    padding: 6px 10px;
    border-radius: var(--radius-md);
    font-size: 13px;
    text-decoration: none;
    color: var(--color-text-secondary);
    border: 1px solid var(--color-border);
}

.adm-companies__page-link--active { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }
.adm-companies__page-link--disabled { opacity: 0.4; }

.adm-companies__sheet { max-width: 460px; margin: 0 auto; max-height: 88vh; overflow-y: auto; }
.adm-companies__sheet-title { font-family: var(--font-heading); font-size: 17px; font-weight: 700; margin-bottom: 14px; }
</style>
