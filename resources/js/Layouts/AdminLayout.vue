<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — AdminLayout.vue
//  Location: resources/js/Layouts/AdminLayout.vue
//
//  Shell for the super_admin panel (admin.* routes) — Dashboard and
//  Companies are the only two things a super_admin does here: see
//  platform totals, and onboard/manage companies.
//
//  Always forces Dark theme — admin panel is a separate context
//  from the company-facing app, no need for a theme toggle.
//
//  NOTE: this file used to be InPractice's, with a "View Platform"
//  link to the member-facing dashboard and a sidebar for Cases/
//  Users/Suggestions. None of that exists in MaliyatDocs — the
//  member.* routes were removed entirely and super_admin only
//  manages companies (see routes/web.php's admin.* group), so the
//  nav and that link have been replaced rather than edited in place.
// ══════════════════════════════════════════════════════════════════

// 1. Imports
import { onMounted, computed, ref, watch } from 'vue';
import { usePage, Link, router }           from '@inertiajs/vue3';
import { useAuthStore }                    from '@/Stores/useAuthStore';

// 2. Props
defineProps({
    title: {
        type: String,
        default: 'Admin',
    },
});

// 3. Stores
const authStore = useAuthStore();
const page      = usePage();

// 4. Reactive state
const showFlash    = ref(false);
const flashMessage = ref('');
const flashType    = ref('success');
const sidebarOpen  = ref(false);

// 5. Computed
const auth = computed(() => page.props.auth);

const navItems = [
    { label: 'Dashboard', route: 'admin.dashboard',        icon: 'grid' },
    { label: 'Companies', route: 'admin.companies.index',  icon: 'building' },
];

// 6. Methods
function showFlashToast(message, type = 'success') {
    flashMessage.value = message;
    flashType.value    = type;
    showFlash.value    = true;
    setTimeout(() => { showFlash.value = false; }, 3500);
}

function logout() {
    router.post(route('logout'));
}

// 7. Lifecycle
onMounted(() => {
    // Admin panel always forces Dark theme (the old 'navy' theme this
    // used to force no longer exists — MaliyatDocs only ships light/dark)
    document.documentElement.setAttribute('data-theme', 'dark');
    document.documentElement.setAttribute('lang', 'en');
    document.documentElement.setAttribute('dir', 'ltr');

    const flash = page.props.flash ?? {};
    if (flash.success) showFlashToast(flash.success, 'success');
    if (flash.error)   showFlashToast(flash.error,   'error');
});

watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) return;
        if (flash.success) showFlashToast(flash.success, 'success');
        if (flash.error)   showFlashToast(flash.error,   'error');
    }
);
</script>

<template>
    <div class="ip-admin-shell" data-theme="dark">

        <!-- ═══════════════════════════════════════════════════════
             ADMIN HEADER
        ════════════════════════════════════════════════════════════ -->
        <header class="ip-admin-header">
            <div class="ip-admin-header__left">
                <!-- Mobile sidebar toggle -->
                <button
                    class="ip-admin-menu-btn"
                    @click="sidebarOpen = !sidebarOpen"
                    aria-label="Toggle sidebar"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>
                    </svg>
                </button>

                <span class="ip-admin-header__brand">
                    Maliyat<span>Docs</span>
                    <span class="ip-admin-badge">Admin</span>
                </span>
            </div>

            <div class="ip-admin-header__right">
                <!-- Logout -->
                <button class="ip-admin-header__link ip-admin-header__link--danger" @click="logout">
                    Logout
                </button>
            </div>
        </header>

        <div class="ip-admin-body">

            <!-- ═══════════════════════════════════════════════
                 SIDEBAR
            ════════════════════════════════════════════════════ -->
            <aside
                class="ip-admin-sidebar"
                :class="{ 'ip-admin-sidebar--open': sidebarOpen }"
            >
                <nav class="ip-admin-nav">
                    <Link
                        v-for="item in navItems"
                        :key="item.route"
                        :href="route(item.route)"
                        class="ip-admin-nav__item"
                        :class="{ 'ip-admin-nav__item--active': route().current(item.route) }"
                        @click="sidebarOpen = false"
                    >
                        <!-- Grid icon -->
                        <svg v-if="item.icon === 'grid'" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
                        </svg>
                        <!-- Book icon -->
                        <svg v-if="item.icon === 'book-open'" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
                        </svg>
                        <!-- Building icon (Companies) -->
                        <svg v-if="item.icon === 'building'" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="4" y="2" width="16" height="20" rx="1"/><line x1="9" y1="7" x2="9" y2="7.01"/><line x1="15" y1="7" x2="15" y2="7.01"/><line x1="9" y1="12" x2="9" y2="12.01"/><line x1="15" y1="12" x2="15" y2="12.01"/><path d="M9 22v-4h6v4"/>
                        </svg>

                        <span>{{ item.label }}</span>
                    </Link>
                </nav>
            </aside>

            <!-- Sidebar overlay (mobile) -->
            <div
                v-if="sidebarOpen"
                class="ip-admin-overlay"
                @click="sidebarOpen = false"
            />

            <!-- ═══════════════════════════════════════════════
                 MAIN CONTENT
            ════════════════════════════════════════════════════ -->
            <main class="ip-admin-main">
                <slot />
            </main>

        </div>

        <!-- ═══════════════════════════════════════════════════════
             FLASH TOAST
        ════════════════════════════════════════════════════════════ -->
        <Transition name="ip-toast">
            <div
                v-if="showFlash"
                class="ip-toast"
                :class="flashType === 'error' ? 'ip-toast--error' : 'ip-toast--success'"
                role="alert"
            >
                <svg v-if="flashType === 'success'" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
                <span>{{ flashMessage }}</span>
            </div>
        </Transition>

    </div>
