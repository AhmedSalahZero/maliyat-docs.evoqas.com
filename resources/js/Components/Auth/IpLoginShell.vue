<script setup>
import { computed, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useAuthStore } from '@/stores/useAuthStore';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    backHref: { type: String, default: null },
    backLabel: { type: String, default: '' },
});

const authStore = useAuthStore();
const isRtl = computed(() => authStore.isRtl);
const locale = computed(() => authStore.locale);
const isDark = computed(() => authStore.isDark);

function toggleTheme() {
    authStore.setThemeLocal(authStore.theme === 'navy' ? 'dark' : 'navy');
}

function toggleLocale() {
    const next = locale.value === 'en' ? 'ar' : 'en';
    authStore.setLocaleLocal(next);
    router.post(route('guest.locale'), { locale: next });
}

onMounted(() => {
    authStore.setThemeLocal(localStorage.getItem('ip_theme') ?? 'navy');
    authStore.setLocaleLocal(localStorage.getItem('ip_locale') ?? 'en');
});
</script>

<template>
    <div class="ip-shell" :data-theme="authStore.theme" :dir="isRtl ? 'rtl' : 'ltr'">

        <!-- ═══════════════════════════════════════════════════════
             HEADER — brand (left) + theme/locale controls (right)
        ════════════════════════════════════════════════════════════ -->
        <header class="ip-shell__header">
            <!-- The product's own logo.
                 
                 This read "InPractice" — hardcoded, from the project
                 this codebase was built out of. It sat in the header
                 of every auth screen except Login and Register, which
                 carry their own layout: so forgot-password, reset,
                 verify-email and confirm-password all showed a
                 customer the name of a different product, on the one
                 page they reach before they have an account.
                 
                 The square icon, not the full logo: the full lockup
                 is portrait (409x610) and belongs in Login's tall
                 hero panel, not in a header bar. Two files because
                 the mark needs contrast in both themes. -->
            <Link :href="route('home')" class="ip-shell__brand">
                <img
                    :src="isDark ? '/images/logo-icon-light.png' : '/images/logo-icon-dark.png'"
                    alt=""
                    aria-hidden="true"
                    class="ip-shell__brand-icon"
                    width="197"
                    height="197"
                />
                <span class="ip-shell__brand-name">
                    {{ locale === 'ar' ? 'ماليات دوكس' : 'Maliyat Docs' }}
                </span>
            </Link>

            <div class="ip-shell__controls">
                <button
                    type="button"
                    class="ip-shell__ctrl-btn"
                    @click="toggleLocale"
                    :title="locale === 'en' ? 'العربية' : 'English'"
                    :aria-label="locale === 'en' ? 'العربية' : 'English'"
                >
                    <span class="ip-shell__ctrl-label">{{ locale === 'en' ? 'ع' : 'EN' }}</span>
                </button>
                <button
                    type="button"
                    class="ip-shell__ctrl-btn"
                    @click="toggleTheme"
                    :title="isDark ? 'Light theme' : 'Dark theme'"
                    :aria-label="isDark ? 'Light theme' : 'Dark theme'"
                >
                    <svg v-if="isDark" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                    </svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                </button>
            </div>
        </header>

        <!-- ═══════════════════════════════════════════════════════
             CARD — back link / title / subtitle / page content
        ════════════════════════════════════════════════════════════ -->
        <main class="ip-shell__main">
            <div class="ip-shell__card">

                <!-- backLabel already carries its own arrow glyph
                     (see authTranslations.js: '← Back to Sign In') -->
                <Link v-if="backHref" :href="backHref" class="ip-shell__back">
                    {{ backLabel }}
                </Link>

                <div class="ip-shell__card-header">
                    <h1 class="ip-shell__title">{{ title }}</h1>
                    <p v-if="subtitle" class="ip-shell__subtitle">{{ subtitle }}</p>
                </div>

                <slot />

            </div>
        </main>

        <footer class="ip-shell__footer">
            <p>© {{ new Date().getFullYear() }} Maliyat Docs</p>
        </footer>

    </div>
</template>

<style scoped>
/* ══════════════════════════════════════════════════════════════
   IpLoginShell — shared centered-card shell for the simpler auth
   pages (Forgot Password, Reset Password, Verify Email, etc).
   This file previously had no <template> or <style> at all, which
   is why every page using it rendered a completely blank screen
   with zero console errors.

   Login.vue / Register.vue manage their own full-screen layout and
   are NOT affected by anything in this file.
══════════════════════════════════════════════════════════════ */

.ip-shell {
    min-height: 100vh;
    min-height: 100dvh;
    background: var(--color-bg);
    display: flex;
    flex-direction: column;
    transition: var(--transition-theme);
}

/* ── Header ── */
.ip-shell__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-4) var(--space-5);
}

.ip-shell__brand {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    text-decoration: none;
    color: var(--color-text-primary);
    min-width: 0;
}

.ip-shell__brand-icon {
    height: 30px;
    width: 30px;
    flex-shrink: 0;
    display: block;
}

.ip-shell__brand-name {
    font-size: var(--text-lg);
    font-weight: var(--fw-bold);
    letter-spacing: -0.01em;
    white-space: nowrap;
}

html[dir="rtl"] .ip-shell__brand-name,
body.lang-ar .ip-shell__brand-name { font-family: 'Cairo', sans-serif; letter-spacing: 0; }

@media (max-width: 480px) {
    .ip-shell__brand-icon { height: 26px; width: 26px; }
    .ip-shell__brand-name { font-size: var(--text-md); }
}

.ip-shell__controls {
    display: flex;
    align-items: center;
    gap: var(--space-2);
}

.ip-shell__ctrl-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: var(--radius-md);
    border: 1px solid var(--color-border);
    background: var(--color-surface);
    color: var(--color-text-secondary);
    cursor: pointer;
    transition: border-color var(--transition-fast), color var(--transition-fast);
}

.ip-shell__ctrl-btn:hover {
    border-color: var(--color-primary);
    color: var(--color-primary);
}

.ip-shell__ctrl-label {
    font-size: 13px;
    font-weight: var(--fw-bold);
}

/* ── Main / card ── */
.ip-shell__main {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-6) var(--space-4);
}

.ip-shell__card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-xl);
    padding: var(--space-6);
    width: 100%;
    max-width: 420px;
    box-shadow: var(--shadow-float);
}

.ip-shell__back {
    display: inline-block;
    font-size: var(--text-sm);
    font-weight: var(--fw-medium);
    color: var(--color-primary);
    text-decoration: none;
    margin-bottom: var(--space-4);
    transition: opacity var(--transition-fast);
}

.ip-shell__back:hover { opacity: 0.75; }

.ip-shell__card-header {
    margin-bottom: var(--space-5);
    text-align: center;
}

.ip-shell__title {
    font-size: var(--text-xl);
    font-weight: var(--fw-bold);
    color: var(--color-text-primary);
    margin-bottom: var(--space-1);
    letter-spacing: -0.01em;
}

.ip-shell__subtitle {
    font-size: var(--text-sm);
    color: var(--color-text-muted);
    line-height: 1.5;
}

/* ── Footer ── */
.ip-shell__footer {
    padding: var(--space-4) var(--space-5);
    text-align: center;
}

.ip-shell__footer p {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
}
</style>