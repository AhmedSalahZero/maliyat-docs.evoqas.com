<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — AppLayout.vue
//  Location: resources/js/Layouts/AppLayout.vue
//
//  Shell for every company-side page (company_admin / employee),
//  i.e. everything under the app.* route group.
//
//  Mobile (<768px): sticky header + page content + fixed bottom
//  nav, 4 stops in normal flex order — Home, New Record (FAB),
//  Reports, Settings. "Menu" opens from the header avatar.
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
//  "Menu" (Team, Profile, theme/language, logout) opens from the
//  header avatar on BOTH breakpoints — same as before.
//
//  "Settings" (Customers, Vendors, Items & categories, Opening
//  balances) is its own sheet now, SettingsSheet.vue, with its own
//  entry point per breakpoint: a step-nav tab right after Payment
//  ("Receive / Pay") on desktop, and a fourth bottom-nav button on
//  mobile. All entries (Ledger) isn't part of either sheet — it
//  already has a Reports tab/tile everywhere.
//
//  "New record" (the FAB sheet) only exists on mobile — on desktop
//  the six actions are already sitting in the tab strip, so there's
//  nothing for a launcher sheet to add.
// ══════════════════════════════════════════════════════════════════

import { ref, computed, onMounted, watch } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import { useAuthStore } from '@/stores/useAuthStore';
import { useAppTranslations } from '@/composables/useAppTranslations';
import AppIcon from '@/Components/App/AppIcon.vue';
import QuickRecordSheet from '@/Components/App/QuickRecordSheet.vue';
import MenuSheet from '@/Components/App/MenuSheet.vue';
import SettingsSheet from '@/Components/App/SettingsSheet.vue';
import { QUICK_RECORD_ACTIONS } from '@/constants/quickRecordActions';
import { REPORTS } from '@/constants/reports';
import { useBusinessType } from '@/composables/useBusinessType';

const authStore = useAuthStore();
const page      = usePage();
const { t }     = useAppTranslations();
const { visibleFor } = useBusinessType();

const visibleQuickActions = computed(() => visibleFor(QUICK_RECORD_ACTIONS));
const visibleReports      = computed(() => visibleFor(REPORTS));

const auth = computed(() => page.props.auth);

// Language toggle — see the button in the header. Mirrors
// MenuSheet's own toggle rather than replacing it, so both entry
// points go through the same store action.
const locale = computed(() => authStore.locale);

function toggleLocale() {
    authStore.setLocale(locale.value === 'en' ? 'ar' : 'en');
}

// Theme toggle — lives directly in the header (not just buried in the
// Menu sheet) for the same reason language does: it's something
// people reach for constantly, especially at night, and shouldn't
// take two taps to get to.
const isDark = computed(() => authStore.isDark);

function toggleTheme() {
    authStore.setTheme(isDark.value ? 'light' : 'dark');
}

// Free-trial countdown. Both values come from the shared auth prop
// (HandleInertiaRequests::resolveCompany), so the banner reflects the
// server's view on every page load rather than a cached client copy.
const trialExpiring = computed(() => auth.value?.user?.company?.trial_expiring === true);
const trialDaysLeft = computed(() => auth.value?.user?.company?.trial_days_left ?? 0);

// Renewal is arranged with a person — there is no billing page to
// send anyone to. Telling a customer their access ends in three days
// without telling them who to talk to is a dead end, so the banner
// carries the contact. Either channel may be unset (see
// config/subscription.php); the banner shows whichever exists.
const support = computed(() => page.props.support ?? {});

const renewMailto = computed(() => {
    if (!support.value.email) return null;

    const company = auth.value?.user?.company?.name ?? '';

    return `mailto:${support.value.email}`
        + `?subject=${encodeURIComponent(t('trial_renew_subject', { company }))}`;
});

const renewWhatsApp = computed(() => {
    if (!support.value.phone) return null;

    // wa.me wants digits only — a number stored as "+20 123 456 7890"
    // would otherwise produce a link that silently does nothing.
    const digits = String(support.value.phone).replace(/\D/g, '');
    if (!digits) return null;

    const company = auth.value?.user?.company?.name ?? '';

    return `https://wa.me/${digits}?text=${encodeURIComponent(t('trial_renew_subject', { company }))}`;
});

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
const settingsOpen    = ref(false);

