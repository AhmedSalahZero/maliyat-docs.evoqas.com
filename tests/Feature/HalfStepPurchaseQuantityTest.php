<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryPurchase;
use App\Models\Item;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Inventory purchase quantities are whole or half only:
//  1, 1.5, 2, 2.5 … (owner's decision, Sep 2026).
//
//  One and a half cartons is a real purchase; 1.25 cartons is almost
//  always a typing mistake that would quietly put the wrong number
//  of pieces into stock. See App\Rules\HalfStepQuantity.
// ══════════════════════════════════════════════════════════════════
class HalfStepPurchaseQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Vendor $vendor;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $admin = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->actingAs($admin);

        $this->vendor = Vendor::create(['company_id' => $this->company->id, 'name' => 'Supplies Co']);
        $this->item   = Item::create([
            'company_id'     => $this->company->id,
            'name'           => 'Juice',
            'qty_per_uom'    => 24,
            'base_unit_name' => 'piece',
        ]);
    }

    private function buy(float $qty): \Illuminate\Testing\TestResponse
    {
        $response = $this->post('/app/inventory-purchases', [
            'vendor_id' => $this->vendor->id,
            'date'      => '2026-09-01',
            'lines'     => [[
                'item_id'        => $this->item->id,
                'qty'            => $qty,
                'uom'            => 'Carton',
                'qty_per_uom'    => 24,
                'base_unit_name' => 'piece',
                'unit_price'     => 240,
            ]],
            'vat_rate'  => 0,
            'mode'      => 'later',
        ]);

        Cache::flush(); // the duplicate guard is not what these tests are about

        return $response;
    }

    /**
     * @return array<string, array{0: float}>
     */
    public static function acceptedQuantities(): array
    {
        return [
            'half'         => [0.5],
            'one'          => [1],
            'one and half' => [1.5],
            'two and half' => [2.5],
            'ten'          => [10],
        ];
    }

    /**
     * @return array<string, array{0: float}>
     */
    public static function refusedQuantities(): array
    {
        return [
            'quarter'       => [1.25],
            'one decimal'   => [1.3],
            'two decimals'  => [2.75],
            'too small'     => [0.2],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedQuantities')]
    public function test_whole_and_half_quantities_are_accepted(float $qty): void
    {
        $this->buy($qty)->assertSessionHasNoErrors();

        $this->assertSame(1, InventoryPurchase::count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedQuantities')]
    public function test_other_decimals_are_refused_with_a_message(float $qty): void
    {
        $this->buy($qty)->assertSessionHasErrors('lines.0.qty');

        $this->assertNotSame('', trim(session('errors')->get('lines.0.qty')[0] ?? ''));
        $this->assertSame(0, InventoryPurchase::count());
    }

    public function test_the_message_exists_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $strings = require lang_path("{$locale}/validation.php");
            $this->assertArrayHasKey('half_step_qty', $strings, "No {$locale} message for half-step quantities");
        }
    }

    /**
     * One and a half cartons of 24 is 36 pieces in stock, priced at
     * 1.5 × 240 = 360.
     */
    public function test_one_and_a_half_cartons_is_36_pieces(): void
    {
        $this->buy(1.5)->assertSessionHasNoErrors();

        $line = InventoryPurchase::query()->firstOrFail()->lines()->firstOrFail();

        $this->assertEquals(36, (float) $line->qty * (float) $line->qty_per_uom);
        $this->assertEquals(360, (float) $line->line_total);
    }
}
