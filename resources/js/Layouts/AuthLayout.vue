<script setup>
// ══════════════════════════════════════════════════════════════════
//  InPractice — AuthLayout.vue
//  Location: resources/js/Layouts/AuthLayout.vue
//
//  Shell for Login, Register, and ForgotPassword pages.
//
//  Features:
//    - Centered card layout on all screen sizes
//    - InPractice brand header
//    - Theme toggle (so user can pick theme before registering)
//    - RTL-aware for Arabic
//    - Gradient background using brand colors
// ══════════════════════════════════════════════════════════════════

// 1. Imports
import { onMounted, computed } from 'vue';
import { Link }                from '@inertiajs/vue3';
import { useAuthStore }        from '@/Stores/useAuthStore';

// 2. Props
defineProps({
    title: {
        type: String,
        default: '',
    },
    subtitle: {
        type: String,
        default: '',
    },
});

// 3. Stores
const authStore = useAuthStore();

// 4. Computed
const isDark   = computed(() => authStore.isDark);
const isRtl    = computed(() => authStore.isRtl);

// 5. Methods
function toggleTheme() {
    const next = authStore.theme === 'navy' ? 'dark' : 'navy';
    // Local only — user hasn't registered yet, no server call
    authStore.setThemeLocal(next);
}

function toggleLocale() {
    const next = authStore.locale === 'en' ? 'ar' : 'en';
    // Apply to DOM immediately so RTL works on the auth form
    document.documentElement.setAttribute('lang', next);
    document.documentElement.setAttribute('dir', next === 'ar' ? 'rtl' : 'ltr');
    authStore.locale = next;
}

// 6. Lifecycle
onMounted(() => {
    // Apply the stored theme (defaults to 'navy' if not yet set)
    const savedTheme = localStorage.getItem('ip_theme') ?? 'navy';
    authStore.setThemeLocal(savedTheme);
});
</script>

<template>
    <div class="ip-auth-shell" :data-theme="authStore.theme">

        <!-- ═══════════════════════════════════════════════════════
             BACKGROUND DECORATION
        ════════════════════════════════════════════════════════════ -->
        <div class="ip-auth-bg" aria-hidden="true">
            <div class="ip-auth-bg__circle ip-auth-bg__circle--1" />
            <div class="ip-auth-bg__circle ip-auth-bg__circle--2" />
        </div>

        <!-- ═══════════════════════════════════════════════════════
             HEADER — brand + controls
        ════════════════════════════════════════════════════════════ -->
        <header class="ip-auth-header">
            <Link :href="route('home')" class="ip-auth-brand">
                In<span>Practice</span>
            </Link>

            <div class="ip-auth-header__controls">
                <!-- Locale toggle -->
                <button class="ip-auth-ctrl-btn" @click="toggleLocale" :title="authStore.locale === 'en' ? 'العربية' : 'English'">
                    <span class="ip-auth-ctrl-label">
                        {{ authStore.locale === 'en' ? 'ع' : 'EN' }}
                    </span>
                </button>

                <!-- Theme toggle -->
                <button class="ip-auth-ctrl-btn" @click="toggleTheme" :title="isDark ? 'Switch to Navy' : 'Switch to Dark'">
                    <svg v-if="isDark" xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                    </svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                </button>
            </div>
        </header>

        <!-- ═══════════════════════════════════════════════════════
             AUTH CARD
        ════════════════════════════════════════════════════════════ -->
        <main class="ip-auth-main">
            <div class="ip-auth-card">

                <!-- Card header (title + subtitle from page) -->
                <div v-if="title || subtitle" class="ip-auth-card__header">
                    <h1 v-if="title" class="ip-auth-card__title">{{ title }}</h1>
                    <p v-if="subtitle" class="ip-auth-card__subtitle">{{ subtitle }}</p>
                </div>

                <!-- Page content (form, etc.) -->
                <slot />

            </div>
        </main>

        <!-- ═══════════════════════════════════════════════════════
             FOOTER
        ════════════════════════════════════════════════════════════ -->
        <footer class="ip-auth-footer">
            <p class="ip-auth-footer__text">
                © {{ new Date().getFullYear() }} InPractice · Learn. Connect. Earn.
            </p>
        </footer>

    </div>
</template>

<style scoped>
/* ── Shell ───────────────────────────────────────────────────── */
.ip-auth-shell {
    min-height: 100vh;
    background: var(--color-bg);
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
    transition: var(--transition-theme);
}

/* ── Background decoration ───────────────────────────────────── */
.ip-auth-bg {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
}

.ip-auth-bg__circle {
    position: absolute;
    border-radius: 50%;
    opacity: 0.06;
}

.ip-auth-bg__circle--1 {
    width: 480px;
    height: 480px;
    background: var(--color-primary);
    top: -160px;
    right: -160px;
}

.ip-auth-bg__circle--2 {
    width: 320px;
    height: 320px;
    background: var(--color-header-bg);
    bottom: -100px;
    left: -100px;
}

/* ── Header ──────────────────────────────────────────────────── */
.ip-auth-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-4) var(--space-5);
    position: relative;
    z-index: 1;
}

.ip-auth-brand {
    font-size: var(--text-lg);
    font-weight: var(--fw-bold);
    color: var(--color-text-primary);
    text-decoration: none;
    letter-spacing: -0.01em;
}

.ip-auth-brand span {
    color: var(--color-primary);
}

.ip-auth-header__controls {
    display: flex;
    align-items: center;
    gap: var(--space-2);
}

/* ── Control buttons ─────────────────────────────────────────── */
.ip-auth-ctrl-btn {
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
    transition: border-color var(--transition-fast), color var(--transition-fast), background var(--transition-fast);
}

.ip-auth-ctrl-btn:hover {
    border-color: var(--color-primary);
    color: var(--color-primary);
}

.ip-auth-ctrl-label {
    font-size: 13px;
    font-weight: var(--fw-bold);
}

/* ── Main / card ─────────────────────────────────────────────── */
.ip-auth-main {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-6) var(--space-4);
    position: relative;
    z-index: 1;
}

.ip-auth-card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-xl);
    padding: var(--space-6) var(--space-6);
    width: 100%;
    max-width: 420px;
    box-shadow: var(--shadow-float);
    transition: var(--transition-theme);
}

.ip-auth-card__header {
    margin-bottom: var(--space-5);
    text-align: center;
}

.ip-auth-card__title {
    font-size: var(--text-xl);
    font-weight: var(--fw-bold);
    color: var(--color-text-primary);
    margin-bottom: var(--space-2);
    letter-spacing: -0.01em;
}

.ip-auth-card__subtitle {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    line-height: 1.5;
}

/* ── Footer ──────────────────────────────────────────────────── */
.ip-auth-footer {
    padding: var(--space-4) var(--space-5);
    text-align: center;
    position: relative;
    z-index: 1;
}

.ip-auth-footer__text {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
}
</style>
