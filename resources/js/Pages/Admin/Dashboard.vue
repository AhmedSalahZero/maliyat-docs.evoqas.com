<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Admin/Dashboard.vue
//  Location: resources/js/Pages/Admin/Dashboard.vue
//
//  Landing page for super_admin. Props come from
//  App\Http\Controllers\Admin\DashboardController: companies_count,
//  active_companies_count, users_count. Three KPI cards + a link
//  into Companies, since that's the only thing a super_admin
//  manages in Maliyat Docs.
//
//  NOTE: this page used to be InPractice's admin dashboard (member
//  stats, hub breakdown, forum stats, recent registrations) built
//  against props this app's DashboardController never sends and
//  routes (admin.users.*, admin.cases.*) that no longer exist —
//  it would have rendered blank/broken. Replaced rather than edited.
// ══════════════════════════════════════════════════════════════════

import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    companies_count:        { type: Number, default: 0 },
    active_companies_count: { type: Number, default: 0 },
    users_count:             { type: Number, default: 0 },
});
</script>

<template>
    <Head title="Admin Dashboard" />

    <AdminLayout title="Dashboard">
        <div class="adm-dash">
            <div class="adm-dash__header">
                <div>
                    <h1 class="adm-dash__title">Platform Overview</h1>
                    <p class="adm-dash__sub">Every company on Maliyat Docs, at a glance</p>
                </div>
                <Link :href="route('admin.companies.index')" class="adm-dash__cta">
                    Manage Companies
                </Link>
            </div>

            <div class="adm-kpi-strip">
                <div class="adm-kpi-card">
                    <div class="adm-kpi-card__value">{{ props.companies_count.toLocaleString() }}</div>
                    <div class="adm-kpi-card__label">Companies</div>
                </div>
                <div class="adm-kpi-card">
                    <div class="adm-kpi-card__value">{{ props.active_companies_count.toLocaleString() }}</div>
                    <div class="adm-kpi-card__label">Active companies</div>
                </div>
                <div class="adm-kpi-card">
                    <div class="adm-kpi-card__value">{{ props.users_count.toLocaleString() }}</div>
                    <div class="adm-kpi-card__label">Users</div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.adm-dash {
    max-width: 1000px;
    margin: 0 auto;
    padding: 32px 20px 48px;
    display: flex;
    flex-direction: column;
    gap: 28px;
}

.adm-dash__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.adm-dash__title {
    font-family: var(--font-heading);
    font-size: 22px;
    font-weight: 700;
    color: var(--color-text-primary);
    margin: 0 0 4px;
}

.adm-dash__sub {
    font-size: 13.5px;
    color: var(--color-text-muted);
    margin: 0;
}

.adm-dash__cta {
    display: inline-flex;
    align-items: center;
    padding: 10px 18px;
    background: var(--color-primary);
    color: #fff;
    border-radius: var(--radius-md);
    font-size: 13.5px;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
    box-shadow: var(--shadow-button);
}

.adm-dash__cta:hover { background: var(--color-primary-dark); }

.adm-kpi-strip {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
}

.adm-kpi-card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: 20px;
}

.adm-kpi-card__value {
    font-family: var(--font-mono);
    font-size: 26px;
    font-weight: 700;
    color: var(--color-text-primary);
    line-height: 1;
    margin-bottom: 6px;
}

.adm-kpi-card__label {
    font-size: 13px;
    color: var(--color-text-secondary);
}
</style>