</template>

<style scoped>
/* ── Admin shell ─────────────────────────────────────────────── */
.ip-admin-shell {
    min-height: 100vh;
    background: var(--color-bg);
    display: flex;
    flex-direction: column;
}

/* ── Admin header ────────────────────────────────────────────── */
.ip-admin-header {
    background: var(--color-header-bg);
    border-bottom: 1px solid var(--color-header-border);
    box-shadow: var(--shadow-header);
    padding: 0 var(--space-5);
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: var(--z-dropdown);
    flex-shrink: 0;
}

.ip-admin-header__left {
    display: flex;
    align-items: center;
    gap: var(--space-3);
}

.ip-admin-header__brand {
    font-size: var(--text-md);
    font-weight: var(--fw-bold);
    color: var(--color-header-text);
    display: flex;
    align-items: center;
    gap: var(--space-2);
}

.ip-admin-header__brand span:first-child {
    color: var(--color-primary);
}

.ip-admin-badge {
    font-size: var(--text-xs);
    font-weight: var(--fw-semibold);
    background: rgba(29, 158, 117, 0.20);
    color: var(--color-primary);
    padding: 2px var(--space-2);
    border-radius: var(--radius-pill);
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.ip-admin-header__right {
    display: flex;
    align-items: center;
    gap: var(--space-4);
}

.ip-admin-header__link {
    font-size: var(--text-sm);
    font-weight: var(--fw-medium);
    color: rgba(255,255,255,0.65);
    background: none;
    border: none;
    cursor: pointer;
    transition: color var(--transition-fast);
    text-decoration: none;
}

.ip-admin-header__link:hover {
    color: #FFFFFF;
}

.ip-admin-header__link--danger:hover {
    color: var(--color-danger);
}

.ip-admin-menu-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    color: var(--color-header-text);
    opacity: 0.70;
    background: none;
    border: none;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: opacity var(--transition-fast);
}

.ip-admin-menu-btn:hover { opacity: 1; }

/* ── Body layout ─────────────────────────────────────────────── */
.ip-admin-body {
    display: flex;
    flex: 1;
    position: relative;
    overflow: hidden;
}

/* ── Sidebar ─────────────────────────────────────────────────── */
.ip-admin-sidebar {
    width: 220px;
    background: var(--color-surface);
    border-right: 1px solid var(--color-border);
    padding: var(--space-4) 0;
    flex-shrink: 0;

    /* Mobile: hidden off-screen */
    position: fixed;
    top: 56px;
    left: 0;
    bottom: 0;
    z-index: var(--z-modal);
    transform: translateX(-100%);
    transition: transform var(--transition-base);
}

.ip-admin-sidebar--open {
    transform: translateX(0);
}

/* Desktop: always visible */
@media (min-width: 768px) {
    .ip-admin-sidebar {
        position: static;
        transform: none;
        top: auto;
        z-index: auto;
    }
}

.ip-admin-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.40);
    z-index: calc(var(--z-modal) - 1);
}

@media (min-width: 768px) {
    .ip-admin-overlay { display: none; }
}

/* ── Sidebar nav ─────────────────────────────────────────────── */
.ip-admin-nav {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 0 var(--space-3);
}

.ip-admin-nav__item {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-3) var(--space-3);
    border-radius: var(--radius-md);
    font-size: var(--text-base);
    font-weight: var(--fw-medium);
    color: var(--color-text-secondary);
    text-decoration: none;
    transition: background var(--transition-fast), color var(--transition-fast);
}

.ip-admin-nav__item:hover {
    background: var(--color-surface-alt);
    color: var(--color-text-primary);
}

.ip-admin-nav__item--active {
    background: var(--color-primary-soft);
    color: var(--color-primary);
    font-weight: var(--fw-semibold);
}

/* ── Main content ────────────────────────────────────────────── */
.ip-admin-main {
    flex: 1;
    padding: var(--space-6);
    overflow-y: auto;
    max-width: 100%;
}

/* ── Toast transition ────────────────────────────────────────── */
.ip-toast-enter-active,
.ip-toast-leave-active {
    transition: all 0.3s ease;
}
.ip-toast-enter-from,
.ip-toast-leave-to {
    opacity: 0;
    transform: translateY(16px) scale(0.95);
}
</style>
