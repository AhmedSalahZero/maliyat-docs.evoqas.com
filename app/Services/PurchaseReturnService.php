<?php

namespace App\Services;

use App\Models\InventoryPurchase;
use App\Models\InventoryPurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — PurchaseReturnService
//  Location: app/Services/PurchaseReturnService.php
//
//  Records and removes a PURCHASE RETURN: goods we sent back to a
//  supplier after a purchase ("Apply quantity return" on the
//  Inventory Purchase screen). Mirror image of SaleReturnService.
//
//  All dated on the RETURN date:
//    - stock goes down, at the price the goods were BOUGHT at (the
//      stock average is then recalculated from what is left);
//    - Inventory (and VAT) go down;
//    - we owe the supplier less. If we had already paid for the
//      goods, the supplier gives the money back (cash / bank…) or
//      keeps it as CREDIT with them — the screen asks which.
//
//      amount = applied_amount + refund_amount
//        applied_amount  reduces what we still owe on the bill;
//        refund_amount   the rest — already paid.
//
//  Goods that were already sold or used in production cannot be sent
//  back (stock would go below zero on some day) — refused with the
//  same message used elsewhere for that situation.
//
//  A return cannot be edited, only deleted and entered again; the
//  original purchase cannot be edited or deleted while it has returns.
// ══════════════════════════════════════════════════════════════════
class PurchaseReturnService
{
    public function __construct(
        private readonly JournalService $journal,
        private readonly MovingAverageCostingService $costing,
        private readonly StockTimeline $timeline,
    ) {}

    /**
     * @return array<int, array{line: InventoryPurchaseLine, returned_qty: float, remaining_qty: float, returned_total: float, returned_vat: float, returned_wht: float}>
     *         keyed by purchase line id
     */
    public function lineStatus(InventoryPurchase $purchase): array
    {
        $returned = PurchaseReturnLine::query()
            ->whereIn('inventory_purchase_line_id', $purchase->lines()->pluck('id'))
            ->get()
            ->groupBy('inventory_purchase_line_id');

        $out = [];

        foreach ($purchase->lines()->get() as $line) {
            $rows = $returned->get($line->id, collect());
            $qty  = round((float) $rows->sum('qty'), 2);

            $out[$line->id] = [
                'line'           => $line,
                'returned_qty'   => $qty,
                'remaining_qty'  => max(0.0, round((float) $line->qty - $qty, 2)),
                'returned_total' => round((float) $rows->sum('line_total'), 2),
                'returned_vat'   => round((float) $rows->sum('vat_amount'), 2),
                'returned_wht'   => round((float) $rows->sum('withholding_amount'), 2),
            ];
        }

        return $out;
    }

    /**
     * What a return would be worth, without saving anything.
     *
     * @param  array<int, array{purchase_line_id:int|string, qty:float|string}>  $lines
     */
    public function preview(InventoryPurchase $purchase, array $lines): array
    {
        $status = $this->lineStatus($purchase);

        $rows = [];
        $subtotal = $vat = $wht = 0.0;

        foreach ($lines as $input) {
            $s   = $status[(int) $input['purchase_line_id']] ?? null;
            $qty = round((float) $input['qty'], 2);

            if (! $s || $qty <= 0) {
                continue;
            }

            /** @var InventoryPurchaseLine $line */
            $line = $s['line'];

            $lineTotal = $this->share((float) $line->line_total, $s['returned_total'], $qty, (float) $line->qty, $s['returned_qty']);
            $lineVat   = $this->share((float) $line->vat_amount, $s['returned_vat'], $qty, (float) $line->qty, $s['returned_qty']);
            $lineWht   = $this->share((float) $line->withholding_amount, $s['returned_wht'], $qty, (float) $line->qty, $s['returned_qty']);

            $baseQty = (float) $line->qty * ((float) $line->qty_per_uom ?: 1);

            $rows[] = [
                'inventory_purchase_line_id' => $line->id,
                'item_id'            => $line->item_id,
                'qty'                => $qty,
                'uom'                => $line->uom,
                'qty_per_uom'        => $line->qty_per_uom,
                'base_unit_name'     => $line->base_unit_name,
                'unit_price'         => $line->unit_price,
                'line_total'         => $lineTotal,
                'vat_amount'         => $lineVat,
                'withholding_amount' => $lineWht,
                // Informational: the price per base unit it was bought at.
                'unit_cost'          => $baseQty > 0 ? round((float) $line->line_total / $baseQty, 4) : 0,
                'remaining_qty'      => $s['remaining_qty'],
            ];

            $subtotal += $lineTotal;
            $vat      += $lineVat;
            $wht      += $lineWht;
        }

        $subtotal = round($subtotal, 2);
        $vat      = round($vat, 2);
        $wht      = round($wht, 2);
        $amount   = round($subtotal + $vat - $wht, 2);

        // The part that just reduces what we still owe on the bill —
        // never more than we actually owe.
        $owed    = max(0.0, round($purchase->balance(), 2));
        $applied = round(min($amount, $owed), 2);
        $refund  = round($amount - $applied, 2);

        return [
            'lines'              => $rows,
            'subtotal'           => $subtotal,
            'vat_amount'         => $vat,
            'withholding_amount' => $wht,
            'amount'             => $amount,
            'applied_amount'     => $applied,
            'refund_amount'      => $refund,
        ];
    }

