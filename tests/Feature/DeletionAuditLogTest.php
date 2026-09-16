<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeletionLog;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  QA audit (Sep 2026), Finding 2: deleting a financial record used
//  to erase it completely with nothing anywhere saying who did it or
//  what it contained. This test protects the fix: every destroy()
//  must write a deletion_logs row, in the SAME request/transaction
//  as the delete, containing who deleted it and a snapshot of what
//  it was — and that row must survive even though the underlying
//  record is gone.
// ══════════════════════════════════════════════════════════════════
class DeletionAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin    = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
    }

    public function test_deleting_a_sale_writes_a_deletion_log_before_the_row_is_gone(): void
    {
        $customer = Customer::create(['name' => 'Acme', 'company_id' => $this->company->id]);

        $sale = Sale::create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id,
            'date' => now()->toDateString(), 'subtotal' => 500, 'vat_rate' => 0,
            'vat_amount' => 0, 'amount' => 500, 'created_by' => $this->admin->id,
        ]);
        $sale->lines()->create(['item_id' => null, 'qty' => 1, 'unit_price' => 500, 'line_total' => 500]);

        $this->actingAs($this->admin)
            ->delete(route('app.sales.destroy', $sale))
            ->assertRedirect();

        $this->assertDatabaseMissing('sales', ['id' => $sale->id]);

        $log = DeletionLog::sole();

        $this->assertSame($this->company->id, $log->company_id);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(Sale::class, $log->model_type);
        $this->assertSame($sale->id, $log->model_id);
        $this->assertStringContainsString('Acme', $log->summary);
        $this->assertEqualsWithDelta(500.0, (float) $log->payload['amount'], 0.001,
            'The snapshot must capture the amount as it stood before deletion.');
        $this->assertCount(1, $log->payload['_related']['lines'] ?? [],
            'A sale\'s line items are deleted separately from the row itself, so the snapshot must capture them explicitly.');
    }

    public function test_deleting_an_expense_writes_a_deletion_log(): void
    {
        $vendor   = Vendor::create(['name' => 'Nile Supplies', 'company_id' => $this->company->id]);
        $category = \App\Models\Category::create(['name' => 'Rent', 'kind' => 'expense', 'company_id' => $this->company->id]);
        $expense  = Expense::create([
            'company_id' => $this->company->id, 'vendor_id' => $vendor->id, 'category_id' => $category->id,
            'date' => now()->toDateString(), 'amount' => 250,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('app.expenses.destroy', $expense))
            ->assertRedirect();

        $log = DeletionLog::sole();

        $this->assertSame(Expense::class, $log->model_type);
        $this->assertStringContainsString('Nile Supplies', $log->summary);
    }

    public function test_a_regular_employee_cannot_delete_at_all_so_nothing_is_logged(): void
    {
        $employee = User::factory()->employee($this->company)->create();

        $customer = Customer::create(['name' => 'Acme', 'company_id' => $this->company->id]);
        $sale      = Sale::create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id,
            'date' => now()->toDateString(), 'subtotal' => 100, 'vat_rate' => 0,
            'vat_amount' => 0, 'amount' => 100,
        ]);

        $this->actingAs($employee)
            ->delete(route('app.sales.destroy', $sale))
            ->assertForbidden();

        $this->assertDatabaseHas('sales', ['id' => $sale->id]);
        $this->assertSame(0, DeletionLog::count());
    }

    public function test_a_deletion_log_survives_even_though_the_deleted_record_is_gone(): void
    {
        $customer = Customer::create(['name' => 'Acme', 'company_id' => $this->company->id]);
        $sale      = Sale::create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id,
            'date' => now()->toDateString(), 'subtotal' => 100, 'vat_rate' => 0,
            'vat_amount' => 0, 'amount' => 100,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('app.sales.destroy', $sale))
            ->assertRedirect();

        $this->assertDatabaseMissing('sales', ['id' => $sale->id]);
        $this->assertDatabaseHas('deletion_logs', ['model_type' => Sale::class, 'model_id' => $sale->id]);
    }
}
