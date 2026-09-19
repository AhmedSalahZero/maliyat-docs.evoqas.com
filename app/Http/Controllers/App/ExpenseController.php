<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreExpenseRequest;
use App\Http\Requests\App\StoreRecurringExpenseRequest;
use App\Http\Requests\App\UpdateExpenseRequest;
use App\Models\Category;
use App\Models\Expense;
use App\Models\PaymentChannel;
use App\Models\ProductionOrder;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Services\PaymentRecorderService;
use App\Services\RecurringExpenseService;
use App\Support\FinancialRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ExpenseController
//  Location: app/Http/Controllers/App/ExpenseController.php
//
//  "Pay {vendor/employee} {amount} for {category}, paid {mode}."
//  store() handles one-off expenses; storeRecurring() sets up a
//  whole series (e.g. monthly rent) via RecurringExpenseService.
//
//  Edit/Delete follow the exact same pattern as SaleController —
//  see that file's class doc comment for the full reasoning
//  (both allowed even with payments recorded, both require the
//  frontend to warn first, both reverse rather than silently
//  rewrite the journal). Deleting one occurrence of a recurring
//  series only deletes that occurrence — cancelRecurring() is the
//  separate, existing action for "stop billing future months".
// ══════════════════════════════════════════════════════════════════
class ExpenseController extends Controller
{
    public function __construct(
        private readonly PaymentRecorderService $paymentRecorder,
        private readonly RecurringExpenseService $recurringExpenses,
        private readonly JournalService $journal,
    ) {}

    public function index(): Response
    {
        // Self-heal for companies created before the default category
        // list existed — idempotent (firstOrCreate), cheap, and means
        // no manual backfill step is ever needed.
        Category::seedDefaults(auth()->user()->company_id);

        $expenses = Expense::query()
            ->with(['vendor:id,name', 'category:id,name', 'payments'])
            ->latest('date')
            ->latest('id')
            ->paginate(20)
            ->through(fn (Expense $expense) => [
                'id'               => $expense->id,
                'date'             => $expense->date->toDateString(),
                'due_date'    => $expense->due_date?->toDateString(),
                'vendor_id'        => $expense->vendor_id,
                'vendor'           => $expense->vendor?->name,
                'category_id'      => $expense->category_id,
                'category'         => $expense->category?->name,
                'amount'           => (float) $expense->amount,
                'paid_amount'      => $expense->paidAmount(),
                'balance'          => $expense->balance(),
                'is_paid'          => $expense->isPaid(),
                'payments_count' => $expense->payments->count(),
                // Individual payments so the edit view can show and
                // remove them — see PaymentController::destroy().
                'payments'       => $expense->payments->map(fn ($payment) => [
                    'id'     => $payment->id,
                    'date'   => $payment->date->toDateString(),
                    'amount' => (float) $payment->amount,
                    'method' => $payment->method,
                    // Needed so the edit form can prefill the bank /
                    // operator the payment actually went through.
                    'payment_channel_id' => $payment->payment_channel_id,
                ])->values(),
                'is_recurring'     => $expense->isRecurring(),
                'recurring_id'     => $expense->recurring_id,
                'recurring_index'  => $expense->recurring_index,
                'recurring_count'  => $expense->recurring_count,
                'is_production_labor' => $expense->is_production_labor,
            ]);

        return Inertia::render('App/Expenses/Index', [
            'vendors'         => Vendor::query()->orderBy('name')->get(['id', 'name']),
            'categories'      => Category::query()->expenseKind()->orderBy('name')->get(['id', 'name', 'name_ar']),
            'paymentChannels' => PaymentChannel::query()->orderBy('name')->get(['id', 'name']),
            'expenses'        => $expenses,
            'recurringSeries' => $this->recurringSeriesSummary(),
        ]);
    }

    public function store(StoreExpenseRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $dueDate  = null;
        $schedule = [];

        if ($data['mode'] === 'installment') {
            $schedule = $this->paymentRecorder->buildInstallmentSchedule(
                $data['amount'], $data['date'], (int) $data['installment_count'], (int) $data['installment_interval_days']
            );
            $dueDate = $schedule[0]['due_date'];
        } elseif ($data['mode'] !== 'now') {
            $dueDate = $this->paymentRecorder->dueDate($data['due_date'] ?? null, $data['due_in_days'] ?? null, $data['date']);
        }

        // This method had no transaction at all — the expense, its
        // ledger entry and its opening payment were three separate
        // commits. See the note in SaleController::store().
        DB::transaction(function () use ($data, $dueDate, $schedule) {
            $expense = Expense::create([
                // Cash Expense submits no vendor_id at all — filed
                // under the one reusable "Cash Vendor" instead of
                // asking the person to pick one. See
                // Vendor::cashVendor()'s doc comment.
                'vendor_id'   => $data['vendor_id'] ?? Vendor::cashVendor(auth()->user()->company_id)->id,
                'category_id' => $data['category_id'],
                'date'        => $data['date'],
                'amount'      => $data['amount'],
                'due_date'    => $dueDate,
                'created_by'  => auth()->id(),
                'is_production_labor' => (bool) ($data['is_production_labor'] ?? false),
            ]);

            $this->postExpenseJournal($expense);

            $payment = $this->paymentRecorder->apply($expense, 'out', $data['mode'], [
                'date'       => $data['date'],
                'method'     => $data['method'] ?? 'cash',
                'amount_now' => $data['amount_now'] ?? null,
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
            ], $schedule);

            if ($payment) {
                $this->journal->postBillPayment($payment);
            }
        });

        return back()->with('success', 'Expense recorded.');
    }

