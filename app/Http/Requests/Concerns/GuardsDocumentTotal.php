<?php

namespace App\Http\Requests\Concerns;

use App\Support\FinancialRules;
use Illuminate\Contracts\Validation\Validator;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — GuardsDocumentTotal
//  Location: app/Http/Requests/Concerns/GuardsDocumentTotal.php
//
//  Two checks that only make sense once a document's lines have been
//  added up, so neither can live in rules().
//
//  1. The TOTAL has to fit the column too. Capping each line at
//     FinancialRules::MAX_AMOUNT is not enough on its own: fifty
//     lines each just under the cap still sum past what
//     decimal(12,2) can hold, and the failure surfaces as a 500 from
//     MySQL rather than as a message on the form.
//
//  2. "Paid now" on a partial cannot exceed what the document is
//     worth. Entering a sale of 500 and paying 5,000 against it used
//     to be accepted, producing a brand-new invoice that was already
//     overpaid the moment it was created — the same negative-balance
//     problem GuardsPaymentAmount closes on the Receive/Pay Money
//     side, reached through a different door.
// ══════════════════════════════════════════════════════════════════
trait GuardsDocumentTotal
{
    /** Half a cent — matches GuardsPaymentAmount and Sale::isPaid(). */
    private const TOTAL_TOLERANCE = 0.004;

    /**
     * Sum qty x unit_price across a `lines` array the way the
     * controller will, so validation and the controller cannot
     * disagree about what the document is worth.
     */
    protected function documentLineTotal(string $key = 'lines'): float
    {
        return round(collect($this->input($key, []))
            ->sum(fn ($line) => round(
                (float) ($line['qty'] ?? 0) * (float) ($line['unit_price'] ?? 0), 2
            )), 2);
    }

    /**
     * Reject a document whose total, VAT included, is past what any
     * money column in this schema can hold.
     */
    protected function rejectTotalAboveCeiling(Validator $validator, float $subtotal, string $field = 'lines'): void
    {
        $vatRate = (float) ($this->input('vat_rate') ?? 0);
        $total   = round($subtotal + round($subtotal * $vatRate / 100, 2), 2);

        if ($total > FinancialRules::MAX_AMOUNT) {
            $validator->errors()->add($field, __('errors.total_too_large', [
                'max' => number_format(FinancialRules::MAX_AMOUNT, 2),
            ]));
        }
    }

    /**
     * Reject a "paid now" figure larger than the document itself.
     * Only applies to mode `partial` — `now` pays the full amount by
     * definition and never reads amount_now.
     */
    protected function rejectAmountNowAboveTotal(Validator $validator, float $total): void
    {
        if ($this->input('mode') !== 'partial') {
            return;
        }

        $amountNow = (float) $this->input('amount_now', 0);

        if ($amountNow > $total + self::TOTAL_TOLERANCE) {
            $validator->errors()->add('amount_now', __('errors.paid_now_exceeds_total', [
                'total' => number_format($total, 2),
            ]));
        }
    }
}
