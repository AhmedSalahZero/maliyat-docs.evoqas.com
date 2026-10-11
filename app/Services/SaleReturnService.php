<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SaleReturnService
//  Location: app/Services/SaleReturnService.php
//
//  Records and removes a SALES RETURN: goods a customer sent back
//  after a sale ("Apply quantity return" on the Sales screen).
//
//  What a return does, all dated on the RETURN date:
//    - stock goes back up, at the cost the original sale took the
//      goods out at (MovingAverageCostingService);
//    - revenue (and VAT) go down, and so does Cost of Goods Sold;
//    - the customer owes less. If they had already paid for the
//      returned goods, that money is handed back (cash / bank /
//      wallet…) or kept as CREDIT on their account — their choice.
//
//  The money split:  amount = applied_amount + refund_amount
//    applied_amount  reduces what is still owed on the invoice
//                    (never more than that);
//    refund_amount   the rest — already paid, so it goes back.
//
//  A return cannot be edited, only deleted and entered again; the
//  original sale cannot be edited or deleted while it has returns.
// ══════════════════════════════════════════════════════════════════
class SaleReturnService
{
    public function __construct(
        private readonly JournalService $journal,
        private readonly MovingAverageCostingService $costing,
        private readonly StockTimeline $timeline,
    ) {}