// Drives the active state on both the desktop "Settings" tab and the
// mobile "Settings" bottom-nav button — true while on any of the
// four pages SettingsSheet links to, even after the sheet itself has
// been closed (mirrors how the other tabs/buttons stay highlighted).
const isSettingsRoute = computed(() => route().current('app.customers.*')
    || route().current('app.vendors.*')
    || route().current('app.items.*')
    || route().current('app.opening-balance.*'));

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
                    <img
                        :src="isDark ? '/images/logo-icon-dark.png' : '/images/logo-icon-light.png'"
                        :alt="t('app_name')"
                        class="app-header__logo"
                    />
                    <div class="app-header__name-line">
                        <span class="app-header__title">{{ t('app_name') }}</span>
                        <span v-if="companyName" class="app-header__company">{{ companyName }}</span>
                    </div>
                </Link>

                <div class="app-header__actions">
                    <!-- Language lives here, not buried in the menu
                         sheet: switching language is something people
                         do on their first visit and then rarely
                         again, but they must be able to find it
                         instantly the first time. -->
                    <button
                        type="button"
                        class="lang-btn"
                        :aria-label="locale === 'en' ? 'التبديل إلى العربية' : 'Switch to English'"
                        :title="locale === 'en' ? 'العربية' : 'English'"
                        @click="toggleLocale"
                    >
                        {{ locale === 'en' ? 'ع' : 'EN' }}
                    </button>

                    <!-- Theme toggle sits right beside language for the
                         same reason — a preference people reach for
                         often and expect to find in one tap, not two. -->
                    <button
                        type="button"
                        class="theme-toggle"
                        :aria-label="isDark ? (locale === 'ar' ? 'الوضع الفاتح' : 'Switch to light theme') : (locale === 'ar' ? 'الوضع الداكن' : 'Switch to dark theme')"
                        :title="isDark ? (locale === 'ar' ? 'الوضع الفاتح' : 'Light theme') : (locale === 'ar' ? 'الوضع الداكن' : 'Dark theme')"
                        @click="toggleTheme"
                    >
                        <AppIcon :name="isDark ? 'sun' : 'moon'" />
                    </button>

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
                        v-for="action in visibleQuickActions"
                        :key="action.key"
                        :href="route(action.route)"
                        class="step"
                        :class="{ active: route().current(action.route) }"
                    >
                        <span class="step-dot"><AppIcon :name="action.icon" /></span>
                        <span class="step-label">{{ t(action.tabKey) }}</span>
                    </Link>

                    <!-- Settings — opens SettingsSheet rather than navigating
                         directly, since it fans out to four destinations
                         (Customers / Vendors / Items & categories / Opening
                         balances) instead of one. Sits right after Payment
                         ("Receive / Pay"), as requested. -->
                    <button type="button" class="step" :class="{ active: isSettingsRoute }" @click="settingsOpen = true">
                        <span class="step-dot"><AppIcon name="gear" /></span>
                        <span class="step-label">{{ t('tab_settings') }}</span>
                    </button>
                </nav>

                <nav class="step-nav">
                    <Link
                        v-for="report in visibleReports"
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
             TRIAL COUNTDOWN — only inside the warning window, and
             only while there is still time to act. Once the trial
             has actually lapsed the user can't reach this layout at
             all (EnsureMember sends them back to login), so there is
             no "expired" state to render here.
        ═══════════════════════════════════════════════════════════ -->
        <div v-if="trialExpiring" class="trial-banner" role="status">
            <span class="trial-banner__icon">⏳</span>
            <span class="trial-banner__text">
                {{ trialDaysLeft === 0
                    ? t('trial_ends_today')
                    : t('trial_ends_in', { days: trialDaysLeft }) }}
                <strong class="trial-banner__cta">{{ t('trial_renew_contact') }}</strong>
            </span>

            <span v-if="renewWhatsApp || renewMailto" class="trial-banner__actions">
                <a v-if="renewWhatsApp" :href="renewWhatsApp" target="_blank" rel="noopener" class="trial-banner__btn">
                    {{ t('trial_renew_whatsapp') }}
                </a>
                <a v-if="renewMailto" :href="renewMailto" class="trial-banner__btn trial-banner__btn--ghost">
                    {{ t('trial_renew_email') }}
                </a>
            </span>
        </div>

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

            <!-- Back in the normal flex flow — see app.css for why
                 it's no longer absolutely centered (that caused the
                 overlap with Reports). -->
            <button type="button" class="bottom-nav__item bottom-nav__item--fab" @click="quickRecordOpen = true">
                <span class="fab-circle"><AppIcon name="plus" /></span>
                <span>{{ t('nav_new_record') }}</span>
            </button>

            <Link :href="route('app.reports.index')" class="bottom-nav__item" :class="{ active: route().current('app.reports.*') }">
                <AppIcon name="pl" />
                <span>{{ t('nav_reports') }}</span>
            </Link>

            <!-- Opens SettingsSheet (Customers / Vendors / Items &
                 categories / Opening balances) rather than navigating
                 directly — same reasoning as the desktop tab above. -->
            <button type="button" class="bottom-nav__item" :class="{ active: isSettingsRoute }" @click="settingsOpen = true">
                <AppIcon name="gear" />
                <span>{{ t('nav_settings') }}</span>
            </button>
        </nav>

        <!-- ══════════════════════════════════════════════════════
             SHEETS — reachable from anywhere in the app
        ═══════════════════════════════════════════════════════════ -->
        <QuickRecordSheet v-model:open="quickRecordOpen" />
        <MenuSheet v-model:open="menuOpen" />
        <SettingsSheet v-model:open="settingsOpen" />

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