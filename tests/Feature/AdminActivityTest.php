<?php

namespace Tests\Feature;

use App\Enums\LoginActivityType;
use App\Models\Company;
use App\Models\User;
use App\Models\UserLoginActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The super admin's view of who is actually using the app.
//
//  The rule that matters: ONE ROW PER PERSON PER DAY, timed at the
//  moment they first turned up. Somebody who opens the app six times
//  on Tuesday is one Tuesday — otherwise a single restless user
//  would out-rank a company whose whole team logs in every morning,
//  and the page would answer a question nobody asked.
//
//  The underlying table already carried both kinds of row — an
//  explicit sign-in, and the first page view of a new day by
//  somebody still signed in from last week. Taking the earliest
//  timestamp of each day is what folds the two into one answer.
// ══════════════════════════════════════════════════════════════════
class AdminActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->company    = Company::factory()->create(['name' => 'Acme Trading']);
    }

    private function member(string $name = 'Ahmed'): User
    {
        return User::factory()->companyAdmin($this->company)->create(['name' => $name]);
    }

    private function recordVisit(User $user, string $at, LoginActivityType $type = LoginActivityType::Login): void
    {
        $moment = Carbon::parse($at);

        UserLoginActivity::query()->create([
            'user_id'       => $user->id,
            'login_at'      => $moment,
            'activity_date' => $moment->toDateString(),
            'type'          => $type,
            'source'        => 'web',
        ]);
    }

    private function rows(array $query = []): array
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.activity.index', $query))
            ->viewData('page')['props']['rows']['data'];
    }

    // ── Access ───────────────────────────────────────────────────

    public function test_only_a_super_admin_can_open_it(): void
    {
        $this->actingAs($this->member())
            ->get(route('admin.activity.index'))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('admin.activity.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_renders(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.activity.index'))
            ->assertOk();
    }

    // ── One row per person per day ───────────────────────────────

    public function test_six_visits_in_one_day_are_one_row(): void
    {
        $user = $this->member();

        foreach (['08:15', '09:40', '11:02', '13:30', '16:45', '19:10'] as $time) {
            $this->recordVisit($user, "2026-09-10 {$time}");
        }

        $rows = $this->rows(['from' => '2026-09-10', 'to' => '2026-09-10']);

        $this->assertCount(1, $rows);
    }

    public function test_the_time_shown_is_the_first_of_that_day(): void
    {
        $user = $this->member();

        $this->recordVisit($user, '2026-09-10 16:45');
        $this->recordVisit($user, '2026-09-10 08:15');
        $this->recordVisit($user, '2026-09-10 11:02');

        $rows = $this->rows(['from' => '2026-09-10', 'to' => '2026-09-10']);

        $this->assertSame('08:15', $rows[0]['time']);
    }

    public function test_separate_days_are_separate_rows(): void
    {
        $user = $this->member();

        $this->recordVisit($user, '2026-09-08 09:00');
        $this->recordVisit($user, '2026-09-09 09:00');
        $this->recordVisit($user, '2026-09-10 09:00');

        $this->assertCount(3, $this->rows(['from' => '2026-09-01', 'to' => '2026-09-30']));
    }

    public function test_two_people_on_the_same_day_are_two_rows(): void
    {
        $this->recordVisit($this->member('Ahmed'), '2026-09-10 09:00');
        $this->recordVisit($this->member('Mona'), '2026-09-10 10:00');

        $rows = $this->rows(['from' => '2026-09-10', 'to' => '2026-09-10']);

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['Ahmed', 'Mona'], array_column($rows, 'user_name'));
    }

    /**
     * A sign-in and a next-day first-page-view are different row
     * types in the table; both are "they turned up" and must fold
     * into the same daily answer.
     */
    public function test_a_login_and_a_daily_access_on_one_day_are_one_row(): void
    {
        $user = $this->member();

        $this->recordVisit($user, '2026-09-10 07:30', LoginActivityType::DailyAccess);
        $this->recordVisit($user, '2026-09-10 14:00', LoginActivityType::Login);

        $rows = $this->rows(['from' => '2026-09-10', 'to' => '2026-09-10']);

        $this->assertCount(1, $rows);
        $this->assertSame('07:30', $rows[0]['time']);
    }

    // ── What each row carries ────────────────────────────────────

    public function test_a_row_names_the_person_and_their_company(): void
    {
        $this->recordVisit($this->member('Ahmed'), '2026-09-10 09:00');

        $row = $this->rows(['from' => '2026-09-10', 'to' => '2026-09-10'])[0];

        $this->assertSame('Ahmed', $row['user_name']);
        $this->assertSame('Acme Trading', $row['company_name']);
        $this->assertSame('2026-09-10', $row['date']);
        $this->assertSame('09:00', $row['time']);
        $this->assertSame('company_admin', $row['user_role']);
    }

    public function test_newest_days_come_first(): void
    {
        $user = $this->member();

        $this->recordVisit($user, '2026-09-08 09:00');
        $this->recordVisit($user, '2026-09-10 09:00');
        $this->recordVisit($user, '2026-09-09 09:00');

        $dates = array_column($this->rows(['from' => '2026-09-01', 'to' => '2026-09-30']), 'date');

        $this->assertSame(['2026-09-10', '2026-09-09', '2026-09-08'], $dates);
    }

    /**
     * The platform's own staff browsing the admin area is not a
     * customer using the product.
     */
    public function test_super_admins_are_left_out(): void
    {
        $this->recordVisit($this->superAdmin, '2026-09-10 09:00');
        $this->recordVisit($this->member('Ahmed'), '2026-09-10 10:00');

        $rows = $this->rows(['from' => '2026-09-10', 'to' => '2026-09-10']);

        $this->assertCount(1, $rows);
        $this->assertSame('Ahmed', $rows[0]['user_name']);
    }

    // ── Filters ──────────────────────────────────────────────────

    public function test_the_date_range_is_respected(): void
    {
        $user = $this->member();

        $this->recordVisit($user, '2026-09-05 09:00');
        $this->recordVisit($user, '2026-09-15 09:00');
        $this->recordVisit($user, '2026-09-25 09:00');

        $dates = array_column($this->rows(['from' => '2026-09-10', 'to' => '2026-09-20']), 'date');

        $this->assertSame(['2026-09-15'], $dates);
    }

    public function test_it_can_be_narrowed_to_one_company(): void
    {
        $otherCompany = Company::factory()->create(['name' => 'Beta Ltd']);
        $otherUser    = User::factory()->companyAdmin($otherCompany)->create(['name' => 'Sara']);

        $this->recordVisit($this->member('Ahmed'), '2026-09-10 09:00');
        $this->recordVisit($otherUser, '2026-09-10 10:00');

        $rows = $this->rows([
            'from' => '2026-09-10', 'to' => '2026-09-10', 'company_id' => $otherCompany->id,
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Sara', $rows[0]['user_name']);
    }

    public function test_it_can_be_searched_by_person_or_company(): void
    {
        $this->recordVisit($this->member('Ahmed'), '2026-09-10 09:00');
        $this->recordVisit($this->member('Mona'), '2026-09-10 10:00');

        $rows = $this->rows(['from' => '2026-09-10', 'to' => '2026-09-10', 'q' => 'Mona']);

        $this->assertCount(1, $rows);
        $this->assertSame('Mona', $rows[0]['user_name']);
    }

    /**
     * A range typed backwards returns nothing and reads as a broken
     * page, so it is swapped rather than argued with.
     */
    public function test_a_backwards_range_is_swapped_rather_than_empty(): void
    {
        $this->recordVisit($this->member(), '2026-09-15 09:00');

        $rows = $this->rows(['from' => '2026-09-20', 'to' => '2026-09-10']);

        $this->assertCount(1, $rows);
    }

    // ── Summary ──────────────────────────────────────────────────

    public function test_the_summary_counts_distinct_people_not_visits(): void
    {
        $user = $this->member();

        foreach (['08:00', '12:00', '18:00'] as $time) {
            $this->recordVisit($user, Carbon::today()->toDateString()." {$time}");
        }

        $summary = $this->actingAs($this->superAdmin)
            ->get(route('admin.activity.index'))
            ->viewData('page')['props']['summary'];

        $this->assertSame(1, $summary['today']);
        $this->assertSame(1, $summary['this_month']);
    }

    public function test_the_summary_reports_how_many_users_exist_at_all(): void
    {
        $this->member('Ahmed');
        $this->member('Mona');

        $summary = $this->actingAs($this->superAdmin)
            ->get(route('admin.activity.index'))
            ->viewData('page')['props']['summary'];

        $this->assertSame(2, $summary['total_users']);
        $this->assertSame(0, $summary['today'], 'Existing is not the same as showing up');
    }

    /**
     * The whole point of the page is spotting who is NOT using it.
     */
    public function test_a_company_that_never_shows_up_has_no_rows(): void
    {
        $this->member('Ahmed');

        $this->assertCount(0, $this->rows());
    }

    // ── It reflects real use, not just seeded rows ───────────────

    public function test_signing_in_puts_a_person_on_the_page(): void
    {
        $user = User::factory()->companyAdmin($this->company)->create([
            'name'              => 'Ahmed',
            'email'             => 'ahmed@acme.test',
            'password'          => 'password123',
            'email_verified_at' => now(),
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertSessionHasNoErrors();

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('Ahmed', $rows[0]['user_name']);
        $this->assertSame(now()->toDateString(), $rows[0]['date']);
    }
}
