<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Dashboard.vue (Home)
//
//  Reads top-to-bottom as three questions:
//    1. Where do I stand?      — revenue / COGS / real net profit,
//       and separately, actual cash movement
//    2. What needs chasing?    — open invoices and bills
//    3. What is actually       — the SKU donut, then who leads on
//       happening in my shop?    each of volume, value and frequency
//
//  Net Profit is the real, accrual figure (Revenue − COGS −
//  Operating Expenses, same as the P&L report) — it used to be cash
//  in minus cash out mislabeled "Net Profit"; that cash figure is
//  still here, honestly relabeled "Net Cash Flow".
//
//  The three "top" panels each show the leader by THREE different
//  measures rather than one ranked list, because they routinely
//  disagree: the customer who spends the most is often not the one
//  who buys most often, and knowing which is which is the point.
//
//  Mobile keeps the quick-record cards below the summary; desktop
//  hides them because the same six actions are already in the
//  step-nav (AppLayout).
// ══════════════════════════════════════════════════════════════════

import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppIcon from '@/Components/App/AppIcon.vue';
import DonutChart3D from '@/Components/App/DonutChart3D.vue';
import { useAppTranslations } from '@/composables/useAppTranslations';
import { QUICK_RECORD_ACTIONS } from '@/constants/quickRecordActions';
import { useBusinessType } from '@/composables/useBusinessType';
import { useMoneyFormat } from '@/composables/useMoneyFormat';

const { visibleFor } = useBusinessType();
const visibleQuickActions = computed(() => visibleFor(QUICK_RECORD_ACTIONS));

const props = defineProps({
    period:              { type: String, default: 'year' },
    period_from:         { type: String, default: '' },
    period_to:           { type: String, default: '' },
    // Row 1 — Profit & Loss. All four come from the same
    // ReportDataService::profitAndLoss() call on the backend, so
    // Sales − COGS − Operating Expense always equals Net Income here,
    // exactly as it does on the P&L report.
    revenue:             { type: Number, default: 0 },
    cost_of_goods_sold:  { type: Number, default: 0 },
    operating_expenses:  { type: Number, default: 0 },
    net_profit:          { type: Number, default: 0 },
    // Row 2 — Cash position. All five come from the payments ledger
    // / open-balance counts — a different, cash-basis source, kept
    // in its own row so it's never mistaken for the P&L row above.
    total_cash_in:       { type: Number, default: 0 },
    total_cash_out:      { type: Number, default: 0 },
    cash_balance:        { type: Number, default: 0 },
    open_invoices_count: { type: Number, default: 0 },
    open_bills_count:    { type: Number, default: 0 },
    sku_sales:           { type: Array, default: () => [] },
    sales_by_channel:    { type: Array, default: () => [] },
    top_customers:       { type: Array, default: () => [] },
    top_suppliers:       { type: Array, default: () => [] },
});

const page = usePage();
const { t, locale } = useAppTranslations();

// Aliased to the local name "money" so every call site in this file
// (money(props.revenue), etc.) stays short — this is the
// whole-number, currency-embedded variant ("EGP 3,453,321"), kept
// distinct from the plain money(v) other pages use, since
// Dashboard's headline tiles were never showing cents. See
// useMoneyFormat.js for why there are two.
const { currency, intlLocale, moneyWithCurrency: money } = useMoneyFormat();

function number(value) {
    return new Intl.NumberFormat(intlLocale.value, { maximumFractionDigits: 2 }).format(value ?? 0);
}

// ── Period ───────────────────────────────────────────────────────
const PERIODS = ['month', 'quarter', 'year'];

