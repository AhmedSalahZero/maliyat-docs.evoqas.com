<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\Company;
use App\Models\Custody;
use App\Models\EquipmentPurchase;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Models\JournalEntry;
use App\Models\OwnerTransaction;
use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\Sale;
use App\Support\FinancialRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — JournalService
//  Location: app/Services/JournalService.php
//
//  The double-entry engine. See the class-level note in
//  Account.php for the standard chart of accounts this posts
//  against. Two kinds of methods here:
//
//    seedChartOfAccounts()  — called once, when a company is
//                             created (RegisterService and
//                             Admin\CompanyController both call it).
//
//    post*() methods        — one per business event. Each builds
//                             a balanced set of lines and hands
//                             them to post(), which is the only
//                             place that actually writes to the
//                             database and the only place that
//                             enforces debits == credits.
//
//  IMPORTANT — this runs ALONGSIDE the existing payments table, not
//  instead of it. Reports (ReportController) still read the
//  payments table for cash-basis P&L/cash-flow exactly as before —
//  nothing about existing behavior changes. This class is additive:
//  it gives the app a real, correct general ledger underneath for
//  audit trail, a trial balance, and a real balance sheet, without
//  requiring a rewrite of what already works.
// ══════════════════════════════════════════════════════════════════
class JournalService
{
    /**
     * Create the standard chart of accounts for a brand-new company.
     * Safe to call more than once — skips codes that already exist.
     */
    public function seedChartOfAccounts(Company $company): void
    {
        foreach (Account::STANDARD_CODES as $code => [$name, $nameAr, $type]) {
            Account::query()->firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                ['name' => $name, 'name_ar' => $nameAr, 'type' => $type],
            );
        }
    }

    // ── Sales ─────────────────────────────────────────────────────

    /**
     * A sale is invoiced: the customer now owes the amount due
     * (Accounts Receivable), split between revenue earned and VAT
     * collected on the state's behalf.
     *
     * Debit Withholding Tax: when the customer withholds part of the
     * invoice and pays it to the tax authority for us, that part is
     * NOT owed by the customer any more — it becomes a claim on the
     * tax authority (Withholding Tax Receivable, an asset). So
     * Accounts Receivable is debited only for `amount` (which already
     * has the withholding taken off) and the withholding is debited
     * to its own account:
     *
     *     Dr Accounts Receivable          amount            (net owed)
     *     Dr Withholding Tax Receivable   withholding
     *         Cr Sales Revenue            subtotal
     *         Cr VAT Payable              VAT
     */
    public function postSaleInvoice(Sale $sale): void
    {
        $lines = [
            ['account' => Account::ACCOUNTS_RECEIVABLE, 'debit' => (float) $sale->amount],
            ['account' => Account::SALES_REVENUE, 'credit' => (float) $sale->subtotal],
        ];

        if ((float) $sale->vat_amount > 0) {
            $lines[] = ['account' => Account::VAT_PAYABLE, 'credit' => (float) $sale->vat_amount];
        }

        if ((float) $sale->withholding_amount > 0) {
            $lines[] = ['account' => Account::WITHHOLDING_TAX_RECEIVABLE, 'debit' => (float) $sale->withholding_amount];
        }

        $this->post($sale->company_id, $sale->date->toDateString(), 'Sale invoiced', $sale, $lines);
    }

    /**
     * Money received against a sale (whether at creation time via
     * "pay now/partial", or later via Receive Money settling an
     * open invoice) — moves the amount from receivable to cash.
     */
    public function postSaleReceipt(Payment $payment): void
    {
        $this->post($payment->company_id, $payment->date->toDateString(), 'Payment received against sale', $payment, [
            ['account' => $this->cashAccountFor($payment->method), 'debit' => (float) $payment->amount],
            ['account' => Account::ACCOUNTS_RECEIVABLE, 'credit' => (float) $payment->amount],
        ]);
    }

    /**
     * A receipt with no invoice AND no customer behind it (a tip, a
     * one-off service with no paperwork) — recognised as revenue
     * directly. A receipt FROM A CUSTOMER that settles no invoice is
     * not this: see postCustomerCreditReceipt().
     */
    public function postStandaloneReceipt(Payment $payment): void
    {
        $this->post($payment->company_id, $payment->date->toDateString(), 'Cash receipt (no invoice)', $payment, [
            ['account' => $this->cashAccountFor($payment->method), 'debit' => (float) $payment->amount],
            ['account' => Account::SALES_REVENUE, 'credit' => (float) $payment->amount],
        ]);
    }

    /**
     * A customer paid money that settles no invoice — in advance, or
     * the extra over an invoice they overpaid. That is NOT revenue:
     * nothing has been sold for it yet, and the business owes it back
     * (in goods, services or cash). It is held in Customer Credits,
     * a liability, until used against one of their invoices (audit
     * finding 4.6 — it used to be booked straight to Sales Revenue).
     */
    public function postCustomerCreditReceipt(Payment $payment): void
    {
        $this->post($payment->company_id, $payment->date->toDateString(), 'Customer credit received (advance / overpayment)', $payment, [
            ['account' => $this->cashAccountFor($payment->method), 'debit' => (float) $payment->amount],
            ['account' => Account::CUSTOMER_CREDITS, 'credit' => (float) $payment->amount],
        ]);
    }

    /**
     * Part of a customer's credit used to pay one of their invoices.
     * No money moves — the credit they already have simply settles
     * what they owe.
     */
    public function postCustomerCreditApplied(Payment $payment): void
    {
        $this->post($payment->company_id, $payment->date->toDateString(), 'Customer credit used against invoice', $payment, [
            ['account' => Account::CUSTOMER_CREDITS, 'debit' => (float) $payment->amount],
            ['account' => Account::ACCOUNTS_RECEIVABLE, 'credit' => (float) $payment->amount],
        ]);
    }

    // ── Bills (Expense / InventoryPurchase / EquipmentPurchase) ────

    public function postExpenseInvoice(Expense $expense): void
    {
        $account = $this->expenseAccountFor($expense->category, $expense->company_id);

        $this->post($expense->company_id, $expense->date->toDateString(), 'Expense billed', $expense, [
            ['account' => $account, 'debit' => (float) $expense->amount],
            ['account' => Account::ACCOUNTS_PAYABLE, 'credit' => (float) $expense->amount],
        ]);
    }

    /**
     * Credit Withholding Tax: when we keep part of the bill back to
     * pay the tax authority ourselves, that part is NOT owed to the
     * supplier any more — we owe it to the tax authority instead
     * (Withholding Tax Payable, a liability). So Accounts Payable is
     * credited only for `amount` (which already has the withholding
     * taken off):
     *
     *     Dr Inventory                    subtotal
     *     Dr VAT Receivable               VAT
     *         Cr Accounts Payable         amount            (net owed)
     *         Cr Withholding Tax Payable  withholding
     */
    public function postInventoryPurchaseInvoice(InventoryPurchase $purchase): void
    {
        $lines = [
            ['account' => Account::INVENTORY_ASSET, 'debit' => (float) $purchase->subtotal],
            ['account' => Account::ACCOUNTS_PAYABLE, 'credit' => (float) $purchase->amount],
        ];

        if ((float) $purchase->vat_amount > 0) {
            $lines[] = ['account' => Account::VAT_RECEIVABLE, 'debit' => (float) $purchase->vat_amount];
        }

        if ((float) $purchase->withholding_amount > 0) {
            $lines[] = ['account' => Account::WITHHOLDING_TAX_PAYABLE, 'credit' => (float) $purchase->withholding_amount];
        }

        $this->post($purchase->company_id, $purchase->date->toDateString(), 'Inventory purchase billed', $purchase, $lines);
    }

    /**
     * Recognise the cost of inventory actually sold — moves value
     * out of the Inventory asset and into Cost of Goods Sold, at
     * the item's current moving-average cost (see
     * MovingAverageCostingService — the caller has already priced
     * this using that engine, not the old, removed
     * Item::averagePurchaseCost()). Called once per sale with the
     * combined cost across every line that references a tracked
     * item; lines with no item (a free-text/service line) don't
     * contribute anything here.
     */
    public function postCostOfGoodsSold(Sale $sale, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $this->post($sale->company_id, $sale->date->toDateString(), 'Cost of goods sold', $sale, [
            ['account' => Account::COST_OF_GOODS_SOLD, 'debit' => $amount],
            ['account' => Account::INVENTORY_ASSET, 'credit' => $amount],
        ]);
    }

    // ── Production Orders ("Day Production") ───────────────────────

    /**
     * A production run: raw materials become a finished product.
     * Inventory Asset is debited for the FULL cost of what came out
     * (materials + labor — standard "absorb every manufacturing cost
     * into inventory value" practice), and credited back for the raw
     * materials that went in, so only the VALUE ADDED (labor)
     * actually grows the account.
     *
     * Labor isn't a real cash cost yet at this point — it's an
     * estimate that gets reconciled later against the real payroll
     * Expense (see postProductionLaborExpense()) — so it's parked in
     * Production Labor Accrued rather than any cash/expense account.
     *
     * A run therefore touches NO cash account at all. That is the
     * whole point of the block parked below.
     */
    public function postProductionOrder(ProductionOrder $order): void
    {
        $lines = [
            ['account' => Account::INVENTORY_ASSET, 'debit' => (float) $order->total_cost],
        ];

        if ((float) $order->material_cost > 0) {
            $lines[] = ['account' => Account::INVENTORY_ASSET, 'credit' => (float) $order->material_cost];
        }

        if ((float) $order->labor_cost > 0) {
            $lines[] = ['account' => Account::PRODUCTION_LABOR_ACCRUED, 'credit' => (float) $order->labor_cost];
        }

        // ── PARKED: "other costs" credited straight to Cash ───────
        //
        // Kept here, not deleted, because the feature may come back
        // for a company that really does need it. It must not come
        // back in this shape.
        //
        // This credited Cash unconditionally: every "other cost" on
        // a run was posted as money leaving the till that day, and
        // the repeater never asked. A workshop recording a cost it
        // had not actually paid yet — a contractor invoice, a share
        // of the electricity bill — drove Cash down, potentially
        // negative, while the money was still in the bank, and the
        // Trial Balance then showed an abnormal cash balance with
        // nothing in any report to explain it.
        //
        // The repeater itself is parked too (see
        // ProductionOrderService::cost() and the production form), so
        // other_cost_total is 0 on every run this code creates today.
        // A workshop's run is materials + labor; anything else is a
        // normal Expense, entered on the Expenses screen where it can
        // be marked paid, unpaid or due — and where payroll can be
        // ticked "This is Production Labor" to settle the accrual
        // above (see postProductionLaborExpense()).
        //
        // if ((float) $order->other_cost_total > 0) {
        //     $lines[] = ['account' => Account::CASH, 'credit' => (float) $order->other_cost_total];
        // }

        $this->post($order->company_id, $order->date->toDateString(), 'Production order', $order, $lines);
    }

    /**
     * The real payroll Expense checked "This is Production Labor" —
     * reconciles what was ESTIMATED on Production Orders and is still
     * outstanding in Production Labor Accrued (any month — see
     * productionLaborOutstanding()) against what was ACTUALLY paid. The difference (either direction) lands
     * directly in Cost of Goods Sold rather than sitting unexplained:
     *
     *   - Clears $appliedAmount out of Production Labor Accrued.
     *   - Paid MORE than estimated → the extra is a real added cost
     *     → debit COGS for the difference.
     *   - Paid LESS than estimated → the estimate overstated cost
     *     → credit COGS for the difference (reduces it back).
     *   - The full real amount owed is credited to Accounts Payable,
     *     exactly like a normal expense — so the existing Pay Money
     *     flow settles it exactly the same way afterwards.
     *
     * Replaces postExpenseInvoice() for this one expense — see
     * ExpenseController::store()/update().
     */
    public function postProductionLaborExpense(Expense $expense, float $appliedAmount): void
    {
        $actual   = (float) $expense->amount;
        $variance = round($actual - $appliedAmount, 2);

        $lines = [];

        if ($appliedAmount > 0) {
            $lines[] = ['account' => Account::PRODUCTION_LABOR_ACCRUED, 'debit' => $appliedAmount];
        }

        if ($variance > 0) {
            $lines[] = ['account' => Account::COST_OF_GOODS_SOLD, 'debit' => $variance];
        } elseif ($variance < 0) {
            $lines[] = ['account' => Account::COST_OF_GOODS_SOLD, 'credit' => -$variance];
        }

        $lines[] = ['account' => Account::ACCOUNTS_PAYABLE, 'credit' => $actual];

        $this->post($expense->company_id, $expense->date->toDateString(), 'Production labor (payroll vs. estimate)', $expense, $lines);
    }

    /**
     * What is still sitting in Production Labor Accrued (account
     * 2200): labour ESTIMATED on production runs that no real payroll
     * has cleared yet. Positive = owed (a credit balance).
     *
     * Read straight from the ledger, so it naturally includes every
     * month — a run from last month whose wages are paid this month,
     * or a month in which no payroll was ever ticked "Production
     * Labor", is still here waiting to be cleared (audit finding
     * 4.3: the old month-by-month matching left those in 2200
     * forever).
     *
     * With $asOf, the balance as of that day — but never more than
     * the balance today, so a payroll entered with an earlier date
     * cannot clear an estimate that a later payroll already cleared.
     */
    public function productionLaborOutstanding(int $companyId, ?string $asOf = null): float
    {
        // Read-only: never seeds a chart of accounts just to answer.
        $account = Account::query()
            ->where('company_id', $companyId)
            ->where('code', Account::PRODUCTION_LABOR_ACCRUED)
            ->first();

        if (! $account) {
            return 0.0;
        }

        $balance = function (?string $upTo) use ($companyId, $account): float {
            $row = DB::table('journal_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_lines.company_id', $companyId)
                ->where('journal_entries.company_id', $companyId)
                ->where('journal_lines.account_id', $account->id)
                ->when($upTo, fn ($q) => $q->whereDate('journal_entries.date', '<=', $upTo))
                ->selectRaw('COALESCE(SUM(journal_lines.credit), 0) - COALESCE(SUM(journal_lines.debit), 0) as balance')
                ->first();

            return round((float) ($row->balance ?? 0), 2);
        };

        $outstanding = $asOf ? min($balance($asOf), $balance(null)) : $balance(null);

        return max(0.0, $outstanding);
    }

    /**
     * Equipment/vehicles are capitalised as a fixed asset, not
     * expensed immediately — standard practice for a purchase whose
     * benefit lasts beyond the current period. (Depreciating that
     * asset over time is a real, separate feature this doesn't
     * attempt yet — flagged, not silently skipped.)
     */
    public function postEquipmentPurchaseInvoice(EquipmentPurchase $purchase): void
    {
        $this->post($purchase->company_id, $purchase->date->toDateString(), 'Equipment/vehicle purchased', $purchase, [
            ['account' => Account::EQUIPMENT_ASSET, 'debit' => (float) $purchase->amount],
            ['account' => Account::ACCOUNTS_PAYABLE, 'credit' => (float) $purchase->amount],
        ]);
    }

    /**
     * One month's straight-line depreciation for one equipment/
     * vehicle purchase — entirely invisible to the user, posted by
     * the scheduled depreciation command, never shown as a UI field
     * anywhere. Moves value from the asset into an expense via the
     * standard contra-asset (Accumulated Depreciation) rather than
     * crediting Equipment directly, so the asset's original cost
     * stays visible on the books even as it depreciates.
     */
    public function postDepreciation(EquipmentPurchase $purchase, float $amount, string $date): void
    {
        if ($amount <= 0) {
            return;
        }

        $this->post($purchase->company_id, $date, 'Depreciation', $purchase, [
            ['account' => Account::DEPRECIATION_EXPENSE, 'debit' => $amount],
            ['account' => Account::ACCUMULATED_DEPRECIATION, 'credit' => $amount],
        ]);
    }

    /**
     * Money paid against a bill — whether at creation time or later
     * via Pay Money settling an open bill.
     */
    public function postBillPayment(Payment $payment): void
    {
        $this->post($payment->company_id, $payment->date->toDateString(), 'Payment made against bill', $payment, [
            ['account' => Account::ACCOUNTS_PAYABLE, 'debit' => (float) $payment->amount],
            ['account' => $this->cashAccountFor($payment->method), 'credit' => (float) $payment->amount],
        ]);
    }

    /**
     * A payment with no bill behind it (Pay Money's "or log a
     * generic payment"). Uses the category's expense account when
     * one was given (see StorePaymentOutRequest), falling back to
     * Miscellaneous Expense otherwise.
     */
    public function postStandalonePayment(Payment $payment): void
    {
        $account = $payment->category_id
            ? $this->expenseAccountFor($payment->category, $payment->company_id)
            : Account::MISC_EXPENSE;

        $this->post($payment->company_id, $payment->date->toDateString(), 'Cash payment (no bill)', $payment, [
            ['account' => $account, 'debit' => (float) $payment->amount],
            ['account' => $this->cashAccountFor($payment->method), 'credit' => (float) $payment->amount],
        ]);
    }

    // ── Opening balances ─────────────────────────────────────────
    //  One rule for all five of these: the "real" side of the entry
    //  is whatever asset/liability the line represents (Cash, Bank,
    //  Accounts Receivable, Inventory, Equipment, Accounts Payable)
    //  — the OTHER side is always Owner's Equity, never Revenue, an
    //  expense account, or a fresh Accounts Payable. That's what
    //  makes entering an opening balance different from entering a
    //  normal sale/bill: nothing here is new income, a new expense,
    //  or a new debt to a real supplier — it's simply recognising a
    //  balance that already existed on day one. See
    //  OpeningBalanceService for what creates the Sale/Expense/
    //  InventoryPurchase/EquipmentPurchase/Payment rows these post
    //  against.

    public function postOpeningBalanceCash(Payment $payment): void
    {
        $this->post($payment->company_id, $payment->date->toDateString(), 'Opening balance — cash & bank', $payment, [
            ['account' => $this->cashAccountFor($payment->method), 'debit' => (float) $payment->amount],
            ['account' => Account::OWNERS_EQUITY, 'credit' => (float) $payment->amount],
        ]);
    }

    public function postOpeningBalanceReceivable(Sale $sale): void
    {
        $this->post($sale->company_id, $sale->date->toDateString(), 'Opening balance — customer owes', $sale, [
            ['account' => Account::ACCOUNTS_RECEIVABLE, 'debit' => (float) $sale->amount],
            ['account' => Account::OWNERS_EQUITY, 'credit' => (float) $sale->amount],
        ]);
    }

    public function postOpeningBalancePayable(Expense $expense): void
    {
        $this->post($expense->company_id, $expense->date->toDateString(), 'Opening balance — owed to supplier', $expense, [
            ['account' => Account::OWNERS_EQUITY, 'debit' => (float) $expense->amount],
            ['account' => Account::ACCOUNTS_PAYABLE, 'credit' => (float) $expense->amount],
        ]);
    }

    public function postOpeningBalanceInventory(InventoryPurchase $purchase): void
    {
        $this->post($purchase->company_id, $purchase->date->toDateString(), 'Opening balance — starting stock', $purchase, [
            ['account' => Account::INVENTORY_ASSET, 'debit' => (float) $purchase->amount],
            ['account' => Account::OWNERS_EQUITY, 'credit' => (float) $purchase->amount],
        ]);
    }

    /**
     * Already-owned equipment at the opening date: its original cost
     * goes to Equipment, the depreciation it had ALREADY taken before
     * the opening date to Accumulated Depreciation, and only the
     * difference — its book value — to Owner's Equity (audit finding
     * 4.2; it used to come in at full cost with nothing depreciated).
     *
     * Dated on the asset's own purchase date, as before.
     */
    public function postOpeningBalanceEquipment(EquipmentPurchase $purchase): void
    {
        $cost        = (float) $purchase->amount;
        $accumulated = round(min($cost, max(0.0, (float) $purchase->accumulated_depreciation)), 2);

        $lines = [
            ['account' => Account::EQUIPMENT_ASSET, 'debit' => $cost],
        ];

        if (round($cost - $accumulated, 2) > 0) {
            $lines[] = ['account' => Account::OWNERS_EQUITY, 'credit' => round($cost - $accumulated, 2)];
        }

        if ($accumulated > 0) {
            $lines[] = ['account' => Account::ACCUMULATED_DEPRECIATION, 'credit' => $accumulated];
        }

        $this->post($purchase->company_id, $purchase->date->toDateString(), 'Opening balance — already-owned equipment', $purchase, $lines);
    }

    // ── Custody ──────────────────────────────────────────────────

    public function postCustodyGiven(Custody $custody): void
    {
        $this->post($custody->company_id, $custody->given_at->toDateString(), 'Custody handed out', $custody, [
            ['account' => Account::CUSTODY_ADVANCES, 'debit' => (float) $custody->amount],
            ['account' => $this->cashAccountFor($custody->method), 'credit' => (float) $custody->amount],
        ]);
    }

    /**
     * Settling a custody advance: recognise what was actually spent
     * (per category, from the settlement lines) and clear the
     * advance. If less was spent than handed out, the leftover cash
     * physically comes back in; if more was spent, the company
     * reimburses the difference. Both are folded into this one
     * entry so it's always exactly balanced in a single post() —
     * see the method body for the arithmetic reasoning.
     */
    public function postCustodySettlement(Custody $custody): void
    {
        $custody->loadMissing('settlementLines.category');

        $lines = [];

        foreach ($custody->settlementLines as $settlementLine) {
            $lines[] = [
                'account' => $this->expenseAccountFor($settlementLine->category, $custody->company_id),
                'debit'   => (float) $settlementLine->amount,
            ];
        }

        $leftover = (float) $custody->leftover_returned;
        $extra    = (float) $custody->extra_reimbursed;

        if ($leftover > 0) {
            $lines[] = ['account' => $this->cashAccountFor($custody->method), 'debit' => $leftover];
        }

        // Clearing the advance by exactly its original amount, plus
        // the cash side of any extra reimbursement, is always what
        // balances the debits above — see class doc comment on the
        // controller-side custody settlement for the worked example.
        $lines[] = ['account' => Account::CUSTODY_ADVANCES, 'credit' => (float) $custody->amount];

        if ($extra > 0) {
            $lines[] = ['account' => $this->cashAccountFor($custody->method), 'credit' => $extra];
        }

        $this->post($custody->company_id, $custody->settlement_date->toDateString(), 'Custody settled', $custody, $lines);
    }

    // ── Owner Injection / Withdrawal ────────────────────────────────

    /**
     * Money moving between the business and one of its owners.
     * Posted directly against the OwnerTransaction itself (source),
     * the same way Custody's give/settlement are — not against the
     * Payment row created alongside it for cash-figures purposes
     * (see OwnerTransaction::payments()'s doc comment).
     *
     * capital_injection and repay_withdrawal both credit
     * OWNER_CONTRIBUTIONS_WITHDRAWALS — they're the same accounting
     * fact (equity going up) and only differ in which label the
     * Owner Statement shows; withdrawal debits that same account
     * back down. profit_distribution is kept in its OWN account
     * (OWNER_PROFIT_DISTRIBUTIONS) rather than folded into the same
     * one, because — unlike a withdrawal of capital — it's also read
     * back out explicitly by ReportDataService::profitAndLoss() to
     * show what was actually paid out against the period's profit.
     */
    public function postOwnerTransaction(OwnerTransaction $transaction): void
    {
        $cash = $this->cashAccountFor($transaction->method);
        $amount = (float) $transaction->amount;

        $equityAccount = $transaction->category === 'profit_distribution'
            ? Account::OWNER_PROFIT_DISTRIBUTIONS
            : Account::OWNER_CONTRIBUTIONS_WITHDRAWALS;

        $lines = $transaction->direction === 'in'
            ? [
                ['account' => $cash, 'debit' => $amount],
                ['account' => $equityAccount, 'credit' => $amount],
            ]
            : [
                ['account' => $equityAccount, 'debit' => $amount],
                ['account' => $cash, 'credit' => $amount],
            ];

        $memo = match ($transaction->category) {
            'capital_injection'   => 'Owner capital injection',
            'repay_withdrawal'    => 'Owner repaying withdrawal',
            'withdrawal'          => 'Owner withdrawal',
            'profit_distribution' => 'Owner profit distribution',
        };

        $this->post($transaction->company_id, $transaction->date->toDateString(), $memo, $transaction, $lines);
    }

    /**
     * Reverse every journal entry tied to a record AND to its
     * payments, in one call — used when deleting a record entirely
     * (see e.g. SaleController::destroy()).
     */
    public function reverseAllForPayable(Model $payable): void
    {
        $this->reverseEntriesFor($payable);

        if (method_exists($payable, 'payments')) {
            foreach ($payable->payments as $payment) {
                $this->reverseEntriesFor($payment);
            }
        }
    }

    /**
     * Reverse only the entries posted directly against one record
     * (not its payments) — used when EDITING a record: the old
     * invoice/bill entry no longer reflects reality once totals
     * change, but existing payments are still real cash that
     * actually moved and must be left alone. Already-reversed
     * entries and reversal entries themselves are skipped so
     * nothing gets double-reversed.
     */
    public function reverseEntriesFor(Model $source): void
    {
        JournalEntry::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->id)
            ->get()
            ->each(function (JournalEntry $entry) {
                if (! $entry->reverses_id && ! $entry->isReversed()) {
                    $this->reverse($entry, 'Reversed — record deleted or edited');
                }
            });
    }

    /**
     * Reverse ONLY the Cost of Goods Sold entry posted against a
     * source (a Sale, so far) — leaving any other entry for that
     * same source (the sale's revenue/invoice entry) untouched.
     *
     * Needed because postCostOfGoodsSold() posts its own separate
     * JournalEntry against the same source as postSaleInvoice(), so
     * a plain reverseEntriesFor($sale) would reverse both — correct
     * when the whole sale is being re-entered, wrong when only the
     * item's moving-average cost changed underneath an otherwise
     * unchanged sale (see MovingAverageCostingService::repriceSaleCogs()).
     * Identifies the entry by which account it touches, not by memo
     * text, since memo strings aren't a stable contract to match on.
     */
    public function reverseCostOfGoodsSoldFor(Model $source): void
    {
        JournalEntry::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->id)
            ->whereHas('lines.account', fn ($q) => $q->where('code', Account::COST_OF_GOODS_SOLD))
            ->get()
            ->each(function (JournalEntry $entry) {
                if (! $entry->reverses_id && ! $entry->isReversed()) {
                    $this->reverse($entry, 'Reversed — cost recalculated (moving average correction)');
                }
            });
    }

    // ── Core primitive — the only place that writes journal rows ──

    /**
     * @param  list<array{account:string|Account, debit?:float, credit?:float}>  $lines
     */
    private function post(int $companyId, string $date, string $memo, ?Model $source, array $lines): JournalEntry
    {
        $totalDebits  = round(array_sum(array_column($lines, 'debit')), 2);
        $totalCredits = round(array_sum(array_column($lines, 'credit')), 2);

        // Same tolerance JournalEntry::isBalanced() checks after the
        // fact (FinancialRules::AMOUNT_TOLERANCE) — an entry that
        // gets past this guard can no longer fail that check later.
        // (QA audit, Sep 2026: this used to allow a full cent of
        // slack here while the model only allowed half a cent, so an
        // entry could be created successfully and still read back as
        // "unbalanced". See FinancialRules::AMOUNT_TOLERANCE.)
        if (! FinancialRules::amountsEqual($totalDebits, $totalCredits)) {
            throw new RuntimeException(
                "Unbalanced journal entry ({$memo}): debits {$totalDebits} != credits {$totalCredits}"
            );
        }

        return DB::transaction(function () use ($companyId, $date, $memo, $source, $lines) {
            $entry = JournalEntry::create([
                'company_id' => $companyId,
                'date'       => $date,
                'memo'       => $memo,
                'source_type' => $source ? $source::class : null,
                'source_id'   => $source?->id,
                'created_by'  => auth()->id(),
            ]);

            foreach ($lines as $line) {
                $account = $line['account'] instanceof Account
                    ? $line['account']
                    : $this->accountByCode($companyId, $line['account']);

                $entry->lines()->create([
                    'company_id' => $companyId,
                    'account_id' => $account->id,
                    'debit'      => $line['debit'] ?? 0,
                    'credit'     => $line['credit'] ?? 0,
                ]);
            }

            return $entry;
        });
    }

    /**
     * Reverse a posted entry with the exact opposite debits/credits
     * — used for voiding/correcting instead of ever editing or
     * deleting a posted entry. See JournalEntry::reverses()/
     * reversedBy() for how the link between the two is tracked.
     *
     * The reversal carries the ORIGINAL entry's date, not today's.
     * That matters more than it looks: correcting a March invoice
     * posts the correction back in March, so if the reversal landed
     * in September instead, March would keep the original figure AND
     * gain the corrected one — the month would read as the sum of
     * both. Dating the reversal with its original means the two
     * cancel inside the period they belong to, and every closed
     * month keeps reporting the same total no matter when somebody
     * fixes a typo. The audit trail is unaffected: created_at still
     * records when the correction was actually made.
     */
    public function reverse(JournalEntry $entry, ?string $memo = null): JournalEntry
    {
        $entry->loadMissing('lines');

        return DB::transaction(function () use ($entry, $memo) {
            $reversal = JournalEntry::create([
                'company_id'  => $entry->company_id,
                'date'        => $entry->date->toDateString(),
                'memo'        => $memo ?? "Reversal of: {$entry->memo}",
                'source_type' => $entry->source_type,
                'source_id'   => $entry->source_id,
                'reverses_id' => $entry->id,
                'created_by'  => auth()->id(),
            ]);

            foreach ($entry->lines as $line) {
                $reversal->lines()->create([
                    'company_id' => $entry->company_id,
                    'account_id' => $line->account_id,
                    // swapped — this is what makes it a reversal
                    'debit'  => $line->credit,
                    'credit' => $line->debit,
                ]);
            }

            return $reversal;
        });
    }

    // ── Account resolution helpers ───────────────────────────────

    private function cashAccountFor(?string $method): string
    {
        return $method === 'cash' ? Account::CASH : Account::BANK;
    }

    /**
     * Find-or-create the expense account for an expense Category
     * (Rent, Salaries, Utilities, ...) so the P&L can eventually
     * show real categorized expense accounts instead of one lump
     * sum. Falls back to Miscellaneous Expense if no category was
     * given (matches how Custody settlement lines allow a blank
     * category today).
     */
    private function expenseAccountFor(?Category $category, ?int $companyId = null): Account
    {
        if (! $category) {
            return $this->accountByCode($companyId, Account::MISC_EXPENSE);
        }

        return Account::query()->firstOrCreate(
            ['company_id' => $category->company_id, 'code' => "EXP-{$category->id}"],
            ['name' => $category->name, 'type' => 'expense'],
        );
    }

    private function accountByCode(?int $companyId, string $code): Account
    {
        $account = Account::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->first();

        if ($account) {
            return $account;
        }

        // Self-heal: a company created before the chart of accounts
        // existed gets seeded here, on first use, instead of every
        // posting call failing forever for it.
        $this->seedChartOfAccounts(Company::findOrFail($companyId));

        return Account::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->firstOrFail();
    }
}
