<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Admin/Activity/Index.vue
//  Location: resources/js/Pages/Admin/Activity/Index.vue
//
//  Who is using the app, one row per person per day. Props from
//  App\Http\Controllers\Admin\ActivityController::index() — see that
//  file for why a person who opens the app six times on Tuesday is
//  one Tuesday row, timed at their first arrival.
//
//  The date range and the company filter drive a plain GET, so a
//  particular view is a shareable URL and the browser's back button
//  behaves the way anyone would expect.
// ══════════════════════════════════════════════════════════════════

import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    rows:      { type: Object, required: true }, // paginator: { data, links, ... }
    summary:   { type: Object, required: true },
    companies: { type: Array,  required: true },
    filters:   { type: Object, required: true },
});

const form = ref({
    from:       props.filters.from,
    to:         props.filters.to,
    company_id: props.filters.company_id ?? '',
    q:          props.filters.q ?? '',
});

// Server-side filtering keeps one source of truth for what the table
// shows; the alternative (filtering the current page in the browser)
// would silently only search the 50 rows already loaded.
function applyFilters() {
    router.get(route('admin.activity.index'), {
        from:       form.value.from,
        to:         form.value.to,
        company_id: form.value.company_id || undefined,
        q:          form.value.q || undefined,
    }, { preserveScroll: true, preserveState: true, replace: true });
}

let searchTimer = null;
watch(() => form.value.q, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 350);
});

function resetFilters() {
    form.value = { from: '', to: '', company_id: '', q: '' };
    router.get(route('admin.activity.index'), {}, { preserveScroll: true, replace: true });
}

// Rows arrive newest-first and already sorted; grouping by date here
// is presentation only — it turns a flat list into "Tuesday: these
// four people", which is the shape the question is actually asked in.
const grouped = computed(() => {
    const out = [];
    for (const row of props.rows.data) {
        const last = out[out.length - 1];
        if (last && last.date === row.date) {
            last.people.push(row);
        } else {
            out.push({ date: row.date, people: [row] });
        }
    }
    return out;
});

function weekday(iso) {
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', {
        weekday: 'long', day: 'numeric', month: 'short', year: 'numeric',
    });
}

function roleLabel(role) {
    return role === 'company_admin' ? 'Admin' : 'Employee';
}
</script>

