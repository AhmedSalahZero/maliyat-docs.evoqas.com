<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreSaleRequest;
use App\Http\Requests\App\UpdateSaleRequest;
use App\Models\Customer;
use App\Models\Item;
use App\Models\PaymentChannel;
use App\Models\Sale;
use App\Models\SalesChannel;
use App\Services\JournalService;
use App\Services\MovingAverageCostingService;
use App\Services\PaymentRecorderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SaleController
//  Location: app/Http/Controllers/App/SaleController.php
//
//  "Sell to {customer}: {item} x{qty} @ {price} ... for {total},
//   paid {mode}." — the Sales tab of the guided entry form, plus a
//   recent-sales list underneath it with Edit/Delete.
//
//  EDIT & DELETE — both allowed even after payments exist, but both
//  are consequential and both require the frontend to warn the user
//  first (see Sales/Index.vue's confirm dialogs):
//    - Deleting a sale ALSO deletes its payment records (the cash
//      that was received against it) — that's real money history
//      disappearing, not just the invoice.
//    - Editing a sale's total after it's been partly/fully paid
//      does NOT touch existing payments — it just changes what's
//      owed, which can leave a deficit (still owed more) or a
//      surplus (customer now overpaid). balance() reflects this
//      automatically once the amount changes; no special "deficit/
//      surplus" field is needed.
//  Both operations reverse the old journal entry/entries (see
//  JournalService::reverseAllForPayable()/reverse()) rather than
//  silently rewriting accounting history — the GL keeps a full
//  trail of every correction even though the user only ever sees
//  a normal Vue confirm dialog, never any accounting language.
// ══════════════════════════════════════════════════════════════════
class SaleController extends Controller
{
    public function __construct(
        private readonly PaymentRecorderService $paymentRecorder,
        private readonly JournalService $journal,
        private readonly MovingAverageCostingService $costing,
    ) {}

    public function index(): Response
    {
        // Self-heal for companies created before Sales Channels
        // existed — idempotent (firstOrCreate), cheap, and means no
        // manual backfill step is ever needed. Same pattern as
        // Category::seedDefaults() elsewhere in this app.
        SalesChannel::seedDefaults(auth()->user()->company_id);

        $sales = Sale::query()
            ->with(['customer:id,name', 'salesChannel:id,name,name_ar', 'lines.item:id,name', 'payments'])
            ->latest('date')
            ->latest('id')
            ->paginate(20)
            ->through(fn (Sale $sale) => [
                'id'          => $sale->id,
                'date'        => $sale->date->toDateString(),
                'due_date'    => $sale->due_date?->toDateString(),
                'customer_id' => $sale->customer_id,
                'customer'    => $sale->customer?->name,
                'sales_channel_id' => $sale->sales_channel_id,
                'sales_channel'    => $sale->salesChannel?->name,
                'sales_channel_ar' => $sale->salesChannel?->name_ar,
                'lines'       => $sale->lines->map(fn ($line) => [
                    'item_id'        => $line->item_id,
                    'item'           => $line->item?->name,
                    'qty'            => (float) $line->qty,
                    'uom'            => $line->uom,
                    'qty_per_uom'    => (float) $line->qty_per_uom,
                    'base_unit_name' => $line->base_unit_name,
                    'unit_price'     => (float) $line->unit_price,
                ]),
                'vat_rate'      => (float) $sale->vat_rate,
                'amount'        => (float) $sale->amount,
                'paid_amount'   => $sale->paidAmount(),
                'balance'       => $sale->balance(),
                'is_paid'       => $sale->isPaid(),
                'payments_count' => $sale->payments->count(),
                // Individual payments so the edit view can show and
                // remove them — see PaymentController::destroy().
                'payments'       => $sale->payments->map(fn ($payment) => [
                    'id'     => $payment->id,
                    'date'   => $payment->date->toDateString(),
                    'amount' => (float) $payment->amount,
                    'method' => $payment->method,
                    // Needed so the edit form can prefill the bank /
                    // operator the payment actually went through.
                    'payment_channel_id' => $payment->payment_channel_id,
                ])->values(),
            ]);

        return Inertia::render('App/Sales/Index', [
            'customers'      => Customer::query()->orderBy('name')->get(['id', 'name']),
            // Raw materials are consumed by Production Orders, not
            // sold directly — see the Item type note in Item.php.
            // uom/qty_per_uom/base_unit_name — the item's own last-
            // used unit pair (e.g. "Carton" = 10 "kg") — is what lets
            // the Sales form offer a unit choice per line at all; see
            // Sales/Index.vue's saleUnitOptions().
            'items'          => Item::query()->whereIn('type', ['trading', 'product'])->orderBy('name')->get(['id', 'name', 'uom', 'qty_per_uom', 'base_unit_name']),
            'paymentChannels'=> PaymentChannel::query()->orderBy('name')->get(['id', 'name']),
            'salesChannels'  => SalesChannel::query()->orderBy('id')->get(['id', 'name', 'name_ar']),
            'sales'          => $sales,
        ]);
    }

