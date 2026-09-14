/**
 * CSRF for fetch() and other non-Inertia requests.
 * Uses Laravel's XSRF-TOKEN cookie (X-XSRF-TOKEN header) — always current.
 * Meta tag is plain text and is not updated on Inertia visits; use syncCsrfMeta() after navigation.
 */
function readXsrfCookie() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta?.content ?? '';
}

export function syncCsrfMeta(token) {
    if (!token) {
        return;
    }

    const meta = document.head?.querySelector('meta[name="csrf-token"]');
    if (meta) {
        meta.content = token;
    }
}

export function syncCsrfMetaFromPage(page) {
    syncCsrfMeta(page?.props?.csrf_token);
}

export function csrfJsonHeaders(extra = {}) {
    const headers = {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
        ...extra,
    };

    const xsrf = readXsrfCookie();
    if (xsrf) {
        headers['X-XSRF-TOKEN'] = xsrf;
    } else if (csrfToken()) {
        headers['X-CSRF-TOKEN'] = csrfToken();
    }

    return headers;
}
