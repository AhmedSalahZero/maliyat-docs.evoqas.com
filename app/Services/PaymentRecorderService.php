<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — PaymentRecorderService
//  Location: app/Services/PaymentRecorderService.php
//
//  Every "record a transaction" controller (SaleController,
//  ExpenseController, InventoryPurchaseController,
//  EquipmentPurchaseController) works the same way: create the
//  transaction row itself, then decide what actually happened to
//  the cash — paid in full now, partially paid with the rest due
//  later, nothing paid yet (credit), or split into a scheduled
//  installment plan. That decision is identical across all four,
//  so it lives here instead of being copy-pasted four times.
//
//  $payable can be any model with payments()/installments()
//  MorphMany relations, an `amount` attribute, and company_id —
//  i.e. Sale, Expense, InventoryPurchase, or EquipmentPurchase. No
//  shared interface exists for these (kept simple on purpose), so
//  this is typed loosely against the base Model and relies on that
//  shared shape.
// ══════════════════════════════════════════════════════════════════
class PaymentRecorderService
{
    /**
     * Resolve the due date for a transaction that isn't fully paid
     * up front: an explicit due_date wins, otherwise due_in_days is
     * added to the transaction date, otherwise fall back to a
     * plain net-30 default so a due date always exists whenever one
     * is actually needed (mode 'later' or 'partial'). Not used for
     * mode 'installment' — see buildInstallmentSchedule() instead.
     */
    public function dueDate(?string $dueDate, ?int $dueInDays, string $fromDate): string
    {
        if (! empty($dueDate)) {
            return $dueDate;
        }

        if (! empty($dueInDays)) {
            return Carbon::parse($fromDate)->addDays($dueInDays)->toDateString();
        }

        return Carbon::parse($fromDate)->addDays(30)->toDateString();
    }

    /**
     * Build an N-payment installment schedule — same math as the
     * prototype's collectPayment() installment branch: split the
     * total evenly, and let the LAST installment absorb whatever a
     * few cents of rounding leaves over so the sum always equals
     * the total exactly.
     *
     * @return list<array{sequence:int, due_date:string, amount:float}>
     */
    public function buildInstallmentSchedule(float $total, string $fromDate, int $count, int $intervalDays): array
    {
        $count        = max(2, $count);
        $intervalDays = max(1, $intervalDays);
        $perInstallment = round($total / $count, 2);

        $schedule  = [];
        $allocated = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $isLast = $i === $count - 1;
            $amount = $isLast ? round($total - $allocated, 2) : $perInstallment;
            $allocated += $amount;

            $schedule[] = [
                'sequence' => $i + 1,
                'due_date' => Carbon::parse($fromDate)->addDays($intervalDays * ($i + 1))->toDateString(),
                'amount'   => $amount,
            ];
        }

        return $schedule;
    }

    /**
     * Apply the actual cash movement (and/or installment schedule)
     * for a just-created transaction.
     *
     * @param  Model  $payable  Sale|Expense|InventoryPurchase|EquipmentPurchase
     * @param  string  $direction  'in' (Sale) or 'out' (everything else)
     * @param  string  $mode  'now' | 'partial' | 'later' | 'installment'
     * @param  array{date:string, method?:string, amount_now?:float|string|null, payment_channel_id?:int|null}  $data
     * @param  list<array{sequence:int, due_date:string, amount:float}>  $installmentSchedule
     *         Only used when $mode === 'installment' — pass the result of
     *         buildInstallmentSchedule(). Ignored for every other mode.
     * @return \App\Models\Payment|null  The Payment row created, if any —
     *         null for 'later'/'installment' (nothing paid yet). Callers
     *         pass this straight to JournalService::postSaleReceipt() /
     *         postBillPayment() so the GL stays in sync with the cash
     *         ledger without this service needing to know about accounts.
     */
    public function apply(Model $payable, string $direction, string $mode, array $data, array $installmentSchedule = []): ?Payment
    {
        // 'installment' — nothing paid yet, just persist the schedule
        // for display (open bills list, etc.). The balance stays the
        // full amount until real payments come in the normal way.
        if ($mode === 'installment') {
            if (! empty($installmentSchedule)) {
                $payable->installments()->createMany(
                    array_map(fn (array $row) => [
                        'company_id' => $payable->company_id,
                        ...$row,
                    ], $installmentSchedule)
                );
            }

            return null;
        }

        // 'later' — nothing paid yet, the whole amount sits as an
        // open balance until someone pays it via Receive/Pay Money.
        if ($mode === 'later') {
            return null;
        }

        $amount = $mode === 'partial'
            ? (float) ($data['amount_now'] ?? 0)
            : (float) $payable->amount; // 'now' — paid in full

        if ($amount <= 0) {
            return null;
        }

        return $payable->payments()->create([
            'company_id' => $payable->company_id,
            'date'       => $data['date'],
            'amount'     => $amount,
            'method'     => $data['method'] ?? 'cash',
            'payment_channel_id' => $data['payment_channel_id'] ?? null,
            'direction'  => $direction,
        ]);
    }
}