    public function store(StoreSaleRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $subtotal = collect($data['lines'])->sum(
            fn (array $line) => round($line['qty'] * $line['unit_price'], 2)
        );

        $vatRate   = (float) ($data['vat_rate'] ?? 0);
        $vatAmount = round($subtotal * $vatRate / 100, 2);
        $total     = round($subtotal + $vatAmount, 2);

        $dueDate  = null;
        $schedule = [];

        if ($data['mode'] === 'installment') {
            $schedule = $this->paymentRecorder->buildInstallmentSchedule(
                $total, $data['date'], (int) $data['installment_count'], (int) $data['installment_interval_days']
            );
            $dueDate = $schedule[0]['due_date'];
        } elseif ($data['mode'] !== 'now') {
            $dueDate = $this->paymentRecorder->dueDate($data['due_date'] ?? null, $data['due_in_days'] ?? null, $data['date']);
        }

        // The invoice row, its lines, the journal entries and the
        // opening payment are one business fact — they commit
        // together or not at all. Previously the transaction closed
        // before any of the posting ran, so a failure there left a
        // sale on the books with nothing in the general ledger.
        DB::transaction(function () use ($data, $subtotal, $vatRate, $vatAmount, $total, $dueDate, $schedule) {
            $sale = Sale::create([
                // Cash Sales submit no customer_id at all — filed
                // under the one reusable "Cash Customer" instead of
                // asking the person to pick one. See
                // Customer::cashCustomer()'s doc comment.
                'customer_id' => $data['customer_id'] ?? Customer::cashCustomer(auth()->user()->company_id)->id,
                // Same idea for the sales channel — the form always
                // sends one (it defaults to "Direct Sales" client
                // side), but this is the safety net if it's ever
                // missing. See SalesChannel::defaultChannel().
                'sales_channel_id' => $data['sales_channel_id'] ?? SalesChannel::defaultChannel(auth()->user()->company_id)->id,
                'date'        => $data['date'],
                'subtotal'    => $subtotal,
                'vat_rate'    => $vatRate,
                'vat_amount'  => $vatAmount,
                'amount'      => $total,
                'due_date'    => $dueDate,
                'created_by'  => auth()->id(),
            ]);

            foreach ($data['lines'] as $line) {
                $sale->lines()->create($this->lineAttributes($line));
            }

            $this->journal->postSaleInvoice($sale);
            $this->recalculateCostsFor($sale->company_id, $data['lines'], $sale->date->toDateString());

            $payment = $this->paymentRecorder->apply($sale, 'in', $data['mode'], [
                'date'       => $data['date'],
                'method'     => $data['method'] ?? 'cash',
                'amount_now' => $data['amount_now'] ?? null,
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
            ], $schedule);

            if ($payment) {
                $this->journal->postSaleReceipt($payment);
            }
        });

        return back()->with('success', 'Sale recorded.');
    }

    /**
     * Edit an existing sale's customer/lines/VAT. Existing payments
     * are left completely untouched — see class doc comment on why
     * that's correct (it's what creates the deficit/surplus the
     * frontend warns about, not a bug to "fix" here).
     */
    public function update(UpdateSaleRequest $request, Sale $sale): RedirectResponse
    {
        $data = $request->validated();

        $subtotal = collect($data['lines'])->sum(
            fn (array $line) => round($line['qty'] * $line['unit_price'], 2)
        );
        $vatRate   = (float) ($data['vat_rate'] ?? 0);
        $vatAmount = round($subtotal * $vatRate / 100, 2);
        $total     = round($subtotal + $vatAmount, 2);

        DB::transaction(function () use ($sale, $data, $subtotal, $vatRate, $vatAmount, $total) {
            // Captured before anything changes, so the moving-average
            // recalculation below knows every item and every date
            // this edit could possibly affect — including an item
            // that's being REMOVED from the sale entirely, which
            // still needs its ledger recalculated to remove this
            // sale's outbound movement from it.
            $oldItemIds = $sale->lines()->pluck('item_id')->filter()->unique();
            $oldDate    = $sale->date->toDateString();

            // The old invoice entry no longer reflects reality once
            // the total changes — reverse it before posting the
            // corrected one, rather than editing it in place.
            // Payments are NOT touched — they're still real cash
            // that was actually received.
            // Reversing the sale's entries also reverses any
            // previously-posted Cost of Goods Sold entry (it shares
            // source = this sale) — re-posted below against the new
            // line quantities, same as the invoice entry itself.
            $this->journal->reverseEntriesFor($sale);

            $sale->update([
                'customer_id' => $data['customer_id'],
                'sales_channel_id' => $data['sales_channel_id'] ?? SalesChannel::defaultChannel(auth()->user()->company_id)->id,
                'date'        => $data['date'],
                'subtotal'    => $subtotal,
                'vat_rate'    => $vatRate,
                'vat_amount'  => $vatAmount,
                'amount'      => $total,
                // Correctable now — see the note in the Update*Request.
                'due_date'    => array_key_exists('due_date', $data) ? $data['due_date'] : $sale->due_date,
            ]);

            $sale->lines()->delete();
            foreach ($data['lines'] as $line) {
                $sale->lines()->create($this->lineAttributes($line));
            }

            $freshSale = $sale->fresh();
            $this->journal->postSaleInvoice($freshSale);

            // Recalculate from whichever is earlier — the sale's old
            // date or its new one — across every item that was on
            // the old lines OR the new lines, so a backdated edit,
            // a forward-dated edit, and a changed item mix are all
            // handled the same way: nothing downstream is left
            // pricing against a version of this sale that no longer
            // exists.
            $newItemIds = collect($data['lines'])->pluck('item_id')->filter()->unique();
            $itemIds    = $oldItemIds->merge($newItemIds)->unique();
            $fromDate   = min($oldDate, $freshSale->date->toDateString());

            foreach ($itemIds as $itemId) {
                $this->costing->onItemMovementChanged((int) $freshSale->company_id, (int) $itemId, $fromDate);
            }
        });

        return back()->with('success', 'Sale updated.');
    }

