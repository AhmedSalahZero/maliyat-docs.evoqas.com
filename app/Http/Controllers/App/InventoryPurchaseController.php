<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreInventoryPurchaseRequest;
use App\Http\Requests\App\UpdateInventoryPurchaseRequest;
use App\Models\InventoryPurchase;
use App\Models\Item;
use App\Models\PaymentChannel;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Services\MovingAverageCostingService;
use App\Services\PaymentRecorderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — InventoryPurchaseController
//  Location: app/Http/Controllers/App/InventoryPurchaseController.php
//
//  "Buy from {vendor}: {item} {qty} {uom} = {qty_per_uom} {unit} @
//   {unit cost} ... for {total}, paid {mode}." Each line snapshots
//   its own UOM breakdown (see inventory_purchase_lines migration)
//   so stock math stays correct even if an item's default UOM
//   changes later — but the Item itself also remembers the most
//   recently used UOM definition, so the NEXT purchase of that item
//   pre-fills it automatically (matches ledger-prototype-v8.html's
//   submitInventory(): "remember this UOM definition on the item").
//
//  Inventory value uses a TRUE MOVING AVERAGE cost per item (see
//  MovingAverageCostingService), recalculated day by day as stock is
//  bought, sold, and consumed by production — the old
//  Item::averagePurchaseCost() this used to defer to has been
//  removed (Sep 2026). Every purchase saved here triggers that
//  engine (see recalculateCostsFor() below) to price it and, if the
//  edit or delete touches an earlier date, cascade the correction
//  forward through anything downstream — see SaleController's Cost
//  of Goods Sold handling for the other half of this.
//
//  Edit/Delete follow the same pattern as Sales/Expenses — see
//  SaleController's class doc comment for the full reasoning.
// ══════════════════════════════════════════════════════════════════
class InventoryPurchaseController extends Controller
{
    public function __construct(
        private readonly PaymentRecorderService $paymentRecorder,
        private readonly JournalService $journal,
        private readonly MovingAverageCostingService $costing,
    ) {}

    public function index(): Response
    {
        $purchases = InventoryPurchase::query()
            ->with(['vendor:id,name', 'lines.item:id,name', 'payments'])
            ->latest('date')
            ->latest('id')
            ->paginate(20)
            ->through(fn (InventoryPurchase $purchase) => [
                'id'          => $purchase->id,
                'date'        => $purchase->date->toDateString(),
                'due_date'    => $purchase->due_date?->toDateString(),
                'vendor_id'   => $purchase->vendor_id,
                'vendor'      => $purchase->vendor?->name,
                'lines'       => $purchase->lines->map(fn ($line) => [
                    'item_id'        => $line->item_id,
                    'item'           => $line->item?->name,
                    'qty'            => (float) $line->qty,
                    'uom'            => $line->uom,
                    'qty_per_uom'    => (float) $line->qty_per_uom,
                    'base_unit_name' => $line->base_unit_name,
                    'unit_price'     => (float) $line->unit_price,
                ]),
                'vat_rate'       => (float) $purchase->vat_rate,
                'amount'         => (float) $purchase->amount,
                'paid_amount'    => $purchase->paidAmount(),
                'balance'        => $purchase->balance(),
                'is_paid'        => $purchase->isPaid(),
                'payments_count' => $purchase->payments->count(),
                // Individual payments so the edit view can show and
                // remove them — see PaymentController::destroy().
                'payments'       => $purchase->payments->map(fn ($payment) => [
                    'id'     => $payment->id,
                    'date'   => $payment->date->toDateString(),
                    'amount' => (float) $payment->amount,
                    'method' => $payment->method,
                    // Needed so the edit form can prefill the bank /
                    // operator the payment actually went through.
                    'payment_channel_id' => $payment->payment_channel_id,
                ])->values(),
            ]);

        return Inertia::render('App/InventoryPurchases/Index', [
            'vendors'         => Vendor::query()->orderBy('name')->get(['id', 'name']),
            // Raw materials aren't sold directly — see the Item type
            // note in Item.php's class doc comment.
            'items'           => Item::query()->whereIn('type', ['trading', 'raw_material'])->orderBy('name')->get(['id', 'name', 'uom', 'qty_per_uom', 'base_unit_name']),
            'paymentChannels' => PaymentChannel::query()->orderBy('name')->get(['id', 'name']),
            'purchases'       => $purchases,
        ]);
    }

    public function store(StoreInventoryPurchaseRequest $request): RedirectResponse
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

        // Record, lines, ledger entries and opening payment commit
        // as one unit — see the same note in SaleController::store().
        DB::transaction(function () use ($data, $subtotal, $vatRate, $vatAmount, $total, $dueDate, $schedule) {
            $purchase = InventoryPurchase::create([
                'vendor_id'  => $data['vendor_id'],
                'date'       => $data['date'],
                'subtotal'   => $subtotal,
                'vat_rate'   => $vatRate,
                'vat_amount' => $vatAmount,
                'amount'     => $total,
                'due_date'   => $dueDate,
                'created_by' => auth()->id(),
            ]);

            $this->createLines($purchase, $data['lines']);

            $this->journal->postInventoryPurchaseInvoice($purchase);

            $this->recalculateCostsFor($purchase->company_id, $data['lines'], $purchase->date);

            $payment = $this->paymentRecorder->apply($purchase, 'out', $data['mode'], [
                'date'       => $data['date'],
                'method'     => $data['method'] ?? 'cash',
                'amount_now' => $data['amount_now'] ?? null,
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
            ], $schedule);

            if ($payment) {
                $this->journal->postBillPayment($payment);
            }
        });

