<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\OpeningBalance;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Opening balance — the one-time setup every new company does first.
//
//  This whole feature was dead on arrival: the controller type-hinted
//  App\Http\Requests\App\StoreOpeningBalanceRequest, and that class
//  did not exist anywhere in the project. Every submission raised a
//  "class not found" fatal before a single line of the service ran.
//
//  These tests pin down three things the missing class now decides:
//  who may post one, what shape the data must have, and that a form
//  left partly blank (which is the normal case — the screen ships one
//  empty row per section) still goes through.
// ══════════════════════════════════════════════════════════════════
class OpeningBalanceTest extends TestCase
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
    }

    /**
     * The payload the screen actually sends — note every section
     * carries one blank row, because OpeningBalance.vue seeds them.
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'opening_date' => now()->toDateString(),
            'cash_amount'  => null,
            'bank_amount'  => null,
            'customers'    => [['customer_id' => null, 'amount' => null]],
            'suppliers'    => [['vendor_id' => null, 'amount' => null]],
            'inventory'    => [['item_id' => null, 'qty' => null, 'unit_price' => null]],
            'equipment'    => [['name' => '', 'category_id' => null, 'amount' => null, 'date' => now()->toDateString()]],
        ], $overrides);
    }

    private function submit(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('app.opening-balance.store'), $this->payload($overrides));
    }

    // ── The class exists at all ──────────────────────────────────

    public function test_the_validation_class_the_controller_asks_for_exists(): void
    {
        $this->assertTrue(
            class_exists(\App\Http\Requests\App\StoreOpeningBalanceRequest::class),
            'OpeningBalanceController type-hints this class; without it every submission is a fatal error.'
        );
    }

    public function test_the_screen_loads(): void
    {
        $this->actingAs($this->admin)
            ->get(route('app.opening-balance.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Settings/OpeningBalance'));
    }

    // ── Posting ──────────────────────────────────────────────────

    public function test_cash_and_bank_are_recorded_and_posted_to_the_ledger(): void
    {
        $this->submit(['cash_amount' => 5000, 'bank_amount' => 12000])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('app.opening-balance.index'));

        $this->assertSame('posted', OpeningBalance::sole()->status);

        $payments = Payment::where('is_opening_balance', true)->get();
        $this->assertCount(2, $payments);
        $this->assertEqualsWithDelta(17000.0, (float) $payments->sum('amount'), 0.001);

        $cash = JournalEntry::with('lines.account')->get()->flatMap->lines
            ->filter(fn ($l) => $l->account->code === Account::CASH)
            ->sum(fn ($l) => (float) $l->debit - (float) $l->credit);

        $this->assertEqualsWithDelta(5000.0, $cash, 0.001);
    }

    public function test_a_form_left_entirely_blank_still_posts(): void
    {
        // The single blank row in each section is what the screen
        // always sends. Rejecting it would make the common "I only
        // have opening cash" case impossible.
        $this->submit(['cash_amount' => 1000])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Expense::count());
    }

    public function test_customers_who_already_owe_money_become_open_invoices(): void
    {
        $customer = Customer::create(['name' => 'Acme', 'company_id' => $this->company->id]);

        $this->submit(['customers' => [['customer_id' => $customer->id, 'amount' => 2500]]])
            ->assertSessionHasNoErrors();

        $sale = Sale::sole();

        $this->assertTrue((bool) $sale->is_opening_balance);
        $this->assertEqualsWithDelta(2500.0, (float) $sale->amount, 0.001);
        $this->assertEqualsWithDelta(2500.0, $sale->balance(), 0.001, 'It should read as fully unpaid.');
    }

    public function test_suppliers_still_owed_become_open_bills(): void
    {
        $vendor = Vendor::create(['name' => 'Supplier', 'company_id' => $this->company->id]);

        $this->submit(['suppliers' => [['vendor_id' => $vendor->id, 'amount' => 900]]])
            ->assertSessionHasNoErrors();

        $expense = Expense::sole();

        $this->assertTrue((bool) $expense->is_opening_balance);
        $this->assertEqualsWithDelta(900.0, (float) $expense->amount, 0.001);
    }

    public function test_starting_stock_is_recorded(): void
    {
        $item = Item::create([
            'company_id' => $this->company->id, 'name' => 'Cement',
            'uom' => 'Bag', 'qty_per_uom' => 1, 'base_unit_name' => 'Bag',
        ]);

        $this->submit(['inventory' => [['item_id' => $item->id, 'qty' => 40, 'unit_price' => 60]]])
            ->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(40.0, $item->fresh()->totalPurchasedBase(), 0.001);
    }

    public function test_equipment_already_owned_is_capitalised(): void
    {
        $category = Category::query()->equipmentKind()->firstOrFail();

        $this->submit(['equipment' => [[
            'name' => 'Delivery van', 'category_id' => $category->id,
            'amount' => 80000, 'date' => now()->subYear()->toDateString(),
        ]]])->assertSessionHasNoErrors();

        $asset = \App\Models\EquipmentPurchase::sole();

        $this->assertSame('Delivery van', $asset->name);
        $this->assertEqualsWithDelta(80000.0, (float) $asset->amount, 0.001);
        // Its real purchase date is kept so depreciation starts from
        // the right month, not from the opening date.
        $this->assertSame(now()->subYear()->toDateString(), $asset->date->toDateString());
    }

    public function test_the_whole_thing_posts_as_one_submission(): void
    {
        $customer = Customer::create(['name' => 'Acme', 'company_id' => $this->company->id]);
        $vendor   = Vendor::create(['name' => 'Supplier', 'company_id' => $this->company->id]);
        $item     = Item::create(['company_id' => $this->company->id, 'name' => 'Cement']);
        $category = Category::query()->equipmentKind()->firstOrFail();

        $this->submit([
            'cash_amount' => 3000,
            'customers'   => [['customer_id' => $customer->id, 'amount' => 1500]],
            'suppliers'   => [['vendor_id' => $vendor->id, 'amount' => 700]],
            'inventory'   => [['item_id' => $item->id, 'qty' => 10, 'unit_price' => 50]],
            'equipment'   => [['name' => 'Laptop', 'category_id' => $category->id, 'amount' => 20000, 'date' => null]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, Expense::count());
        $this->assertSame(1, \App\Models\InventoryPurchase::count());
        $this->assertSame(1, \App\Models\EquipmentPurchase::count());
        $this->assertSame('posted', OpeningBalance::sole()->status);
    }

    // ── Validation ───────────────────────────────────────────────

    public function test_an_amount_against_no_customer_is_refused(): void
    {
        // A partly-filled row is a mistake worth reporting, unlike a
        // wholly blank one which is just an untouched field.
        $this->submit(['customers' => [['customer_id' => null, 'amount' => 500]]])
            ->assertSessionHasErrors('customers.0.customer_id');

        $this->assertSame(0, Sale::count());
    }

    public function test_the_opening_date_is_required(): void
    {
        $this->submit(['opening_date' => null])->assertSessionHasErrors('opening_date');
    }

    public function test_another_companys_customer_cannot_be_used(): void
    {
        $other         = Company::factory()->create();
        $otherCustomer = Customer::create(['name' => 'Theirs', 'company_id' => $other->id]);

        $this->submit(['customers' => [['customer_id' => $otherCustomer->id, 'amount' => 100]]])
            ->assertSessionHasErrors('customers.0.customer_id');

        $this->assertSame(0, Sale::count());
    }

    public function test_another_companys_item_cannot_be_used(): void
    {
        $other     = Company::factory()->create();
        $theirItem = Item::create(['company_id' => $other->id, 'name' => 'Theirs']);

        $this->submit(['inventory' => [['item_id' => $theirItem->id, 'qty' => 5, 'unit_price' => 10]]])
            ->assertSessionHasErrors('inventory.0.item_id');
    }

    // ── Authorization ────────────────────────────────────────────

    public function test_an_employee_cannot_post_an_opening_balance(): void
    {
        // The screen hides the form from employees, but store() had no
        // check of its own — the rule lived only in the UI.
        $employee = User::factory()->employee($this->company)->create();

        $this->actingAs($employee)
            ->post(route('app.opening-balance.store'), $this->payload(['cash_amount' => 9999]))
            ->assertForbidden();

        $this->assertSame(0, OpeningBalance::count());
    }

    public function test_an_employee_cannot_reset_one_either(): void
    {
        $employee = User::factory()->employee($this->company)->create();

        $this->actingAs($employee)
            ->delete(route('app.opening-balance.reset'))
            ->assertForbidden();
    }

    // ── Posting twice ────────────────────────────────────────────

    public function test_resetting_clears_everything_and_reopens_the_form(): void
    {
        $customer = Customer::create(['name' => 'Acme', 'company_id' => $this->company->id]);

        $this->submit([
            'cash_amount' => 1000,
            'customers'   => [['customer_id' => $customer->id, 'amount' => 500]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::count());

        $this->actingAs($this->admin)
            ->delete(route('app.opening-balance.reset'))
            ->assertRedirect();

        $this->assertSame(0, Sale::count(), 'Reset should remove the opening-balance documents.');
        $this->assertSame(0, Payment::where('is_opening_balance', true)->count());

        $header = OpeningBalance::first();
        $this->assertTrue($header === null || ! $header->isPosted(), 'The form should be open for entry again.');
    }
}
