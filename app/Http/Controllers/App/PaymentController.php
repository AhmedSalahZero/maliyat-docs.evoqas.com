<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StorePaymentOutRequest;
use App\Http\Requests\App\StoreReceiptRequest;
use App\Http\Requests\App\UpdatePaymentRequest;
use App\Models\EquipmentPurchase;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Sale;
use App\Services\CustomerCreditService;
use App\Services\JournalService;
use App\Support\FinancialRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — PaymentController
//  Location: app/Http/Controllers/App/PaymentController.php
//
//  "Receive Money" / "Pay Money" — the two tabs that let a company
//  settle an already-open invoice/bill. A cash sale/expense with no
//  invoice/bill behind it is no longer created here — see Sales/
//  Expenses' "Cash Sales"/"Cash Expense" toggle, which creates a
//  real, fully-accounted Sale/Expense record instead.
//
//  Bills can be open on three different tables (Expense,
//  InventoryPurchase, EquipmentPurchase) — openBills() merges all
//  three into one list rather than forcing the frontend to call
//  three endpoints.
//
//  storeReceipt()/storePayment() still accept a standalone (no
//  sale_id/payable) shape at the request level — kept, not removed,
//  because update()/repost() still need to correctly re-post
//  existing standalone payments recorded before this change; there
//  is just no UI entry point left that creates a new one that way.
// ══════════════════════════════════════════════════════════════════
class PaymentController extends Controller
{
    public function __construct(
        private readonly JournalService $journal,
    ) {}