    /**
     * Delete a sale entirely, including every payment recorded
     * against it. The frontend has already shown exactly what will
     * be deleted and required explicit confirmation before this
     * request is ever sent — see Sales/Index.vue.
     */
    public function destroy(Sale $sale): RedirectResponse
    {
        $this->authorizeDelete();

        DB::transaction(function () use ($sale) {
            // Captured before anything is touched, so the snapshot
            // reflects exactly what existed the instant before
            // deletion — including the lines, which are gone from
            // the row itself by the time anyone reads this back.
            $this->logDeletion(
                $sale,
                "Sale #{$sale->id} — ".($sale->customer?->name ?? 'Unknown customer').' — '.number_format((float) $sale->amount, 2),
                ['lines' => $sale->lines->toArray()]
            );

            // Captured before the lines are gone, so the item(s) this
            // sale drew stock from can have their moving-average
            // ledger recalculated forward from this date — removing
            // this sale's outbound movement is exactly as much a
            // change to that ledger as adding one was.
            $itemIds  = $sale->lines()->pluck('item_id')->filter()->unique();
            $date     = $sale->date->toDateString();
            $companyId = (int) $sale->company_id;

            $this->journal->reverseAllForPayable($sale);
            $sale->payments()->delete();
            $sale->installments()->delete();
            $sale->lines()->delete();
            $sale->delete();

            foreach ($itemIds as $itemId) {
                $this->costing->onItemMovementChanged($companyId, (int) $itemId, $date);
            }
        });

        return back()->with('success', 'Sale deleted.');
    }

    /**
     * Builds one sale line's row attributes. qty/unit_price stay in
     * whatever unit was actually chosen on the form (e.g. 5 Carton
     * @ 200/Carton) — line_total is simply qty * unit_price, exactly
     * as before. uom/qty_per_uom/base_unit_name are the unit that
     * was chosen; defaulting to "1 base unit" (qty_per_uom = 1) for
     * a free-text line or one submitted with no unit info at all, so
     * qty is then read as already being in base units — the same
     * behavior this app had before unit choice existed.
     *
     * @param  array{item_id?:int|null, qty:float, uom?:string|null, qty_per_uom?:float|null, base_unit_name?:string|null, unit_price:float}  $line
     */
    private function lineAttributes(array $line): array
    {
        return [
            'item_id'        => $line['item_id'] ?? null,
            'qty'            => $line['qty'],
            'uom'            => $line['uom'] ?? 'unit',
            'qty_per_uom'    => $line['qty_per_uom'] ?? 1,
            'base_unit_name' => $line['base_unit_name'] ?? 'unit',
            'unit_price'     => $line['unit_price'],
            'line_total'     => round($line['qty'] * $line['unit_price'], 2),
        ];
    }

    /**
     * Prices every line that references a tracked item at the
     * moving average — see MovingAverageCostingService — and
     * corrects Cost of Goods Sold for this sale, and for anything
     * downstream, to match. Replaces the old direct call to
     * Item::averagePurchaseCost() (removed Sep 2026): that method
     * summed EVERY purchase ever made for the item, never removing
     * what had already been sold; this recalculates the item's
     * actual day-by-day stock pool instead. Lines with no item_id
     * (a free-text/service line) are simply not part of any item's
     * pool and contribute nothing.
     *
     * @param  array<int, array{item_id?:int|null, qty:float}>  $lines
     */
    private function recalculateCostsFor(int $companyId, array $lines, string $fromDate): void
    {
        $itemIds = collect($lines)->pluck('item_id')->filter()->unique();

        foreach ($itemIds as $itemId) {
            $this->costing->onItemMovementChanged($companyId, (int) $itemId, $fromDate);
        }
    }
}