        return back()->with('success', 'Inventory purchase recorded.');
    }

    /**
     * Edit an existing purchase's vendor/lines/VAT. Existing
     * payments are left untouched (see SaleController's reasoning).
     */
    public function update(UpdateInventoryPurchaseRequest $request, InventoryPurchase $inventoryPurchase): RedirectResponse
    {
        $data = $request->validated();

        $subtotal = collect($data['lines'])->sum(
            fn (array $line) => round($line['qty'] * $line['unit_price'], 2)
        );
        $vatRate   = (float) ($data['vat_rate'] ?? 0);
        $vatAmount = round($subtotal * $vatRate / 100, 2);
        $total     = round($subtotal + $vatAmount, 2);

        DB::transaction(function () use ($inventoryPurchase, $data, $subtotal, $vatRate, $vatAmount, $total) {
            // Captured before anything changes — see the identical
            // note in SaleController::update().
            $oldItemIds = $inventoryPurchase->lines()->pluck('item_id')->filter()->unique();
            $oldDate    = $inventoryPurchase->date->toDateString();

            $this->journal->reverseEntriesFor($inventoryPurchase);

            $inventoryPurchase->update([
                'vendor_id'  => $data['vendor_id'],
                'date'       => $data['date'],
                'subtotal'   => $subtotal,
                'vat_rate'   => $vatRate,
                'vat_amount' => $vatAmount,
                'amount'     => $total,
                // Correctable now — see the note in the Update*Request.
                'due_date'   => array_key_exists('due_date', $data) ? $data['due_date'] : $inventoryPurchase->due_date,
            ]);

            $inventoryPurchase->lines()->delete();
            $this->createLines($inventoryPurchase, $data['lines']);

            $fresh = $inventoryPurchase->fresh();
            $this->journal->postInventoryPurchaseInvoice($fresh);

            // Recalculate from whichever is earlier, across every
            // item on the old lines OR the new lines — see the
            // identical note in SaleController::update().
            $newItemIds = collect($data['lines'])->pluck('item_id')->filter()->unique();
            $itemIds    = $oldItemIds->merge($newItemIds)->unique();
            $fromDate   = min($oldDate, $fresh->date->toDateString());

            foreach ($itemIds as $itemId) {
                $this->costing->onItemMovementChanged((int) $fresh->company_id, (int) $itemId, $fromDate);
            }
        });

        return back()->with('success', 'Inventory purchase updated.');
    }

    /**
     * Delete a purchase entirely, including its payments and lines.
     * The item(s) it supplied stock to have their moving-average
     * ledger recalculated forward from this date once the lines are
     * gone — deleting a purchase is a real correction to that
     * item's cost history, not a soft hide, so that's the correct
     * behavior, not a side effect to guard against.
     */
    public function destroy(InventoryPurchase $inventoryPurchase): RedirectResponse
    {
        $this->authorizeDelete();

        DB::transaction(function () use ($inventoryPurchase) {
            $this->logDeletion(
                $inventoryPurchase,
                "Inventory purchase #{$inventoryPurchase->id} — ".($inventoryPurchase->vendor?->name ?? 'Unknown vendor').' — '.number_format((float) $inventoryPurchase->amount, 2),
                ['lines' => $inventoryPurchase->lines->toArray()]
            );

            $itemIds   = $inventoryPurchase->lines()->pluck('item_id')->filter()->unique();
            $date      = $inventoryPurchase->date->toDateString();
            $companyId = (int) $inventoryPurchase->company_id;

            $this->journal->reverseAllForPayable($inventoryPurchase);
            $inventoryPurchase->payments()->delete();
            $inventoryPurchase->installments()->delete();
            $inventoryPurchase->lines()->delete();
            $inventoryPurchase->delete();

            foreach ($itemIds as $itemId) {
                $this->costing->onItemMovementChanged($companyId, (int) $itemId, $date);
            }
        });

        return back()->with('success', 'Inventory purchase deleted.');
    }

    /**
     * @param  array<int, array{item_id:int, qty:float}>  $lines
     */
    private function recalculateCostsFor(int $companyId, array $lines, \Illuminate\Support\Carbon|string $fromDate): void
    {
        $date = is_string($fromDate) ? $fromDate : $fromDate->toDateString();
        $itemIds = collect($lines)->pluck('item_id')->filter()->unique();

        foreach ($itemIds as $itemId) {
            $this->costing->onItemMovementChanged($companyId, (int) $itemId, $date);
        }
    }

    /**
     * @param  array<int, array{item_id:int, qty:float, uom?:string|null, qty_per_uom?:float|null, base_unit_name?:string|null, unit_price:float}>  $lines
     */
    private function createLines(InventoryPurchase $purchase, array $lines): void
    {
        foreach ($lines as $line) {
            $purchase->lines()->create([
                'item_id'        => $line['item_id'],
                'qty'            => $line['qty'],
                'uom'            => $line['uom'] ?? 'Carton',
                'qty_per_uom'    => $line['qty_per_uom'] ?? 1,
                'base_unit_name' => $line['base_unit_name'] ?? 'unit',
                'unit_price'     => $line['unit_price'],
                'line_total'     => round($line['qty'] * $line['unit_price'], 2),
            ]);

            // Remember this UOM definition on the item itself, so the
            // NEXT purchase of it pre-fills automatically instead of
            // starting blank every time.
            Item::whereKey($line['item_id'])->update([
                'uom'            => $line['uom'] ?? 'Carton',
                'qty_per_uom'    => $line['qty_per_uom'] ?? 1,
                'base_unit_name' => $line['base_unit_name'] ?? 'unit',
            ]);
        }
    }
}
