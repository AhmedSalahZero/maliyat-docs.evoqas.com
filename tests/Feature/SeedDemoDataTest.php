<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  `php artisan demo:seed` / `demo:seed --clear`
//
//  QA audit (Sep 2026), Finding 4: this command runs unauthenticated
//  from the CLI, so — like RunDepreciation — the BelongsToCompany
//  global scope has nothing to key off, and every query inside the
//  command passes company_id through by hand (see its own doc
//  comment). That's correct today; this test is the regression guard.
//
//  Two things are being protected:
//    • Seeding company A must never create rows against company B,
//      even when B happens to have a lower id / was created first.
//    • --clear must remove only what THIS company's run created,
//      never another company's real data — including another
//      company's own customer/vendor/item rows that happen to share
//      one of the fixed demo names (e.g. two companies each with a
//      real customer literally named "Northwind Trading").
// ══════════════════════════════════════════════════════════════════
class SeedDemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_one_company_creates_nothing_for_another(): void
    {
        $mine  = Company::factory()->create();
        $other = Company::factory()->create();

        Artisan::call('demo:seed', ['--company' => $mine->id, '--sales' => 10]);

        $this->assertGreaterThan(0, Sale::where('company_id', $mine->id)->count());
        $this->assertSame(0, Sale::where('company_id', $other->id)->count(),
            'Seeding one company must not create any sales for another.');
        $this->assertSame(0, Customer::where('company_id', $other->id)->count());
        $this->assertSame(0, Vendor::where('company_id', $other->id)->count());
        $this->assertSame(0, Item::where('company_id', $other->id)->count());
    }

    public function test_clearing_one_companys_demo_data_leaves_a_same_named_real_record_in_another_company_untouched(): void
    {
        $mine  = Company::factory()->create();
        $other = Company::factory()->create();

        Artisan::call('demo:seed', ['--company' => $mine->id, '--sales' => 5]);

        // A genuinely different business that happens to have a real
        // customer with the exact same name as one of the fixed demo
        // names this command uses.
        $theirRealCustomer = Customer::create([
            'company_id' => $other->id,
            'name'       => 'Northwind Trading',
        ]);
        $theirRealSale = Sale::create([
            'company_id'  => $other->id,
            'customer_id' => $theirRealCustomer->id,
            'date'        => now()->toDateString(),
            'subtotal'    => 12345, 'vat_rate' => 0, 'vat_amount' => 0, 'amount' => 12345,
        ]);

        Artisan::call('demo:seed', ['--company' => $mine->id, '--clear' => true]);

        $this->assertSame(0, Sale::where('company_id', $mine->id)->count(),
            'The clear should have removed everything demo:seed created for this company.');

        // The other company's identically-named customer and its
        // sale must both survive untouched.
        $this->assertDatabaseHas('customers', ['id' => $theirRealCustomer->id, 'company_id' => $other->id]);
        $this->assertDatabaseHas('sales', ['id' => $theirRealSale->id, 'company_id' => $other->id]);
    }
}
