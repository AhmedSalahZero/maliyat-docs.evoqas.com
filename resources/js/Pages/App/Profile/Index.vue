<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — App/Profile/Index.vue
//  Location: resources/js/Pages/App/Profile/Index.vue
//
//  Props from App\Http\Controllers\App\ProfileController::index() —
//  { user: { id, name, email, language, theme } }.
//
//  Two independent forms, deliberately separate so a failed password
//  change never discards an edited name (and vice versa):
//    • name        → PATCH app.profile.update
//    • password    → PATCH app.profile.password
//
//  Email is displayed read-only: ProfileController::update() only
//  validates and persists `name`, so offering an email field here
//  would silently do nothing. Theme and language are changed from
//  the menu sheet, not here — this page doesn't duplicate them.
// ══════════════════════════════════════════════════════════════════

import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useAppTranslations } from '@/Composables/useAppTranslations';

const props = defineProps({
    user: { type: Object, required: true },
});

const { t } = useAppTranslations();
const page = usePage();

const role = computed(() => page.props.auth?.user?.role);

const roleLabel = computed(() =>
    role.value === 'employee' ? t('role_employee') : t('role_company_admin')
);

const profileForm = useForm({
    name: props.user.name ?? '',
});

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function saveProfile() {
    profileForm.patch(route('app.profile.update'), {
        preserveScroll: true,
    });
}

function savePassword() {
    passwordForm.patch(route('app.profile.password'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
        // Keep whatever the user typed for the *new* password fields
        // out of the DOM after a failure too — only the current
        // password is worth clearing, since that's the one that was
        // wrong in the common case.
        onError: () => passwordForm.reset('current_password'),
    });
}
</script>

<template>
    <Head :title="t('profile_title')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('profile_title') }}</h1>
        </div>

        <!-- ── Account details ───────────────────────────────── -->
        <div class="card">
            <h2 class="profile__heading">{{ t('profile_account_heading') }}</h2>

            <form @submit.prevent="saveProfile">
                <div class="field">
                    <label for="profile-name">{{ t('profile_name') }}</label>
                    <input
                        id="profile-name"
                        v-model="profileForm.name"
                        type="text"
                        autocomplete="name"
                        required
                    >
                </div>
                <div v-if="profileForm.errors.name" class="form-error">{{ profileForm.errors.name }}</div>

                <div class="field profile__readonly">
                    <label for="profile-email">{{ t('profile_email') }}</label>
                    <input id="profile-email" :value="props.user.email" type="email" disabled>
                    <p class="form-hint">{{ t('profile_email_locked') }}</p>
                </div>

                <div class="profile__role">
                    <span class="profile__role-label">{{ t('profile_role') }}</span>
                    <span class="badge ok">{{ roleLabel }}</span>
                </div>

                <div class="profile__actions">
                    <button type="submit" class="btn btn-primary" :disabled="profileForm.processing">
                        {{ t('profile_save') }}
                    </button>
                    <span v-if="profileForm.recentlySuccessful" class="profile__saved">✓</span>
                </div>
            </form>
        </div>

        <!-- ── Password ──────────────────────────────────────── -->
        <div class="card">
            <h2 class="profile__heading">{{ t('profile_password_heading') }}</h2>

            <form @submit.prevent="savePassword">
                <div class="field">
                    <label for="profile-current-password">{{ t('profile_current_password') }}</label>
                    <input
                        id="profile-current-password"
                        v-model="passwordForm.current_password"
                        type="password"
                        autocomplete="current-password"
                        required
                    >
                </div>
                <div v-if="passwordForm.errors.current_password" class="form-error">
                    {{ passwordForm.errors.current_password }}
                </div>

                <div class="field">
                    <label for="profile-new-password">{{ t('profile_new_password') }}</label>
                    <input
                        id="profile-new-password"
                        v-model="passwordForm.password"
                        type="password"
                        autocomplete="new-password"
                        required
                    >
                    <p class="form-hint">{{ t('profile_password_hint') }}</p>
                </div>
                <div v-if="passwordForm.errors.password" class="form-error">{{ passwordForm.errors.password }}</div>

                <div class="field">
                    <label for="profile-confirm-password">{{ t('profile_confirm_password') }}</label>
                    <input
                        id="profile-confirm-password"
                        v-model="passwordForm.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="profile__actions">
                    <button type="submit" class="btn btn-primary" :disabled="passwordForm.processing">
                        {{ t('profile_update_password') }}
                    </button>
                    <span v-if="passwordForm.recentlySuccessful" class="profile__saved">✓</span>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.profile__heading {
    font-size: 16px;
    font-weight: 600;
    margin: 0 0 16px;
    color: var(--text);
}

.profile__readonly input:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.profile__role {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 16px 0 4px;
}

.profile__role-label {
    font-size: 13px;
    color: var(--text-muted, #6b7280);
}

.profile__actions {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 20px;
}

.profile__saved {
    color: var(--success, #16a34a);
    font-weight: 700;
}
</style>
