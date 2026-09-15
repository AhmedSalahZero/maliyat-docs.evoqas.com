<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The same submission must not be recorded twice.
//
//  Nothing stopped it. A double-click on "Record sale", a phone
//  resending on a flaky connection, or a second impatient tap while
//  the first request was in flight each wrote two invoices AND two
//  payments — the same money counted twice in the one place that
//  exists to count money correctly.
//
//  PreventDuplicateSubmission holds a fingerprint of (user + route +
//  body) for a few seconds and sends a repeat straight back. These
//  tests pin down both directions: the repeat is refused, and
//  everything that is NOT a repeat still goes through — a different
//  amount, a different user, a different screen, and the same entry
//  made again once the window has passed.
// ══════════════════════════════════════════════════════════════════
class DuplicateSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Customer $customer;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin   = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->actingAs($this->admin);

        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Acme']);
        $this->vendor   = Vendor::create(['company_id' => $this->company->id, 'name' => 'Supplies Co']);
    }

    private function salePayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-01',
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => 5000]],
            'vat_rate'    => 0,
            'mode'        => 'now',
            'method'      => 'cash',
        ], $overrides);
    }

    public function test_the_same_sale_submitted_twice_is_recorded_once(): void
    {
        $payload = $this->salePayload();

        $this->post('/app/sales', $payload)->assertSessionHasNoErrors();
        $this->post('/app/sales', $payload);

        $this->assertSame(1, Sale::count());
    }

    /**
     * The money side is the reason this matters — a duplicated sale
     * duplicates its payment too.
     */
    public function test_the_duplicate_does_not_double_the_cash(): void
    {
        $payload = $this->salePayload();

        $this->post('/app/sales', $payload);
        $this->post('/app/sales', $payload);

        $this->assertSame(1, Payment::count());
        $this->assertEquals(5000.0, (float) Payment::query()->firstOrFail()->amount);
    }

    public function test_the_user_is_told_why_nothing_was_saved(): void
    {
        $payload = $this->salePayload();

        $this->post('/app/sales', $payload);

        $this->post('/app/sales', $payload)
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_a_third_attempt_is_still_refused(): void
    {
        $payload = $this->salePayload();

        $this->post('/app/sales', $payload);
        $this->post('/app/sales', $payload);
        $this->post('/app/sales', $payload);

        $this->assertSame(1, Sale::count());
    }

    // ── What must still go through ───────────────────────────────

    public function test_a_different_amount_is_not_a_duplicate(): void
    {
        $this->post('/app/sales', $this->salePayload())->assertSessionHasNoErrors();

        $this->post('/app/sales', $this->salePayload([
            'lines' => [['item_id' => null, 'qty' => 1, 'unit_price' => 5001]],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2, Sale::count());
    }

    public function test_a_different_screen_is_not_a_duplicate(): void
    {
        $this->post('/app/sales', $this->salePayload())->assertSessionHasNoErrors();

        $this->post('/app/expenses', [
            'vendor_id'   => $this->vendor->id,
            'category_id' => Category::query()->where('company_id', $this->company->id)
                ->where('kind', 'expense')->firstOrFail()->id,
            'date'        => '2026-09-01',
            'amount'      => 5000,
            'mode'        => 'now',
            'method'      => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, Expense::count());
    }

    /**
     * Two people on the same company entering the same figure at the
     * same moment are two real entries, not one repeated.
     */
    public function test_another_user_entering_the_same_thing_is_not_a_duplicate(): void
    {
        $colleague = User::factory()->employee($this->company)->create();

        $this->actingAs($this->admin)->post('/app/sales', $this->salePayload())->assertSessionHasNoErrors();
        $this->actingAs($colleague)->post('/app/sales', $this->salePayload())->assertSessionHasNoErrors();

        $this->assertSame(2, Sale::count());
    }

    /**
     * Genuinely entering the same figure twice is a real thing —
     * two identical cash sales in a row. Once the window passes it
     * must go through.
     */
    public function test_the_same_entry_is_allowed_again_once_the_window_passes(): void
    {
        $payload = $this->salePayload();

        $this->post('/app/sales', $payload)->assertSessionHasNoErrors();
        $this->post('/app/sales', $payload);
        $this->assertSame(1, Sale::count());

        // The window is a short cache TTL; clearing it is the same
        // thing as waiting for it to lapse.
        Cache::flush();

        $this->post('/app/sales', $payload)->assertSessionHasNoErrors();
        $this->assertSame(2, Sale::count());
    }

    /**
     * Field order is not meaningful — a client that serialises the
     * same body differently must still be caught.
     */
    public function test_the_same_body_in_a_different_order_is_still_a_duplicate(): void
    {
        $this->post('/app/sales', [
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-01',
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => 5000]],
            'vat_rate'    => 0,
            'mode'        => 'later',
        ])->assertSessionHasNoErrors();

        $this->post('/app/sales', [
            'mode'        => 'later',
            'vat_rate'    => 0,
            'lines'       => [['unit_price' => 5000, 'qty' => 1, 'item_id' => null]],
            'date'        => '2026-09-01',
            'customer_id' => $this->customer->id,
        ]);

        $this->assertSame(1, Sale::count());
    }

    /**
     * Toggling a preference back and forth sends the same body twice
     * on purpose and records nothing new — it must not be blocked.
     */
    public function test_preference_toggles_are_exempt(): void
    {
        $this->patch('/app/preferences/theme', ['theme' => 'dark'])->assertSessionHasNoErrors();
        $this->patch('/app/preferences/theme', ['theme' => 'light'])->assertSessionHasNoErrors();
        $this->patch('/app/preferences/theme', ['theme' => 'dark'])->assertSessionHasNoErrors();

        $this->assertSame('dark', $this->admin->fresh()->theme);
    }

    /**
     * Deleting is left out of the guard on purpose — clearing
     * several rows quickly is normal, and a repeat of a delete is
     * already harmless.
     */
    public function test_deleting_is_not_blocked_by_the_guard(): void
    {
        $this->post('/app/sales', $this->salePayload())->assertSessionHasNoErrors();
        $this->post('/app/sales', $this->salePayload(['date' => '2026-09-02']))->assertSessionHasNoErrors();

        foreach (Sale::query()->pluck('id') as $id) {
            $this->delete("/app/sales/{$id}")->assertSessionHasNoErrors();
        }

        $this->assertSame(0, Sale::count());
    }
}
