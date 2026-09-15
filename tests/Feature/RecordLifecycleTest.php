<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Recording, correcting and un-recording money.
//
//  Two things are being protected here.
//
//  Atomicity: the invoice row, its ledger entries and the payment
//  taken at the same moment are one business fact. They used to be
//  written in three separate commits — the transaction closed before
//  any posting ran — so a failure part-way left a sale on the books
//  with nothing behind it in the general ledger.
//
//  Reversal: a payment entered by mistake can now be removed from the
//  record's own edit view. Removing it reverses the ledger entry
//  rather than deleting it, so the books keep the correction trail.
// ══════════════════════════════════════════════════════════════════
class RecordLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company  = Company::factory()->create();
        $this->user     = User::factory()->companyAdmin($this->company)->create();
        $this->customer = Customer::create(['name' => 'Acme', 'company_id' => $this->company->id]);

        app(JournalService::class)->seedChartOfAccounts($this->company);
    }

    private function salePayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'date'        => now()->toDateString(),
            'vat_rate'    => 0,
            'lines'       => [['item_id' => null, 'qty' => 2, 'unit_price' => 50]],
            'mode'        => 'now',
            'method'      => 'cash',
        ], $overrides);
    }

    // ── Atomicity ────────────────────────────────────────────────

    public function test_recording_a_sale_writes_the_invoice_its_ledger_entries_and_its_payment(): void
    {
        $this->actingAs($this->user)
            ->post(route('app.sales.store'), $this->salePayload())
            ->assertRedirect();

        $sale = Sale::sole();

        $this->assertEqualsWithDelta(100.0, (float) $sale->amount, 0.001);
        $this->assertSame(1, $sale->payments()->count(), 'The "pay now" payment should exist.');

        // Invoice entry + receipt entry.
        $this->assertSame(2, JournalEntry::where('company_id', $this->company->id)->count());
    }

    public function test_nothing_is_written_when_the_ledger_posting_fails(): void
    {
        // Make the last step of the sequence blow up. Everything
        // before it has already been written at that point, so this
        // is exactly the case the old code left half-committed.
        $this->app->bind(JournalService::class, fn () => new class extends JournalService
        {
            public function postSaleReceipt(Payment $payment): void
            {
                throw new \RuntimeException('simulated ledger failure');
            }
        });

        try {
            $this->withoutExceptionHandling()
                ->actingAs($this->user)
                ->post(route('app.sales.store'), $this->salePayload());

            $this->fail('The simulated failure should have propagated.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated ledger failure', $e->getMessage());
        }

        $this->assertSame(0, Sale::count(), 'The sale row survived a failed posting.');
        $this->assertSame(0, Payment::count(), 'A payment row survived a failed posting.');
        $this->assertSame(0, JournalEntry::count(), 'A journal entry survived a failed posting.');
        $this->assertDatabaseCount('sale_lines', 0);
    }

    // ── Removing a payment ───────────────────────────────────────

    public function test_removing_a_payment_restores_the_balance_and_reverses_the_ledger(): void
    {
        $this->actingAs($this->user)
            ->post(route('app.sales.store'), $this->salePayload([
                'mode' => 'partial', 'amount_now' => 40, 'due_in_days' => 30,
            ]))
            ->assertRedirect();

        $sale    = Sale::sole();
        $payment = $sale->payments()->sole();

        $this->assertEqualsWithDelta(60.0, $sale->balance(), 0.001, 'Precondition: 40 of 100 paid.');

        $entriesForPayment = fn () => JournalEntry::where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->count();

        $this->assertSame(1, $entriesForPayment());

        $this->actingAs($this->user)
            ->delete(route('app.payments.destroy', $payment->id))
            ->assertRedirect();

        $sale->refresh();

        $this->assertNull(Payment::find($payment->id), 'The payment row should be gone.');
        $this->assertEqualsWithDelta(100.0, $sale->balance(), 0.001, 'The full amount should be owed again.');

        // The original entry is kept and a reversal added — the
        // ledger records the correction instead of hiding it.
        $this->assertSame(2, $entriesForPayment(), 'Expected the original entry plus its reversal.');
    }

    public function test_the_reversal_cancels_out_the_original_entry(): void
    {
        $this->actingAs($this->user)
            ->post(route('app.sales.store'), $this->salePayload())
            ->assertRedirect();

        $payment = Payment::sole();

        $netFor = function (int $paymentId): float {
            $entries = JournalEntry::with('lines')
                ->where('source_type', Payment::class)
                ->where('source_id', $paymentId)
                ->get();

            return (float) $entries->flatMap->lines->sum(fn ($line) => (float) $line->debit - (float) $line->credit);
        };

        $this->actingAs($this->user)->delete(route('app.payments.destroy', $payment->id));

        $this->assertEqualsWithDelta(
            0.0,
            $netFor($payment->id),
            0.001,
            'Entry plus reversal should net to zero across every account.'
        );
    }

    public function test_a_payment_belonging_to_another_company_cannot_be_removed(): void
    {
        $otherCompany  = Company::factory()->create();
        $otherCustomer = Customer::create(['name' => 'Theirs', 'company_id' => $otherCompany->id]);

        $theirSale = Sale::create([
            'company_id' => $otherCompany->id, 'customer_id' => $otherCustomer->id,
            'date' => now()->toDateString(), 'subtotal' => 100, 'vat_rate' => 0,
            'vat_amount' => 0, 'amount' => 100,
        ]);
        $theirPayment = $theirSale->payments()->create([
            'company_id' => $otherCompany->id, 'date' => now()->toDateString(),
            'amount' => 100, 'direction' => 'in', 'method' => 'cash',
        ]);

        $this->actingAs($this->user)
            ->delete(route('app.payments.destroy', $theirPayment->id))
            ->assertNotFound();

        $this->assertNotNull(Payment::withoutGlobalScopes()->find($theirPayment->id));
    }

    // ── Editing ──────────────────────────────────────────────────

    public function test_the_due_date_can_be_corrected_after_the_fact(): void
    {
        $this->actingAs($this->user)
            ->post(route('app.sales.store'), $this->salePayload([
                'mode' => 'later', 'due_in_days' => 30,
            ]))
            ->assertRedirect();

        $sale = Sale::sole();
        $this->assertNotNull($sale->due_date, 'Precondition: the sale has a due date.');

        $corrected = now()->addDays(90)->toDateString();

        $this->actingAs($this->user)
            ->put(route('app.sales.update', $sale->id), [
                'customer_id' => $this->customer->id,
                'date'        => now()->toDateString(),
                'vat_rate'    => 0,
                'lines'       => [['item_id' => null, 'qty' => 2, 'unit_price' => 50]],
                'due_date'    => $corrected,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame($corrected, $sale->fresh()->due_date->toDateString());
    }

    public function test_a_due_date_can_be_cleared_entirely(): void
    {
        $this->actingAs($this->user)
            ->post(route('app.sales.store'), $this->salePayload(['mode' => 'later', 'due_in_days' => 30]))
            ->assertRedirect();

        $sale = Sale::sole();

        $this->actingAs($this->user)
            ->put(route('app.sales.update', $sale->id), [
                'customer_id' => $this->customer->id,
                'date'        => now()->toDateString(),
                'vat_rate'    => 0,
                'lines'       => [['item_id' => null, 'qty' => 2, 'unit_price' => 50]],
                'due_date'    => null,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($sale->fresh()->due_date, 'A record must be able to go back to having no due date.');
    }

    public function test_a_due_date_before_the_document_date_is_refused(): void
    {
        $this->actingAs($this->user)
            ->post(route('app.sales.store'), $this->salePayload(['mode' => 'later', 'due_in_days' => 30]))
            ->assertRedirect();

        $sale = Sale::sole();

        $this->actingAs($this->user)
            ->put(route('app.sales.update', $sale->id), [
                'customer_id' => $this->customer->id,
                'date'        => now()->toDateString(),
                'vat_rate'    => 0,
                'lines'       => [['item_id' => null, 'qty' => 2, 'unit_price' => 50]],
                'due_date'    => now()->subWeek()->toDateString(),
            ])
            ->assertSessionHasErrors('due_date');
    }

    public function test_omitting_the_due_date_leaves_the_existing_one_alone(): void
    {
        $this->actingAs($this->user)
            ->post(route('app.sales.store'), $this->salePayload(['mode' => 'later', 'due_in_days' => 30]))
            ->assertRedirect();

        $sale     = Sale::sole();
        $original = $sale->due_date->toDateString();

        // An older client that doesn't send the field must not wipe it.
        $this->actingAs($this->user)
            ->put(route('app.sales.update', $sale->id), [
                'customer_id' => $this->customer->id,
                'date'        => now()->toDateString(),
                'vat_rate'    => 0,
                'lines'       => [['item_id' => null, 'qty' => 2, 'unit_price' => 50]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($original, $sale->fresh()->due_date->toDateString());
    }

    public function test_editing_a_sale_keeps_the_ledger_in_step_with_the_new_total(): void
    {
        $this->actingAs($this->user)
            ->post(route('app.sales.store'), $this->salePayload(['mode' => 'later', 'due_in_days' => 30]))
            ->assertRedirect();

        $sale = Sale::sole();

        $this->actingAs($this->user)
            ->put(route('app.sales.update', $sale->id), [
                'customer_id' => $this->customer->id,
                'date'        => now()->toDateString(),
                'vat_rate'    => 0,
                'lines'       => [['item_id' => null, 'qty' => 4, 'unit_price' => 50]],
            ])
            ->assertRedirect();

        $sale->refresh();

        $this->assertEqualsWithDelta(200.0, (float) $sale->amount, 0.001);

        // Receivable across every entry for this sale should equal
        // the corrected total: original 100, reversed -100, new 200.
        $receivable = JournalEntry::with('lines.account')
            ->where('source_type', Sale::class)
            ->where('source_id', $sale->id)
            ->get()
            ->flatMap->lines
            ->filter(fn ($line) => $line->account->code === \App\Models\Account::ACCOUNTS_RECEIVABLE)
            ->sum(fn ($line) => (float) $line->debit - (float) $line->credit);

        $this->assertEqualsWithDelta(200.0, $receivable, 0.001);
    }
}
