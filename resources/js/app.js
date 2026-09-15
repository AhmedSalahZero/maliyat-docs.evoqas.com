import '../css/app.css';
import '@tabler/icons-webfont/dist/tabler-icons.min.css';
import './bootstrap';

import { createApp, h, Fragment } from 'vue';
import PwaInstallBanner from '@/Components/PwaInstallBanner.vue';
import { createPinia } from 'pinia';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { createI18n } from 'vue-i18n';
import { initPwaInstallListener, registerServiceWorker, setPwaLoginContext } from '@/Composables/usePwaInstall';
import { markPwaColdStart, routePwaEntry } from '@/Composables/usePwaEntry';
import { forceScrollToTop, shouldResetScroll } from '@/Composables/useScrollToTop';
import { syncCsrfMetaFromPage } from '@/Utils/csrf';

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Main JS Entry Point
//
//  Boots the Vue application with:
//    - Inertia.js  → connects Vue to Laravel
//    - Pinia       → global state management
//    - ZiggyVue    → Laravel named routes in Vue
//    - vue-i18n    → English / Arabic language switching
//    - PWA prompt  → captures install event for the install banner
// ══════════════════════════════════════════════════════════════════

const appName = import.meta.env.VITE_APP_NAME || 'Maliyat Docs';

let lastVisitPreserveScroll = false;

function syncPwaAuthContext(page) {
    setPwaLoginContext(page?.props?.auth?.user?.login_count ?? 0);
}

function resetMobileScrollAfterVisit(visit) {
    if (!shouldResetScroll(visit)) return;

    forceScrollToTop();
}

router.on('before', (event) => {
    const visit = event.detail.visit;

    lastVisitPreserveScroll = visit.preserveScroll === true;

    if (!lastVisitPreserveScroll) {
        visit.preserveScroll = false;
    }
});

// ── Apply theme and locale before Vue boots ────────────────────
const savedTheme  = localStorage.getItem('ip_theme')  ?? 'navy';
const savedLocale = localStorage.getItem('ip_locale') ?? 'en';
document.documentElement.setAttribute('data-theme', savedTheme);
document.documentElement.setAttribute('lang', savedLocale);
document.documentElement.setAttribute('dir', savedLocale === 'ar' ? 'rtl' : 'ltr');

// ── PWA — service worker + install prompt (before Vue mounts) ───
registerServiceWorker();

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    window.__maliyat_pwa_prompt = e;
    window.dispatchEvent(new CustomEvent('maliyat-pwa-installable'));
});

markPwaColdStart();
initPwaInstallListener();

// ── i18n Setup ─────────────────────────────────────────────────
const i18n = createI18n({
    legacy: false,
    locale: savedLocale,
    fallbackLocale: 'en',
    messages: {},
});

// ── Inertia App ────────────────────────────────────────────────
createInertiaApp({
    title: (title) => `${title} — ${appName}`,

    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),

    setup({ el, App, props, plugin }) {
        const pinia = createPinia();

        const vueApp = createApp({
            render: () => h(Fragment, null, [
                h(App, props),
                h(PwaInstallBanner),
            ]),
        })
            .use(plugin)
            .use(ZiggyVue)
            .use(pinia)
            .use(i18n)
            .mount(el);

        syncPwaAuthContext(props.initialPage);
        syncCsrfMetaFromPage(props.initialPage);
        routePwaEntry(props.initialPage);

        document.addEventListener('inertia:finish', (event) => {
            const page = event.detail?.page;
            const visit = event.detail?.visit;

            syncCsrfMetaFromPage(page);
            syncPwaAuthContext(page);
            resetMobileScrollAfterVisit(visit);
        });

        document.addEventListener('inertia:success', () => {
            if (!lastVisitPreserveScroll) {
                resetMobileScrollAfterVisit({ preserveScroll: false });
            }
        });

        return vueApp;
    },

    progress: {
        color: '#1D9E75',
    },
});
