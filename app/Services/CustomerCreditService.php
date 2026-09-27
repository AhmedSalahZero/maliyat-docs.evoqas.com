<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — CustomerCreditService
//  Location: app/Services/CustomerCreditService.php
//
//  A customer's CREDIT is money they paid that no invoice has used
//  yet — an advance, or the extra when they paid more than an
//  invoice was worth (audit finding 4.6).
//
//    created by  a receipt marked is_customer_credit (posted to
//                Customer Credits, account 2300 — a liability);
//    used by     a payment against one of their invoices with
//                method 'credit' (no money moves: 2300 → Receivable).
//
//  Available credit = everything received as credit − everything
//  already used. Deleting an invoice that used credit deletes that
//  payment too, so the credit simply becomes available again.
// ══════════════════════════════════════════════════════════════════
class CustomerCreditService
{
    /** Payment method used when credit (not money) settles an invoice. */
    public const METHOD = 'credit';

    /**
     * How much credit one customer has available right now.
     *
     * @param  int|null  $ignorePaymentId  Leave one payment out — used
     *                                     when checking whether removing
     *                                     a credit receipt is safe.
     */
    public function available(int $customerId, ?int $ignorePaymentId = null): float
    {
        return (float) ($this->availableFor([$customerId], $ignorePaymentId)->get($customerId) ?? 0.0);
    }

    /**
     * Available credit for several customers in two queries,
     * whatever the number of customers (for lists).
     *
     * @param  iterable<int>  $customerIds
     * @return Collection<int, float>  customer id => available credit
     */
    public function availableFor(iterable $customerIds, ?int $ignorePaymentId = null): Collection
    {
        $ids = collect($customerIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $received = Payment::query()
            ->whereIn('customer_id', $ids)
            ->where('is_customer_credit', true)
            ->where('direction', 'in')
            ->when($ignorePaymentId, fn ($q) => $q->whereKeyNot($ignorePaymentId))
            ->groupBy('customer_id')
            ->select('customer_id', DB::raw('SUM(amount) as total'))
            ->pluck('total', 'customer_id');

        $used = Payment::query()
            ->join('sales', function ($join) {
                $join->on('sales.id', '=', 'payments.payable_id')
                    ->where('payments.payable_type', Sale::class);
            })
            ->whereColumn('sales.company_id', 'payments.company_id')
            ->whereIn('sales.customer_id', $ids)
            ->where('payments.method', self::METHOD)
            ->when($ignorePaymentId, fn ($q) => $q->where('payments.id', '!=', $ignorePaymentId))
            ->groupBy('sales.customer_id')
            ->select('sales.customer_id', DB::raw('SUM(payments.amount) as total'))
            ->pluck('total', 'customer_id');

        return $ids->mapWithKeys(fn (int $id) => [
            $id => max(0.0, round((float) ($received[$id] ?? 0) - (float) ($used[$id] ?? 0), 2)),
        ]);
    }
}
