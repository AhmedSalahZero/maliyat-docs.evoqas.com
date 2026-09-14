<?php

namespace App\Services;

use App\Models\Expense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — RecurringExpenseService
//  Location: app/Services/RecurringExpenseService.php
//
//  A "recurring expense" (rent, a subscription, an installment
//  plan) is just N ordinary Expense rows sharing one recurring_id,
//  each with its own scheduled date — NOT one row that repeats
//  itself. That's what lets each occurrence be paid individually
//  later through the normal Receive/Pay Money screen: it's just
//  another Expense with a balance(), exactly like a one-off one.
//
//  Only the FIRST occurrence's payment status comes from the setup
//  form (mode/method/amount_now) — occurrences 2..N are always
//  created unpaid, due on their own scheduled date, and get paid
//  as they come due.
// ══════════════════════════════════════════════════════════════════
class RecurringExpenseService
{
    public function __construct(
        private readonly PaymentRecorderService $paymentRecorder,
    ) {}

    /**
     * @param  array{
     *     vendor_id:int, category_id:int, date:string, amount:float,
     *     frequency:string, count:int,
     *     mode:string, method?:string, amount_now?:float|string|null,
     *     due_date?:string|null,
     * }  $data
     */
    public function createSeries(array $data): Expense
    {
        $recurringId = (string) Str::uuid();
        $count       = (int) $data['count'];

        $firstDueDate = $data['mode'] === 'now'
            ? null
            : $this->paymentRecorder->dueDate($data['due_date'] ?? null, null, $data['date']);

        return DB::transaction(function () use ($data, $recurringId, $count, $firstDueDate) {
            $first = null;

            for ($index = 1; $index <= $count; $index++) {
                $occurrenceDate = $this->occurrenceDate($data['date'], $data['frequency'], $index - 1);

                $expense = Expense::create([
                    'vendor_id'           => $data['vendor_id'],
                    'category_id'         => $data['category_id'],
                    'date'                => $occurrenceDate,
                    'amount'              => $data['amount'],
                    // First occurrence's due date comes from the form (or
                    // null if paid now); every later occurrence is simply
                    // due on the date it's scheduled for.
                    'due_date'            => $index === 1 ? $firstDueDate : $occurrenceDate,
                    'recurring_id'        => $recurringId,
                    'recurring_index'     => $index,
                    'recurring_count'     => $count,
                    'recurring_frequency' => $data['frequency'],
                    'created_by'          => auth()->id(),
                ]);

                if ($index === 1) {
                    $first = $expense;
                }
            }

            $this->paymentRecorder->apply($first, 'out', $data['mode'], [
                'date'       => $data['date'],
                'method'     => $data['method'] ?? 'cash',
                'amount_now' => $data['amount_now'] ?? null,
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
            ]);

            return $first;
        });
    }

    /**
     * Cancel every unpaid, not-yet-due occurrence left in a series
     * (e.g. the tenant moved out — stop billing future months).
     * Already-paid or already-due/overdue occurrences are left
     * alone; only genuinely future, untouched ones are removed.
     *
     * @return int  number of occurrences cancelled
     */
    public function cancelRemaining(string $recurringId): int
    {
        return Expense::query()
            ->inRecurringSeries($recurringId)
            ->whereDoesntHave('payments')
            ->where('date', '>', now()->toDateString())
            ->delete();
    }

    private function occurrenceDate(string $startDate, string $frequency, int $stepsAhead): string
    {
        $date = Carbon::parse($startDate);

        $date = match ($frequency) {
            'weekly'  => $date->addWeeks($stepsAhead),
            'monthly' => $date->addMonthsNoOverflow($stepsAhead),
            'q3'      => $date->addMonthsNoOverflow($stepsAhead * 3),
            'h6'      => $date->addMonthsNoOverflow($stepsAhead * 6),
            default   => $date->addMonthsNoOverflow($stepsAhead),
        };

        return $date->toDateString();
    }
}
