<?php

namespace App\Services;

use App\Models\Category;
use App\Models\EquipmentPurchase;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Models\InventoryPurchaseLine;
use App\Models\Item;
use App\Models\OpeningBalance;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — OpeningBalanceService
//  Location: app/Services/OpeningBalanceService.php
//
//  Everything the Opening Balance wizard needs, in one place:
//    - data()   → what to show the wizard (posted or still draft).
//    - submit() → validated input in, real records + journal
//                 entries out, all in one transaction.
//    - reset()  → undoes every opening-balance record for a company
//                 exactly the way each screen already deletes its
//                 own records (reverse the journal entry, delete
//                 child rows, delete the row) — so the owner can
//                 redo the whole thing from scratch if the first
//                 attempt had mistakes, without a second, separate
//                 "editing" system having to be built and kept in
//                 sync with the real one.
//
//  Every asset/liability line's OTHER side is Owner's Equity — see
//  JournalService's "Opening balances" section for why. Cash/Bank/
//  Customers/Suppliers/Inventory/Equipment don't need to add up to
//  anything themselves; Owner's Equity is what makes the whole set
//  balance, exactly as a real accountant would open a new company's
//  books.
// ══════════════════════════════════════════════════════════════════
class OpeningBalanceService
{
    // Vendor used only for the referential-integrity requirement
    // that InventoryPurchase/EquipmentPurchase always have a
    // vendor_id — never shown to the user, never picked by them.
    private const SYSTEM_VENDOR_NAME = 'Opening Balance';

    // Category used only so Expense's required category_id has
    // something to point at. Doesn't drive any journal posting —
    // postOpeningBalancePayable() ignores it entirely.
    private const SYSTEM_EXPENSE_CATEGORY_NAME = 'Opening Balance';

    public function __construct(
        private readonly JournalService $journal,
    ) {}

    /**
     * What the wizard screen needs: the header (draft or posted),
     * and — only once posted — the actual lines that were entered,
     * read back from the real Sale/Expense/InventoryPurchase/
     * EquipmentPurchase/Payment rows (there is no separate copy of
     * this data anywhere, so it can never drift from what's on the
     * statements).
     */
    public function data(int $companyId): array
    {
        $header = OpeningBalance::query()->firstOrNew(
            ['company_id' => $companyId],
            ['opening_date' => now()->toDateString(), 'status' => 'draft']
        );

        if (! $header->isPosted()) {
            return ['header' => $this->headerArray($header), 'lines' => null];
        }

        $customers = Sale::query()
            ->where('is_opening_balance', true)
            ->with('customer:id,name')
            ->get()
            ->map(fn (Sale $s) => [
                'id' => $s->id, 'customer_id' => $s->customer_id,
                'customer_name' => $s->customer?->name, 'amount' => (float) $s->amount,
            ]);

        $suppliers = Expense::query()
            ->where('is_opening_balance', true)
            ->with('vendor:id,name')
            ->get()
            ->map(fn (Expense $e) => [
                'id' => $e->id, 'vendor_id' => $e->vendor_id,
                'vendor_name' => $e->vendor?->name, 'amount' => (float) $e->amount,
            ]);

        $inventory = InventoryPurchaseLine::query()
            ->whereHas('inventoryPurchase', fn ($q) => $q->where('is_opening_balance', true))
            ->with('item:id,name')
            ->get()
            ->map(fn (InventoryPurchaseLine $line) => [
                'id' => $line->id, 'item_id' => $line->item_id,
                'item_name' => $line->item?->name, 'qty' => (float) $line->qty,
                'unit_price' => (float) $line->unit_price, 'line_total' => (float) $line->line_total,
            ]);

        $equipment = EquipmentPurchase::query()
            ->where('is_opening_balance', true)
            ->with('category:id,name')
            ->get()
            ->map(fn (EquipmentPurchase $p) => [
                'id' => $p->id, 'name' => $p->name, 'category_id' => $p->category_id,
                'category_name' => $p->category?->name, 'amount' => (float) $p->amount,
                'date' => $p->date->toDateString(),
            ]);

        return [
            'header' => $this->headerArray($header),
            'lines' => [
                'customers' => $customers,
                'suppliers' => $suppliers,
                'inventory' => $inventory,
                'equipment' => $equipment,
            ],
        ];
    }

