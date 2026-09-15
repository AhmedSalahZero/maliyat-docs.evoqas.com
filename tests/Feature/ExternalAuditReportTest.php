<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  External Audit — the Trial Balance and the Journal behind one
//  entry point.
//
//  These are the only two screens written for the company's auditor
//  rather than its owner, and they are used together: read a figure
//  off the Trial Balance, open the Journal to see what produced it.
//  Two tiles on the reports hub made them look like two unrelated
//  destinations and made an owner who will never open either scroll
//  past both.
//
//  Two things these tests hold in place. First, only the half being
//  looked at is built — the Trial Balance sums every journal line
//  ever posted and the Journal loads whole entries with their lines,
//  so building both to show one would double the cost of every page
//  load and every tab switch. Second, the old URLs still work: an
//  auditor's bookmark has to land on the right half, not a 404.
// ══════════════════════════════════════════════════════════════════
class ExternalAuditReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin   = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->actingAs($this->admin);
    }

    private function recordSale(string $date = '2026-09-01', float $amount = 1000): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Acme']);

        $this->post('/app/sales', [
            'customer_id' => $customer->id,
            'date'        => $date,
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => $amount]],
            'vat_rate'    => 0,
            'mode'        => 'later',
        ])->assertSessionHasNoErrors();
    }

    private function props(array $query = []): array
    {
        return $this->get(route('app.reports.external-audit', $query))
            ->viewData('page')['props'];
    }

    // ── One page, two halves ─────────────────────────────────────

    public function test_it_renders_the_trial_balance_by_default(): void
    {
        $this->recordSale();

        $props = $this->props();

        $this->assertSame('trial-balance', $props['view']);
        $this->assertNotNull($props['trialBalance']);
        $this->assertNull($props['entries'], 'The Journal is not built when it is not being looked at');
    }

    public function test_it_renders_the_journal_when_asked(): void
    {
        $this->recordSale();

        $props = $this->props(['view' => 'journal', 'from' => '2026-09-01', 'to' => '2026-09-30']);

        $this->assertSame('journal', $props['view']);
        $this->assertNotNull($props['entries']);
        $this->assertNull($props['trialBalance'], 'The Trial Balance is not built when it is not being looked at');
    }

    public function test_an_unknown_view_falls_back_to_the_trial_balance(): void
    {
        $this->assertSame('trial-balance', $this->props(['view' => 'nonsense'])['view']);
    }

    public function test_it_uses_one_page_component_for_both(): void
    {
        $this->assertSame(
            'App/Reports/ExternalAudit',
            $this->get(route('app.reports.external-audit'))->viewData('page')['component']
        );

        $this->assertSame(
            'App/Reports/ExternalAudit',
            $this->get(route('app.reports.external-audit', ['view' => 'journal']))->viewData('page')['component']
        );
    }

    // ── Each half keeps its own filter ───────────────────────────

    public function test_the_trial_balance_takes_a_single_date(): void
    {
        $this->recordSale('2026-09-01', 1000);

        $before = $this->props(['as_of' => '2026-08-31'])['trialBalance'];
        $after  = $this->props(['as_of' => '2026-09-30'])['trialBalance'];

        $this->assertCount(0, $before['rows'], 'Nothing was posted before September');
        $this->assertNotEmpty($after['rows']);
    }

    public function test_the_journal_takes_a_range(): void
    {
        $this->recordSale('2026-09-01', 1000);

        $inRange  = $this->props(['view' => 'journal', 'from' => '2026-09-01', 'to' => '2026-09-30'])['entries'];
        $outRange = $this->props(['view' => 'journal', 'from' => '2026-10-01', 'to' => '2026-10-31'])['entries'];

        $this->assertNotEmpty($inRange);
        $this->assertEmpty($outRange);
    }

    public function test_the_trial_balance_still_balances(): void
    {
        $this->recordSale('2026-09-01', 1000);

        $trialBalance = $this->props(['as_of' => '2026-09-30'])['trialBalance'];

        $this->assertTrue($trialBalance['is_balanced']);
        $this->assertEquals($trialBalance['total_debit'], $trialBalance['total_credit']);
    }

    // ── The old links still work ─────────────────────────────────

    public function test_the_old_trial_balance_url_lands_on_the_right_half(): void
    {
        $this->get('/app/reports/trial-balance')
            ->assertRedirect(route('app.reports.external-audit', ['view' => 'trial-balance']));
    }

    public function test_the_old_journal_url_lands_on_the_right_half(): void
    {
        $response = $this->get('/app/reports/journal');

        $response->assertRedirect();
        $this->assertStringContainsString('view=journal', $response->headers->get('Location'));
    }

    public function test_an_old_bookmark_keeps_its_filter(): void
    {
        $response = $this->get('/app/reports/trial-balance?as_of=2026-06-30');

        $this->assertStringContainsString('as_of=2026-06-30', $response->headers->get('Location'));
    }

    public function test_following_an_old_link_renders_the_page(): void
    {
        $this->recordSale();

        $location = $this->get('/app/reports/journal')->headers->get('Location');

        $this->get($location)->assertOk();
    }

    // ── Exports are untouched ────────────────────────────────────

    public function test_both_exports_still_work(): void
    {
        $this->recordSale();

        $this->get(route('app.reports.trial-balance.export', ['format' => 'excel', 'as_of' => '2026-09-30']))
            ->assertOk();

        $this->get(route('app.reports.journal.export', ['format' => 'excel', 'from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk();
    }

    // ── Still behind the company boundary ────────────────────────

    public function test_another_companys_figures_are_not_visible(): void
    {
        $this->recordSale('2026-09-01', 1000);

        $otherCompany = Company::factory()->create();
        $otherAdmin   = User::factory()->companyAdmin($otherCompany)->create();

        app(JournalService::class)->seedChartOfAccounts($otherCompany);

        $props = $this->actingAs($otherAdmin)
            ->get(route('app.reports.external-audit', ['as_of' => '2026-09-30']))
            ->viewData('page')['props'];

        $this->assertCount(0, $props['trialBalance']['rows']);
    }
}
