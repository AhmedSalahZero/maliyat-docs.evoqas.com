<script setup>
// ══════════════════════════════════════════════════════════════════
//  InPractice — GuestLayout.vue
//  Location: resources/js/Layouts/GuestLayout.vue
//
//  Minimal shell for public pages (landing, about, error pages).
//  No auth required. No nav bar. Just brand header + slot + footer.
//  Always defaults to Navy theme for the public face of InPractice.
// ══════════════════════════════════════════════════════════════════

// 1. Imports
import { onMounted } from 'vue';
import { Link }      from '@inertiajs/vue3';

// 2. Props
defineProps({
    title: {
        type: String,
        default: '',
    },
});

// 3. Lifecycle
onMounted(() => {
    // Public pages always show navy — authoritative, trustworthy
    document.documentElement.setAttribute('data-theme', 'navy');
    document.documentElement.setAttribute('lang', 'en');
    document.documentElement.setAttribute('dir', 'ltr');
});
</script>

<template>
    <div class="ip-guest-shell" data-theme="navy">

        <!-- ═══════════════════════════════════════════════════════
             HEADER
        ════════════════════════════════════════════════════════════ -->
        <header class="ip-guest-header">
            <div class="ip-guest-header__inner">
                <Link :href="route('home')" class="ip-guest-brand">
                    In<span>Practice</span>
                </Link>

                <nav class="ip-guest-nav">
                    <Link :href="route('login')"    class="ip-guest-nav__link">Sign In</Link>
                    <Link :href="route('register')" class="ip-btn ip-btn--primary ip-btn--sm ip-btn--pill">
                        Join Free
                    </Link>
                </nav>
            </div>
        </header>

        <!-- ═══════════════════════════════════════════════════════
             PAGE CONTENT
        ════════════════════════════════════════════════════════════ -->
        <main class="ip-guest-main">
            <slot />
        </main>

        <!-- ═══════════════════════════════════════════════════════
             FOOTER
        ════════════════════════════════════════════════════════════ -->
        <footer class="ip-guest-footer">
            <div class="ip-guest-footer__inner">
                <span class="ip-guest-footer__brand">InPractice</span>
                <span class="ip-guest-footer__tagline">Learn. Connect. Earn.</span>
                <span class="ip-guest-footer__copy">© {{ new Date().getFullYear() }}</span>
            </div>
        </footer>

    </div>
</template>

<style scoped>
/* ── Shell ───────────────────────────────────────────────────── */
.ip-guest-shell {
    min-height: 100vh;
    background: var(--color-bg);
    display: flex;
    flex-direction: column;
    transition: var(--transition-theme);
}

/* ── Header ──────────────────────────────────────────────────── */
.ip-guest-header {
    background: var(--color-header-bg);
    border-bottom: 1px solid var(--color-header-border);
    box-shadow: var(--shadow-header);
    padding: 0 var(--space-5);
    height: 60px;
    position: sticky;
    top: 0;
    z-index: var(--z-dropdown);
}

.ip-guest-header__inner {
    max-width: 1024px;
    margin: 0 auto;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.ip-guest-brand {
    font-size: var(--text-lg);
    font-weight: var(--fw-bold);
    color: var(--color-header-text);
    text-decoration: none;
    letter-spacing: -0.01em;
}

.ip-guest-brand span {
    color: var(--color-primary);
}

/* ── Guest nav ───────────────────────────────────────────────── */
.ip-guest-nav {
    display: flex;
    align-items: center;
    gap: var(--space-4);
}

.ip-guest-nav__link {
    font-size: var(--text-sm);
    font-weight: var(--fw-medium);
    color: rgba(255,255,255,0.70);
    text-decoration: none;
    transition: color var(--transition-fast);
}

.ip-guest-nav__link:hover {
    color: #FFFFFF;
    opacity: 1;
}

/* ── Main ────────────────────────────────────────────────────── */
.ip-guest-main {
    flex: 1;
}

/* ── Footer ──────────────────────────────────────────────────── */
.ip-guest-footer {
    background: var(--color-header-bg);
    border-top: 1px solid var(--color-header-border);
    padding: var(--space-5);
}

.ip-guest-footer__inner {
    max-width: 1024px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    gap: var(--space-4);
    flex-wrap: wrap;
}

.ip-guest-footer__brand {
    font-size: var(--text-sm);
    font-weight: var(--fw-bold);
    color: var(--color-header-text);
}

.ip-guest-footer__tagline {
    font-size: var(--text-sm);
    color: var(--color-primary);
    font-weight: var(--fw-medium);
}

.ip-guest-footer__copy {
    font-size: var(--text-xs);
    color: rgba(255,255,255,0.40);
    margin-left: auto;
}
</style>
