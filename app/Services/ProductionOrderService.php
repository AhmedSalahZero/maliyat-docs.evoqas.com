<?php

namespace App\Services;

// Used only by the parked "other costs" block in cost() — kept so
// un-parking it is one uncomment, not a hunt for what it needed.
use App\Models\Category;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Services\JournalService;
use App\Services\MovingAverageCostingService;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ProductionOrderService
//  Location: app/Services/ProductionOrderService.php
//
//  "Made {qty} {product} today: used {materials...}, labor {X}."
//
//     material_cost = sum(qty consumed × that raw material's TRUE
//                     MOVING AVERAGE cost on this run's date — see
//                     MovingAverageCostingService; the old
//                     Item::averagePurchaseCost() this used to
//                     reference has been removed (Sep 2026))
//     total_cost = material_cost + labor_cost
//     unit_cost  = total_cost ÷ qty_produced
//
//  This class creates the order and its material lines with a
//  placeholder (zero) material cost, then hands off to
//  MovingAverageCostingService::onItemMovementChanged() per raw
//  material — that's what actually prices each material line,
//  updates this order's material_cost/total_cost/unit_cost, posts
//  the journal entry, and cascades into the finished item's own
//  moving-average pool (this order is that item's "purchase" event).
//
//  Raw material lines are the thing that actually reduces that
//  material's stock (see Item::totalConsumedInProductionBase());
//  the ProductionOrder row itself is what increases the product's
//  stock (see Item::totalProducedBase()) at unit_cost.
//
//  ── The "other costs" repeater is PARKED ─────────────────────────
//  This app is built for WORKSHOPS, not factories. A day's run is
//  what was consumed and what the day's labor cost — anything else
//  (electricity, delivery, a contractor) is a normal Expense, and
//  the Expenses screen already handles it properly: a vendor, a
//  category, and a payment mode that can say "not paid yet". The
//  repeater here could say none of that, so JournalService posted
//  every line as cash leaving the till the same day whether it had
//  or not.
//
//  So other_cost_total is 0 on every run created from here on, and
//  the code that computed it is commented out below rather than
//  deleted — the table, the model and the column all stay, so
//  historical runs keep their figures and the feature can come back
//  if a factory ever needs it. Correcting a legacy run re-costs it
//  from scratch and therefore zeroes its other costs, which is the
//  intended behaviour, not a side effect.
//
//  Production labor is still settled the way it always was: enter
//  the real payroll on the Expenses screen and tick "This is
//  Production Labor" — see JournalService::postProductionLaborExpense().
// ══════════════════════════════════════════════════════════════════
class ProductionOrderService
{
    public function __construct(
        private readonly JournalService $journal,
        private readonly MovingAverageCostingService $costing,
    ) {}

    /**
     * @param  array{
     *     item_id:int, date:string, qty_produced:float,
     *     materials: list<array{item_id:int, qty:float}>,
     *     labor_cost?:float,
     *     other_costs?: list<array{category_id:int, amount:float}>,
     * }  $data
     */
    public function create(array $data): ProductionOrder
    {
        return DB::transaction(function () use ($data) {
            $costed = $this->cost($data);

            // Material lines are created with a zero placeholder
            // cost — the moving-average engine prices them properly
            // a few lines down, once they actually exist as rows it
            // can read as this item's newest outbound movement (the
            // same reason Sale/InventoryPurchase controllers create
            // their lines before calling the costing engine too).
            $order = ProductionOrder::create([
                'item_id'          => $data['item_id'],
                'date'             => $data['date'],
                'qty_produced'     => $costed['qty_produced'],
                'material_cost'    => 0,
                'labor_cost'       => $costed['labor_cost'],
                'other_cost_total' => $costed['other_cost_total'],
                'total_cost'       => $costed['labor_cost'] + $costed['other_cost_total'],
                'unit_cost'        => 0,
                'created_by'       => auth()->id(),
            ]);

            foreach ($costed['material_lines'] as $line) {
                $order->materialLines()->create($line);
            }

            // PARKED with the repeater — see the class doc comment.
            // foreach ($costed['other_cost_lines'] as $line) {
            //     $order->otherCostLines()->create($line);
            // }

            // Post once up front against the placeholder total
            // (material_cost still 0 at this point) — guarantees an
            // entry exists even for the edge case where every
            // material used turns out to be priced at 0 (never
            // purchased), which would otherwise mean total_cost
            // never actually changes below and the repricing step
            // has nothing to trigger a repost with.
            $this->journal->postProductionOrder($order->fresh(['materialLines']));

            // For each raw material this run touches: recalculate its
            // moving-average ledger from this order's date forward
            // (which now includes this order's material line as a
            // new outbound movement, so it gets priced), then
            // reverse-and-repost this order's journal entry against
            // the real material cost, and cascade into the finished
            // item's pool. All of that happens inside
            // onItemMovementChanged() — see MovingAverageCostingService.
            // Processing one material item at a time means the order
            // may be reposted more than once here if the run uses
            // several different raw materials (each call reprices
            // from what's in the database at that moment) — a little
            // extra journal churn on creation, in exchange for never
            // needing a separate code path for "one material" vs
            // "several materials".
            $itemIds = collect($costed['material_lines'])->pluck('item_id')->filter()->unique();

            foreach ($itemIds as $itemId) {
                $this->costing->onItemMovementChanged((int) $order->company_id, (int) $itemId, $order->date->toDateString());
            }

            return $order->fresh(['materialLines']);
        });
    }

