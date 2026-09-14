<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — AppLayout.vue
//  Location: resources/js/Layouts/AppLayout.vue
//
//  Shell for every company-side page (company_admin / employee),
//  i.e. everything under the app.* route group.
//
//  Mobile (<768px): sticky header + page content + fixed bottom
//  nav, 3 stops — Home, New Record (center FAB), Reports. "Menu"
//  opens from the header avatar.
//
//  Desktop (≥768px, "web view"): bottom nav hidden, replaced by a
//  two-row step-nav tab strip right under the header — Home + the
//  six quick-record actions on row one, the six reports on row
//  two. This mirrors ledger-prototype-v8.html's #entryNav/#reportNav
//  exactly (same .step-nav/.step/.step-dot/.step-label markup and
//  CSS, ported verbatim into app.css) — that file is the source of
//  truth for the web view's shape, and it has no sidebar and no
//  card-hub Home; it's a tab strip. The container also goes full
//  width at this breakpoint (app.css overrides --app-max-width).
//
//  "Menu" (Customers, Vendors, Items & categories, Team, Profile,
//  theme/language, logout) opens from the header avatar on BOTH
//  breakpoints — those aren't tabs in the prototype either (they're
//  dropdowns inside the forms), so they don't belong in the step-nav.
//
//  "New record" (the FAB sheet) only exists on mobile — on desktop
//  the six actions are already sitting in the tab strip, so there's
//  nothing for a launcher sheet to add.
// ══════════════════════════════════════════════════════════════════

import { ref, computed, onMounted, watch } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import { useAuthStore } from '@/Stores/useAuthStore';
import { useAppTranslations } from '@/Composables/useAppTranslations';
import AppIcon from '@/Components/App/AppIcon.vue';
import QuickRecordSheet from '@/Components/App/QuickRecordSheet.vue';
import MenuSheet from '@/Components/App/MenuSheet.vue';
import { QUICK_RECORD_ACTIONS } from '@/constants/quickRecordActions';
import { REPORTS } from '@/constants/reports';

const authStore = useAuthStore();
const page      = usePage();
const { t }     = useAppTranslations();

const auth = computed(() => page.props.auth);

const avatarInitials = computed(() => {
    const name  = auth.value?.user?.name ?? '';
    const parts = name.trim().split(' ').filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return 'MD';
});

const companyName = computed(() => {
    const company = auth.value?.user?.company;
    if (!company) return '';
    return authStore.locale === 'ar' && company.name_ar ? company.name_ar : company.name;
});

// ── Sheets ────────────────────────────────────────────────────────
const quickRecordOpen = ref(false);
const menuOpen        = ref(false);

// ── Flash toast ───────────────────────────────────────────────────
const showFlash    = ref(false);
const flashMessage = ref('');
const flashType    = ref('success');

function showFlashToast(message, type = 'success') {
    flashMessage.value = message;
    flashType.value    = type;
    showFlash.value    = true;
    setTimeout(() => { showFlash.value = false; }, 3500);
}

onMounted(() => {
    if (auth.value?.user) authStore.init(auth.value.user);

    const flash = page.props.flash ?? {};
    if (flash.success) showFlashToast(flash.success, 'success');
    if (flash.error)   showFlashToast(flash.error, 'danger');
});

watch(() => page.props.flash, (flash) => {
    if (!flash) return;
    if (flash.success) showFlashToast(flash.success, 'success');
    if (flash.error)   showFlashToast(flash.error, 'danger');
});
</script>