    /**
     * @param  array{
     *     opening_date: string,
     *     cash_amount: float, bank_amount: float,
     *     customers: list<array{customer_id:int, amount:float}>,
     *     suppliers: list<array{vendor_id:int, amount:float}>,
     *     inventory: list<array{item_id:int, qty:float, unit_price:float}>,
     *     equipment: list<array{name:string, category_id:int, amount:float, date:string}>,
     * }  $data
     */
    public function submit(int $companyId, array $data): OpeningBalance
    {
        $header = OpeningBalance::query()->where('company_id', $companyId)->first();

        if ($header?->isPosted()) {
            throw new \RuntimeException('Opening balance already posted for this company.');
        }

        return DB::transaction(function () use ($companyId, $data) {
            $date = $data['opening_date'];

            $header = OpeningBalance::query()->updateOrCreate(
                ['company_id' => $companyId],
                [
                    'opening_date' => $date,
                    'cash_amount'  => $data['cash_amount'] ?? 0,
                    'bank_amount'  => $data['bank_amount'] ?? 0,
                    'status'       => 'posted',
                    'posted_at'    => now(),
                    'posted_by'    => auth()->id(),
                ]
            );

            // ── Cash & Bank ──────────────────────────────────────
            foreach ([['cash', $data['cash_amount'] ?? 0], ['bank', $data['bank_amount'] ?? 0]] as [$method, $amount]) {
                if ((float) $amount <= 0) {
                    continue;
                }

                $payment = Payment::create([
                    'company_id' => $companyId,
                    'date' => $date, 'amount' => $amount, 'method' => $method,
                    'direction' => 'in', 'note' => 'Opening balance',
                    'is_opening_balance' => true,
                ]);

                $this->journal->postOpeningBalanceCash($payment);
            }

            // ── Customers who owe money ──────────────────────────
            foreach ($data['customers'] ?? [] as $row) {
                if ((float) ($row['amount'] ?? 0) <= 0) {
                    continue;
                }

                $sale = Sale::create([
                    'company_id' => $companyId, 'customer_id' => $row['customer_id'],
                    'date' => $date, 'subtotal' => $row['amount'], 'vat_rate' => 0,
                    'vat_amount' => 0, 'amount' => $row['amount'],
                    'is_opening_balance' => true,
                ]);

                $this->journal->postOpeningBalanceReceivable($sale);
            }

            // ── Suppliers still owed money ────────────────────────
            $expenseCategory = $this->systemExpenseCategory($companyId);

            foreach ($data['suppliers'] ?? [] as $row) {
                if ((float) ($row['amount'] ?? 0) <= 0) {
                    continue;
                }

                $expense = Expense::create([
                    'company_id' => $companyId, 'vendor_id' => $row['vendor_id'],
                    'category_id' => $expenseCategory->id, 'date' => $date,
                    'amount' => $row['amount'], 'is_opening_balance' => true,
                ]);

                $this->journal->postOpeningBalancePayable($expense);
            }

            // ── Starting inventory ────────────────────────────────
            $inventoryRows = collect($data['inventory'] ?? [])
                ->filter(fn ($row) => (float) ($row['qty'] ?? 0) > 0 && (float) ($row['unit_price'] ?? 0) > 0)
                ->values();

            if ($inventoryRows->isNotEmpty()) {
                $vendor = $this->systemVendor($companyId);
                $total = $inventoryRows->sum(fn ($row) => round($row['qty'] * $row['unit_price'], 2));

                $purchase = InventoryPurchase::create([
                    'company_id' => $companyId, 'vendor_id' => $vendor->id, 'date' => $date,
                    'subtotal' => $total, 'vat_rate' => 0, 'vat_amount' => 0, 'amount' => $total,
                    'is_opening_balance' => true,
                ]);

                foreach ($inventoryRows as $row) {
                    $item = Item::query()->findOrFail($row['item_id']);

                    $purchase->lines()->create([
                        'item_id' => $item->id, 'qty' => $row['qty'],
                        'uom' => $item->uom ?? $item->base_unit_name ?? 'unit',
                        'qty_per_uom' => $item->qty_per_uom ?? 1,
                        'base_unit_name' => $item->base_unit_name ?? 'unit',
                        'unit_price' => $row['unit_price'],
                        'line_total' => round($row['qty'] * $row['unit_price'], 2),
                    ]);
                }

                $this->journal->postOpeningBalanceInventory($purchase);
            }

            // ── Already-owned equipment & tools ───────────────────
            $vendor = null;

            foreach ($data['equipment'] ?? [] as $row) {
                if ((float) ($row['amount'] ?? 0) <= 0) {
                    continue;
                }

                $vendor ??= $this->systemVendor($companyId);
                $category = Category::query()->findOrFail($row['category_id']);

                $purchase = EquipmentPurchase::create([
                    'company_id' => $companyId, 'vendor_id' => $vendor->id,
                    'category_id' => $category->id, 'name' => $row['name'],
                    'qty' => 1, 'unit_price' => $row['amount'], 'amount' => $row['amount'],
                    'date' => $row['date'] ?? $date,
                    'useful_life_years' => $category->default_useful_life_years
                        ?? Category::DEFAULT_EQUIPMENT_USEFUL_LIFE_YEARS,
                    'is_opening_balance' => true,
                ]);

                $this->journal->postOpeningBalanceEquipment($purchase);
            }

            return $header;
        });
    }

