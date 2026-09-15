<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ActivityController (super_admin side)
//  Location: app/Http/Controllers/Admin/ActivityController.php
//
//  Who is actually using the app, and how regularly.
//
//  ONE ROW PER PERSON PER DAY. Somebody who opens the app six times
//  on Tuesday is one Tuesday, shown at the time they first arrived.
//  That is the whole point: the question being answered is "is this
//  customer using what they are paying for", and counting every page
//  visit would answer a different, much less useful one — a single
//  long session would out-rank a company whose whole team logs in
//  every morning.
//
//  Reading, not writing. The rows come from user_login_activities,
//  which UserLoginFrequencyService has been filling all along from
//  two places: RecordSuccessfulLogin on an explicit sign-in, and
//  TrackDailyUserAccess on the first page view of a new day by
//  somebody already signed in. The second matters more than it
//  sounds — a user who stays logged in for weeks would otherwise
//  look dormant. Taking the EARLIEST timestamp of each day folds
//  both kinds of row into the single answer "when did they first
//  turn up that day".
//
//  Super admins are left out: this is about customers, and the
//  platform's own staff browsing the admin area is not usage of the
//  product.
// ══════════════════════════════════════════════════════════════════
class ActivityController extends Controller
{
    /** Days shown when the page is opened with no date filter. */
    private const DEFAULT_WINDOW_DAYS = 30;

    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('Admin/Activity/Index', [
            'rows'      => $this->dailyFirstVisits($filters),
            'summary'   => $this->summary(),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'filters'   => $filters,
        ]);
    }

    /**
     * @return array{from: string, to: string, company_id: ?int, q: string}
     */
    private function filters(Request $request): array
    {
        $to   = $request->date('to') ?? Carbon::today();
        $from = $request->date('from') ?? $to->copy()->subDays(self::DEFAULT_WINDOW_DAYS - 1);

        // A backwards range returns nothing and looks like a bug, so
        // swap rather than argue with the user about it.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from'       => $from->toDateString(),
            'to'         => $to->toDateString(),
            'company_id' => $request->integer('company_id') ?: null,
            'q'          => trim((string) $request->query('q', '')),
        ];
    }

    /**
     * One row per user per calendar day, carrying the first moment
     * they showed up that day.
     *
     * The MIN() runs in its own grouped subquery and the joins hang
     * off that, rather than grouping the joined result — this keeps
     * the paginator's COUNT honest, which a top-level GROUP BY
     * quietly breaks.
     */
    private function dailyFirstVisits(array $filters)
    {
        $firstVisitPerDay = DB::table('user_login_activities')
            ->selectRaw('user_id, activity_date, MIN(login_at) as first_at')
            ->whereBetween('activity_date', [$filters['from'], $filters['to']])
            ->groupBy('user_id', 'activity_date');

        $query = DB::query()
            ->fromSub($firstVisitPerDay, 'visits')
            ->join('users', 'users.id', '=', 'visits.user_id')
            ->join('companies', 'companies.id', '=', 'users.company_id')
            ->select([
                'visits.activity_date',
                'visits.first_at',
                'users.id as user_id',
                'users.name as user_name',
                'users.email as user_email',
                'users.role as user_role',
                'users.is_active as user_is_active',
                'companies.id as company_id',
                'companies.name as company_name',
            ]);

        if ($filters['company_id']) {
            $query->where('companies.id', $filters['company_id']);
        }

        if ($filters['q'] !== '') {
            $query->where(function ($q) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $q->where('users.name', 'like', $term)
                    ->orWhere('users.email', 'like', $term)
                    ->orWhere('companies.name', 'like', $term);
            });
        }

        return $query
            ->orderByDesc('visits.activity_date')
            ->orderByDesc('visits.first_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn ($row) => [
                'date'         => Carbon::parse($row->activity_date)->toDateString(),
                'time'         => Carbon::parse($row->first_at)->format('H:i'),
                'user_id'      => $row->user_id,
                'user_name'    => $row->user_name,
                'user_email'   => $row->user_email,
                'user_role'    => $row->user_role,
                'is_active'    => (bool) $row->user_is_active,
                'company_id'   => $row->company_id,
                'company_name' => $row->company_name,
            ]);
    }

    /**
     * How many distinct people turned up today, this week and this
     * month — the "is anybody actually using this" line, independent
     * of whatever range the table below is filtered to.
     *
     * @return array{today: int, this_week: int, this_month: int, total_users: int}
     */
    private function summary(): array
    {
        $distinctUsersSince = fn (string $from): int => DB::table('user_login_activities')
            ->join('users', 'users.id', '=', 'user_login_activities.user_id')
            ->whereNotNull('users.company_id')
            ->where('user_login_activities.activity_date', '>=', $from)
            ->distinct()
            ->count('user_login_activities.user_id');

        return [
            'today'       => $distinctUsersSince(Carbon::today()->toDateString()),
            'this_week'   => $distinctUsersSince(Carbon::today()->startOfWeek()->toDateString()),
            'this_month'  => $distinctUsersSince(Carbon::today()->startOfMonth()->toDateString()),
            'total_users' => DB::table('users')->whereNotNull('company_id')->count(),
        ];
    }
}