    /**
     * Correct a production run that was entered wrong.
     *
     * There was no way to do this — the service had create() and
     * delete() and nothing between them, so fixing a mistyped
     * quantity meant deleting the run and entering it again. Those
     * are not equivalent: deleting is company-admin only precisely
     * because it destroys the record and its ledger trail, so an
     * employee who made a typo could not fix their own work.
     *
     * The run is re-costed from scratch rather than patched. Material
     * costs are snapshots of each material's average cost, and the
     * mix of materials may itself be what changed — so recomputing
     * the whole thing is the only way the stored total can still
     * describe the lines underneath it.
     *
     * The old ledger entry is REVERSED rather than edited, the same
     * treatment every other correction in this app gets: the general
     * ledger keeps the original, its reversal and the corrected
     * entry, and the reversal carries the original's date so the
     * pair settles inside the month it belongs to.
     *
     * @param  array{
     *     item_id:int, date:string, qty_produced:float,
     *     materials: list<array{item_id:int, qty:float}>,
     *     labor_cost?:float,
     *     other_costs?: list<array{category_id:int, amount:float}>,
     * }  $data
     */
    public function update(ProductionOrder $order, array $data): ProductionOrder
    {
        return DB::transaction(function () use ($order, $data) {
            // Captured before anything changes — see the identical
            // note in SaleController::update(). Both the raw
            // materials THIS order used to consume and the item it
            // used to PRODUCE need their ledgers recalculated if
            // either the mix or the date changes.
            $oldMaterialItemIds = $order->materialLines()->pluck('item_id')->filter()->unique();
            $oldProductItemId   = (int) $order->item_id;
            $oldDate            = $order->date->toDateString();

            $this->journal->reverseEntriesFor($order);

            $costed = $this->cost($data);

            $order->update([
                'item_id'          => $data['item_id'],
                'date'             => $data['date'],
                'qty_produced'     => $costed['qty_produced'],
                'material_cost'    => 0,
                'labor_cost'       => $costed['labor_cost'],
                'other_cost_total' => $costed['other_cost_total'],
                'total_cost'       => $costed['labor_cost'] + $costed['other_cost_total'],
                'unit_cost'        => 0,
            ]);

            $order->materialLines()->delete();
            // NOT parked: a legacy run still carries other-cost lines,
            // and re-costing has just zeroed its other_cost_total. The
            // lines have to go with it, or the row and the lines under
            // it would describe two different runs.
            $order->otherCostLines()->delete();

            foreach ($costed['material_lines'] as $line) {
                $order->materialLines()->create($line);
            }

            // PARKED with the repeater — see the class doc comment.
            // foreach ($costed['other_cost_lines'] as $line) {
            //     $order->otherCostLines()->create($line);
            // }

            // Post once up front against the placeholder total — see
            // the identical guard in create() for why (a run made
            // entirely of never-purchased materials would otherwise
            // never trigger a repost below).
            $fresh = $order->fresh();
            $this->journal->postProductionOrder($fresh->load('materialLines'));

            // Recalculate every raw material this order touches —
            // old mix or new mix, whichever is earlier — exactly the
            // same "union of old and new, earliest of old and new
            // date" rule SaleController::update() follows.
            $newMaterialItemIds = collect($costed['material_lines'])->pluck('item_id')->filter()->unique();
            $materialItemIds    = $oldMaterialItemIds->merge($newMaterialItemIds)->unique();
            $materialFromDate   = min($oldDate, $fresh->date->toDateString());

            foreach ($materialItemIds as $itemId) {
                $this->costing->onItemMovementChanged((int) $fresh->company_id, (int) $itemId, $materialFromDate);
            }

            // This recalculates the CURRENT product's own pool too
            // (materialItemIds recalculation above already priced
            // this order's own material lines and, inside
            // repriceProductionOrder(), reposts THIS order and
            // cascades into $fresh->item_id — see
            // MovingAverageCostingService::onItemMovementChanged()).
            // The one case that still needs handling directly here:
            // the PRODUCT this order makes changed (a different
            // finished item entirely) — the OLD product's pool lost
            // this order's inbound movement and needs recalculating
            // too, from the old date forward.
            if ($oldProductItemId !== (int) $fresh->item_id) {
                $this->costing->onItemMovementChanged((int) $fresh->company_id, $oldProductItemId, $oldDate);
            }

            return $order->fresh(['materialLines']);
        });
    }