function setPeriod(period) {
    if (period === props.period) return;

    router.get(route('app.dashboard'), { period }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

// ── SKU donut ────────────────────────────────────────────────────
const donutSlices = computed(() =>
    props.sku_sales.map((row) => ({ name: row.name, value: row.value }))
);

const skuTotal = computed(() =>
    props.sku_sales.reduce((sum, row) => sum + (row.value || 0), 0)
);

// Compact ("1.5M") rather than grouped ("1,500,745"): this sits
// inside the donut's hole, where a full-length figure simply does
// not fit once the numbers get real.
const skuTotalCompact = computed(() => {
    const formatted = new Intl.NumberFormat(intlLocale.value, {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(skuTotal.value);

    return `${currency.value} ${formatted}`;
});

// ── Sales-by-channel donut ────────────────────────────────────────
// Same language fallback used throughout the app for anything that
// ships a name_ar — Arabic when the UI is in Arabic and a
// translation exists, otherwise the plain (English) name.
function channelLabel(row) {
    return locale.value === 'ar' && row.name_ar ? row.name_ar : row.name;
}

const channelDonutSlices = computed(() =>
    props.sales_by_channel.map((row) => ({ name: channelLabel(row), value: row.value }))
);

const channelTotal = computed(() =>
    props.sales_by_channel.reduce((sum, row) => sum + (row.value || 0), 0)
);

const channelTotalCompact = computed(() => {
    const formatted = new Intl.NumberFormat(intlLocale.value, {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(channelTotal.value);

    return `${currency.value} ${formatted}`;
});

// ── "Top by each measure" panels ─────────────────────────────────
function leaderBy(rows, key) {
    if (!rows.length) return null;

    return rows.reduce((best, row) => ((row[key] ?? 0) > (best[key] ?? 0) ? row : best), rows[0]);
}

function buildPanel(rows, titleKey, icon, tone) {
    return {
        titleKey,
        icon,
        tone,
        empty: rows.length === 0,
        measures: [
            {
                key: 'value',
                labelKey: 'rank_by_value',
                leader: leaderBy(rows, 'value'),
                format: (row) => money(row.value),
            },
            {
                key: 'volume',
                labelKey: 'rank_by_volume',
                leader: leaderBy(rows, 'volume'),
                format: (row) => `${number(row.volume)} ${t('rank_units')}`,
            },
            {
                key: 'transactions',
                labelKey: 'rank_by_transactions',
                leader: leaderBy(rows, 'transactions'),
                format: (row) => `${number(row.transactions)} ${t('rank_times')}`,
            },
        ],
    };
}

const panels = computed(() => [
    buildPanel(props.sku_sales,     'rank_top_item',     'tag',      'stock'),
    buildPanel(props.top_customers, 'rank_top_customer', 'team',     'in'),
    buildPanel(props.top_suppliers, 'rank_top_supplier', 'building', 'out'),
]);
</script>

<template>
    <Head :title="t('nav_home')" />

    <AppLayout>
        <div class="page-header dash-header">
            <h1>{{ t('nav_home') }}</h1>

            <div class="dash-periods" role="group" :aria-label="t('period_label')">
                <button
                    v-for="option in PERIODS"
                    :key="option"
                    type="button"
                    class="dash-period"
                    :class="{ 'dash-period--active': props.period === option }"
                    :aria-pressed="props.period === option"
                    @click="setPeriod(option)"
                >
                    {{ t(`period_${option}`) }}
                </button>
            </div>
        </div>

        <!-- ── 1. Profit & Loss — one row, one source (the P&L report) ── -->
        <div class="home-stats">
            <div class="home-stat home-stat--in">
                <div class="home-stat__label">{{ t('home_sales') }}</div>
                <div class="home-stat__value">{{ money(props.revenue) }}</div>
            </div>
            <div class="home-stat home-stat--cogs">
                <div class="home-stat__label">{{ t('home_cogs') }}</div>
                <div class="home-stat__value">{{ money(props.cost_of_goods_sold) }}</div>
            </div>
            <div class="home-stat home-stat--out">
                <div class="home-stat__label">{{ t('home_operating_expenses') }}</div>
                <div class="home-stat__value">{{ money(props.operating_expenses) }}</div>
            </div>
            <div class="home-stat home-stat--net">
                <div class="home-stat__label">{{ t('home_net_income') }}</div>
                <div class="home-stat__value" :class="props.net_profit >= 0 ? 'text-success' : 'text-danger'">
                    {{ money(props.net_profit) }}
                </div>
            </div>
        </div>

        <!-- ── 2. Cash position — one row, one source (the payments ledger) ── -->
        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-box__label">{{ t('home_cash_in') }}</div>
                <div class="stat-box__value text-success">{{ money(props.total_cash_in) }}</div>
            </div>
            <div class="stat-box">
                <div class="stat-box__label">{{ t('home_cash_out') }}</div>
                <div class="stat-box__value text-danger">{{ money(props.total_cash_out) }}</div>
            </div>
            <div class="stat-box">
                <div class="stat-box__label">{{ t('home_cash_balance') }}</div>
                <div class="stat-box__value">{{ money(props.cash_balance) }}</div>
            </div>
            <Link :href="route('app.payments.index')" class="stat-box stat-box--link">
                <div class="stat-box__label">{{ t('home_open_invoices') }}</div>
                <div class="stat-box__value">{{ props.open_invoices_count }}</div>
            </Link>
            <Link :href="route('app.payments.index')" class="stat-box stat-box--link">
                <div class="stat-box__label">{{ t('home_open_bills') }}</div>
                <div class="stat-box__value">{{ props.open_bills_count }}</div>
            </Link>
        </div>

        <!-- ── 2. What is selling ──────────────────────────────── -->
        <div class="card card--stock dash-chart">
            <h3 class="sub">{{ t('sku_mix_title') }}</h3>

            <DonutChart3D
                :slices="donutSlices"
                :center-label="t('sku_mix_center')"
                :center-value="skuTotalCompact"
            >
                <template #empty>{{ t('sku_mix_empty') }}</template>
            </DonutChart3D>
        </div>

        <div class="card card--in dash-chart">
            <h3 class="sub">{{ t('channel_mix_title') }}</h3>

            <DonutChart3D
                :slices="channelDonutSlices"
                :center-label="t('channel_mix_center')"
                :center-value="channelTotalCompact"
            >
                <template #empty>{{ t('channel_mix_empty') }}</template>
            </DonutChart3D>
        </div>

        <!-- ── 3. Who leads on what ────────────────────────────── -->
        <div class="dash-ranks">
            <div
                v-for="panel in panels"
                :key="panel.titleKey"
                class="card dash-rank"
                :class="`card--${panel.tone}`"
            >
                <h3 class="sub dash-rank__title">
                    <AppIcon :name="panel.icon" class="dash-rank__icon" />
                    {{ t(panel.titleKey) }}
                </h3>

                <p v-if="panel.empty" class="dash-rank__empty">{{ t('rank_empty') }}</p>

                <dl v-else class="dash-rank__list">
                    <div v-for="measure in panel.measures" :key="measure.key" class="dash-rank__row">
                        <dt class="dash-rank__measure">{{ t(measure.labelKey) }}</dt>
                        <dd class="dash-rank__winner">
                            <span class="dash-rank__name">{{ measure.leader.name }}</span>
                            <span class="dash-rank__figure">{{ measure.format(measure.leader) }}</span>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <!-- Quick-record cards — mobile only -->
        <div class="home-actions">
            <Link
                v-for="action in visibleQuickActions"
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
.dash-header { align-items: center; }

.dash-periods {
    display: flex;
    gap: 4px;
    background: var(--color-surface-alt);
    padding: 3px;
    border-radius: var(--radius-pill);
}

.dash-period {
    border: none;
    background: none;
    font-family: inherit;
    font-size: 12.5px;
    font-weight: 500;
    color: var(--color-text-muted);
    padding: 6px 14px;
    border-radius: var(--radius-pill);
    cursor: pointer;
    white-space: nowrap;
}

.dash-period--active {
    background: var(--color-surface);
    color: var(--color-primary-dark);
    font-weight: 600;
    box-shadow: var(--shadow-card);
}

.home-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.home-stat { border-radius: var(--radius-lg); padding: 16px 18px; }
.home-stat--in   { background: var(--color-success-soft); }
.home-stat--cogs { background: var(--color-success-cogs); }
.home-stat--out  { background: var(--color-danger-soft); }
.home-stat--net  { background: var(--color-surface-alt); }

.home-stat__label { font-size: 12px; font-weight: 500; margin-bottom: 6px; }
.home-stat--in  .home-stat__label { color: var(--color-success-dark); }
.home-stat--out .home-stat__label { color: var(--color-danger-dark); }

.home-stat__value { font-family: var(--font-mono); font-size: 22px; font-weight: 700; }
.home-stat--in  .home-stat__value { color: var(--color-success-dark); }
.home-stat--out .home-stat__value { color: var(--color-danger-dark); }

.stat-box--link { text-decoration: none; cursor: pointer; }
.stat-box--link:hover { border-color: var(--color-primary); }

.dash-chart { margin-top: 20px; }

/* ── Rank panels ─────────────────────────────────────────────── */
.dash-ranks {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(258px, 1fr));
    gap: 14px;
    margin-top: 18px;
}

.dash-rank { margin-top: 0; }

.dash-rank__title {
    display: flex;
    align-items: center;
    gap: 8px;
}

.dash-rank__icon { width: 16px; height: 16px; flex-shrink: 0; }

.dash-rank__list { margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }

.dash-rank__row {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--color-border-light);
}
.dash-rank__row:last-child { border-bottom: none; padding-bottom: 0; }

.dash-rank__measure {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--color-text-muted);
}

.dash-rank__winner {
    margin: 0;
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 10px;
    min-width: 0;
}

.dash-rank__name {
    font-size: 14px;
    font-weight: 600;
    color: var(--color-text-primary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
}

.dash-rank__figure {
    font-family: var(--font-mono);
    font-size: 13px;
    font-weight: 600;
    color: var(--color-primary-dark);
    flex-shrink: 0;
}

.dash-rank__empty {
    margin: 0;
    font-size: 13px;
    color: var(--color-text-muted);
}

.home-actions { display: flex; flex-direction: column; gap: 12px; margin-top: 20px; }

/* Desktop already has these six as step-nav tabs */
@media (min-width: 768px) { .home-actions { display: none; } }

.home-action { display: flex; align-items: center; gap: 14px; padding: 16px 18px; text-decoration: none; }

.home-action__icon {
    width: 44px; height: 44px;
    border-radius: var(--radius-md);
    background: var(--color-primary-soft);
    color: var(--color-primary-dark);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.home-action__icon svg { width: 22px; height: 22px; }

.home-action__text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.home-action__title { font-size: 14.5px; font-weight: 600; color: var(--color-text-primary); }
.home-action__sub { font-size: 12.5px; color: var(--color-text-muted); }

.home-action__chevron { width: 17px; height: 17px; color: var(--color-text-muted); flex-shrink: 0; }
[dir="rtl"] .home-action__chevron { transform: scaleX(-1); }

@media (max-width: 480px) {
    .dash-header { align-items: flex-start; }
    .dash-periods { width: 100%; }
    .dash-period { flex: 1; text-align: center; }
}
</style>
