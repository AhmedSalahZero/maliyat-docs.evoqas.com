// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — useAuthStore.js
//  Location: resources/js/Stores/useAuthStore.js
//
//  Global state for the authenticated user.
//  Holds: user data, active role, theme preference, locale.
//
//  Theme is applied to <html data-theme="..."> so every CSS
//  custom property in app.css switches instantly.
//
//  Locale is applied to <html lang="..."> and <html dir="...">
//  so RTL/LTR and font-family switch for Arabic/English.
//
//  localStorage is used as the source of truth for theme and locale
//  so preferences survive Inertia navigation without a server round trip.
// ══════════════════════════════════════════════════════════════════

import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';

export const useAuthStore = defineStore('auth', () => {

    // ── State ──────────────────────────────────────────────────────
    const user   = ref(null);    // Full user object from Inertia shared data
    const theme  = ref('light'); // 'light' | 'dark'
    const locale = ref('en');    // 'en' | 'ar'

    // ── Computed ───────────────────────────────────────────────────
    const isSuperAdmin  = computed(() => user.value?.role === 'super_admin');
    const isCompanyUser = computed(() => user.value?.role === 'company_admin' || user.value?.role === 'employee');
    const isRtl    = computed(() => locale.value === 'ar');

    const isDark = computed(() =>
        theme.value === 'dark' ||
        document.documentElement.getAttribute('data-theme') === 'dark'
    );

    const userAvatar   = computed(() => user.value?.avatar ?? null);

    // ── Actions ────────────────────────────────────────────────────

    /**
     * Initialise the store from the Inertia shared auth prop.
     * Called on every page mount via AppLayout.
     *
     * localStorage wins over the server value — it is always
     * up to date because applyTheme/applyLocale write to it instantly.
     * The server value is only a fallback for first login on a new device.
     */
    function init(authUser) {
        if (!authUser) return;

        user.value = authUser;

        // localStorage is the source of truth — server is the fallback
        const savedTheme  = localStorage.getItem('ip_theme')  ?? authUser.theme  ?? 'light';
        const savedLocale = localStorage.getItem('ip_locale') ?? authUser.locale ?? 'en';

        applyTheme(savedTheme);
        applyLocale(savedLocale);
    }

    /**
     * Set theme and apply it to the <html> element.
     * The CSS custom properties in app.css do the rest automatically.
     */
    function setTheme(newTheme) {
        applyTheme(newTheme);

        // Persist to server — fire and forget (no page reload)
        if (user.value) {
            router.patch(
                route('app.preferences.theme'),
                { theme: newTheme },
                { preserveState: true, preserveScroll: true }
            );
        }
    }

    /**
     * Set locale and apply it to the <html> element.
     * Triggers RTL direction and Cairo font for Arabic.
     */
    function setLocale(newLocale) {
        applyLocale(newLocale);

        if (user.value) {
            router.patch(
                route('app.preferences.locale'),
                { locale: newLocale },
                {
                    preserveState: true,
                    preserveScroll: true,
                }
            );
        }
    }

    /**
     * For the onboarding theme picker — sets theme locally only,
     * before the user has an account. Saved to server on registration.
     */
    function setThemeLocal(newTheme) {
        applyTheme(newTheme);
    }

    function setLocaleLocal(newLocale) {
        applyLocale(newLocale);
    }

    /**
     * Clear store on logout.
     */
    function clear() {
        user.value = null;
        localStorage.removeItem('ip_theme');
        localStorage.removeItem('ip_locale');
    }

    // ── Private helpers ────────────────────────────────────────────

    /**
     * Apply theme to DOM and persist to localStorage immediately.
     * localStorage write is synchronous — no race condition possible.
     */
    function applyTheme(newTheme) {
        theme.value = newTheme;
        localStorage.setItem('ip_theme', newTheme);
        document.documentElement.setAttribute('data-theme', newTheme);
    }

    /**
     * Apply locale to DOM and persist to localStorage immediately.
     */
    function applyLocale(newLocale) {
        locale.value = newLocale;
        localStorage.setItem('ip_locale', newLocale);
        document.documentElement.setAttribute('lang', newLocale);
        document.documentElement.setAttribute('dir', newLocale === 'ar' ? 'rtl' : 'ltr');
    }

    // ── Return ─────────────────────────────────────────────────────
    return {
        // State
        user,
        theme,
        locale,

        // Computed
        isSuperAdmin,
        isCompanyUser,
        isRtl,
        isDark,
        userAvatar,

        // Actions
        init,
        setTheme,
        setLocale,
        setThemeLocal,
        setLocaleLocal,
        clear,
    };
});