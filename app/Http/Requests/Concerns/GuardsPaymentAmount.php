<?php

namespace App\Http\Requests\Concerns;

use App\Models\EquipmentPurchase;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Models\Sale;
use App\Support\FinancialRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — GuardsPaymentAmount
//  Location: app/Http/Requests/Concerns/GuardsPaymentAmount.php
//
//  Stops a payment settling more than the record it is settling
//  actually owes. Validation used to check only that the amount was
//  a positive number, so receiving 100,000 against a 100 invoice
//  went straight through and left that invoice on a balance of
//  -99,900 — a negative debt that then fed the open-invoice
//  worklist, the customer statement, and Accounts Receivable in the
//  ledger, all of them quietly wrong.
//
//  Genuine overpayments do happen (a customer rounds up, pays two
//  invoices with one transfer). The app's answer for those is a
//  standalone receipt tagged to the customer, which is what the
//  "or log a generic receipt" half of the screen is for — money
//  that really arrived, recorded as revenue, without pretending an
//  invoice was worth more than it was.
//
//  The tolerance used below is FinancialRules::AMOUNT_TOLERANCE, the
//  same one the rest of the app uses to decide "close enough to
//  zero" (see Sale::isPaid()), so a payment that settles a balance
//  exactly is never rejected because of a rounding cent.
// ══════════════════════════════════════════════════════════════════
trait GuardsPaymentAmount
{
    /**
     * The three tables "Pay Money" can settle against, keyed by the
     * payable_type string the form sends.
     *
     * @var array<string, class-string<Model>>
     */
    private const BILL_TYPES = [
        'expense'            => Expense::class,
        'inventory_purchase' => InventoryPurchase::class,
        'equipment_purchase' => EquipmentPurchase::class,
    ];

    /**
     * Add an error to `amount` when it would settle more than the
     * record still owes.
     *
     * @param  float  $creditBack  What this payment already counts
     *                             towards the record's paid total —
     *                             non-zero only when EDITING a
     *                             payment, where its current amount
     *                             has to be added back before asking
     *                             what is left, or correcting a
     *                             payment to the same figure would
     *                             be rejected as an overpayment of
     *                             itself.
     */
    protected function rejectOverpayment(Validator $validator, ?Model $payable, ?float $amount, float $creditBack = 0.0): void
    {
        if (! $payable || $amount === null || $amount <= 0) {
            return;
        }

        $remaining = round((float) $payable->amount - $payable->paidAmount() + $creditBack, 2);

        if ($amount <= $remaining + FinancialRules::AMOUNT_TOLERANCE) {
            return;
        }

        $validator->errors()->add('amount', __('errors.payment_exceeds_balance', [
            'remaining' => number_format(max($remaining, 0), 2),
        ]));
    }

    /**
     * The Sale a receipt names, if it named one. Resolved through
     * the model so BelongsToCompany's global scope applies — another
     * company's invoice id simply resolves to null here.
     */
    protected function resolveSale(): ?Sale
    {
        $id = $this->input('sale_id');

        return $id ? Sale::find($id) : null;
    }

    /**
     * The bill a payment names, if it named one.
     */
    protected function resolveBill(): ?Model
    {
        $model = self::BILL_TYPES[$this->input('payable_type')] ?? null;
        $id    = $this->input('payable_id');

        return ($model && $id) ? $model::find($id) : null;
    }
}
