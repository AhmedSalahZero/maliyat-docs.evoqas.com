<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Dashboard.vue (Home)
//  Location: resources/js/Pages/App/Dashboard.vue
//
//  Props come from App\Http\Controllers\App\DashboardController:
//  income/expenses/net_this_month, cash_balance (all-time),
//  top_product (this month, nullable), open_invoices_count,
//  open_bills_count.
//
//  Mobile: this is the top of the card-hub — summary first, then
//  the six quick-record action cards below (see .home-actions).
//  Desktop ("web view"): the six actions already live in the
//  step-nav tabs (AppLayout.vue), so .home-actions is hidden here
//  via CSS and Home stands alone as the results-summary dashboard.
// ══════════════════════════════════════════════════════════════════

import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppIcon from '@/Components/App/AppIcon.vue';
import { useAppTranslations } from '@/Composables/useAppTranslations';
import { QUICK_RECORD_ACTIONS } from '@/constants/quickRecordActions';

const props = defineProps({
    income_this_month:   { type: Number, default: 0 },
    expenses_this_month: { type: Number, default: 0 },
    net_this_month:      { type: Number, default: 0 },
    cash_balance:        { type: Number, default: 0 },
    open_invoices_count: { type: Number, default: 0 },
    open_bills_count:    { type: Number, default: 0 },
    top_product:         { type: Object, default: null }, // { name, revenue } | null
});

const page = usePage();
const { t, locale } = useAppTranslations();

// Company's own currency (default EGP — see companies table migration),
// not a hardcoded symbol, since this is meant to serve any micro-company.
const currency = computed(() => page.props.auth?.user?.company?.currency ?? 'EGP');

function money(value) {
    const formatted = new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        maximumFractionDigits: 0,
    }).format(value ?? 0);
    return `${currency.value} ${formatted}`;
}
</script>

<template>
    <Head :title="t('nav_home')" />

    <AppLayout>
        <div class="page-header">
            <h1>{{ t('nav_home') }}</h1>
        </div>

        <div class="home-stats">
            <div class="home-stat home-stat--in">
                <div class="home-stat__label">{{ t('home_income_month') }}</div>
                <div class="home-stat__value">{{ money(props.income_this_month) }}</div>
            </div>
            <div class="home-stat home-stat--out">
                <div class="home-stat__label">{{ t('home_expenses_month') }}</div>
                <div class="home-stat__value">{{ money(props.expenses_this_month) }}</div>
            </div>
        </div>

        <!-- Results summary — net, cash balance, top product, what needs attention -->
        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-box__label">{{ t('home_net_month') }}</div>
                <div class="stat-box__value" :class="props.net_this_month >= 0 ? 'text-success' : 'text-danger'">
                    {{ money(props.net_this_month) }}
                </div>
            </div>
            <div class="stat-box">
                <div class="stat-box__label">{{ t('home_cash_balance') }}</div>
                <div class="stat-box__value">{{ money(props.cash_balance) }}</div>
            </div>
            <div class="stat-box">
                <div class="stat-box__label">{{ t('home_top_product') }}</div>
                <div v-if="props.top_product" class="stat-box__value stat-box__value--text">
                    {{ props.top_product.name }}
                </div>
                <div v-else class="stat-box__value stat-box__value--text muted-inline">
                    {{ t('home_top_product_none') }}
                </div>
            </div>
            <Link :href="route('app.payments.index')" class="stat-box stat-box--link">
                <div class="stat-box__label">{{ t('home_open_invoices') }} / {{ t('home_open_bills') }}</div>
                <div class="stat-box__value">{{ props.open_invoices_count }} / {{ props.open_bills_count }}</div>
            </Link>
        </div>

        <!-- Quick-record cards — mobile only; desktop uses the step-nav tabs instead -->
        <div class="home-actions">
            <Link
                v-for="action in QUICK_RECORD_ACTIONS"
                :key="action.key"
                :href="route(action.route)"
                class="card card--interactive home-action"
            >
                <span class="home-action__icon">
                    <AppIcon :name="action.icon" />
                </span>
                <span class="home-action__text">
                    <span class="home-action__title">{{ t(action.titleKey) }}</span>
                    <span class="home-action__sub">{{ t(action.subKey) }}</span>
                </span>
                <AppIcon name="chevron" class="home-action__chevron" />
            </Link>
        </div>
    </AppLayout>
</template>

<style scoped>
.home-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 16px;
}

.home-stat {
    border-radius: var(--radius-lg);
    padding: 16px 18px;
}

.home-stat--in  { background: var(--color-success-soft); }
.home-stat--out { background: var(--color-danger-soft); }

.home-stat__label { font-size: 12px; font-weight: 500; margin-bottom: 6px; }
.home-stat--in  .home-stat__label { color: var(--color-success-dark); }
.home-stat--out .home-stat__label { color: var(--color-danger-dark); }

.home-stat__value { font-family: var(--font-mono); font-size: 22px; font-weight: 700; }
.home-stat--in  .home-stat__value { color: var(--color-success-dark); }
.home-stat--out .home-stat__value { color: var(--color-danger-dark); }

.stat-box--link { text-decoration: none; cursor: pointer; }
.stat-box--link:hover { border-color: var(--color-primary); }
.stat-box__value--text { font-family: var(--font-body); font-size: 15px; }

.home-actions {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 20px;
}

/* Desktop already has these six as step-nav tabs — no need to repeat them here */
@media (min-width: 768px) {
    .home-actions { display: none; }
}

.home-action {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    text-decoration: none;
}

.home-action__icon {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-md);
    background: var(--color-primary-soft);
    color: var(--color-primary-dark);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.home-action__icon svg { width: 22px; height: 22px; }

.home-action__text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.home-action__title { font-size: 14.5px; font-weight: 600; color: var(--color-text-primary); }
.home-action__sub { font-size: 12.5px; color: var(--color-text-muted); }

.home-action__chevron { width: 17px; height: 17px; color: var(--color-text-muted); flex-shrink: 0; }
[dir="rtl"] .home-action__chevron { transform: scaleX(-1); }
</style>
