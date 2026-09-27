<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Custody;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Owner;
use App\Models\OwnerTransaction;
use App\Models\Payment;
use App\Models\SalesChannel;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Services\Reports\ReportDataService;
use App\Support\FinancialRules;
use App\Support\Reports\ExcelReportExporter;
use App\Support\Reports\PdfReportExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The third round of audit fixes (medium findings):
//    M1  supplier payments with no bill are refused
//    M2  opening-balance / custody / owner payments are not edited
//        or deleted from the Payments screen
//    M3  PDFs are rendered by mPDF (Arabic shaping + right-to-left)
//    M4  Excel figures are real numbers
//    M5  bad report dates give a message, not an error page
//    M6  one clock (Africa/Cairo) everywhere
//    M7  owner statement: brought-forward balance + export
//    M8  goods-only companies must pick the item on a sale line
//    M9  stock ledger quantities to 4 decimals, value = posted COGS
//    M11 default sales channel found by id, not by English name
// ══════════════════════════════════════════════════════════════════
class AuditRoundThreeFixesTest extends TestCase
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

    // ══ M2 ═══════════════════════════════════════════════════════

    public function test_m2_an_opening_balance_cash_payment_cannot_be_edited_or_deleted_from_payments(): void
    {
        $payment = Payment::create([
            'company_id' => $this->company->id, 'date' => '2026-09-01', 'amount' => 5000,
            'method' => 'cash', 'direction' => 'in', 'note' => 'Opening balance', 'is_opening_balance' => true,
        ]);
        app(JournalService::class)->postOpeningBalanceCash($payment);

        $this->patch("/app/payments/{$payment->id}", ['date' => '2026-09-01', 'amount' => 10, 'method' => 'cash'])
            ->assertSessionHasErrors('amount');
        $this->delete("/app/payments/{$payment->id}")->assertRedirect()->assertSessionHas('error');

        $this->assertEquals(5000.0, (float) $payment->fresh()->amount);
    }

    public function test_m2_a_custody_payment_cannot_be_edited_or_deleted_from_payments(): void
    {
        $holder = Vendor::create(['company_id' => $this->company->id, 'name' => 'Ahmed']);

        $this->post('/app/custodies', [
            'holder_id' => $holder->id, 'amount' => 1000, 'method' => 'cash', 'given_at' => '2026-09-01',
        ])->assertSessionHasNoErrors();

        $payment = Payment::query()->where('payable_type', Custody::class)->firstOrFail();

        // Used to crash: a custody has no "amount still owed".
        $this->patch("/app/payments/{$payment->id}", ['date' => '2026-09-01', 'amount' => 10, 'method' => 'cash'])
            ->assertSessionHasErrors('amount');
        $this->delete("/app/payments/{$payment->id}")->assertSessionHas('error');

        $this->assertNotNull($payment->fresh());
    }

    // ══ M3 / M4 ═════════════════════════════════════════════════

    public function test_m3_arabic_pdf_is_rendered_by_mpdf(): void
    {
        $this->assertTrue(class_exists(\Mpdf\Mpdf::class), 'mpdf/mpdf must be installed');

        $pdf = PdfReportExporter::renderWithMpdf('<p dir="rtl">كشف حساب عميل</p>', true);

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_m4_excel_figures_are_numbers_and_labels_stay_text(): void
    {
        $this->assertSame([1250.0, '"EGP "#,##0.00;"EGP "-#,##0.00'], ExcelReportExporter::parseFigure('EGP 1,250.00'));
        $this->assertSame(-1000.0, ExcelReportExporter::parseFigure('EGP -1,000.00')[0]);
        $this->assertSame(-200.0, ExcelReportExporter::parseFigure('-EGP 200.00')[0]);
        $this->assertSame(0.342, ExcelReportExporter::parseFigure('34.2%')[0]);
        $this->assertNull(ExcelReportExporter::parseFigure('—'));
        $this->assertNull(ExcelReportExporter::parseFigure('Total balance'));
        $this->assertNull(ExcelReportExporter::parseFigure('2026-09-01'));
    }

    // ══ M5 ═══════════════════════════════════════════════════════

    public function test_m5_a_bad_report_date_shows_the_report_with_a_message(): void
    {
        $this->get('/app/reports/profit-loss?from=2026-02-30&to=rubbish')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('flash.error', __('errors.invalid_report_date'))
                ->where('from', now()->startOfMonth()->toDateString()));
    }

    public function test_m5_a_backwards_range_is_turned_around(): void
    {
        $this->get('/app/reports/ledger?from=2026-09-30&to=2026-09-01')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('from', '2026-09-01')->where('to', '2026-09-30'));
    }

    public function test_m5_bad_dates_never_break_an_export(): void
    {
        $this->get('/app/reports/cash-flow/export/excel?from=nonsense')->assertOk();
        $this->get('/app/reports/balance-sheet/export/excel?bs_as_of=2026-99-99')->assertOk();
    }

    // ══ M6 ═══════════════════════════════════════════════════════

    public function test_m6_the_app_and_the_date_rules_use_the_same_clock(): void
    {
        $this->assertSame('Africa/Cairo', config('app.timezone'));

        // 00:30 in Cairo is still "yesterday" in UTC.
        Carbon::setTestNow(Carbon::parse('2026-09-20 00:30', 'Africa/Cairo'));

        $this->assertSame('2026-09-20', FinancialRules::latestAllowedDate());
        $this->assertSame('2026-09-20', now()->toDateString());

        Carbon::setTestNow();
    }

    // ══ M7 ═══════════════════════════════════════════════════════

    public function test_m7_owner_statement_brings_the_balance_forward(): void
    {
        $owner = Owner::create(['company_id' => $this->company->id, 'name' => 'Mahmoud']);

        foreach ([['in', 'capital_injection', 10000, '2026-01-10'], ['out', 'withdrawal', 3000, '2026-02-10'], ['out', 'withdrawal', 1000, '2026-03-10']] as [$dir, $cat, $amt, $date]) {
            OwnerTransaction::create([
                'company_id' => $this->company->id, 'owner_id' => $owner->id, 'direction' => $dir,
                'category' => $cat, 'amount' => $amt, 'method' => 'cash', 'date' => $date,
            ]);
        }

        $data = app(ReportDataService::class)->ownerStatement($owner, 'withdrawals', '2026-03-01', '2026-03-31');

        $this->assertEquals(7000.0, $data['opening_balance']);
        $this->assertEquals(6000.0, $data['closing_balance']);
        $this->assertEquals(6000.0, $data['entries'][0]['balance']);
    }

    public function test_m7_owner_statement_can_be_exported(): void
    {
        $owner = Owner::create(['company_id' => $this->company->id, 'name' => 'Mahmoud']);

        $this->get(route('app.reports.owner-statement.export', ['format' => 'excel', 'owner' => $owner->id]))->assertOk();
        $this->get(route('app.reports.owner-statement.export', ['format' => 'pdf', 'type' => 'profit']))->assertOk();
    }

    // ══ M8 ═══════════════════════════════════════════════════════

    public function test_m8_a_goods_only_company_must_pick_the_item(): void
    {
        $this->company->forceFill(['business_types' => ['trading']])->save();
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Acme']);

        $this->post('/app/sales', [
            'customer_id' => $customer->id, 'date' => '2026-09-01',
            'lines' => [['item_id' => null, 'qty' => 1, 'unit_price' => 100]],
            'vat_rate' => 0, 'mode' => 'later',
        ])->assertSessionHasErrors('lines.0.item_id');
    }

    public function test_m8_a_company_offering_services_may_sell_a_service_line(): void
    {
        $this->company->forceFill(['business_types' => ['service']])->save();
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Acme']);

        $this->post('/app/sales', [
            'customer_id' => $customer->id, 'date' => '2026-09-01',
            'lines' => [['item_id' => null, 'qty' => 1, 'unit_price' => 100]],
            'vat_rate' => 0, 'mode' => 'later',
        ])->assertSessionHasNoErrors();
    }

    // ══ M9 ═══════════════════════════════════════════════════════

    public function test_m9_part_unit_quantities_are_not_rounded_away_and_stock_value_matches_the_ledger(): void
    {
        $vendor   = Vendor::create(['company_id' => $this->company->id, 'name' => 'Supplier']);
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Acme']);
        $item     = Item::create(['company_id' => $this->company->id, 'name' => 'Rice', 'type' => 'trading', 'uom' => 'kg', 'qty_per_uom' => 1, 'base_unit_name' => 'kg']);

        // 3 bags of 0.33 kg = 0.99 kg at 10.00 a bag.
        $this->post('/app/inventory-purchases', [
            'vendor_id' => $vendor->id, 'date' => '2026-09-01', 'vat_rate' => 0, 'mode' => 'later',
            'lines' => [['item_id' => $item->id, 'qty' => 3, 'qty_per_uom' => 0.33, 'unit_price' => 10]],
        ])->assertSessionHasNoErrors();
        \Illuminate\Support\Facades\Cache::flush();

        // Three separate sales of 0.33 kg.
        foreach (['2026-09-02', '2026-09-03'] as $date) {
            $this->post('/app/sales', [
                'customer_id' => $customer->id, 'date' => $date, 'vat_rate' => 0, 'mode' => 'later',
                'lines' => [['item_id' => $item->id, 'qty' => 0.33, 'qty_per_uom' => 1, 'unit_price' => 20]],
            ])->assertSessionHasNoErrors();
            \Illuminate\Support\Facades\Cache::flush();
        }

        $last = \App\Models\InventoryStockLedger::query()->where('item_id', $item->id)->orderByDesc('date')->firstOrFail();

        $this->assertEquals(0.33, (float) $last->ending_qty);

        // Stock value in the stock ledger = the Inventory account in the books.
        $inventoryAccount = \App\Models\Account::query()->where('company_id', $this->company->id)->where('code', \App\Models\Account::INVENTORY_ASSET)->firstOrFail();
        $booked = round(\App\Models\JournalLine::query()->where('account_id', $inventoryAccount->id)->get()
            ->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2);

        $this->assertEquals($booked, (float) $last->ending_value);
    }

    // ══ M11 ══════════════════════════════════════════════════════

    public function test_m11_renaming_direct_sales_keeps_it_the_default(): void
    {
        SalesChannel::seedDefaults($this->company->id);
        $default = SalesChannel::defaultChannel($this->company->id);

        $default->update(['name' => 'Walk-in']);

        // Visiting the Sales screen again must not bring back a second "Direct Sales".
        $this->get('/app/sales')->assertOk()
            ->assertInertia(fn ($page) => $page->where('defaultSalesChannelId', $default->id));

        $this->assertSame(0, SalesChannel::query()->where('name', 'Direct Sales')->count());
        $this->assertSame($default->id, SalesChannel::defaultChannel($this->company->id)->id);
    }
}
