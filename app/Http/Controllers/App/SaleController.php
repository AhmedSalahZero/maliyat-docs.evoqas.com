<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreSaleRequest;
use App\Http\Requests\App\UpdateSaleRequest;
use App\Models\Customer;
use App\Models\Item;
use App\Models\PaymentChannel;
use App\Models\Sale;
use App\Services\JournalService;
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
    ) {}

    public function index(): Response
    {
        $sales = Sale::query()
            ->with(['customer:id,name', 'lines.item:id,name', 'payments'])
            ->latest('date')
            ->latest('id')
            ->paginate(20)
            ->through(fn (Sale $sale) => [
                'id'          => $sale->id,
                'date'        => $sale->date->toDateString(),
                'due_date'    => $sale->due_date?->toDateString(),
                'customer_id' => $sale->customer_id,
                'customer'    => $sale->customer?->name,
                'lines'       => $sale->lines->map(fn ($line) => [
                    'item_id'    => $line->item_id,
                    'item'       => $line->item?->name,
                    'qty'        => (float) $line->qty,
                    'unit_price' => (float) $line->unit_price,
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
            'items'          => Item::query()->orderBy('name')->get(['id', 'name']),
            'paymentChannels'=> PaymentChannel::query()->orderBy('name')->get(['id', 'name']),
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
                'customer_id' => $data['customer_id'],
                'date'        => $data['date'],
                'subtotal'    => $subtotal,
                'vat_rate'    => $vatRate,
                'vat_amount'  => $vatAmount,
                'amount'      => $total,
                'due_date'    => $dueDate,
                'created_by'  => auth()->id(),
            ]);

            foreach ($data['lines'] as $line) {
                $sale->lines()->create([
                    'item_id'    => $line['item_id'] ?? null,
                    'qty'        => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => round($line['qty'] * $line['unit_price'], 2),
                ]);
            }

            $this->journal->postSaleInvoice($sale);
            $this->postCogsForLines($sale, $data['lines']);

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
                $sale->lines()->create([
                    'item_id'    => $line['item_id'] ?? null,
                    'qty'        => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => round($line['qty'] * $line['unit_price'], 2),
                ]);
            }

            $freshSale = $sale->fresh();
            $this->journal->postSaleInvoice($freshSale);
            $this->postCogsForLines($freshSale, $data['lines']);
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
            $this->journal->reverseAllForPayable($sale);
            $sale->payments()->delete();
            $sale->installments()->delete();
            $sale->lines()->delete();
            $sale->delete();
        });

        return back()->with('success', 'Sale deleted.');
    }

    /**
     * Cost of Goods Sold for a sale = sum over every line that
     * references a tracked item of (qty sold × that item's current
     * weighted-average purchase cost — see Item::averagePurchaseCost()).
     * Lines with no item_id (a free-text/service line) contribute
     * nothing. An item never purchased yet has no average cost to
     * draw from, so it's skipped too — the sale still records fine,
     * it just has no COGS recognised, since there's no historical
     * cost information for the "already got value out of nothing"
     * question a stock-count audit would need to answer separately.
     */
    private function postCogsForLines(Sale $sale, array $lines): void
    {
        $itemIds = array_filter(array_column($lines, 'item_id'));
        $items   = Item::query()->whereIn('id', $itemIds)->get()->keyBy('id');

        $totalCogs = 0.0;

        foreach ($lines as $line) {
            $item = $items->get($line['item_id'] ?? null);
            if (! $item) {
                continue;
            }

            $avgCost = $item->averagePurchaseCost();
            if ($avgCost === null) {
                continue;
            }

            $totalCogs += $line['qty'] * $avgCost;
        }

        $this->journal->postCostOfGoodsSold($sale, round($totalCogs, 2));
    }
}
