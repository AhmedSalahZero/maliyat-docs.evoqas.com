<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Auth/ConfirmPassword.vue
//  Location: resources/js/Pages/Auth/ConfirmPassword.vue
//
//  Re-entering your own password before a sensitive action, behind
//  Laravel's `password.confirm` middleware.
//
//  This was the last page still shipping Laravel Breeze's default
//  look — the stock GuestLayout with Tailwind utility classes and
//  hardcoded English, in an app whose every other screen uses
//  IpLoginShell and is bilingual. It is rarely reached, which is
//  exactly why it went unnoticed: a user who did land here would
//  suddenly be looking at a different product.
// ══════════════════════════════════════════════════════════════════

import { Head, useForm } from '@inertiajs/vue3';
import { useAuthStore } from '@/Stores/useAuthStore';
import { useAuthTranslations } from '@/Composables/useAuthTranslations';
import IpLoginShell from '@/Components/Auth/IpLoginShell.vue';
import PasswordInput from '@/Components/PasswordInput.vue';

const authStore = useAuthStore();
const { t } = useAuthTranslations();

const form = useForm({
    password: '',
    locale: authStore.locale,
});

function submit() {
    form.locale = authStore.locale;
    form.post(route('password.confirm'), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head :title="t('confirm_password')" />

    <IpLoginShell
        :title="t('confirm_password')"
        :subtitle="t('confirm_password_subtitle')"
        :back-href="route('login')"
        :back-label="t('back_to_sign_in')"
    >
        <form class="ip-login__form" @submit.prevent="submit">
            <div class="ip-form-group">
                <label class="ip-login__label" for="password">{{ t('password') }}</label>
                <PasswordInput
                    id="password"
                    v-model="form.password"
                    :has-error="!!form.errors.password"
                    autocomplete="current-password"
                    autofocus
                    required
                />
                <p v-if="form.errors.password" class="ip-login__error">{{ form.errors.password }}</p>
            </div>

            <button
                type="submit"
                class="ip-btn ip-btn--primary ip-btn--full ip-login__submit"
                :disabled="form.processing"
            >
                {{ t('confirm_password') }}
            </button>
        </form>
    </IpLoginShell>
</template>
