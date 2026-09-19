<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Owner;
use App\Models\Vendor;
use App\Services\Reports\ReportDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ReportController
//  Location: app/Http/Controllers/App/ReportController.php
//
//  All reports here are CASH-BASIS — see ReportDataService's class
//  doc comment. Every method accepts optional ?from=&to= (Y-m-d)
//  date-range filters; both default to the current calendar month.
//
//  The actual number-crunching lives in ReportDataService, shared
//  with ReportExportController (Excel/PDF) so the screen and the
//  downloaded file are always reading the same math — see that
//  class's doc comment for why that split exists.
// ══════════════════════════════════════════════════════════════════
class ReportController extends Controller
{
    public function __construct(private readonly ReportDataService $reports) {}

    /**
     * Reports hub — the destination for the "Reports" tab in the
     * bottom nav / sidebar. Just a card grid linking to the six
     * report methods below; no data needed here.
     */
    public function index(): Response
    {
        return Inertia::render('App/Reports/Index');
    }

    /**
     * "All Entries" — every sale, expense, inventory/equipment
     * purchase in one chronological list.
     */
    public function ledger(Request $request): Response
    {
        [$from, $to] = $this->reports->monthRange($request->string('from')->value() ?: null, $request->string('to')->value() ?: null);

        return Inertia::render('App/Reports/Ledger', [
            'entries' => $this->reports->ledger($from, $to),
            'from'    => $from,
            'to'      => $to,
        ]);
    }

    public function profitAndLoss(Request $request): Response
    {
        [$from, $to] = $this->reports->monthRange($request->string('from')->value() ?: null, $request->string('to')->value() ?: null);

        return Inertia::render('App/Reports/ProfitAndLoss', $this->reports->profitAndLoss($from, $to));
    }

    public function customerStatement(Request $request, ?Customer $customer = null): Response
    {
        [$from, $to] = $this->statementRange($request);

        $customers = Customer::query()->orderBy('name')->get(['id', 'name']);
        $data      = $this->reports->customerStatement($customer, $from, $to);

        return Inertia::render('App/Reports/CustomerStatement', [
            'customers'       => $customers,
            'customer'        => $customer?->only(['id', 'name']),
            'entries'         => $data['entries'],
            'balance'         => $data['balance'],
            'opening_balance' => $data['opening_balance'],
            'from'            => $from,
            'to'              => $to,
        ]);
    }

    /**
     * A statement's date window.
     *
     * Unlike the other reports this defaults to NO range — the whole
     * history — because the first question anyone asks of a customer
     * statement is "what do they owe me", and defaulting to the
     * current month would answer a narrower one without saying so.
     * A range is opt-in, and when one is given the balance brought
     * forward keeps the figure honest (see
     * ReportDataService::windowStatement()).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function statementRange(Request $request): array
    {
        return [
            $request->string('from')->value() ?: null,
            $request->string('to')->value() ?: null,
        ];
    }

    public function supplierStatement(Request $request, ?Vendor $vendor = null): Response
    {
        [$from, $to] = $this->statementRange($request);

        $vendors = Vendor::query()->orderBy('name')->get(['id', 'name', 'type']);
        $data    = $this->reports->supplierStatement($vendor, $from, $to);

        return Inertia::render('App/Reports/SupplierStatement', [
            'vendors'         => $vendors,
            'vendor'          => $vendor?->only(['id', 'name', 'type']),
            'entries'         => $data['entries'],
            'balance'         => $data['balance'],
            'opening_balance' => $data['opening_balance'],
            'from'            => $from,
            'to'              => $to,
        ]);
    }

    /**
     * Owner Statement — Withdrawals or Profit Pay, for one owner (via
     * the route's optional {owner}) or every owner at once (route
     * with no owner segment). `?type=` picks which of the two —
     * defaults to withdrawals.
     */
    public function ownerStatement(Request $request, ?Owner $owner = null): Response
    {
        [$from, $to] = $this->statementRange($request);

        $type = $request->string('type')->value() === 'profit' ? 'profit' : 'withdrawals';

        $owners = Owner::query()->orderBy('name')->get(['id', 'name']);
        $data   = $this->reports->ownerStatement($owner, $type, $from, $to);

        return Inertia::render('App/Reports/OwnerStatement', [
            'owners'          => $owners,
            'owner'           => $owner?->only(['id', 'name']),
            'type'            => $type,
            'entries'         => $data['entries'],
            'total_in'        => $data['total_in'],
            'total_out'       => $data['total_out'],
            'net'             => $data['net'],
            'running_balance' => $data['running_balance'],
            'from'            => $from,
            'to'              => $to,
        ]);
    }

