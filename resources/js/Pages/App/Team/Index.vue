<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — App/Team/Index.vue
//  Location: resources/js/Pages/App/Team/Index.vue
//
//  Props from App\Http\Controllers\App\UserController::index() —
//  { employees: [{ id, name, email, role, is_active, last_login_at }] }.
//  The controller already excludes the signed-in user and scopes to
//  their company, so this list needs no further filtering.
//
//  Creating and toggling are company_admin-only. That's enforced
//  server-side (StoreEmployeeRequest::authorize() and the
//  abort_unless in UserController::toggleActive) — the role check
//  here only hides controls that would 403 anyway, it isn't the
//  security boundary.
// ══════════════════════════════════════════════════════════════════

import { computed, ref } from 'vue';
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormInstructions from '@/Components/App/FormInstructions.vue';
import ConfirmDialog from '@/Components/App/ConfirmDialog.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';

const props = defineProps({
    employees: { type: Array, required: true },
});

const { t, locale } = useAppTranslations();
const page = usePage();

const isCompanyAdmin = computed(() => page.props.auth?.user?.role === 'company_admin');

// One form for both adding a colleague and correcting one.
// `editingId` is null while adding and holds a user id while editing;
// the fields are the same either way, except that a blank password
// when editing means "leave it alone" rather than "no password".
const showAddForm = ref(false);
const editingId = ref(null);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const formOpen = computed(() => showAddForm.value || editingId.value !== null);

const countLabel = computed(() =>
    props.employees.length === 1
        ? t('team_count_one')
        : t('team_count', { count: props.employees.length })
);

function openAddForm() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showAddForm.value = true;
}

function openEditForm(member) {
    showAddForm.value = false;
    form.reset();
    form.clearErrors();
    form.name = member.name;
    form.email = member.email;
    editingId.value = member.id;
}

function closeForm() {
    showAddForm.value = false;
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function submit() {
    if (editingId.value !== null) {
        form.patch(route('app.team.update', editingId.value), {
            preserveScroll: true,
            onSuccess: () => closeForm(),
        });

        return;
    }

    form.post(route('app.team.store'), {
        preserveScroll: true,
        onSuccess: () => closeForm(),
    });
}

// ── Activate / deactivate ────────────────────────────────────────
const confirmOpen = ref(false);
const pendingMember = ref(null);

const confirmMessage = computed(() => {
    if (!pendingMember.value) return '';

    return pendingMember.value.is_active
        ? t('team_confirm_deactivate', { name: pendingMember.value.name })
        : t('team_confirm_reactivate', { name: pendingMember.value.name });
});

function askToggle(member) {
    pendingMember.value = member;
    confirmOpen.value = true;
}

function applyToggle() {
    const member = pendingMember.value;
    if (!member) return;

    router.patch(route('app.team.toggle-active', member.id), {}, {
        preserveScroll: true,
        onFinish: () => { pendingMember.value = null; },
    });
}

function roleLabel(role) {
    return role === 'employee' ? t('role_employee') : t('role_company_admin');
}

function formatLastLogin(value) {
    if (!value) return t('team_never_logged_in');

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return t('team_never_logged_in');

    return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', {
        day: '2-digit', month: 'short', year: 'numeric',
    }).format(date);
}
</script>

