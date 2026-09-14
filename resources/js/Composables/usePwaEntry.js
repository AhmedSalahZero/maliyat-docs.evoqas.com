import { router } from '@inertiajs/vue3';

const PWA_OPEN_COOKIE = 'pwa_open=1; path=/; max-age=120; SameSite=Lax';

export function isPwaStandalone() {
    if (typeof window === 'undefined') return false;
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        window.matchMedia('(display-mode: fullscreen)').matches ||
        window.navigator.standalone === true
    );
}

/** Mark next full page request as a PWA cold start (optional server middleware). */
export function markPwaColdStart() {
    if (!isPwaStandalone()) return;

    const nav = performance.getEntriesByType('navigation')[0];
    if (nav && !['navigate', 'reload'].includes(nav.type)) return;

    document.cookie = PWA_OPEN_COOKIE;
}

export function resolvePwaEntryUrl(pageProps) {
    const user = pageProps?.auth?.user;

    if (!user) {
        return typeof route === 'function' ? route('login') : '/login';
    }

    // Must match App\Enums\UserRole::SuperAdmin's value — the old
    // 'admin' string never matched, so super admins were sent to the
    // member dashboard and bounced back by EnsureMember.
    if (user.role === 'super_admin') {
        return typeof route === 'function' ? route('admin.dashboard') : '/admin/dashboard';
    }

    return typeof route === 'function' ? route('app.dashboard') : '/app/dashboard';
}

const ENTRY_PATHS = ['/', '/app', '/login', '/register'];

/** Client routing when the PWA opens on a generic entry URL. */
export function routePwaEntry(initialPage) {
    if (!isPwaStandalone()) return;

    markPwaColdStart();

    const path = window.location.pathname;
    const user = initialPage?.props?.auth?.user;

    if (!ENTRY_PATHS.includes(path)) return;

    if (!user) {
        if (path === '/' || path === '/app') {
            router.visit(resolvePwaEntryUrl(initialPage?.props), { replace: true });
        }
        return;
    }

    if (path === '/' || path === '/app') {
        router.visit(resolvePwaEntryUrl(initialPage?.props), { replace: true });
    }
}
