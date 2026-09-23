<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Things that could be created but never corrected.
//
//  Three gaps, all the same shape: the record had a store route and
//  no update route at all, so a typo entered once was permanent.
//
//    • An item's name and unit setup.
//    • An employee's name, email and password.
//    • A payment's date, amount, method and channel — previously only
//      deletable, which left two reversal entries in the ledger for
//      what was really one typo.
//
//  The payment case carries the most weight: correcting one must
//  leave the general ledger agreeing with the document, which means
//  reversing the old entry and posting a fresh one rather than
//  quietly editing history.
// ══════════════════════════════════════════════════════════════════
class EditableRecordsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin   = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
    }

    // ══════════════════════════════════════════════════════════════
    //  Items
    // ══════════════════════════════════════════════════════════════

    private function item(array $attributes = []): Item
    {
        return Item::create(array_merge([
            'company_id'     => $this->company->id,
            'name'           => 'Cement',
            'uom'            => 'Bag',
            'qty_per_uom'    => 1,
            'base_unit_name' => 'Bag',
        ], $attributes));
    }

    /**
     * FIXED (QA audit, Sep 2026): ItemController::update() deliberately
     * answers two ways — JSON (200) for a plain request, a redirect
     * for a real Inertia form submission (see its own comment). This
     * test asserted a redirect without sending the X-Inertia header
     * that triggers that path, so it was checking for behavior it
     * never actually asked for. Pre-existing, unrelated to anything
     * else fixed in this pass.
     */
    public function test_an_items_name_can_be_corrected(): void
    {
        $item = $this->item(['name' => 'Cemnet']);   // typo

        $this->actingAs($this->admin)
            ->patch(route('app.items.update', $item->id), [
                'name'           => 'Cement',
                'uom'            => 'Bag',
                'qty_per_uom'    => 1,
                'base_unit_name' => 'Bag',
            ], ['X-Inertia' => 'true'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('Cement', $item->fresh()->name);
    }

    public function test_the_unit_setup_can_be_corrected(): void
    {
        $item = $this->item();

        $this->actingAs($this->admin)
            ->patch(route('app.items.update', $item->id), [
                'name'           => 'Cement',
                'uom'            => 'Pallet',
                'qty_per_uom'    => 40,
                'base_unit_name' => 'Bag',
            ])
            ->assertSessionHasNoErrors();

        $item->refresh();

        $this->assertSame('Pallet', $item->uom);
        $this->assertEqualsWithDelta(40.0, (float) $item->qty_per_uom, 0.001);
        $this->assertSame('Bag', $item->base_unit_name);
    }

    public function test_an_item_needs_a_name(): void
    {
        $item = $this->item();

        $this->actingAs($this->admin)
            ->patch(route('app.items.update', $item->id), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame('Cement', $item->fresh()->name);
    }

    public function test_another_companys_item_cannot_be_touched(): void
    {
        $other     = Company::factory()->create();
        $theirItem = Item::create(['company_id' => $other->id, 'name' => 'Theirs']);

        $this->actingAs($this->admin)
            ->patch(route('app.items.update', $theirItem->id), ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Theirs', Item::withoutGlobalScopes()->find($theirItem->id)->name);
    }

    // ══════════════════════════════════════════════════════════════
    //  Employees
    // ══════════════════════════════════════════════════════════════

    public function test_an_employees_name_and_email_can_be_corrected(): void
    {
        $employee = User::factory()->employee($this->company)->create([
            'name' => 'Ahmad', 'email' => 'wrong@example.test',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('app.team.update', $employee->id), [
                'name'  => 'Ahmed Salah',
                'email' => 'ahmed@example.test',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $employee->refresh();

        $this->assertSame('Ahmed Salah', $employee->name);
        $this->assertSame('ahmed@example.test', $employee->email);
    }

    public function test_leaving_the_password_blank_leaves_it_alone(): void
    {
        $employee = User::factory()->employee($this->company)->create();

        $this->actingAs($this->admin)
            ->patch(route('app.team.update', $employee->id), [
                'name'  => 'Renamed',
                'email' => $employee->email,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(
            Hash::check('password', $employee->fresh()->password),
            'An edit that did not mention the password should not have changed it.'
        );
    }

    public function test_a_password_can_be_reset_when_given(): void
    {
        $employee = User::factory()->employee($this->company)->create();

        $this->actingAs($this->admin)
            ->patch(route('app.team.update', $employee->id), [
                'name'                  => $employee->name,
                'email'                 => $employee->email,
                'password'              => 'Fresh-secret1',
                'password_confirmation' => 'Fresh-secret1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Fresh-secret1', $employee->fresh()->password));
    }

    public function test_a_weak_password_is_refused(): void
    {
        $employee = User::factory()->employee($this->company)->create();

        $this->actingAs($this->admin)
            ->patch(route('app.team.update', $employee->id), [
                'name'                  => $employee->name,
                'email'                 => $employee->email,
                'password'              => 'lettersonly',
                'password_confirmation' => 'lettersonly',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $employee->fresh()->password));
    }

    public function test_an_email_already_taken_is_refused(): void
    {
        $taken    = User::factory()->employee($this->company)->create(['email' => 'taken@example.test']);
        $employee = User::factory()->employee($this->company)->create();

        $this->actingAs($this->admin)
            ->patch(route('app.team.update', $employee->id), [
                'name' => $employee->name, 'email' => 'taken@example.test',
            ])
            ->assertSessionHasErrors('email');

        $this->assertNotSame('taken@example.test', $employee->fresh()->email);
        $this->assertSame('taken@example.test', $taken->fresh()->email);
    }

    public function test_keeping_your_own_email_is_not_a_duplicate(): void
    {
        $employee = User::factory()->employee($this->company)->create();

        $this->actingAs($this->admin)
            ->patch(route('app.team.update', $employee->id), [
                'name' => 'New Name', 'email' => $employee->email,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('New Name', $employee->fresh()->name);
    }

    public function test_an_employee_cannot_edit_a_colleague(): void
    {
        $employee  = User::factory()->employee($this->company)->create();
        $colleague = User::factory()->employee($this->company)->create(['name' => 'Untouched']);

        $this->actingAs($employee)
            ->patch(route('app.team.update', $colleague->id), [
                'name' => 'Hijacked', 'email' => $colleague->email,
            ])
            ->assertForbidden();

        $this->assertSame('Untouched', $colleague->fresh()->name);
    }

    public function test_an_admin_cannot_edit_someone_in_another_company(): void
    {
        $other     = Company::factory()->create();
        $outsider  = User::factory()->employee($other)->create(['name' => 'Outsider']);

        $this->actingAs($this->admin)
            ->patch(route('app.team.update', $outsider->id), [
                'name' => 'Hijacked', 'email' => $outsider->email,
            ])
            ->assertForbidden();

        $this->assertSame('Outsider', $outsider->fresh()->name);
    }

    // ══════════════════════════════════════════════════════════════
    //  Payments
    // ══════════════════════════════════════════════════════════════

    private function saleWithPayment(float $total = 100, float $paid = 40): array
    {
        $customer = Customer::create(['name' => 'Acme', 'company_id' => $this->company->id]);

        $sale = Sale::create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id,
            'date' => now()->toDateString(), 'subtotal' => $total, 'vat_rate' => 0,
            'vat_amount' => 0, 'amount' => $total, 'created_by' => $this->admin->id,
        ]);
        app(JournalService::class)->postSaleInvoice($sale);

        $payment = $sale->payments()->create([
            'company_id' => $this->company->id, 'date' => now()->toDateString(),
            'amount' => $paid, 'direction' => 'in', 'method' => 'cash',
        ]);
        app(JournalService::class)->postSaleReceipt($payment);

        return [$sale, $payment];
    }

    public function test_a_payments_amount_can_be_corrected_in_place(): void
    {
        [$sale, $payment] = $this->saleWithPayment(100, 40);

        $this->assertEqualsWithDelta(60.0, $sale->balance(), 0.001, 'Precondition.');

        $this->actingAs($this->admin)
            ->patch(route('app.payments.update', $payment->id), [
                'date'   => now()->toDateString(),
                'amount' => 70,
                'method' => 'cash',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertEqualsWithDelta(70.0, (float) $payment->fresh()->amount, 0.001);
        $this->assertEqualsWithDelta(30.0, $sale->fresh()->balance(), 0.001, 'The balance must follow the correction.');
    }

    public function test_the_correction_leaves_the_ledger_agreeing_with_the_document(): void
    {
        [$sale, $payment] = $this->saleWithPayment(100, 40);

        $this->actingAs($this->admin)
            ->patch(route('app.payments.update', $payment->id), [
                'date' => now()->toDateString(), 'amount' => 70, 'method' => 'cash',
            ]);

        // Original + reversal + corrected = the cash side should net
        // to exactly the corrected figure, not 40, and not 110.
        $cash = JournalEntry::with('lines.account')
            ->where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->get()
            ->flatMap->lines
            ->filter(fn ($line) => $line->account->code === Account::CASH)
            ->sum(fn ($line) => (float) $line->debit - (float) $line->credit);

        $this->assertEqualsWithDelta(70.0, $cash, 0.001);
    }

    public function test_the_original_entry_is_reversed_not_edited(): void
    {
        [, $payment] = $this->saleWithPayment();

        $this->actingAs($this->admin)
            ->patch(route('app.payments.update', $payment->id), [
                'date' => now()->toDateString(), 'amount' => 70, 'method' => 'cash',
            ]);

        // One original, one reversal, one corrected.
        $this->assertSame(
            3,
            JournalEntry::where('source_type', Payment::class)->where('source_id', $payment->id)->count(),
            'The ledger should record the correction, not hide it.'
        );
    }

    public function test_the_method_and_date_can_be_corrected(): void
    {
        [, $payment] = $this->saleWithPayment();

        $corrected = now()->subDays(3)->toDateString();

        $this->actingAs($this->admin)
            ->patch(route('app.payments.update', $payment->id), [
                'date' => $corrected, 'amount' => 40, 'method' => 'bank',
            ])
            ->assertSessionHasNoErrors();

        $payment->refresh();

        $this->assertSame('bank', $payment->method);
        $this->assertSame($corrected, $payment->date->toDateString());
    }

    public function test_switching_method_moves_the_money_to_the_right_account(): void
    {
        [, $payment] = $this->saleWithPayment(100, 40);

        $this->actingAs($this->admin)
            ->patch(route('app.payments.update', $payment->id), [
                'date' => now()->toDateString(), 'amount' => 40, 'method' => 'bank',
            ]);

        $net = fn (string $code) => JournalEntry::with('lines.account')
            ->where('source_type', Payment::class)->where('source_id', $payment->id)
            ->get()->flatMap->lines
            ->filter(fn ($line) => $line->account->code === $code)
            ->sum(fn ($line) => (float) $line->debit - (float) $line->credit);

        $this->assertEqualsWithDelta(0.0, $net(Account::CASH), 0.001, 'Cash should be back to nothing.');
        $this->assertEqualsWithDelta(40.0, $net(Account::BANK), 0.001, 'The money should now sit in the bank.');
    }

    public function test_a_zero_amount_is_refused(): void
    {
        [, $payment] = $this->saleWithPayment(100, 40);

        $this->actingAs($this->admin)
            ->patch(route('app.payments.update', $payment->id), [
                'date' => now()->toDateString(), 'amount' => 0, 'method' => 'cash',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertEqualsWithDelta(40.0, (float) $payment->fresh()->amount, 0.001);
    }

    public function test_another_companys_payment_cannot_be_corrected(): void
    {
        $other         = Company::factory()->create();
        $otherCustomer = Customer::create(['name' => 'Theirs', 'company_id' => $other->id]);
        $theirSale     = Sale::create([
            'company_id' => $other->id, 'customer_id' => $otherCustomer->id,
            'date' => now()->toDateString(), 'subtotal' => 100, 'vat_rate' => 0,
            'vat_amount' => 0, 'amount' => 100,
        ]);
        $theirPayment = $theirSale->payments()->create([
            'company_id' => $other->id, 'date' => now()->toDateString(),
            'amount' => 100, 'direction' => 'in', 'method' => 'cash',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('app.payments.update', $theirPayment->id), [
                'date' => now()->toDateString(), 'amount' => 1, 'method' => 'cash',
            ])
            ->assertNotFound();

        $this->assertEqualsWithDelta(
            100.0,
            (float) Payment::withoutGlobalScopes()->find($theirPayment->id)->amount,
            0.001
        );
    }
}
