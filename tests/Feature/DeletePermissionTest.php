<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Custody;
use App\Models\Customer;
use App\Models\EquipmentPurchase;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Deleting a financial record is company-admin only.
//
//  Every destroy() in the app used to run with no authorization
//  check of any kind — no form request, no abort_unless, and no
//  role test in the Vue page either. Any employee could permanently
//  remove any sale, expense, purchase, custody or payment in their
//  company, and deleting a sale takes its payments with it: the cash
//  that came in disappears alongside the invoice. There is no soft
//  delete and no audit table, so nothing recorded that it happened
//  or who did it.
//
//  Editing is deliberately still open to employees — an edit leaves
//  a full reversal trail in the general ledger and never touches
//  payments, so it is visible and recoverable. Delete is neither.
//  These tests assert both halves of that, so a later change cannot
//  quietly widen one or narrow the other.
//
//  See Controller::authorizeDelete() for the rule itself.
// ══════════════════════════════════════════════════════════════════
class DeletePermissionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $employee;

    private Customer $customer;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company  = Company::factory()->create();
        $this->admin    = User::factory()->companyAdmin($this->company)->create();
        $this->employee = User::factory()->employee($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Acme']);
        $this->vendor   = Vendor::create(['company_id' => $this->company->id, 'name' => 'Supplies Co']);
    }

    private function category(string $kind): Category
    {
        return Category::query()->where('company_id', $this->company->id)
            ->where('kind', $kind)->firstOrFail();
    }

    // ── Fixtures, each created by the admin ──────────────────────

    private function makePaidSale(): Sale
    {
        $this->actingAs($this->admin)->post('/app/sales', [
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-01',
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => 5000]],
            'vat_rate'    => 0,
            'mode'        => 'now',
            'method'      => 'cash',
        ])->assertSessionHasNoErrors();

        return Sale::query()->latest('id')->firstOrFail();
    }

    private function makeExpense(): Expense
    {
        $this->actingAs($this->admin)->post('/app/expenses', [
            'vendor_id'   => $this->vendor->id,
            'category_id' => $this->category('expense')->id,
            'date'        => '2026-09-01',
            'amount'      => 400,
            'mode'        => 'later',
        ])->assertSessionHasNoErrors();

        return Expense::query()->latest('id')->firstOrFail();
    }

    private function makeInventoryPurchase(): InventoryPurchase
    {
        $item = Item::create([
            'company_id'     => $this->company->id,
            'name'           => 'Widget',
            'qty_per_uom'    => 1,
            'base_unit_name' => 'pc',
        ]);

        $this->actingAs($this->admin)->post('/app/inventory-purchases', [
            'vendor_id' => $this->vendor->id,
            'date'      => '2026-09-01',
            'lines'     => [['item_id' => $item->id, 'qty' => 10, 'qty_per_uom' => 1, 'unit_price' => 25]],
            'vat_rate'  => 0,
            'mode'      => 'later',
        ])->assertSessionHasNoErrors();

        return InventoryPurchase::query()->latest('id')->firstOrFail();
    }

    private function makeEquipmentPurchase(): EquipmentPurchase
    {
        $this->actingAs($this->admin)->post('/app/equipment-purchases', [
            'vendor_id'   => $this->vendor->id,
            'category_id' => $this->category('equipment')->id,
            'name'        => 'Delivery van',
            'date'        => '2026-09-01',
            'qty'         => 1,
            'unit_price'  => 90000,
            'mode'        => 'later',
        ])->assertSessionHasNoErrors();

        return EquipmentPurchase::query()->latest('id')->firstOrFail();
    }

    private function makeCustody(): Custody
    {
        $this->actingAs($this->admin)->post('/app/custodies', [
            'holder_id' => $this->vendor->id,
            'amount'    => 1000,
            'method'    => 'cash',
            'given_at'  => '2026-09-01',
        ])->assertSessionHasNoErrors();

        return Custody::query()->latest('id')->firstOrFail();
    }

    // ── An employee is refused, and nothing is removed ───────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function deleteRoutes(): array
    {
        return [
            'sale'               => ['sale'],
            'expense'            => ['expense'],
            'inventory purchase' => ['inventory_purchase'],
            'equipment purchase' => ['equipment_purchase'],
            'custody'            => ['custody'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deleteRoutes')]
    public function test_an_employee_cannot_delete_a_financial_record(string $kind): void
    {
        [$url, $model] = match ($kind) {
            'sale'               => ['/app/sales/'.$this->makePaidSale()->id, Sale::class],
            'expense'            => ['/app/expenses/'.$this->makeExpense()->id, Expense::class],
            'inventory_purchase' => ['/app/inventory-purchases/'.$this->makeInventoryPurchase()->id, InventoryPurchase::class],
            'equipment_purchase' => ['/app/equipment-purchases/'.$this->makeEquipmentPurchase()->id, EquipmentPurchase::class],
            'custody'            => ['/app/custodies/'.$this->makeCustody()->id, Custody::class],
        };

        $this->actingAs($this->employee)->delete($url)->assertForbidden();

        $this->assertSame(1, $model::count(), 'The record survives the refused delete');
    }

    public function test_an_employee_cannot_delete_a_payment(): void
    {
        $sale = $this->makePaidSale();
        $payment = $sale->payments()->firstOrFail();

        $this->actingAs($this->employee)
            ->delete("/app/payments/{$payment->id}")
            ->assertForbidden();

        $this->assertSame(1, Payment::count());
    }

    public function test_an_employee_cannot_cancel_a_recurring_series(): void
    {
        $this->actingAs($this->admin)->post('/app/expenses/recurring', [
            'vendor_id'   => $this->vendor->id,
            'category_id' => $this->category('expense')->id,
            'date'        => '2026-09-01',
            'amount'      => 500,
            'mode'        => 'later',
            'frequency'   => 'monthly',
            'count'       => 3,
        ])->assertSessionHasNoErrors();

        $recurringId = Expense::query()->whereNotNull('recurring_id')->firstOrFail()->recurring_id;
        $before      = Expense::count();

        $this->actingAs($this->employee)
            ->delete("/app/expenses/recurring/{$recurringId}")
            ->assertForbidden();

        $this->assertSame($before, Expense::count());
    }

    /**
     * Deleting a sale is what takes its cash with it — the case the
     * missing check made most expensive.
     */
    public function test_a_refused_delete_leaves_the_cash_history_intact(): void
    {
        $sale = $this->makePaidSale();

        $this->actingAs($this->employee)->delete("/app/sales/{$sale->id}")->assertForbidden();

        $this->assertSame(1, Payment::count());
        $this->assertEquals(5000.0, (float) Payment::query()->firstOrFail()->amount);
    }

    // ── A company admin is allowed ───────────────────────────────

    public function test_a_company_admin_can_delete_a_sale(): void
    {
        $sale = $this->makePaidSale();

        $this->actingAs($this->admin)
            ->delete("/app/sales/{$sale->id}")
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_a_company_admin_can_delete_a_payment(): void
    {
        $sale    = $this->makePaidSale();
        $payment = $sale->payments()->firstOrFail();

        $this->actingAs($this->admin)
            ->delete("/app/payments/{$payment->id}")
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Payment::count());
        $this->assertSame(1, Sale::count(), 'Removing a payment leaves the invoice');
    }

    // ── Editing stays open to employees ──────────────────────────

    public function test_an_employee_can_still_create_a_sale(): void
    {
        $this->actingAs($this->employee)->post('/app/sales', [
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-01',
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => 250]],
            'vat_rate'    => 0,
            'mode'        => 'later',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::count());
    }

    public function test_an_employee_can_still_edit_a_sale(): void
    {
        $sale = $this->makePaidSale();

        $this->actingAs($this->employee)->put("/app/sales/{$sale->id}", [
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-01',
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => 5500]],
            'vat_rate'    => 0,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(5500.0, (float) $sale->fresh()->amount);
    }

    public function test_an_employee_can_still_record_a_receipt(): void
    {
        $this->actingAs($this->employee)->post('/app/payments/receive', [
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-05',
            'amount'      => 300,
            'method'      => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Payment::count());
    }

    // ── The rule is about role, not about company ────────────────

    public function test_an_admin_of_another_company_gets_a_404_not_a_403(): void
    {
        $sale = $this->makePaidSale();

        $otherCompany = Company::factory()->create();
        $otherAdmin   = User::factory()->companyAdmin($otherCompany)->create();

        // The company scope hides the row entirely, so route-model
        // binding never resolves it — the tenant boundary answers
        // first and the role check is never reached.
        $this->actingAs($otherAdmin)
            ->delete("/app/sales/{$sale->id}")
            ->assertNotFound();

        // Counted back as the owning admin — Sale::count() is itself
        // scoped to whoever is acting, so asking as the outsider
        // would return 0 whether or not the row survived.
        $this->actingAs($this->admin);
        $this->assertSame(1, Sale::count());
    }
}
