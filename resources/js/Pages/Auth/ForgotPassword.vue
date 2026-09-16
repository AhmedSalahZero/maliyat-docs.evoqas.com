<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { useAuthStore } from '@/stores/useAuthStore';
import { useAuthTranslations } from '@/composables/useAuthTranslations';
import IpLoginShell from '@/Components/Auth/IpLoginShell.vue';

defineProps({
    status: { type: String, default: null },
});

const authStore = useAuthStore();
const { t } = useAuthTranslations();

const form = useForm({
    email: '',
    locale: authStore.locale,
});

function submit() {
    if (form.processing) return;

    form.locale = authStore.locale;
    form.post(route('password.email'));
}
</script>

<template>
    <Head :title="t('forgot_password')" />

    <IpLoginShell
        :title="t('forgot_password')"
        :subtitle="t('forgot_password_subtitle')"
        :back-href="route('login')"
        :back-label="t('back_to_sign_in')"
    >
        <div v-if="status" class="ip-login__status">{{ status }}</div>

        <form class="ip-login__form" @submit.prevent="submit">
            <div class="ip-form-group">
                <label class="ip-login__label" for="email">{{ t('email') }}</label>
                <div class="ip-login__input-wrap">
                    <svg class="ip-login__input-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>
                    </svg>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        class="ip-login__input"
                        :class="{ 'ip-login__input--error': form.errors.email }"
                        :disabled="form.processing"
                        required
                        autofocus
                        autocomplete="username"
                    />
                </div>
                <p v-if="form.errors.email" class="ip-login__error">{{ form.errors.email }}</p>
            </div>

            <button
                type="submit"
                class="ip-btn ip-btn--primary ip-btn--full ip-login__submit"
                :disabled="form.processing"
            >
                {{ t('send_reset_link') }}
            </button>
        </form>
    </IpLoginShell>
</template>

<style scoped>
/* ══════════════════════════════════════════════════════════════
   These classes are copied verbatim from Login.vue's <style scoped>
   block so this page matches it exactly. They previously didn't
   exist here at all — this page had no <style> block, and
   IpLoginShell.vue (the wrapper around this form) had no <style>
   block either, so every element below rendered with zero styling
   inside a shell that itself rendered nothing.
══════════════════════════════════════════════════════════════ */

.ip-login__status {
    background: var(--color-success-soft);
    color: var(--color-success);
    border-radius: var(--radius-md);
    padding: var(--space-3) var(--space-4);
    font-size: var(--text-sm);
    font-weight: var(--fw-medium);
    margin-bottom: var(--space-4);
    text-align: center;
}

.ip-login__form {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
}

.ip-login__label {
    display: block;
    font-size: var(--text-xs);
    font-weight: var(--fw-semibold);
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--color-text-muted);
    margin-bottom: var(--space-2);
}

.ip-login__input-wrap {
    position: relative;
    display: flex;
    align-items: center;
}

.ip-login__input-icon {
    position: absolute;
    left: var(--space-3);
    color: var(--color-text-muted);
    pointer-events: none;
    flex-shrink: 0;
}

[dir="rtl"] .ip-login__input-icon {
    left: auto;
    right: var(--space-3);
}

.ip-login__input {
    width: 100%;
    min-height: var(--touch-target);
    background: var(--color-surface-alt);
    border: 1px solid var(--color-border-input);
    border-radius: var(--radius-md);
    padding: 0 var(--space-4) 0 calc(var(--space-3) + 16px + var(--space-3));
    font-family: inherit;
    font-size: var(--text-base);
    color: var(--color-text-primary);
    outline: none;
    transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
}

[dir="rtl"] .ip-login__input {
    padding: 0 calc(var(--space-3) + 16px + var(--space-3)) 0 var(--space-4);
}

.ip-login__input:focus {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px var(--color-primary-soft);
}

.ip-login__input--error {
    border-color: var(--color-danger);
}

.ip-login__input--error:focus {
    box-shadow: 0 0 0 3px var(--color-danger-soft);
}

.ip-login__input::placeholder {
    color: var(--color-text-muted);
}

.ip-login__error {
    font-size: var(--text-xs);
    color: var(--color-danger);
    margin-top: var(--space-1);
}

.ip-login__submit {
    font-size: var(--text-md);
    letter-spacing: 0.02em;
    gap: var(--space-2);
}
</style>