<template>
    <div class="app-shell">

        <!-- ══════════════════════════════════════════════════════
             HEADER
        ═══════════════════════════════════════════════════════════ -->
        <header class="app-header">
            <div class="app-header__inner">
                <Link :href="route('app.dashboard')" class="app-header__brand">
                    <div class="app-header__logo">MD</div>
                    <div>
                        <div class="app-header__title">{{ t('app_name') }}</div>
                        <div v-if="companyName" class="app-header__company">{{ companyName }}</div>
                    </div>
                </Link>

                <div class="app-header__actions">
                    <button type="button" class="avatar" @click="menuOpen = true" :aria-label="t('nav_menu')">
                        {{ avatarInitials }}
                    </button>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════
                 DESKTOP STEP-NAV — mirrors ledger-prototype-v8.html's
                 #entryNav / #reportNav. Hidden on mobile (app.css).
            ═══════════════════════════════════════════════════════ -->
            <div class="app-header__stepnav">
                <nav class="step-nav">
                    <Link :href="route('app.dashboard')" class="step" :class="{ active: route().current('app.dashboard') }">
                        <span class="step-dot"><AppIcon name="home" /></span>
                        <span class="step-label">{{ t('nav_home') }}</span>
                    </Link>
                    <Link
                        v-for="action in QUICK_RECORD_ACTIONS"
                        :key="action.key"
                        :href="route(action.route)"
                        class="step"
                        :class="{ active: route().current(action.route) }"
                    >
                        <span class="step-dot"><AppIcon :name="action.icon" /></span>
                        <span class="step-label">{{ t(action.tabKey) }}</span>
                    </Link>
                </nav>

                <nav class="step-nav">
                    <Link
                        v-for="report in REPORTS"
                        :key="report.key"
                        :href="route(report.route)"
                        class="step"
                        :class="{ active: route().current(report.route) }"
                    >
                        <span class="step-dot"><AppIcon :name="report.icon" /></span>
                        <span class="step-label">{{ t(report.tabKey) }}</span>
                    </Link>
                </nav>
            </div>
        </header>

        <!-- ══════════════════════════════════════════════════════
             MAIN — page content (full width on desktop, see app.css)
        ═══════════════════════════════════════════════════════════ -->
        <div class="app-main">
            <main>
                <slot />
            </main>
        </div>

        <!-- ══════════════════════════════════════════════════════
             BOTTOM NAV (mobile only — app.css hides this ≥768px)
        ═══════════════════════════════════════════════════════════ -->
        <nav class="bottom-nav">
            <Link :href="route('app.dashboard')" class="bottom-nav__item" :class="{ active: route().current('app.dashboard') }">
                <AppIcon name="home" />
                <span>{{ t('nav_home') }}</span>
            </Link>

            <button type="button" class="bottom-nav__item bottom-nav__item--fab" @click="quickRecordOpen = true">
                <span class="fab-circle"><AppIcon name="plus" /></span>
                <span>{{ t('nav_new_record') }}</span>
            </button>

            <Link :href="route('app.reports.index')" class="bottom-nav__item" :class="{ active: route().current('app.reports.*') }">
                <AppIcon name="pl" />
                <span>{{ t('nav_reports') }}</span>
            </Link>
        </nav>

        <!-- ══════════════════════════════════════════════════════
             SHEETS — reachable from anywhere in the app
        ═══════════════════════════════════════════════════════════ -->
        <QuickRecordSheet v-model:open="quickRecordOpen" />
        <MenuSheet v-model:open="menuOpen" />

        <!-- ── Flash toast ─────────────────────────────────────── -->
        <div class="toast-stack">
            <transition name="toast">
                <div v-if="showFlash" class="toast" :class="flashType">
                    {{ flashMessage }}
                </div>
            </transition>
        </div>
    </div>
</template>

<style scoped>
/* Header avatar button needs no extra border/background — .avatar
   from app.css already covers the visual, this just makes the
   <button> reset behave like the old <div>. */
.app-header__actions .avatar {
    border: none;
    padding: 0;
    font: inherit;
    appearance: none;
    cursor: pointer;
}

/* The step-nav rows live inside the sticky header on desktop, so
   they scroll away with it — matches the prototype's header+navrow
   being one continuous block, not a separately-scrolling region. */
.app-header__stepnav {
    display: none;
}

@media (min-width: 768px) {
    .app-header__stepnav {
        display: block;
        max-width: none;
        padding: 0 20px 10px;
    }
}
</style>
