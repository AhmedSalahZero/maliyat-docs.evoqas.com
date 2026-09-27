<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Low (polish) audit findings 2, 4, 5, 6, 7, 8, 10 and 11.
//  Most are screen-only, so they are checked in the screen source the
//  same way FrontendIntegrityTest / AuthStylingCompletenessTest do;
//  finding 11 also has server rules, which are checked for real.
// ══════════════════════════════════════════════════════════════════
class AuditLowFindingsTest extends TestCase
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

    private function source(string $path): string
    {
        return (string) file_get_contents(resource_path('js/'.$path));
    }

    // ── 2. Rename pop-up ─────────────────────────────────────────

    public function test_2_the_rename_popup_has_no_english_only_text(): void
    {
        $modal = $this->source('Components/App/RenameModal.vue');

        $this->assertStringNotContainsString('>Cancel<', $modal);
        $this->assertStringNotContainsString("'Saving…'", $modal);
        $this->assertStringContainsString("t('renameSaveBtn')", $modal);

        foreach (['Sales', 'Expenses', 'Custodies', 'OwnerTransactions', 'EquipmentPurchases', 'InventoryPurchases'] as $screen) {
            $this->assertStringNotContainsString('title="Rename', $this->source("Pages/App/{$screen}/Index.vue"), $screen);
        }
    }

    // ── 4. Reports hub ───────────────────────────────────────────

    public function test_4_the_reports_hub_filters_tiles_by_business_type(): void
    {
        $hub = $this->source('Pages/App/Reports/Index.vue');

        $this->assertStringContainsString('visibleFor(REPORTS)', $hub);
        $this->assertStringContainsString('v-for="report in visibleReports"', $hub);
    }

    // ── 5. Profit & Loss ─────────────────────────────────────────

    public function test_5_profit_paid_to_owners_is_its_own_section_marked_not_an_expense(): void
    {
        $pl = $this->source('Pages/App/Reports/ProfitAndLoss.vue');

        $this->assertStringContainsString("t('plUseOfProfitHeading')", $pl);
        $this->assertStringContainsString("t('plUseOfProfitNote')", $pl);
        $this->assertStringNotContainsString('amount: -props.owners_profit_pay', $pl, 'Shown as a positive amount paid out, not a negative cost');
    }

    // ── 6. Payments edit panel ───────────────────────────────────

    public function test_6_the_payment_method_label_appears_once(): void
    {
        $panel = $this->source('Components/App/EditPaymentsPanel.vue');

        $this->assertStringNotContainsString("<label>{{ t('methodLbl') }}</label>", $panel);
        $this->assertStringContainsString("t('methodLbl')", $this->source('Components/App/PaymentMethodField.vue'));
    }

    // ── 7. Login logo ────────────────────────────────────────────

    public function test_7_the_login_logo_size_range_is_the_right_way_round(): void
    {
        preg_match('/\.ip-login__brand-img\s*\{[^}]*height:\s*clamp\((\d+)px,\s*[\d.]+vh,\s*(\d+)px\)/s', $this->source('Pages/Auth/Login.vue'), $m);

        $this->assertNotEmpty($m, 'The logo height uses clamp()');
        $this->assertLessThan((int) $m[2], (int) $m[1], 'Smallest size must be below the largest');
    }

    // ── 8. Sign-up page ──────────────────────────────────────────

    public function test_8_the_signup_page_remembers_the_language_and_translates_its_title(): void
    {
        $register = $this->source('Pages/Auth/Register.vue');

        $this->assertStringContainsString("route('guest.locale')", $register);
        $this->assertStringContainsString('setLocaleLocal', $register);
        $this->assertStringNotContainsString('<Head title="Create Account" />', $register);
        $this->assertStringContainsString('إنشاء حساب', $register);
    }

    // ── 10. Settings highlight ───────────────────────────────────

    public function test_10_the_owners_page_counts_as_a_settings_page(): void
    {
        $this->assertStringContainsString("route().current('app.owners.*')", $this->source('Layouts/AppLayout.vue'));
    }

    // ── 11. Quantities and units per pack ────────────────────────

    private function item(float $perPack = 10): Item
    {
        return Item::create([
            'company_id' => $this->company->id, 'name' => 'Rice', 'type' => 'trading',
            'uom' => 'Carton', 'qty_per_uom' => $perPack, 'base_unit_name' => 'kg',
        ]);
    }

    private function stock(Item $item, float $cartons = 10): void
    {
        $vendor = Vendor::create(['company_id' => $this->company->id, 'name' => 'Supplier']);

        $this->post('/app/inventory-purchases', [
            'vendor_id' => $vendor->id, 'date' => '2026-09-01', 'vat_rate' => 0, 'mode' => 'later',
            'lines' => [['item_id' => $item->id, 'qty' => $cartons, 'qty_per_uom' => $item->qty_per_uom, 'unit_price' => 100]],
        ])->assertSessionHasNoErrors();
        Cache::flush();
    }

    private function sell(Item $item, float $qty, float $perUnit): \Illuminate\Testing\TestResponse
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Acme']);

        return $this->post('/app/sales', [
            'customer_id' => $customer->id, 'date' => '2026-09-02', 'vat_rate' => 0, 'mode' => 'later',
            'lines' => [['item_id' => $item->id, 'qty' => $qty, 'qty_per_uom' => $perUnit, 'unit_price' => 20]],
        ]);
    }

    public function test_11_cartons_are_sold_in_whole_or_half_like_they_are_bought(): void
    {
        $item = $this->item();
        $this->stock($item);

        $this->sell($item, 1.37, 10)->assertSessionHasErrors('lines.0.qty');
        Cache::flush();
        $this->sell($item, 1.5, 10)->assertSessionHasNoErrors();
    }

    public function test_11_the_base_unit_can_still_be_sold_to_the_hundredth(): void
    {
        $item = $this->item();
        $this->stock($item);

        $this->sell($item, 2.37, 1)->assertSessionHasNoErrors();
    }

    public function test_11_changing_units_per_pack_on_a_used_item_must_be_confirmed(): void
    {
        $item = $this->item();
        $this->stock($item);

        $payload = ['name' => 'Rice', 'uom' => 'Carton', 'qty_per_uom' => 12, 'base_unit_name' => 'kg'];

        $this->patch(route('app.items.update', $item->id), $payload)->assertSessionHasErrors('qty_per_uom');
        $this->assertEqualsWithDelta(10.0, (float) $item->fresh()->qty_per_uom, 0.001);

        $this->patch(route('app.items.update', $item->id), $payload + ['confirm_unit_change' => true])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(12.0, (float) $item->fresh()->qty_per_uom, 0.001);
    }

    public function test_11_other_item_edits_need_no_confirmation(): void
    {
        $item = $this->item();
        $this->stock($item);

        $this->patch(route('app.items.update', $item->id), [
            'name' => 'Basmati Rice', 'uom' => 'Carton', 'qty_per_uom' => 10, 'base_unit_name' => 'kg',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Basmati Rice', $item->fresh()->name);
    }
}
