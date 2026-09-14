<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { useAuthStore } from '@/Stores/useAuthStore';
import { useAuthTranslations } from '@/Composables/useAuthTranslations';
import IpLoginShell from '@/Components/Auth/IpLoginShell.vue';
import PasswordInput from '@/Components/PasswordInput.vue';

const props = defineProps({
    email: { type: String, required: true },
    token: { type: String, required: true },
});

const authStore = useAuthStore();
const { t } = useAuthTranslations();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
    locale: authStore.locale,
});

function submit() {
    form.locale = authStore.locale;
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head :title="t('reset_password')" />

    <IpLoginShell
        :title="t('new_password')"
        :subtitle="t('reset_password_subtitle')"
        :back-href="route('login')"
        :back-label="t('back_to_sign_in')"
    >
        <form class="ip-login__form" @submit.prevent="submit">
            <input type="hidden" v-model="form.token" />
            <input type="hidden" v-model="form.email" />

            <div class="ip-form-group">
                <label class="ip-login__label" for="password">{{ t('new_password') }}</label>
                <PasswordInput
                    id="password"
                    v-model="form.password"
                    :has-error="!!form.errors.password"
                    autocomplete="new-password"
                    required
                />
                <p v-if="form.errors.password" class="ip-login__error">{{ form.errors.password }}</p>
            </div>

            <div class="ip-form-group">
                <label class="ip-login__label" for="password_confirmation">{{ t('confirm_new_password') }}</label>
                <PasswordInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    :has-error="!!form.errors.password_confirmation"
                    autocomplete="new-password"
                    required
                />
                <p v-if="form.errors.password_confirmation" class="ip-login__error">{{ form.errors.password_confirmation }}</p>
            </div>

            <button
                type="submit"
                class="ip-btn ip-btn--primary ip-btn--full ip-login__submit"
                :disabled="form.processing"
            >
                {{ t('reset_password') }}
            </button>
        </form>
    </IpLoginShell>
</template>
