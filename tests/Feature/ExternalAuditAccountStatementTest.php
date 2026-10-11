<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Services\Reports\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  External Audit — Account Statement.
//
//  Pick any account and a date range; get its Opening Balance, every
//  movement (Debit / Credit, running balance, and the customer or
//  supplier each posting was about) and its End Balance.
//
//  The auditor reads this next to the Trial Balance, so the figures
//  must agree with it exactly — that is the main thing held in place
//  here, together with the customer / supplier names on each row.
// ══════════════════════════════════════════════════════════════════
class ExternalAuditAccountStatementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $acme;

    private Vendor $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $admin = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->actingAs($admin);

        $this->acme     = Customer::create(['company_id' => $this->company->id, 'name' => 'Acme']);
        $this->supplier = Vendor::create(['company_id' => $this->company->id, 'name' => 'Supplies Co']);
    }

    private function account(string $code): Account
    {
        return Account::query()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function reports(): ReportDataService
    {
        return app(ReportDataService::class);
    }

    /**
     * August: a 1,000 credit sale to Acme (opening position for September).
     * September: a 500 sale to Acme paid in cash, a 300 sale to Acme
     * with 5% withholding on credit, and an inventory purchase from
     * Supplies Co (2,400 + 14% VAT) on credit.
     */
    private function seedActivity(): void
    {
        $sale = fn (string $date, float $price, string $mode, array $extra = []) => $this->post('/app/sales', array_merge([
            'customer_id' => $this->acme->id,
            'date'        => $date,
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => $price]],
            'mode'        => $mode,
            'method'      => 'cash',
        ], $extra))->assertSessionHasNoErrors();

        $sale('2026-08-10', 1000, 'later');
        Cache::flush();
        $sale('2026-09-02', 500, 'now');
        Cache::flush();
        $sale('2026-09-05', 300, 'later', [
            'lines' => [['item_id' => null, 'qty' => 1, 'unit_price' => 300, 'vat_rate' => 0, 'withholding_rate' => 5]],
        ]);
        Cache::flush();

        $item = Item::create([
            'company_id' => $this->company->id, 'name' => 'Juice', 'qty_per_uom' => 24, 'base_unit_name' => 'piece',
        ]);

        $this->post('/app/inventory-purchases', [
            'vendor_id' => $this->supplier->id,
            'date'      => '2026-09-03',
            'lines'     => [[
                'item_id' => $item->id, 'qty' => 10, 'uom' => 'Carton', 'qty_per_uom' => 24,
                'base_unit_name' => 'piece', 'unit_price' => 240, 'vat_rate' => 14, 'withholding_rate' => 0,
            ]],
            'mode'      => 'later',
        ])->assertSessionHasNoErrors();
        Cache::flush();
    }

    // ── The numbers agree with the Trial Balance ─────────────────

    public function test_every_account_agrees_with_its_trial_balance_row(): void
    {
        $this->seedActivity();

        $trial = $this->reports()->trialBalance('2026-09-01', '2026-09-30');
        $this->assertNotEmpty($trial['rows']);

        foreach ($trial['rows'] as $row) {
            $statement = $this->reports()->accountStatement($this->account($row['code']), '2026-09-01', '2026-09-30');

            $this->assertEquals($row['opening_debit'], $statement['opening_debit'], "{$row['code']} opening debit");
            $this->assertEquals($row['opening_credit'], $statement['opening_credit'], "{$row['code']} opening credit");
            $this->assertEquals($row['period_debit'], $statement['total_debit'], "{$row['code']} total debit");
            $this->assertEquals($row['period_credit'], $statement['total_credit'], "{$row['code']} total credit");
            $this->assertEquals($row['closing_debit'], $statement['closing_debit'], "{$row['code']} end debit");
            $this->assertEquals($row['closing_credit'], $statement['closing_credit'], "{$row['code']} end credit");
        }
    }

    public function test_opening_movements_and_end_balance_add_up(): void
    {
        $this->seedActivity();

        $statement = $this->reports()->accountStatement($this->account(Account::ACCOUNTS_RECEIVABLE), '2026-09-01', '2026-09-30');

        // Before September Acme owed 1,000. September: +500 invoice,
        // -500 payment, +285 (300 less 15 withholding).
        $this->assertEquals(1000.0, $statement['opening_debit']);
        $this->assertSame('debit', $statement['opening_side']);
        $this->assertEquals(785.0, $statement['total_debit'], '500 invoice + 285 invoice (300 less 15 withholding)');
        $this->assertEquals(500.0, $statement['total_credit'], 'the cash payment');
        $this->assertEquals(1285.0, $statement['closing_amount']);
        $this->assertSame('debit', $statement['closing_side']);
    }

    public function test_the_running_balance_follows_each_movement_and_ends_on_the_end_balance(): void
    {
        $this->seedActivity();

        $statement = $this->reports()->accountStatement($this->account(Account::ACCOUNTS_RECEIVABLE), '2026-09-01', '2026-09-30');
        $rows = $statement['rows'];

        $this->assertNotEmpty($rows);

        $running = $statement['opening_debit'] - $statement['opening_credit'];
        foreach ($rows as $row) {
            $running = round($running + $row['debit'] - $row['credit'], 2);
            $shown   = $row['balance_side'] === 'credit' ? -$row['balance_amount'] : $row['balance_amount'];
            $this->assertEquals($running, $shown);
        }

        $last = end($rows);
        $this->assertEquals($statement['closing_amount'], $last['balance_amount']);

        $dates = array_column($rows, 'date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates, 'oldest first');
    }

    public function test_a_range_with_no_movements_still_reports_the_position(): void
    {
        $this->seedActivity();

        $statement = $this->reports()->accountStatement($this->account(Account::ACCOUNTS_RECEIVABLE), '2026-03-01', '2026-03-31');

        $this->assertSame([], $statement['rows']);
        $this->assertEquals(0.0, $statement['opening_amount']);
        $this->assertEquals(0.0, $statement['closing_amount']);
        $this->assertNull($statement['closing_side']);
    }

    // ── Customer / supplier on each row ──────────────────────────

    public function test_receivable_rows_name_the_customer_on_invoices_and_payments(): void
    {
        $this->seedActivity();

        $rows = $this->reports()
            ->accountStatement($this->account(Account::ACCOUNTS_RECEIVABLE), '2026-09-01', '2026-09-30')['rows'];

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('Acme', $row['party'], "'{$row['memo']}' should name the customer");
        }

        $this->assertContains('Payment received against sale', array_column($rows, 'memo'));
    }

    public function test_payable_rows_name_the_supplier(): void
    {
        $this->seedActivity();

        $rows = $this->reports()
            ->accountStatement($this->account(Account::ACCOUNTS_PAYABLE), '2026-09-01', '2026-09-30')['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('Supplies Co', $rows[0]['party']);
        $this->assertEquals(2736.0, $rows[0]['credit']);
    }

    public function test_the_withholding_account_names_the_customer_who_held_it_back(): void
    {
        $this->seedActivity();

        $statement = $this->reports()
            ->accountStatement($this->account(Account::WITHHOLDING_TAX_RECEIVABLE), '2026-09-01', '2026-09-30');

        $this->assertCount(1, $statement['rows']);
        $this->assertSame('Acme', $statement['rows'][0]['party']);
        $this->assertEquals(15.0, $statement['total_debit']);
    }

    public function test_an_account_with_no_party_behind_its_postings_shows_none(): void
    {
        $this->seedActivity();

        // Sales revenue postings come from sales, so they name the customer too —
        // but a posting with no source at all must simply show nobody.
        $rows = $this->reports()
            ->accountStatement($this->account(Account::SALES_REVENUE), '2026-09-01', '2026-09-30')['rows'];

        $this->assertNotEmpty($rows);
        $this->assertSame('Acme', $rows[0]['party']);
    }

    // ── The screen and the exports ───────────────────────────────

    public function test_the_tab_builds_only_when_an_account_is_picked(): void
    {
        $this->seedActivity();

        $props = $this->get(route('app.reports.external-audit', ['view' => 'account-statement']))
            ->viewData('page')['props'];

        $this->assertSame('account-statement', $props['view']);
        $this->assertNotEmpty($props['accounts'], 'the selector lists the accounts');
        $this->assertNull($props['accountStatement'], 'nothing to show until an account is picked');
        $this->assertNull($props['trialBalance']);
        $this->assertNull($props['entries']);
    }

    public function test_picking_an_account_builds_its_statement(): void
    {
        $this->seedActivity();

        $account = $this->account(Account::ACCOUNTS_RECEIVABLE);

        $props = $this->get(route('app.reports.external-audit', [
            'view' => 'account-statement', 'account_id' => $account->id,
            'st_from' => '2026-09-01', 'st_to' => '2026-09-30',
        ]))->viewData('page')['props'];

        $this->assertSame($account->id, $props['account_id']);
        $this->assertSame('1100', $props['accountStatement']['account']['code']);
        $this->assertEquals(1285.0, $props['accountStatement']['closing_amount']);
    }

    public function test_other_tabs_do_not_build_the_account_list(): void
    {
        $props = $this->get(route('app.reports.external-audit'))->viewData('page')['props'];

        $this->assertNull($props['accounts']);
        $this->assertNull($props['accountStatement']);
    }

    public function test_another_companys_account_cannot_be_read(): void
    {
        $other = Company::factory()->create();
        app(JournalService::class)->seedChartOfAccounts($other);
        $foreign = Account::query()->withoutGlobalScopes()->where('company_id', $other->id)->firstOrFail();

        $props = $this->get(route('app.reports.external-audit', [
            'view' => 'account-statement', 'account_id' => $foreign->id,
        ]))->viewData('page')['props'];

        $this->assertNull($props['accountStatement']);
        $this->assertNull($props['account_id']);
    }

    public function test_the_statement_can_be_exported_to_excel_and_pdf(): void
    {
        $this->seedActivity();

        $account = $this->account(Account::ACCOUNTS_RECEIVABLE);

        foreach (['excel', 'pdf'] as $format) {
            $this->get(route('app.reports.account-statement.export', [
                'format' => $format, 'account_id' => $account->id,
                'st_from' => '2026-09-01', 'st_to' => '2026-09-30',
            ]))->assertOk();
        }
    }

    public function test_exporting_another_companys_account_is_refused(): void
    {
        $other = Company::factory()->create();
        app(JournalService::class)->seedChartOfAccounts($other);
        $foreign = Account::query()->withoutGlobalScopes()->where('company_id', $other->id)->firstOrFail();

        $this->get(route('app.reports.account-statement.export', ['format' => 'excel', 'account_id' => $foreign->id]))
            ->assertNotFound();
    }
}