    /**
     * What a run costs, and the lines that make it up.
     *
     * Shared by create() and update() so the two cannot drift apart
     * about what a production run is worth — which, given the costing
     * feeds stock valuation and every sale's cost of goods, is not a
     * difference anyone would notice quickly.
     *
     * @return array{
     *     qty_produced:float, material_cost:float, labor_cost:float,
     *     other_cost_total:float, total_cost:float, unit_cost:float,
     *     material_lines:list<array<string,mixed>>,
     *     other_cost_lines:list<array<string,mixed>>,
     * }
     */
    private function cost(array $data): array
    {
        // Material lines are priced by MovingAverageCostingService
        // once they're saved as real rows (see create()/update()) —
        // NOT here. A material never purchased yet still gets a row,
        // priced at 0 by the engine's own "no stock, no cost" rule
        // (same reasoning the app always used), rather than blocking
        // the whole batch.
        $materialCost = 0.0;
        $materialLines = [];

        foreach ($data['materials'] as $line) {
            $materialLines[] = [
                'item_id'            => $line['item_id'],
                'qty'                => (float) $line['qty'],
                'unit_cost_snapshot' => 0,
                'line_total'         => 0,
            ];
        }

        // ── PARKED: the "other costs" repeater ────────────────────
        //
        // See the class doc comment. Zeroed here rather than only
        // hidden on the form, and this is the line that makes the
        // guarantee real: JournalService::postProductionOrder()
        // debits Inventory by total_cost and credits only materials
        // and labor, so a run reaching it with other costs still in
        // its total would be an unbalanced entry and post() would
        // refuse it. Everything downstream can rely on this being 0.
        //
        // $otherCostInput = collect($data['other_costs'] ?? [])
        //     ->filter(fn ($line) => ! empty($line['category_id']) && (float) $line['amount'] > 0)
        //     ->values();
        //
        // $categories = Category::query()
        //     ->whereIn('id', $otherCostInput->pluck('category_id'))
        //     ->get()
        //     ->keyBy('id');
        //
        // $otherCostLines = $otherCostInput->map(fn ($line) => [
        //     'category_id' => $line['category_id'],
        //     // Snapshot the name now — see class doc comment on why
        //     // this doesn't just join to categories live.
        //     'description' => $categories->get($line['category_id'])?->name ?? '',
        //     'amount'      => round((float) $line['amount'], 2),
        // ])->all();
        //
        // $otherCostTotal = round((float) collect($otherCostLines)->sum('amount'), 2);
        $otherCostLines = [];
        $otherCostTotal = 0.0;

        $materialCost   = round($materialCost, 2);
        $laborCost      = round((float) ($data['labor_cost'] ?? 0), 2);
        $qtyProduced    = (float) $data['qty_produced'];

        $totalCost = round($materialCost + $laborCost + $otherCostTotal, 2);

        return [
            'qty_produced'     => $qtyProduced,
            'material_cost'    => $materialCost,
            'labor_cost'       => $laborCost,
            'other_cost_total' => $otherCostTotal,
            'total_cost'       => $totalCost,
            'unit_cost'        => $qtyProduced > 0 ? round($totalCost / $qtyProduced, 4) : 0,
            'material_lines'   => $materialLines,
            'other_cost_lines' => $otherCostLines,
        ];
    }

    /**
     * Delete a production order: reverse its ledger entry and
     * remove the row (lines cascade-delete). Raw material stock and
     * the product's stock are both computed fresh from what
     * production orders currently exist, so deleting one is a real
     * correction — same reasoning as InventoryPurchaseController::destroy().
     */
    public function delete(ProductionOrder $order): void
    {
        DB::transaction(function () use ($order) {
            \App\Support\DeletionLogger::log(
                $order,
                "Production order #{$order->id} — ".($order->item?->name ?? 'Unknown product').' — '.number_format((float) $order->qty_produced, 2).' produced',
                [
                    'material_lines'   => $order->materialLines->toArray(),
                    'other_cost_lines' => $order->otherCostLines->toArray(),
                ]
            );

            // Captured before the lines are gone — both sides of
            // this order's stock movement (the materials it
            // consumed, and the finished item it produced) need
            // their ledgers recalculated once it's deleted.
            $materialItemIds = $order->materialLines()->pluck('item_id')->filter()->unique();
            $productItemId   = (int) $order->item_id;
            $date            = $order->date->toDateString();
            $companyId       = (int) $order->company_id;

            $this->journal->reverseEntriesFor($order);
            $order->materialLines()->delete();
            $order->otherCostLines()->delete();
            $order->delete();

            // Each raw material's ledger no longer has this order's
            // material line in it at all (deleted above), so this
            // simply removes its contribution going forward — the
            // deleted order can't be "repriced" (it doesn't exist
            // any more), so unlike an edit, this does NOT cascade
            // into the produced item automatically; that's handled
            // explicitly right after.
            foreach ($materialItemIds as $itemId) {
                $this->costing->onItemMovementChanged($companyId, (int) $itemId, $date);
            }

            // The produced item's pool loses this order's inbound
            // movement entirely — recalculate it directly.
            $this->costing->onItemMovementChanged($companyId, $productItemId, $date);
        });
    }
}