    /**
     * The plain-language reason these goods cannot go back (they were
     * already sold or used, so stock would run short on some day), or
     * null when it is fine.
     *
     * @param  list<array{item_id:int|null, qty:float|int|string}>  $lines  returned lines (qty in the line's own unit)
     */
    public function stockProblem(string $date, array $lines): ?string
    {
        $byItem = [];

        foreach ($lines as $row) {
            if (empty($row['item_id'])) {
                continue;
            }

            $base = (float) $row['qty'] * ((float) ($row['qty_per_uom'] ?? 1) ?: 1);
            $byItem[(int) $row['item_id']][] = [$date, -$base];
        }

        // Several lines of the same item are judged together.
        foreach ($byItem as $itemId => $changes) {
            $byItem[$itemId] = [[$date, array_sum(array_column($changes, 1))]];
        }

        return $byItem === [] ? null : $this->timeline->removalProblem($byItem);
    }

    /**
     * Record the return. $data: date, lines [{purchase_line_id, qty}],
     * refund_method?, payment_channel_id?, note?
     */
    public function record(InventoryPurchase $purchase, array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($purchase, $data) {
            $purchase = InventoryPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            $preview = $this->preview($purchase, $data['lines']);

            if ($preview['lines'] === []) {
                throw ValidationException::withMessages(['lines' => __('errors.return_nothing')]);
            }

            foreach ($preview['lines'] as $row) {
                if ($row['qty'] > $row['remaining_qty'] + 0.004) {
                    $name = InventoryPurchaseLine::find($row['inventory_purchase_line_id'])?->item?->name ?? '';
                    throw ValidationException::withMessages(['lines' => __('errors.return_more_than_bought', ['item' => $name])]);
                }
            }

            if ($problem = $this->stockProblem($data['date'], $preview['lines'])) {
                throw ValidationException::withMessages(['lines' => $problem]);
            }

            $refund = $preview['refund_amount'];
            $method = $refund > 0 ? ($data['refund_method'] ?? null) : null;

            if ($refund > 0 && ! $method) {
                throw ValidationException::withMessages(['refund_method' => __('errors.return_refund_method_needed', ['amount' => number_format($refund, 2)])]);
            }

            // Money going back by bank / Visa / InstaPay / wallet must say
            // WHICH bank or operator, or the refund cannot be traced in
            // the books. Cash and "kept as credit" need no channel.
            if ($refund > 0 && ! in_array($method, ['cash', PurchaseReturn::CREDIT], true) && empty($data['payment_channel_id'])) {
                throw ValidationException::withMessages(['payment_channel_id' => __('errors.return_refund_channel_needed', ['amount' => number_format($refund, 2)])]);
            }

            $return = PurchaseReturn::create([
                'company_id'            => $purchase->company_id,
                'inventory_purchase_id' => $purchase->id,
                'vendor_id'             => $purchase->vendor_id,
                'date'                  => $data['date'],
                'subtotal'              => $preview['subtotal'],
                'vat_amount'            => $preview['vat_amount'],
                'withholding_amount'    => $preview['withholding_amount'],
                'amount'                => $preview['amount'],
                'applied_amount'        => $preview['applied_amount'],
                'refund_amount'         => $refund,
                'refund_method'         => $method,
                'payment_channel_id'    => ($method && $method !== PurchaseReturn::CREDIT && $method !== 'cash') ? ($data['payment_channel_id'] ?? null) : null,
                'note'                  => $data['note'] ?? null,
                'created_by'            => auth()->id(),
            ]);

            foreach ($preview['lines'] as $row) {
                unset($row['remaining_qty']);
                $return->lines()->create($row);
            }

            // Money the supplier gives back is a real cash/bank
            // movement (the Cash Flow report reads Payment rows); its
            // ledger entry is part of the return's own entry. Credit
            // kept with the supplier moves no money.
            if ($refund > 0 && $method !== PurchaseReturn::CREDIT) {
                $return->payments()->create([
                    'company_id'         => $purchase->company_id,
                    'date'               => $data['date'],
                    'amount'             => $refund,
                    'method'             => $method,
                    'payment_channel_id' => $return->payment_channel_id,
                    'direction'          => 'in',
                    'note'               => 'Refund — purchase return',
                ]);
            }

            $this->journal->postPurchaseReturn($return);
            $purchase->refreshReturnsApplied();

            $date = $return->date->toDateString();
            foreach ($return->lines()->whereNotNull('item_id')->pluck('item_id')->unique() as $itemId) {
                $this->costing->onItemMovementChanged((int) $purchase->company_id, (int) $itemId, $date);
            }

            return $return->fresh(['lines']);
        });
    }

    /**
     * Take a return back out. The goods come back into stock, so this
     * can never leave stock short and needs no stock check.
     */
    public function delete(PurchaseReturn $return): void
    {
        DB::transaction(function () use ($return) {
            $purchase  = $return->inventoryPurchase;
            $companyId = (int) $return->company_id;
            $date      = $return->date->toDateString();
            $itemIds   = $return->lines()->whereNotNull('item_id')->pluck('item_id')->unique();

            $this->journal->reverseEntriesFor($return);
            $return->payments()->delete();
            $return->lines()->delete();
            $return->delete();

            $purchase?->refreshReturnsApplied();

            foreach ($itemIds as $itemId) {
                $this->costing->onItemMovementChanged($companyId, (int) $itemId, $date);
            }
        });
    }

    private function share(float $lineValue, float $valueReturnedBefore, float $qty, float $lineQty, float $qtyReturnedBefore): float
    {
        if ($lineQty <= 0) {
            return 0.0;
        }

        if (abs(($qtyReturnedBefore + $qty) - $lineQty) < 0.005) {
            return max(0.0, round($lineValue - $valueReturnedBefore, 2));
        }

        return round($lineValue * $qty / $lineQty, 2);
    }
}