    /**
     * "Receive / Pay Money" landing page — the sixth quick-record
     * action. Lookup lists for the generic-entry sentences; the
     * open-invoices/open-bills lists themselves are fetched
     * separately (see openInvoices()/openBills() below) since
     * they change independently of these lookups.
     *
     * Only paymentChannels is needed here now — the standalone
     * "log one with no invoice/bill" flow (which used to need
     * customers/vendors/categories) moved to the Sales and Expense
     * tabs as their "Cash Sales"/"Cash Expense" toggle. This page
     * is purely for settling an already-open invoice or bill.
     */
    public function page(): Response
    {
        return Inertia::render('App/Payments/Index', [
            'paymentChannels' => PaymentChannel::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * How many open invoices/bills one request will return. The list
     * is a "what do I settle next" worklist, not an archive — it is
     * ordered by due date so the most urgent are always in the first
     * page, and `has_more` tells the frontend to prompt for a search
     * rather than silently hiding rows.
     */
    private const OPEN_LIST_LIMIT = 50;

    /**
     * Open (unpaid or partially paid) sales — "money owed to you".
     *
     * The open/closed test runs in SQL against a payments subquery
     * sum, not in PHP. The previous version loaded every sale the
     * company had ever made and called balance() on each, which cost
     * one SUM query per row and grew without bound — 200 sales
     * already meant ~400 queries.
     */
    public function openInvoices(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $query = Sale::query()
            ->with('customer:id,name')
            ->withSum('payments as payments_total', 'amount')
            ->whereRaw('COALESCE((select sum(p.amount) from payments p'
                .' where p.payable_type = ? and p.payable_id = sales.id'
                .' and p.company_id = sales.company_id), 0) < sales.amount - '.FinancialRules::AMOUNT_TOLERANCE, [Sale::class]);

        if ($search !== '') {
            $query->whereHas('customer', fn ($q) => $q->where('name', 'like', '%'.$search.'%'));
        }

        $rows = $this->applyDueDateOrder($query, 'sales')
            ->limit(self::OPEN_LIST_LIMIT + 1)
            ->get();

        [$rows, $hasMore] = $this->splitOverflow($rows);

        // Credit each customer already has (advances / overpayments),
        // so the settle form can offer to use it (audit finding 4.6).
        // Two grouped queries for the whole page, never one per row.
        $credits = app(CustomerCreditService::class)->availableFor($rows->pluck('customer_id'));

        return response()->json([
            'data' => $rows->map(fn (Sale $sale) => [
                'id'       => $sale->id,
                'customer' => $sale->customer?->name,
                'customer_credit' => (float) ($credits[(int) $sale->customer_id] ?? 0),
                'amount'   => (float) $sale->amount,
                'balance'  => round((float) $sale->amount - (float) $sale->payments_total, 2),
                'due_date' => $sale->due_date?->toDateString(),
                'date'     => $sale->date->toDateString(),
            ])->values(),
            'has_more' => $hasMore,
        ]);
    }

    /**
     * Open (unpaid or partially paid) bills across expenses,
     * inventory purchases, and equipment purchases — "money you owe".
     *
     * Each source is filtered and capped in SQL the same way
     * openInvoices() is, then the three capped lists are merged and
     * re-sorted. Taking LIMIT+1 from each source before merging is
     * what keeps the merged top-N correct: no source can contribute
     * more rows to the final page than it was asked for.
     */
    public function openBills(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $sources = [
            ['expense',            Expense::class,            'expenses'],
            ['inventory_purchase', InventoryPurchase::class,  'inventory_purchases'],
            ['equipment_purchase', EquipmentPurchase::class,  'equipment_purchases'],
        ];

        $bills = collect();

        foreach ($sources as [$type, $model, $table]) {
            $query = $model::query()
                ->with('vendor:id,name')
                ->withSum('payments as payments_total', 'amount')
                ->whereRaw('COALESCE((select sum(p.amount) from payments p'
                    ." where p.payable_type = ? and p.payable_id = {$table}.id"
                    ." and p.company_id = {$table}.company_id), 0) < {$table}.amount - ".FinancialRules::AMOUNT_TOLERANCE, [$model]);

            // Opening-balance stock and equipment were already owned
            // (booked against Owner's Equity) — they are not bills and
            // nobody is owed for them. Opening SUPPLIER balances are
            // Expense rows and DO stay: that money really is owed.
            if ($type !== 'expense') {
                $query->where("{$table}.is_opening_balance", false);
            }

            if ($search !== '') {
                $query->whereHas('vendor', fn ($q) => $q->where('name', 'like', '%'.$search.'%'));
            }

            $rows = $this->applyDueDateOrder($query, $table)
                ->limit(self::OPEN_LIST_LIMIT + 1)
                ->get();

            $bills = $bills->concat(
                $rows->map(fn ($bill) => $this->billRow($type, $bill->id, $bill->vendor?->name, $bill))
            );
        }

        // Nulls last, then soonest due first — matching the per-source
        // ordering so the merge preserves urgency.
        $bills = $bills
            ->sortBy([
                fn ($a, $b) => ($a['due_date'] === null ? 1 : 0) <=> ($b['due_date'] === null ? 1 : 0),
                fn ($a, $b) => (string) $a['due_date'] <=> (string) $b['due_date'],
            ])
            ->values();

        [$bills, $hasMore] = $this->splitOverflow($bills);

        return response()->json([
            'data'     => $bills->values(),
            'has_more' => $hasMore,
        ]);
    }

    /**
     * Soonest due first, with undated rows last. Ordering by the
     * raw "due_date IS NULL" expression first is what pushes nulls
     * to the end on MySQL, which otherwise sorts them first.
     */
    private function applyDueDateOrder($query, string $table)
    {
        return $query
            ->orderByRaw("{$table}.due_date is null")
            ->orderBy("{$table}.due_date")
            ->orderBy("{$table}.id");
    }

    /**
     * We always fetch LIMIT+1 rows so the presence of the extra one
     * tells us there is more to show, without a second COUNT query.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: bool}
     */
    private function splitOverflow($rows): array
    {
        $hasMore = $rows->count() > self::OPEN_LIST_LIMIT;

        return [$rows->take(self::OPEN_LIST_LIMIT), $hasMore];
    }

    /**
     * Settle an open invoice, or log a standalone receipt.
     */
    public function storeReceipt(StoreReceiptRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Payment row + its ledger entry commit together.
        if (! empty($data['sale_id'])) {
            $sale = Sale::findOrFail($data['sale_id']);

            // Paid from the customer's existing credit: no money
            // moves, the credit settles the invoice (audit 4.6).
            if (! empty($data['use_credit'])) {
                DB::transaction(function () use ($sale, $data) {
                    $payment = $sale->payments()->create([
                        'company_id' => $sale->company_id,
                        'date'       => $data['date'],
                        'amount'     => $data['amount'],
                        'method'     => CustomerCreditService::METHOD,
                        'direction'  => 'in',
                        'note'       => 'Paid from customer credit',
                    ]);

                    $this->journal->postCustomerCreditApplied($payment);
                });

                return back()->with('success', 'Invoice paid from customer credit.');
            }

            DB::transaction(function () use ($sale, $data) {
                // Customer paid more than the invoice: settle the
                // invoice, keep the rest as their credit — a liability,
                // never revenue (audit finding 4.6).
                $owed   = max(0.0, round($sale->balance(), 2));
                $amount = (float) $data['amount'];
                $extra  = ! empty($data['keep_extra_as_credit']) ? max(0.0, round($amount - $owed, 2)) : 0.0;
                $toInvoice = round($amount - $extra, 2);

                if ($toInvoice > 0) {
                    $payment = $sale->payments()->create([
                        'company_id' => $sale->company_id,
                        'date'       => $data['date'],
                        'amount'     => $toInvoice,
                        'method'     => $data['method'],
                        'payment_channel_id' => $data['payment_channel_id'] ?? null,
                        'direction'  => 'in',
                    ]);

                    $this->journal->postSaleReceipt($payment);
                }

                if ($extra > 0) {
                    $credit = Payment::create([
                        'company_id'   => $sale->company_id,
                        'payable_type' => null,
                        'payable_id'   => null,
                        'customer_id'  => $sale->customer_id,
                        'date'         => $data['date'],
                        'amount'       => $extra,
                        'method'       => $data['method'],
                        'payment_channel_id' => $data['payment_channel_id'] ?? null,
                        'note'         => 'Overpayment on sale — kept as customer credit',
                        'direction'    => 'in',
                        'is_customer_credit' => true,
                    ]);

                    $this->journal->postCustomerCreditReceipt($credit);
                }
            });

            return back()->with('success', 'Receipt recorded against invoice.');
        }

        DB::transaction(function () use ($data) {
            // Money from a named customer that settles no invoice is an
            // ADVANCE — their credit, owed back to them — not revenue
            // (audit finding 4.6). Only money from nobody in particular
            // (a tip, a one-off with no paperwork) is revenue.
            $isCredit = ! empty($data['customer_id']);

            $payment = Payment::create([
                'company_id'   => auth()->user()->company_id,
                'payable_type' => null,
                'payable_id'   => null,
                'customer_id'  => $data['customer_id'] ?? null,
                'date'         => $data['date'],
                'amount'       => $data['amount'],
                'method'       => $data['method'],
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
                'note'         => $data['note'] ?? null,
                'direction'    => 'in',
                'is_customer_credit' => $isCredit,
            ]);

            $isCredit
                ? $this->journal->postCustomerCreditReceipt($payment)
                : $this->journal->postStandaloneReceipt($payment);
        });

        return back()->with('success', 'Receipt recorded.');
    }

    /**
     * Settle an open bill, or log a standalone payment.
     */
    public function storePayment(StorePaymentOutRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $payableModel = match ($data['payable_type'] ?? null) {
            'expense'             => Expense::class,
            'inventory_purchase'  => InventoryPurchase::class,
            'equipment_purchase'  => EquipmentPurchase::class,
            default               => null,
        };

        if ($payableModel && ! empty($data['payable_id'])) {
            $payable = $payableModel::findOrFail($data['payable_id']);

            DB::transaction(function () use ($payable, $data) {
                $payment = $payable->payments()->create([
                    'company_id' => $payable->company_id,
                    'date'       => $data['date'],
                    'amount'     => $data['amount'],
                    'method'     => $data['method'],
                    'payment_channel_id' => $data['payment_channel_id'] ?? null,
                    'direction'  => 'out',
                ]);

                $this->journal->postBillPayment($payment);
            });

            return back()->with('success', 'Payment recorded against bill.');
        }

        DB::transaction(function () use ($data) {
            $payment = Payment::create([
                'company_id'   => auth()->user()->company_id,
                'payable_type' => null,
                'payable_id'   => null,
                'vendor_id'    => $data['vendor_id'] ?? null,
                'category_id'  => $data['category_id'] ?? null,
                'date'         => $data['date'],
                'amount'       => $data['amount'],
                'method'       => $data['method'],
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
                'note'         => $data['note'] ?? null,
                'direction'    => 'out',
            ]);

            $this->journal->postStandalonePayment($payment);
        });

        return back()->with('success', 'Payment recorded.');
    }

    /**
     * @param  Expense|InventoryPurchase|EquipmentPurchase  $bill
     */
    /**
     * Correct a payment in place — its date, amount, method or
     * channel.
     *
     * The ledger is kept honest the same way editing an invoice is:
     * the entry this payment produced is reversed, then a fresh one is
     * posted from the corrected figures. Nothing is edited in the
     * general ledger itself, so the trail shows the original, its
     * reversal, and the correction.
     *
     * Which record the payment settles is NOT editable — see
     * UpdatePaymentRequest for why.
     */
    public function update(UpdatePaymentRequest $request, Payment $payment): RedirectResponse
    {
        $data = $request->validated();

        // Belt and braces: UpdatePaymentRequest already refuses these.
        if ($blocked = $this->refuseManagedElsewhere($payment)) {
            return $blocked;
        }

        // Customer credit (audit finding 4.6). Credit USED against an
        // invoice moved no money, so it cannot be "corrected" into a
        // cash payment — remove it and record it again instead. A
        // credit RECEIVED cannot shrink below what has already been
        // used, or the customer would owe back credit they spent.
        if ($payment->method === CustomerCreditService::METHOD) {
            return back()->with('error', __('errors.credit_payment_not_editable'));
        }

        if ($payment->is_customer_credit && $payment->customer_id) {
            $alreadyUsed = round((float) $payment->amount - app(CustomerCreditService::class)->available((int) $payment->customer_id), 2);

            if ((float) $data['amount'] < $alreadyUsed - FinancialRules::AMOUNT_TOLERANCE) {
                return back()->with('error', __('errors.credit_already_used', ['used' => number_format($alreadyUsed, 2)]));
            }
        }

        DB::transaction(function () use ($payment, $data) {
            $this->journal->reverseEntriesFor($payment);

            $payment->update([
                'date'               => $data['date'],
                'amount'             => $data['amount'],
                'method'             => $data['method'],
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
            ]);

            $this->repost($payment->fresh());
        });

        return back()->with('success', 'Payment updated.');
    }

    /**
     * Post the ledger entry a payment should have, given what it is.
     *
     * The four shapes here mirror how the payment was posted when it
     * was first recorded, so a corrected payment lands on exactly the
     * same accounts as the original did.
     */
    private function repost(Payment $payment): void
    {
        $isBill = in_array($payment->payable_type, [
            Expense::class, InventoryPurchase::class, EquipmentPurchase::class,
        ], true);

        // Only ordinary payments reach here (see refuseManagedElsewhere());
        // anything else would be silently re-booked to the wrong
        // accounts, so stop loudly instead (the transaction rolls back).
        if ($payment->managedBy()) {
            throw new \LogicException("Payment #{$payment->id} is managed by the {$payment->managedBy()} screen and cannot be re-posted here.");
        }

        match (true) {
            $payment->payable_type === Sale::class
                && $payment->method === CustomerCreditService::METHOD => $this->journal->postCustomerCreditApplied($payment),
            $payment->payable_type === Sale::class => $this->journal->postSaleReceipt($payment),
            $isBill                                => $this->journal->postBillPayment($payment),
            $payment->direction === 'in' && $payment->is_customer_credit => $this->journal->postCustomerCreditReceipt($payment),
            $payment->direction === 'in'           => $this->journal->postStandaloneReceipt($payment),
            default                                => $this->journal->postStandalonePayment($payment),
        };
    }

    /**
     * Remove one payment from a record.
     *
     * Reached from the edit view of a sale/expense/purchase, where a
     * payment entered at creation time turns out to be wrong. The
     * ledger entry it produced is reversed rather than deleted, so
     * the general ledger keeps the full correction trail — same
     * treatment as editing an invoice (see JournalService::reverse).
     *
     * Route-model binding applies the company global scope, so a
     * payment belonging to another company resolves as a 404 here.
     */
    public function destroy(Payment $payment): RedirectResponse
    {
        $this->authorizeDelete();

        // Opening-balance, custody and owner payments are removed from
        // their own screens, which also undo the rest of that record
        // (audit M2).
        if ($blocked = $this->refuseManagedElsewhere($payment)) {
            return $blocked;
        }

        // A customer credit that has already been used against their
        // invoices cannot simply vanish (audit finding 4.6) — remove
        // the invoice payments that used it first.
        if ($payment->is_customer_credit && $payment->customer_id) {
            $available = app(CustomerCreditService::class)->available((int) $payment->customer_id);

            if ((float) $payment->amount > $available + FinancialRules::AMOUNT_TOLERANCE) {
                return back()->with('error', __('errors.credit_already_used', [
                    'used' => number_format(round((float) $payment->amount - $available, 2), 2),
                ]));
            }
        }

        DB::transaction(function () use ($payment) {
            $this->logDeletion(
                $payment,
                "Payment #{$payment->id} — ".($payment->direction === 'in' ? 'received' : 'paid').' — '.number_format((float) $payment->amount, 2)
            );

            $this->journal->reverseEntriesFor($payment);
            $payment->delete();
        });

        return back()->with('success', 'Payment removed.');
    }

    /**
     * A friendly refusal for a payment another screen owns — see
     * Payment::managedBy(). Null when the payment is ordinary.
     */
    private function refuseManagedElsewhere(Payment $payment): ?RedirectResponse
    {
        $owner = $payment->managedBy();

        return $owner ? back()->with('error', $payment->managedElsewhereMessage()) : null;
    }

    private function billRow(string $type, int $id, ?string $party, $bill): array
    {
        return [
            'payable_type' => $type,
            'id'           => $id,
            'party'        => $party,
            'amount'       => (float) $bill->amount,
            // From the withSum alias, not balance() — calling that
            // here would fire one SUM per row again.
            'balance'      => round((float) $bill->amount - (float) $bill->payments_total, 2),
            'due_date'     => $bill->due_date?->toDateString(),
            'date'         => $bill->date->toDateString(),
        ];
    }
}
