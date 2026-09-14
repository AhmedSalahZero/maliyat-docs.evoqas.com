<script setup>
import { computed, ref } from 'vue'
import { useAuthStore } from '@/Stores/useAuthStore'

const props = defineProps({
    modelValue: { type: String, default: '' },
    id: { type: String, required: true },
    placeholder: { type: String, default: '' },
    autocomplete: { type: String, default: 'current-password' },
    hasError: { type: Boolean, default: false },
    dir: { type: String, default: 'ltr' },
    required: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const authStore = useAuthStore()
const showPassword = ref(false)

const value = computed({
    get: () => props.modelValue,
    set: (next) => emit('update:modelValue', next),
})

const inputType = computed(() => (showPassword.value ? 'text' : 'password'))

const showLabel = computed(() =>
    authStore.locale === 'ar' ? 'إظهار كلمة المرور' : 'Show password'
)
const hideLabel = computed(() =>
    authStore.locale === 'ar' ? 'إخفاء كلمة المرور' : 'Hide password'
)

function toggleVisibility() {
    showPassword.value = !showPassword.value
}
</script>

<template>
    <div
        class="ip-password-field"
        :class="{ 'ip-password-field--error': hasError }"
    >
        <input
            :id="id"
            v-model="value"
            :type="inputType"
            class="ip-password-input"
            :placeholder="placeholder"
            :autocomplete="autocomplete"
            :required="required"
            :dir="dir"
        />
        <button
            type="button"
            class="us-password-toggle"
            :aria-label="showPassword ? hideLabel : showLabel"
            :aria-pressed="showPassword"
            @click.prevent.stop="toggleVisibility"
        >
            <svg v-if="!showPassword" xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                <circle cx="12" cy="12" r="3" />
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round" aria-hidden="true">
                <path
                    d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
                <line x1="1" y1="1" x2="23" y2="23" />
            </svg>
        </button>
    </div>
</template>

<style scoped>
.ip-password-field {
    display: flex;
    align-items: center;
    width: 100%;
    gap: 4px;
    background: var(--color-surface);
    border: 1px solid var(--color-border-input);
    border-radius: var(--radius-md, 10px);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.ip-password-field:focus-within {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px var(--color-primary-soft);
}

.ip-password-field--error {
    border-color: var(--color-danger) !important;
    box-shadow: 0 0 0 3px var(--color-danger-soft) !important;
}

.ip-password-input {
    flex: 1;
    min-width: 0;
    border: none;
    outline: none;
    background: transparent;
    color: var(--color-text-primary);
    font-family: inherit;
    font-size: 14px;
    padding: 11px 4px 11px 14px;
}

.ip-password-input::placeholder {
    color: var(--color-text-muted);
}

.ip-password-input::-ms-reveal,
.ip-password-input::-ms-clear {
    display: none;
}

.us-password-toggle {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    margin-inline-end: 4px;
    padding: 0;
    border: none;
    border-radius: 8px;
    background: transparent;
    color: var(--color-text-muted);
    cursor: pointer;
    transition: color 0.15s ease, background 0.15s ease;
}

.us-password-toggle:hover {
    color: var(--color-text-primary);
    background: var(--color-surface-alt);
}
</style>