    /**
     * How much of each line of the sale can still be returned.
     *
     * @return array<int, array{line: SaleLine, returned_qty: float, remaining_qty: float, returned_total: float, returned_vat: float, returned_wht: float}>
     *         keyed by sale line id
     */
    public function lineStatus(Sale $sale): array
    {
        $returned = SaleReturnLine::query()
            ->whereIn('sale_line_id', $sale->lines()->pluck('id'))
            ->get()
            ->groupBy('sale_line_id');

        $out = [];

        foreach ($sale->lines()->get() as $line) {
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
     * Work out what a return would be worth, without saving anything.
     *
     * @param  array<int, array{sale_line_id:int|string, qty:float|string}>  $lines
     * @return array{
     *     lines: list<array<string, mixed>>, subtotal: float, vat_amount: float,
     *     withholding_amount: float, amount: float, applied_amount: float, refund_amount: float
     * }
     */
    public function preview(Sale $sale, array $lines): array
    {
        $status = $this->lineStatus($sale);

        $rows = [];
        $subtotal = $vat = $wht = 0.0;

        foreach ($lines as $input) {
            $s   = $status[(int) $input['sale_line_id']] ?? null;
            $qty = round((float) $input['qty'], 2);

            if (! $s || $qty <= 0) {
                continue;
            }

            /** @var SaleLine $line */
            $line = $s['line'];

            $lineTotal = $this->share((float) $line->line_total, $s['returned_total'], $qty, (float) $line->qty, $s['returned_qty']);
            $lineVat   = $this->share((float) $line->vat_amount, $s['returned_vat'], $qty, (float) $line->qty, $s['returned_qty']);
            $lineWht   = $this->share((float) $line->withholding_amount, $s['returned_wht'], $qty, (float) $line->qty, $s['returned_qty']);

            $rows[] = [
                'sale_line_id'       => $line->id,
                'item_id'            => $line->item_id,
                'qty'                => $qty,
                'uom'                => $line->uom,
                'qty_per_uom'        => $line->qty_per_uom,
                'base_unit_name'     => $line->base_unit_name,
                'unit_price'         => $line->unit_price,
                'line_total'         => $lineTotal,
                'vat_amount'         => $lineVat,
                'withholding_amount' => $lineWht,
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

        // The part that just reduces what the customer still owes —
        // never more than they actually owe (nothing if they overpaid).
        $owed    = max(0.0, round($sale->balance(), 2));
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
     * Record the return. $data: date, lines [{sale_line_id, qty}],
     * refund_method?, payment_channel_id?, note?
     */
    public function record(Sale $sale, array $data): SaleReturn
    {
        return DB::transaction(function () use ($sale, $data) {
            // Lock the sale so two returns submitted at the same moment
            // cannot both use up the same remaining quantity.
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            $preview = $this->preview($sale, $data['lines']);

            if ($preview['lines'] === []) {
                throw ValidationException::withMessages(['lines' => __('errors.return_nothing')]);
            }

            foreach ($preview['lines'] as $row) {
                $original = $sale->lines->firstWhere('id', $row['sale_line_id']) ?? SaleLine::find($row['sale_line_id']);
                if ($row['qty'] > $row['remaining_qty'] + 0.004) {
                    throw ValidationException::withMessages(['lines' => __('errors.return_more_than_sold', [
                        'item' => $original?->item?->name ?? '',
                    ])]);
                }
            }

            $refund = $preview['refund_amount'];
            $method = $refund > 0 ? ($data['refund_method'] ?? null) : null;

            if ($refund > 0 && ! $method) {
                throw ValidationException::withMessages(['refund_method' => __('errors.return_refund_method_needed', ['amount' => number_format($refund, 2)])]);
            }

            // Money going back by bank / Visa / InstaPay / wallet must say
            // WHICH bank or operator, or the refund cannot be traced in
            // the books. Cash and "kept as credit" need no channel.
            if ($refund > 0 && ! in_array($method, ['cash', SaleReturn::CREDIT], true) && empty($data['payment_channel_id'])) {
                throw ValidationException::withMessages(['payment_channel_id' => __('errors.return_refund_channel_needed', ['amount' => number_format($refund, 2)])]);
            }

            $return = SaleReturn::create([
                'company_id'         => $sale->company_id,
                'sale_id'            => $sale->id,
                'customer_id'        => $sale->customer_id,
                'date'               => $data['date'],
                'subtotal'           => $preview['subtotal'],
                'vat_amount'         => $preview['vat_amount'],
                'withholding_amount' => $preview['withholding_amount'],
                'amount'             => $preview['amount'],
                'applied_amount'     => $preview['applied_amount'],
                'refund_amount'      => $refund,
                'refund_method'      => $method,
                'payment_channel_id' => ($method && $method !== SaleReturn::CREDIT && $method !== 'cash') ? ($data['payment_channel_id'] ?? null) : null,
                'note'               => $data['note'] ?? null,
                'created_by'         => auth()->id(),
            ]);

            foreach ($preview['lines'] as $row) {
                unset($row['remaining_qty']);
                $return->lines()->create($row);
            }

            // Money handed back to the customer is a real cash/bank
            // movement, so it gets a Payment row (the Cash Flow report
            // reads those). Its ledger entry is part of the return's
            // own entry below. Credit kept on account moves no money.
            if ($refund > 0 && $method !== SaleReturn::CREDIT) {
                $return->payments()->create([
                    'company_id'         => $sale->company_id,
                    'date'               => $data['date'],
                    'amount'             => $refund,
                    'method'             => $method,
                    'payment_channel_id' => $return->payment_channel_id,
                    'direction'          => 'out',
                    'note'               => 'Refund — sales return',
                ]);
            }

            $this->journal->postSaleReturn($return);
            $sale->refreshReturnsApplied();

            // Stock back in + Cost of Goods Sold reversed, per item.
            $date = $return->date->toDateString();
            foreach ($return->lines()->whereNotNull('item_id')->pluck('item_id')->unique() as $itemId) {
                $this->costing->onItemMovementChanged((int) $sale->company_id, (int) $itemId, $date);
            }

            return $return->fresh(['lines']);
        });
    }

    /**
     * Why this return cannot be deleted, or null when it can. Deleting
     * it takes the goods out of stock again, so it is refused if a
     * later sale or production run is already using them.
     */
    public function deleteProblem(SaleReturn $return): ?string
    {
        $date = $return->date->toDateString();
        $byItem = [];

        foreach ($return->lines()->whereNotNull('item_id')->get() as $line) {
            $byItem[(int) $line->item_id][0] = [$date, ($byItem[(int) $line->item_id][0][1] ?? 0.0) - $line->baseQty()];
        }

        return $byItem === [] ? null : $this->timeline->removalProblem($byItem);
    }

    /**
     * Take a return back out (the return was entered by mistake).
     * Stock goes down again, so the caller must already have checked
     * that nothing later needs those goods — see
     * SaleReturnController::destroy().
     */
    public function delete(SaleReturn $return): void
    {
        DB::transaction(function () use ($return) {
            $sale      = $return->sale;
            $companyId = (int) $return->company_id;
            $date      = $return->date->toDateString();
            $itemIds   = $return->lines()->whereNotNull('item_id')->pluck('item_id')->unique();

            $this->journal->reverseEntriesFor($return);
            $return->payments()->delete();
            $return->lines()->delete();
            $return->delete();

            $sale?->refreshReturnsApplied();

            foreach ($itemIds as $itemId) {
                $this->costing->onItemMovementChanged($companyId, (int) $itemId, $date);
            }
        });
    }

    /**
     * The share of a line's value that belongs to $qty returned units.
     * The LAST units of a line take whatever is left, so the pieces of
     * several partial returns add up to the original line to the cent.
     */
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