    /**
     * Undo every opening-balance record for a company (mirrors each
     * model's own destroy() controller action exactly) and reopen
     * the wizard as a fresh draft.
     */
    public function reset(int $companyId): void
    {
        DB::transaction(function () use ($companyId) {
            // One consolidated log entry for the whole wipe, rather
            // than one per row — a reset is a single deliberate
            // action ("start opening balance over"), not several
            // unrelated deletes, so the audit trail should read that
            // way too. Counts are taken before anything is touched.
            $header = OpeningBalance::query()->where('company_id', $companyId)->first();

            if ($header) {
                \App\Support\DeletionLogger::log(
                    $header,
                    'Opening balance reset — cash '.number_format((float) $header->cash_amount, 2)
                        .', bank '.number_format((float) $header->bank_amount, 2),
                    [
                        'sales_removed'               => Sale::query()->where('is_opening_balance', true)->count(),
                        'expenses_removed'            => Expense::query()->where('is_opening_balance', true)->count(),
                        'inventory_purchases_removed' => InventoryPurchase::query()->where('is_opening_balance', true)->count(),
                        'equipment_purchases_removed' => EquipmentPurchase::query()->where('is_opening_balance', true)->count(),
                        'payments_removed'            => Payment::query()->where('is_opening_balance', true)->count(),
                    ]
                );
            }

            Sale::query()->where('is_opening_balance', true)->get()->each(function (Sale $sale) {
                $this->journal->reverseAllForPayable($sale);
                $sale->payments()->delete();
                $sale->lines()->delete();
                $sale->delete();
            });

            Expense::query()->where('is_opening_balance', true)->get()->each(function (Expense $expense) {
                $this->journal->reverseAllForPayable($expense);
                $expense->payments()->delete();
                $expense->delete();
            });

            InventoryPurchase::query()->where('is_opening_balance', true)->get()->each(function (InventoryPurchase $purchase) {
                $this->journal->reverseAllForPayable($purchase);
                $purchase->payments()->delete();
                $purchase->lines()->delete();
                $purchase->delete();
            });

            EquipmentPurchase::query()->where('is_opening_balance', true)->get()->each(function (EquipmentPurchase $purchase) {
                $this->journal->reverseAllForPayable($purchase);
                $purchase->payments()->delete();
                $purchase->delete();
            });

            Payment::query()->where('is_opening_balance', true)->get()->each(function (Payment $payment) {
                $this->journal->reverseEntriesFor($payment);
                $payment->delete();
            });

            OpeningBalance::query()->where('company_id', $companyId)->update([
                'status' => 'draft', 'posted_at' => null, 'posted_by' => null,
            ]);
        });
    }

    private function headerArray(OpeningBalance $header): array
    {
        return [
            'opening_date' => optional($header->opening_date)->toDateString() ?? now()->toDateString(),
            'cash_amount' => (float) $header->cash_amount,
            'bank_amount' => (float) $header->bank_amount,
            'status' => $header->status,
            'posted_at' => optional($header->posted_at)->toDateTimeString(),
        ];
    }

    private function systemVendor(int $companyId): Vendor
    {
        return Vendor::query()->firstOrCreate(
            ['company_id' => $companyId, 'name' => self::SYSTEM_VENDOR_NAME],
            ['type' => 'vendor']
        );
    }

    private function systemExpenseCategory(int $companyId): Category
    {
        return Category::query()->firstOrCreate(
            ['company_id' => $companyId, 'kind' => 'expense', 'name' => self::SYSTEM_EXPENSE_CATEGORY_NAME],
        );
    }
}