    /**
     * Per-item stock levels — quantity and value. Accepts an
     * optional ?item_id= to filter to one product and show its full
     * transaction history (drill-down), and ?mode=quantity|value
     * which the frontend uses purely to decide which columns to
     * lead with — both numbers are always returned, mode never
     * changes what's computed, only what's emphasized on screen.
     */
    public function inventoryStatement(Request $request): Response
    {
        $itemId = $request->filled('item_id') ? $request->integer('item_id') : null;
        $mode   = $request->string('mode')->value() === 'value' ? 'value' : 'quantity';

        // Same opt-in range as the statements: stock on hand today is
        // the default question, and a range answers "what moved during
        // this period" without pretending purchases made before it
        // never happened.
        [$from, $to] = $this->statementRange($request);

        $data = $this->reports->inventoryStatement($itemId, $from, $to);

        return Inertia::render('App/Reports/InventoryStatement', [
            'items'             => $data['items'],
            'total_stock_value' => $data['total_stock_value'],
            'product_options'   => Item::query()->orderBy('name')->get(['id', 'name']),
            'selected_item_id'  => $itemId,
            'selected'          => $data['selected'],
            'history'           => $data['history'],
            'mode'              => $mode,
            'from'              => $from,
            'to'                => $to,
        ]);
    }

    public function cashFlow(Request $request): Response
    {
        [$from, $to] = $this->reports->monthRange($request->string('from')->value() ?: null, $request->string('to')->value() ?: null);

        return Inertia::render('App/Reports/CashFlow', $this->reports->cashFlow($from, $to));
    }

    /**
     * External Audit — the Trial Balance, the Balance Sheet, and the
     * Journal, behind one entry point.
     *
     * These are the only screens in the app written for the
     * company's auditor rather than its owner, and they're read
     * together: a figure on the Trial Balance or Balance Sheet gets
     * traced back to the transaction(s) that produced it in the
     * Journal. One tile, with a toggle inside — the same shape as
     * Receive/Pay on the payments screen.
     *
     * ?view= chooses which one is showing. Only the visible one is
     * queried; building all three to show one would triple the work
     * of every page load and every tab switch.
     *
     * Each keeps its own filter — the Trial Balance is a range
     * (?tb_from=&tb_to=, "this year to date" by default), the
     * Balance Sheet is a single date (?bs_as_of=, today by default —
     * it's a snapshot, not a period), and the Journal is its own
     * range (?from=&to=, "this month" by default). Sharing filters
     * between them would misrepresent whichever ones weren't showing.
     */
    public function externalAudit(Request $request): Response
    {
        $view = match ($request->string('view')->value()) {
            'balance-sheet' => 'balance-sheet',
            'journal'       => 'journal',
            default         => 'trial-balance',
        };

        [$tbFrom, $tbTo] = $this->reports->yearRange(
            $request->string('tb_from')->value() ?: null,
            $request->string('tb_to')->value() ?: null,
        );
        $bsAsOf = $request->string('bs_as_of')->value() ?: now()->toDateString();
        [$from, $to] = $this->reports->monthRange(
            $request->string('from')->value() ?: null,
            $request->string('to')->value() ?: null,
        );

        return Inertia::render('App/Reports/ExternalAudit', [
            'view'    => $view,
            'tb_from' => $tbFrom,
            'tb_to'   => $tbTo,
            'bs_as_of' => $bsAsOf,
            'from'    => $from,
            'to'      => $to,

            'trialBalance' => $view === 'trial-balance'
                ? $this->reports->trialBalance($tbFrom, $tbTo)
                : null,

            'balanceSheet' => $view === 'balance-sheet'
                ? $this->reports->balanceSheet($bsAsOf)
                : null,

            'entries' => $view === 'journal'
                ? $this->reports->journalReport($from, $to)
                : null,
        ]);
    }
}
