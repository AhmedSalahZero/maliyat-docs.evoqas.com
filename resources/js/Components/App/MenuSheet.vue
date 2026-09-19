<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — MenuSheet.vue
//  Location: resources/js/Components/App/MenuSheet.vue
//
//  The "Menu" sheet — opened by tapping the avatar in the header.
//  Now holds only: Team (company_admin only), Profile & settings,
//  the Theme/Language toggles, and Log out.
//
//  Customers, Vendors, Items & categories and Opening balances used
//  to live here too — they moved to their own SettingsSheet.vue,
//  reachable from a dedicated "Settings" tab (desktop, next to the
//  Payment/"Receive-Pay" tab) or bottom-nav button (mobile). All
//  entries (Ledger) was dropped entirely rather than moved — it
//  already has its own Reports tab/tile everywhere, so a second
//  entry point here was pure duplication.
//
//  Same bottom-sheet pattern as QuickRecordSheet.vue/SettingsSheet.vue
//  (shares its .sheet-item styles from app.css) so all three feel
//  like one consistent interaction language.
// ══════════════════════════════════════════════════════════════════

import { computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import AppIcon from '@/Components/App/AppIcon.vue';
import { useAuthStore } from '@/stores/useAuthStore';
import { useAppTranslations } from '@/composables/useAppTranslations';

const props = defineProps({
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['update:open']);

const page      = usePage();
const authStore = useAuthStore();
const { t, locale } = useAppTranslations();

const user    = computed(() => page.props.auth?.user ?? null);
const isAdmin = computed(() => user.value?.role === 'company_admin');
const isDark  = computed(() => authStore.theme === 'dark');

const links = computed(() => {
    const items = [];

    if (isAdmin.value) {
        items.push({ key: 'team', icon: 'gear', route: 'app.team.index', title: t('menu_team'), sub: t('menu_team_sub') });
    }

    items.push({ key: 'profile', icon: 'box', route: 'app.profile.index', title: t('menu_profile'), sub: t('menu_profile_sub') });

    return items;
});

function close() {
    emit('update:open', false);
}

function toggleTheme() {
    authStore.setTheme(isDark.value ? 'light' : 'dark');
}

function toggleLocale() {
    authStore.setLocale(locale.value === 'en' ? 'ar' : 'en');
}

function logout() {
    close();
    router.post(route('logout'));
}
</script>

<template>
    <transition name="fade-in">
        <div v-if="props.open" class="modal-backdrop" @click.self="close">
            <div class="modal-sheet menu-sheet slide-up">
                <div class="modal-sheet__handle"></div>

                <div class="sheet-header">
                    <div class="menu-sheet__who">
                        <div class="avatar menu-sheet__avatar">
                            {{ (user?.name ?? '').trim().slice(0, 2).toUpperCase() }}
                        </div>
                        <div>
                            <div class="menu-sheet__name">{{ user?.name }}</div>
                            <div class="menu-sheet__company">{{ user?.company?.name }}</div>
                        </div>
                    </div>
                    <button type="button" class="sheet-close-btn" @click="close" :aria-label="t('back_to_home')">
                        <AppIcon name="close" />
                    </button>
                </div>

                <div class="sheet-list menu-sheet__list">
                    <Link
                        v-for="item in links"
                        :key="item.key"
                        :href="route(item.route)"
                        class="sheet-item"
                        @click="close"
                    >
                        <span class="sheet-item__icon"><AppIcon :name="item.icon" /></span>
                        <span class="sheet-item__text">
                            <span class="sheet-item__title">{{ item.title }}</span>
                            <span class="sheet-item__sub">{{ item.sub }}</span>
                        </span>
                        <AppIcon name="chevron" class="sheet-item__chevron" />
                    </Link>
                </div>

                <div class="menu-sheet__prefs">
                    <button type="button" class="menu-sheet__pref-btn" @click="toggleTheme">
                        <AppIcon :name="isDark ? 'sun' : 'moon'" />
                        {{ t('menu_theme') }}: {{ isDark ? t('menu_theme_dark') : t('menu_theme_light') }}
                    </button>
                    <button type="button" class="menu-sheet__pref-btn" @click="toggleLocale">
                        <AppIcon name="globe" />
                        {{ t('menu_language') }}: {{ locale === 'en' ? 'English' : 'العربية' }}
                    </button>
                </div>

                <button type="button" class="btn btn-ghost btn-block menu-sheet__logout" @click="logout">
                    <AppIcon name="logout" />
                    {{ t('menu_logout') }}
                </button>
            </div>
        </div>
    </transition>
</template>

<style scoped>
.menu-sheet { max-height: 86vh; overflow-y: auto; }

.sheet-header { margin-bottom: 8px; }

.menu-sheet__who { display: flex; align-items: center; gap: 12px; min-width: 0; }
.menu-sheet__avatar { width: 44px; height: 44px; font-size: 16px; }
.menu-sheet__name { font-weight: 700; font-size: 14.5px; color: var(--color-text-primary); }
.menu-sheet__company { font-size: 12px; color: var(--color-text-muted); margin-top: 1px; }

.menu-sheet__list { margin-top: 8px; max-height: none; overflow: visible; }

.menu-sheet__prefs {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 14px 0 10px;
    padding-top: 12px;
    border-top: 1px solid var(--color-border);
}

.menu-sheet__pref-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 4px;
    background: none;
    border: none;
    font-size: 13.5px;
    font-weight: 500;
    color: var(--color-text-secondary);
    cursor: pointer;
    text-align: start;
}

.menu-sheet__pref-btn svg { width: 18px; height: 18px; flex-shrink: 0; }

.menu-sheet__logout {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: var(--color-danger);
}

.menu-sheet__logout svg { width: 17px; height: 17px; }
</style>