<template>
    <Head title="Activity" />

    <AdminLayout title="Activity">
        <div class="adm-activity">
            <div class="adm-activity__header">
                <div>
                    <h1 class="adm-activity__title">Activity</h1>
                    <p class="adm-activity__sub">
                        Who opened the app, and when they first opened it that day
                    </p>
                </div>
            </div>

            <!-- ── Headline: is anybody actually using this ───────── -->
            <div class="adm-activity__stats">
                <div class="adm-activity__stat">
                    <span class="adm-activity__stat-value">{{ props.summary.today }}</span>
                    <span class="adm-activity__stat-label">Active today</span>
                </div>
                <div class="adm-activity__stat">
                    <span class="adm-activity__stat-value">{{ props.summary.this_week }}</span>
                    <span class="adm-activity__stat-label">This week</span>
                </div>
                <div class="adm-activity__stat">
                    <span class="adm-activity__stat-value">{{ props.summary.this_month }}</span>
                    <span class="adm-activity__stat-label">This month</span>
                </div>
                <div class="adm-activity__stat adm-activity__stat--muted">
                    <span class="adm-activity__stat-value">{{ props.summary.total_users }}</span>
                    <span class="adm-activity__stat-label">Users in total</span>
                </div>
            </div>

            <!-- ── Filters ────────────────────────────────────────── -->
            <div class="card adm-activity__filters">
                <div class="adm-activity__filter">
                    <label class="form-label" for="act-from">From</label>
                    <input id="act-from" v-model="form.from" type="date" class="form-input" @change="applyFilters">
                </div>
                <div class="adm-activity__filter">
                    <label class="form-label" for="act-to">To</label>
                    <input id="act-to" v-model="form.to" type="date" class="form-input" @change="applyFilters">
                </div>
                <div class="adm-activity__filter">
                    <label class="form-label" for="act-company">Company</label>
                    <select id="act-company" v-model="form.company_id" class="form-select" @change="applyFilters">
                        <option value="">All companies</option>
                        <option v-for="company in props.companies" :key="company.id" :value="company.id">
                            {{ company.name }}
                        </option>
                    </select>
                </div>
                <div class="adm-activity__filter adm-activity__filter--grow">
                    <label class="form-label" for="act-q">Search</label>
                    <input id="act-q" v-model="form.q" type="search" class="form-input" placeholder="Person or company">
                </div>
                <button type="button" class="btn btn-ghost btn-sm adm-activity__reset" @click="resetFilters">
                    Reset
                </button>
            </div>

            <!-- ── The log ────────────────────────────────────────── -->
            <div v-if="grouped.length === 0" class="card adm-activity__empty">
                Nobody opened the app in this range.
            </div>

            <div v-for="day in grouped" :key="day.date" class="adm-activity__day">
                <div class="adm-activity__day-head">
                    <span class="adm-activity__day-date">{{ weekday(day.date) }}</span>
                    <span class="adm-activity__day-count">
                        {{ day.people.length }} {{ day.people.length === 1 ? 'person' : 'people' }}
                    </span>
                </div>

                <div class="report-table-wrap card">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>First opened</th>
                                <th>Person</th>
                                <th>Company</th>
                                <th>Role</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="person in day.people" :key="`${day.date}-${person.user_id}`">
                                <td class="adm-activity__time">{{ person.time }}</td>
                                <td>
                                    <div class="adm-activity__person">
                                        {{ person.user_name }}
                                        <span v-if="!person.is_active" class="badge warn">Deactivated</span>
                                    </div>
                                    <div class="adm-activity__email">{{ person.user_email }}</div>
                                </td>
                                <td>{{ person.company_name }}</td>
                                <td>{{ roleLabel(person.user_role) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="props.rows.links?.length > 3" class="adm-activity__pager">
                <template v-for="(link, i) in props.rows.links" :key="i">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="adm-activity__page-link"
                        :class="{ 'adm-activity__page-link--active': link.active }"
                        v-html="link.label"
                        preserve-scroll
                    />
                    <span v-else class="adm-activity__page-link adm-activity__page-link--disabled" v-html="link.label"></span>
                </template>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.adm-activity__header { margin-bottom: 18px; }
.adm-activity__title { font-size: 22px; font-weight: 700; margin: 0; }
.adm-activity__sub { color: var(--color-text-muted); font-size: 13.5px; margin: 4px 0 0; }

/* ── Headline stats ─────────────────────────────────────────── */
.adm-activity__stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}

.adm-activity__stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 14px 16px;
    border-radius: var(--radius-md);
    background: var(--color-surface);
    border: 1px solid var(--color-border);
}

.adm-activity__stat--muted { opacity: 0.72; }
.adm-activity__stat-value { font-size: 24px; font-weight: 700; font-family: var(--font-mono); }
.adm-activity__stat-label { font-size: 12px; color: var(--color-text-muted); }

/* ── Filters ────────────────────────────────────────────────── */
.adm-activity__filters {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 12px;
    padding: 14px 16px;
    margin-bottom: 18px;
}

.adm-activity__filter { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
.adm-activity__filter--grow { flex: 1 1 180px; }
.adm-activity__reset { margin-inline-start: auto; }

/* ── One block per day ──────────────────────────────────────── */
.adm-activity__day { margin-bottom: 20px; }

.adm-activity__day-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 8px;
}

.adm-activity__day-date { font-size: 14px; font-weight: 600; }
.adm-activity__day-count { font-size: 12px; color: var(--color-text-muted); }

.adm-activity__time { font-family: var(--font-mono); white-space: nowrap; }
.adm-activity__person { display: flex; align-items: center; gap: 8px; font-weight: 600; }
.adm-activity__email { font-size: 12px; color: var(--color-text-muted); }

.adm-activity__empty {
    padding: 28px 16px;
    text-align: center;
    color: var(--color-text-muted);
}

/* ── Pager ──────────────────────────────────────────────────── */
.adm-activity__pager { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 16px; }

.adm-activity__page-link {
    padding: 6px 11px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--color-border);
    font-size: 13px;
    text-decoration: none;
    color: inherit;
}

.adm-activity__page-link--active { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }
.adm-activity__page-link--disabled { opacity: 0.4; }

@media (max-width: 640px) {
    .adm-activity__reset { margin-inline-start: 0; }
}
</style>
