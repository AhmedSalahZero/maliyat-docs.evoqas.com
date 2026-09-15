<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreExpenseRequest;
use App\Http\Requests\App\StoreRecurringExpenseRequest;
use App\Http\Requests\App\UpdateExpenseRequest;
use App\Models\Category;
use App\Models\Expense;
use App\Models\PaymentChannel;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Services\PaymentRecorderService;
use App\Services\RecurringExpenseService;
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
                'vendor_id'   => $data['vendor_id'],
                'category_id' => $data['category_id'],
                'date'        => $data['date'],
                'amount'      => $data['amount'],
                'due_date'    => $dueDate,
                'created_by'  => auth()->id(),
            ]);

            $this->journal->postExpenseInvoice($expense);

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
            ]);

            $this->journal->postExpenseInvoice($expense->fresh());
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
     * One summary row per recurring series — vendor/category/amount
     * (from the first occurrence), how many of the total are paid,
     * and the next due date — for the "Recurring plans" section of
     * the page. Computed here rather than in the paginated list
     * above since a series can span many pages of that list.
     */
    private function recurringSeriesSummary(): array
    {
        return Expense::query()
            ->whereNotNull('recurring_id')
            ->with(['vendor:id,name', 'category:id,name'])
            ->get()
            ->groupBy('recurring_id')
            ->map(function ($occurrences) {
                $first = $occurrences->sortBy('recurring_index')->first();
                $paidCount = $occurrences->filter(fn (Expense $e) => $e->isPaid())->count();
                $nextDue = $occurrences
                    ->filter(fn (Expense $e) => ! $e->isPaid())
                    ->sortBy('date')
                    ->first()?->due_date?->toDateString();

                return [
                    'recurring_id'    => $first->recurring_id,
                    'vendor'          => $first->vendor?->name,
                    'category'        => $first->category?->name,
                    'amount'          => (float) $first->amount,
                    'frequency'       => $first->recurring_frequency,
                    'paid_count'      => $paidCount,
                    'total_count'     => $occurrences->count(),
                    'next_due'        => $nextDue,
                    'has_unpaid'      => $paidCount < $occurrences->count(),
                ];
            })
            ->values()
            ->all();
    }
}
