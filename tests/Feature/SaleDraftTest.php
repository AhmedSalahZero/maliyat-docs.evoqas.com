<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SaleDraft;
use App\Models\User;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Unfinished sales ("drafts") — owner request, Sep 2026.
//
//    - A draft can be incomplete, and saving one must leave the
//      accounts completely untouched: no sale, no journal entry, no
//      stock movement.
//    - Drafts are shared by the company team: an employee sees a
//      draft the admin started, can continue it and can delete it.
//    - Recording a sale from a draft removes the draft.
//    - Another company can never see or touch them.
// ══════════════════════════════════════════════════════════════════
class SaleDraftTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $employee;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company  = Company::factory()->create();
        $this->admin    = User::factory()->companyAdmin($this->company)->create();
        $this->employee = User::factory()->employee($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Acme']);
    }

    /** An unfinished sale: customer picked, one line with no price yet. */
    private function draftPayload(): array
    {
        return ['data' => [
            'sale_kind'   => 'invoice',
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-01',
            'lines'       => [['item_id' => null, 'qty' => 2, 'unit_price' => null]],
            'vat_rate'    => 0,
            'mode'        => 'later',
        ]];
    }

    private function accountingRows(): array
    {
        return [
            'sales'           => DB::table('sales')->count(),
            'journal_entries' => DB::table('journal_entries')->count(),
            'stock_ledger'    => DB::table('inventory_stock_ledger')->count(),
            'payments'        => DB::table('payments')->count(),
        ];
    }

    public function test_an_unfinished_sale_can_be_saved_as_a_draft(): void
    {
        $this->actingAs($this->admin)
            ->post('/app/sale-drafts', $this->draftPayload())
            ->assertSessionHasNoErrors();

        $draft = SaleDraft::query()->firstOrFail();
        $this->assertSame($this->customer->id, $draft->data['customer_id']);
        $this->assertSame($this->admin->id, $draft->created_by);
    }

    public function test_a_draft_has_no_effect_on_the_accounts(): void
    {
        $before = $this->accountingRows();

        $this->actingAs($this->admin)->post('/app/sale-drafts', $this->draftPayload());

        $this->assertSame(1, SaleDraft::count());
        $this->assertSame($before, $this->accountingRows(), 'Saving a draft wrote to the accounts');
    }

    public function test_the_whole_team_sees_the_draft(): void
    {
        $this->actingAs($this->admin)->post('/app/sale-drafts', $this->draftPayload());
        Cache::flush();

        $this->actingAs($this->employee)
            ->get('/app/sales')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/Sales/Index')
                ->has('drafts', 1)
            );
    }

    public function test_a_team_member_can_update_and_delete_a_draft(): void
    {
        $this->actingAs($this->admin)->post('/app/sale-drafts', $this->draftPayload());
        $draft = SaleDraft::query()->firstOrFail();

        $payload = $this->draftPayload();
        $payload['data']['lines'][0]['unit_price'] = 150;

        $this->actingAs($this->employee)
            ->put("/app/sale-drafts/{$draft->id}", $payload)
            ->assertSessionHasNoErrors();

        $draft->refresh();
        $this->assertEquals(150, $draft->data['lines'][0]['unit_price']);
        $this->assertSame($this->employee->id, $draft->updated_by);

        $this->actingAs($this->employee)
            ->delete("/app/sale-drafts/{$draft->id}")
            ->assertSessionHasNoErrors();

        $this->assertSame(0, SaleDraft::count());
    }

    public function test_recording_the_sale_removes_its_draft(): void
    {
        $this->actingAs($this->admin)->post('/app/sale-drafts', $this->draftPayload());
        $draft = SaleDraft::query()->firstOrFail();
        Cache::flush();

        $this->actingAs($this->admin)->post('/app/sales', [
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-01',
            'lines'       => [['item_id' => null, 'qty' => 2, 'unit_price' => 150]],
            'vat_rate'    => 0,
            'mode'        => 'later',
            'draft_id'    => $draft->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(0, SaleDraft::count(), 'The draft is still listed after it was recorded');
    }

    public function test_a_failed_sale_keeps_its_draft(): void
    {
        $this->actingAs($this->admin)->post('/app/sale-drafts', $this->draftPayload());
        $draft = SaleDraft::query()->firstOrFail();
        Cache::flush();

        // No price on the line → the sale is refused.
        $this->actingAs($this->admin)->post('/app/sales', [
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-01',
            'lines'       => [],
            'vat_rate'    => 0,
            'mode'        => 'later',
            'draft_id'    => $draft->id,
        ])->assertSessionHasErrors();

        $this->assertSame(1, SaleDraft::count());
    }

    public function test_another_company_cannot_touch_the_draft(): void
    {
        $this->actingAs($this->admin)->post('/app/sale-drafts', $this->draftPayload());
        $draft = SaleDraft::query()->firstOrFail();

        $otherCompany = Company::factory()->create();
        $outsider     = User::factory()->companyAdmin($otherCompany)->create();

        $this->actingAs($outsider)->delete("/app/sale-drafts/{$draft->id}")->assertNotFound();

        $this->assertSame(1, SaleDraft::withoutGlobalScopes()->count());
    }

    public function test_a_future_sale_date_is_explained_in_plain_words(): void
    {
        $this->actingAs($this->admin)->post('/app/sales', [
            'customer_id' => $this->customer->id,
            'date'        => now('Africa/Cairo')->addDays(3)->toDateString(),
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => 100]],
            'vat_rate'    => 0,
            'mode'        => 'later',
        ])->assertSessionHasErrors('date');

        $this->assertStringContainsString('Future dates are not allowed', session('errors')->get('date')[0]);
    }
}
