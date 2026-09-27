<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\JournalEntry;
use App\Support\DeletionLogger;
use App\Support\FinancialRules;
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
//
//  ── Posting to the ledger (fixed Sep 2026, audit finding 3.1) ────
//  This service used to create the rows and post NOTHING — not the
//  bill, not even the first occurrence's payment. Rent and salaries
//  set up here never reached the P&L, the Balance Sheet or cash.
//
//  Now each occurrence posts its own "Expense billed" entry dated on
//  its own date, the moment that date is reached — an expense is
//  incurred in the period it belongs to, not months ahead:
//    - createSeries() posts every occurrence already due (normally
//      just the first) plus the first occurrence's payment, at once;
//    - postDueOccurrences() posts the rest as their dates arrive. It
//      is run by the PostDueDepreciation middleware on the first page
//      a company opens each day (the same catch-up that already
//      posts depreciation), so no server cron job is needed and a
//      missed day is simply caught up the next time.
//  An occurrence counts as posted once ANY journal entry points at
//  it, so nothing is ever posted twice (and series created by the
//  demo seeder, which posts its own entries, are left alone).
// ══════════════════════════════════════════════════════════════════
class RecurringExpenseService
{
    public function __construct(
        private readonly PaymentRecorderService $paymentRecorder,
        private readonly JournalService $journal,
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

            $payment = $this->paymentRecorder->apply($first, 'out', $data['mode'], [
                'date'       => $data['date'],
                'method'     => $data['method'] ?? 'cash',
                'amount_now' => $data['amount_now'] ?? null,
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
            ]);

            // The bill first (every occurrence already due — normally
            // just the first), then the money paid against it — the
            // same order ExpenseController::store() uses.
            $this->postDueOccurrences((int) $first->company_id, $recurringId);

            if ($payment) {
                $this->journal->postBillPayment($payment);
            }

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
        // "Future" in the business's own time zone, compared as a
        // date (not a date-time string), so an occurrence due today
        // is never mistaken for a future one. Each cancelled row is
        // written to the deletion log — a bulk delete used to leave
        // no audit trail at all.
        // Read inside the transaction, locked, so a payment recorded at
        // the same moment cannot slip between the check and the delete.
        return DB::transaction(function () use ($recurringId) {
            $future = Expense::query()
                ->inRecurringSeries($recurringId)
                ->whereDoesntHave('payments')
                ->whereDate('date', '>', FinancialRules::latestAllowedDate())
                ->lockForUpdate()
                ->get();

            foreach ($future as $expense) {
                // Normally never posted (it is in the future), but if
                // anything did post against it, undo that too.
                $this->journal->reverseAllForPayable($expense);

                DeletionLogger::log(
                    $expense,
                    "Recurring expense occurrence {$expense->recurring_index}/{$expense->recurring_count} cancelled — "
                        .number_format((float) $expense->amount, 2).' due '.$expense->date->toDateString()
                );

                $expense->installments()->delete();
                $expense->delete();
            }

            return $future->count();
        });
    }

    /**
     * Post the "Expense billed" entry for every recurring occurrence
     * whose date has arrived (on or before today, business time) and
     * that has never been posted. Safe to call any number of times.
     *
     * @param  string|null  $recurringId  Limit to one series.
     * @return int  number of occurrences posted
     */
    public function postDueOccurrences(int $companyId, ?string $recurringId = null): int
    {
        $dueIds = Expense::query()
            ->where('company_id', $companyId)
            ->whereNotNull('recurring_id')
            ->when($recurringId, fn ($q) => $q->where('recurring_id', $recurringId))
            ->whereDate('date', '<=', FinancialRules::latestAllowedDate())
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('journal_entries')
                ->where('journal_entries.source_type', Expense::class)
                ->whereColumn('journal_entries.source_id', 'expenses.id'))
            ->orderBy('date')
            ->pluck('id');

        $posted = 0;

        foreach ($dueIds as $id) {
            DB::transaction(function () use ($id, &$posted) {
                // Re-read under a lock and re-check, so two requests
                // arriving together can never post the same bill twice.
                $expense = Expense::query()->whereKey($id)->lockForUpdate()->first();

                if (! $expense) {
                    return;
                }

                $alreadyPosted = JournalEntry::query()
                    ->where('source_type', Expense::class)
                    ->where('source_id', $expense->id)
                    ->exists();

                if ($alreadyPosted) {
                    return;
                }

                $this->journal->postExpenseInvoice($expense);
                $posted++;
            });
        }

        return $posted;
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