<template>
    <Head :title="t('team_title')" />

    <AppLayout>
        <div class="page-header team__header">
            <div>
                <h1>{{ t('team_title') }}</h1>
                <p class="team__count">{{ countLabel }}</p>
            </div>
            <button
                v-if="isCompanyAdmin && !formOpen"
                type="button"
                class="btn btn-primary"
                @click="openAddForm"
            >
                {{ t('team_add') }}
            </button>
        </div>

        <FormInstructions
            form-key="team"
            :steps="['howto_team_1', 'howto_team_2', 'howto_team_3', 'howto_team_4']"
            tip-key="howto_team_tip"
        />

        <!-- ── Add or correct a colleague ────────────────────── -->
        <div v-if="formOpen" class="card">
            <h2 class="team__heading">
                {{ editingId !== null ? t('team_edit_heading') : t('team_add_heading') }}
            </h2>

            <form @submit.prevent="submit">
                <div class="field">
                    <label for="team-name">{{ t('team_name') }}</label>
                    <input id="team-name" v-model="form.name" type="text" autocomplete="name" required>
                </div>
                <div v-if="form.errors.name" class="form-error">{{ form.errors.name }}</div>

                <div class="field">
                    <label for="team-email">{{ t('team_email') }}</label>
                    <input id="team-email" v-model="form.email" type="email" autocomplete="email" required>
                </div>
                <div v-if="form.errors.email" class="form-error">{{ form.errors.email }}</div>

                <div class="field">
                    <label for="team-password">
                        {{ editingId !== null ? t('team_new_password') : t('team_password') }}
                    </label>
                    <input
                        id="team-password"
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        :required="editingId === null"
                    >
                    <p class="form-hint">
                        {{ editingId !== null ? t('team_password_optional') : t('profile_password_hint') }}
                    </p>
                </div>
                <div v-if="form.errors.password" class="form-error">{{ form.errors.password }}</div>

                <div class="field">
                    <label for="team-password-confirm">{{ t('team_password_confirm') }}</label>
                    <input
                        id="team-password-confirm"
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        :required="editingId === null || Boolean(form.password)"
                    >
                </div>

                <div class="team__actions">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">
                        {{ editingId !== null ? t('profile_save') : t('team_create') }}
                    </button>
                    <button type="button" class="btn btn-ghost" @click="closeForm">
                        {{ t('team_cancel') }}
                    </button>
                </div>
            </form>
        </div>

        <!-- ── Members ───────────────────────────────────────── -->
        <div class="report-table-wrap card">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>{{ t('team_col_member') }}</th>
                        <th>{{ t('team_col_role') }}</th>
                        <th>{{ t('team_col_last_login') }}</th>
                        <th>{{ t('team_col_status') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="member in props.employees" :key="member.id">
                        <td>
                            <div class="team__name">{{ member.name }}</div>
                            <div class="team__email">{{ member.email }}</div>
                        </td>
                        <td>{{ roleLabel(member.role) }}</td>
                        <td>{{ formatLastLogin(member.last_login_at) }}</td>
                        <td>
                            <span class="badge" :class="member.is_active ? 'ok' : 'warn'">
                                {{ member.is_active ? t('team_status_active') : t('team_status_inactive') }}
                            </span>
                        </td>
                        <td>
                            <button
                                v-if="isCompanyAdmin"
                                type="button"
                                class="btn btn-ghost btn-sm"
                                @click="openEditForm(member)"
                            >
                                {{ t('editBtn') }}
                            </button>
                            <button
                                v-if="isCompanyAdmin"
                                type="button"
                                class="btn btn-ghost btn-sm"
                                @click="askToggle(member)"
                            >
                                {{ member.is_active ? t('team_deactivate') : t('team_reactivate') }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="props.employees.length === 0">
                        <td colspan="5" class="team__empty">{{ t('team_empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="t('team_title')"
            :message="confirmMessage"
            :confirm-label="pendingMember?.is_active ? t('team_deactivate') : t('team_reactivate')"
            :cancel-label="t('team_cancel')"
            :danger="pendingMember?.is_active === true"
            @confirm="applyToggle"
        />
    </AppLayout>
</template>

<style scoped>
.team__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.team__count {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--text-muted, #6b7280);
}

.team__heading {
    font-size: 16px;
    font-weight: 600;
    margin: 0 0 16px;
    color: var(--text);
}

.team__actions {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 20px;
    flex-wrap: wrap;
}

.team__name {
    font-weight: 600;
}

.team__email {
    font-size: 12.5px;
    color: var(--text-muted, #6b7280);
    word-break: break-all;
}

.team__empty {
    text-align: center;
    padding: 28px 12px;
    color: var(--text-muted, #6b7280);
}
</style>