    /**
     * Edit an existing (one-off or single occurrence of a recurring)
     * expense. Payments untouched — see class doc comment.
     */
    public function update(UpdateExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($expense, $data) {
            $this->journal->reverseEntriesFor($expense);

            $expense->update([
                'vendor_id'   => $data['vendor_id'],
                'category_id' => $data['category_id'],
                'date'        => $data['date'],
                'amount'      => $data['amount'],
                // Correctable now — see the note in the Update*Request.
                'due_date'    => array_key_exists('due_date', $data) ? $data['due_date'] : $expense->due_date,
                'is_production_labor' => (bool) ($data['is_production_labor'] ?? false),
            ]);

            $this->postExpenseJournal($expense->fresh());
        });

        return back()->with('success', 'Expense updated.');
    }

    /**
     * Delete one expense (and its payments) entirely. If it's part
     * of a recurring series, only this occurrence is removed — use
     * "Cancel remaining" to stop the rest of the series.
     */
    public function destroy(Expense $expense): RedirectResponse
    {
        $this->authorizeDelete();

        DB::transaction(function () use ($expense) {
            $this->logDeletion(
                $expense,
                "Expense #{$expense->id} — ".($expense->vendor?->name ?? 'Unknown vendor').' — '.number_format((float) $expense->amount, 2)
            );

            $this->journal->reverseAllForPayable($expense);
            $expense->payments()->delete();
            $expense->installments()->delete();
            $expense->delete();
        });

        return back()->with('success', 'Expense deleted.');
    }

    public function storeRecurring(StoreRecurringExpenseRequest $request): RedirectResponse
    {
        $this->recurringExpenses->createSeries($request->validated());

        return back()->with('success', 'Recurring expense set up.');
    }

    /**
     * Cancel every unpaid, not-yet-due occurrence left in a series.
     */
    public function cancelRecurring(string $recurringId): RedirectResponse
    {
        $this->authorizeDelete();

        $cancelled = $this->recurringExpenses->cancelRemaining($recurringId);

        return back()->with('success', "{$cancelled} upcoming occurrence(s) cancelled.");
    }

    /**
     * Posts the normal expense journal entry — UNLESS this expense
     * is checked "Production Labor", in which case it reconciles
     * against this month's Production Orders instead (see
     * JournalService::postProductionLaborExpense()'s doc comment
     * for the full reasoning).
     *
     * "Applied so far this month" is this month's total labor cost
     * from Production Orders, minus whatever earlier Production
     * Labor expenses in the same month already claimed (their
     * stored snapshot) — so a second payroll entry in one month
     * (e.g. two weekly runs) doesn't double-clear the same amount.
     * The amount THIS expense actually claims is stored back onto
     * it, so editing/deleting it later can be reversed correctly.
     */
    private function postExpenseJournal(Expense $expense): void
    {
        if (! $expense->is_production_labor) {
            $this->journal->postExpenseInvoice($expense);

            return;
        }

        $totalForMonth = ProductionOrder::totalLaborForMonth($expense->company_id, $expense->date->toDateString());

        $alreadyClaimed = (float) Expense::query()
            ->where('company_id', $expense->company_id)
            ->where('is_production_labor', true)
            ->where('id', '!=', $expense->id)
            ->whereYear('date', $expense->date->year)
            ->whereMonth('date', $expense->date->month)
            ->sum('production_labor_applied_snapshot');

        $availableToApply = max(0, round($totalForMonth - $alreadyClaimed, 2));

        $expense->forceFill(['production_labor_applied_snapshot' => $availableToApply])->save();

        $this->journal->postProductionLaborExpense($expense, $availableToApply);
    }

    /**
     * One summary row per recurring series — vendor/category/amount
     * (from the first occurrence), how many of the total are paid,
     * and the next due date — for the "Recurring plans" section of
     * the page. Computed here rather than in the paginated list
     * above since a series can span many pages of that list.
     */
    private function recurringSeriesSummary(): array
    {
        $companyId = (int) auth()->user()->company_id;

        // "Paid" here must mean exactly what Expense::isPaid() means
        // everywhere else — payments cover the amount within the
        // same FinancialRules::AMOUNT_TOLERANCE float-noise tolerance
        // DashboardController and PaymentController's worklist
        // already use — so this can't quietly disagree with what the
        // rest of the app calls paid.
        $unpaidCondition = 'COALESCE((
            SELECT SUM(p.amount) FROM payments p
            WHERE p.payable_type = '.DB::getPdo()->quote(Expense::class).'
              AND p.payable_id = expenses.id
              AND p.company_id = ?
        ), 0) < expenses.amount - '.FinancialRules::AMOUNT_TOLERANCE;

        // One row per series: how many occurrences it has, and how
        // many of those are still unpaid. addBinding() is needed
        // (rather than a plain whereRaw binding) because the
        // placeholder sits inside a SELECT-level CASE expression,
        // not a WHERE clause — same reason DashboardController's
        // topCustomers() does the same thing for its own correlated
        // subquery.
        $counts = DB::table('expenses')
            ->where('company_id', $companyId)
            ->whereNotNull('recurring_id')
            ->groupBy('recurring_id')
            ->select([
                'recurring_id',
                DB::raw('COUNT(*) as total_count'),
                DB::raw("SUM(CASE WHEN {$unpaidCondition} THEN 1 ELSE 0 END) as unpaid_count"),
            ])
            ->addBinding([$companyId], 'select')
            ->get()
            ->keyBy('recurring_id');

        if ($counts->isEmpty()) {
            return [];
        }

        // The first occurrence of each series (lowest recurring_index)
        // is what carries the vendor/category/amount/frequency the
        // summary row shows — found via a join back to the minimum
        // index per series, rather than loading every occurrence to
        // pick one out in PHP.
        $firstOccurrence = DB::table('expenses as e')
            ->joinSub(
                DB::table('expenses')
                    ->where('company_id', $companyId)
                    ->whereNotNull('recurring_id')
                    ->groupBy('recurring_id')
                    ->select('recurring_id', DB::raw('MIN(recurring_index) as first_index')),
                'first_row',
                fn ($join) => $join->on('e.recurring_id', '=', 'first_row.recurring_id')
                    ->on('e.recurring_index', '=', 'first_row.first_index')
            )
            ->where('e.company_id', $companyId)
            ->leftJoin('vendors', 'vendors.id', '=', 'e.vendor_id')
            ->leftJoin('categories', 'categories.id', '=', 'e.category_id')
            ->select([
                'e.recurring_id', 'e.amount', 'e.recurring_frequency',
                'vendors.name as vendor_name', 'categories.name as category_name',
            ])
            ->get()
            ->keyBy('recurring_id');

        // The soonest-due UNPAID occurrence in each series — same
        // "among the unpaid ones, take the earliest by date, then
        // read that row's due_date" rule the original version used.
        // The outer MIN(due_date) collapses a same-day tie to one
        // deterministic answer rather than leaving it to whichever
        // row the database happens to return first.
        $nextDue = DB::table('expenses as e')
            ->joinSub(
                DB::table('expenses')
                    ->where('company_id', $companyId)
                    ->whereNotNull('recurring_id')
                    ->whereRaw($unpaidCondition, [$companyId])
                    ->groupBy('recurring_id')
                    ->select('recurring_id', DB::raw('MIN(date) as min_date')),
                'soonest',
                fn ($join) => $join->on('e.recurring_id', '=', 'soonest.recurring_id')
                    ->on('e.date', '=', 'soonest.min_date')
            )
            ->where('e.company_id', $companyId)
            ->groupBy('e.recurring_id')
            ->select('e.recurring_id', DB::raw('MIN(e.due_date) as due_date'))
            ->get()
            ->keyBy('recurring_id');

        return $counts->map(function ($count, $recurringId) use ($firstOccurrence, $nextDue) {
            $first       = $firstOccurrence->get($recurringId);
            $totalCount  = (int) $count->total_count;
            $unpaidCount = (int) $count->unpaid_count;

            return [
                'recurring_id' => $recurringId,
                'vendor'       => $first->vendor_name ?? null,
                'category'     => $first->category_name ?? null,
                'amount'       => (float) ($first->amount ?? 0),
                'frequency'    => $first->recurring_frequency ?? null,
                'paid_count'   => $totalCount - $unpaidCount,
                'total_count'  => $totalCount,
                'next_due'     => $nextDue->get($recurringId)?->due_date,
                'has_unpaid'   => $unpaidCount > 0,
            ];
        })->values()->all();
    }
}