<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useAuthStore } from '@/Stores/useAuthStore';
import { useAuthTranslations } from '@/Composables/useAuthTranslations';
import IpLoginShell from '@/Components/Auth/IpLoginShell.vue';

const props = defineProps({
    status: { type: String, default: null },
    email: { type: String, default: '' },
});

const authStore = useAuthStore();
const { t } = useAuthTranslations();
const codeLength = 6;
const digits = ref(Array(codeLength).fill(''));
const inputRefs = ref([]);

const form = useForm({
    email: props.email || '',
    code: '',
    locale: authStore.locale,
});

const resendForm = useForm({
    email: props.email || '',
    locale: authStore.locale,
});

const codeSent = computed(() => props.status === 'verification-code-sent');

watch(digits, (vals) => {
    form.code = vals.join('');
}, { deep: true });

watch(() => props.email, (val) => {
    if (val) {
        form.email = val;
        resendForm.email = val;
    }
});

function onDigitInput(index, event) {
    const value = event.target.value.replace(/\D/g, '').slice(-1);
    digits.value[index] = value;
    if (value && index < codeLength - 1) {
        inputRefs.value[index + 1]?.focus();
    }
}

function onDigitKeydown(index, event) {
    if (event.key === 'Backspace' && !digits.value[index] && index > 0) {
        inputRefs.value[index - 1]?.focus();
    }
}

function onPaste(event) {
    event.preventDefault();
    const pasted = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, codeLength);
    pasted.split('').forEach((char, i) => { digits.value[i] = char; });
    inputRefs.value[Math.min(pasted.length, codeLength - 1)]?.focus();
}

function submit() {
    form.locale = authStore.locale;
    form.post(route('verification.verify-code'));
}

function resend() {
    resendForm.email = form.email;
    resendForm.locale = authStore.locale;
    resendForm.post(route('verification.resend'), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('verify_email')" />

    <IpLoginShell
        :title="t('verify_email')"
        :subtitle="t('verify_email_check')"
        :back-href="route('login')"
        :back-label="t('back_to_sign_in')"
    >
        <div v-if="codeSent" class="ip-login__status">
            {{ t('verify_resend_sent') }}
        </div>

        <form class="ip-login__form" @submit.prevent="submit">
            <!-- The address the code went to.
                 
                 This used to be an editable field, which asked the
                 person a question the app had already answered: the
                 server knows who just registered and passes the
                 address in. An editable box invites them to change
                 it, and changing it cannot do what they would expect
                 — the code was already sent to the original address.
                 
                 Shown, not asked. The value still travels with the
                 submission — Inertia posts the form object, not the
                 DOM — because after signing up nobody is logged in
                 yet and the server needs to know which pending
                 account this is. -->
            <!-- If the address is genuinely unknown — somebody
                 opened this URL cold, with no sign-up or sign-in
                 behind it — there is nothing to verify and nothing
                 to resend to. Say so and point at sign-in, rather
                 than showing a form with a dead button on it. -->
            <div v-if="!form.email" class="ip-login__status ip-login__status--warn">
                {{ t('verify_unknown_address') }}
            </div>

            <div v-else class="ip-form-group">
                <span class="ip-login__label">{{ t('code_sent_to') }}</span>
                <p class="ip-verify-address">
                    <svg class="ip-verify-address__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>
                    </svg>
                    <span class="ip-verify-address__value" dir="ltr">{{ form.email }}</span>
                </p>
                <p v-if="form.errors.email" class="ip-login__error">{{ form.errors.email }}</p>
            </div>

            <div v-if="form.email" class="ip-form-group">
                <label class="ip-login__label">{{ t('verification_code') }}</label>
                <div class="ip-verify-digits" @paste="onPaste">
                    <input
                        v-for="(_, index) in digits"
                        :key="index"
                        :ref="el => inputRefs[index] = el"
                        type="text"
                        inputmode="numeric"
                        maxlength="1"
                        class="ip-verify-digit"
                        :class="{ 'ip-verify-digit--error': form.errors.code }"
                        :value="digits[index]"
                        @input="onDigitInput(index, $event)"
                        @keydown="onDigitKeydown(index, $event)"
                    />
                </div>
                <p v-if="form.errors.code" class="ip-login__error">{{ form.errors.code }}</p>
            </div>

            <button
                v-if="form.email"
                type="submit"
                class="ip-btn ip-btn--primary ip-btn--full ip-login__submit"
                :disabled="form.processing || form.code.length < codeLength"
            >
                {{ t('verify_submit') }}
            </button>

            <div v-if="form.email" class="ip-login__row-actions">
                <button
                    type="button"
                    class="ip-login__link-btn"
                    :disabled="resendForm.processing"
                    @click="resend"
                >
                    {{ t('verify_resend') }}
                </button>
                <p v-if="resendForm.errors.email" class="ip-login__error">{{ resendForm.errors.email }}</p>
            </div>
        </form>
    </IpLoginShell>
</template>

<style scoped>
.ip-verify-digits {
    display: flex;
    gap: 8px;
    justify-content: center;
    margin: var(--space-2) 0;
}
.ip-verify-digit {
    width: 44px;
    height: 52px;
    text-align: center;
    font-size: 22px;
    font-weight: var(--fw-bold);
    border: 1px solid var(--color-border-input);
    border-radius: var(--radius-md);
    background: var(--color-surface-alt);
    color: var(--color-text-primary);
    outline: none;
    font-family: 'Courier New', Courier, monospace;
}
.ip-verify-digit:focus {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px var(--color-primary-soft);
}
.ip-verify-digit--error { border-color: var(--color-danger); }
</style>
