<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreProductionOrderRequest;
use App\Http\Requests\App\UpdateProductionOrderRequest;
// Used only by the parked 'expenseCategories' prop below.
use App\Models\Category;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Services\ProductionOrderService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ProductionOrderController
//  Location: app/Http/Controllers/App/ProductionOrderController.php
//
//  "Day Production" — only reachable at all for a company with
//  Production checked in its Business Type (see routes/web.php and
//  the frontend's businessType composable; there is no server-side
//  gate beyond that today since the items it needs — 'product' /
//  'raw_material' typed items — simply won't exist for a company
//  that never turned Production on).
// ══════════════════════════════════════════════════════════════════
class ProductionOrderController extends Controller
{
    public function __construct(
        private readonly ProductionOrderService $productionOrders,
    ) {}

    public function index(): Response
    {
        $orders = ProductionOrder::query()
            ->with(['item:id,name,base_unit_name', 'materialLines.item:id,name,base_unit_name'])
            ->latest('date')
            ->latest('id')
            ->paginate(20)
            ->through(fn (ProductionOrder $order) => [
                'id'               => $order->id,
                'date'             => $order->date->toDateString(),
                'item_id'          => $order->item_id,
                'item'             => $order->item?->name,
                'qty_produced'     => (float) $order->qty_produced,
                'base_unit_name'   => $order->item?->base_unit_name,
                'material_cost'    => (float) $order->material_cost,
                'labor_cost'       => (float) $order->labor_cost,
                'other_cost_total' => (float) $order->other_cost_total,
                'total_cost'       => (float) $order->total_cost,
                'unit_cost'        => (float) $order->unit_cost,
                'materials'        => $order->materialLines->map(fn ($line) => [
                    'item_id'   => $line->item_id,
                    'item'      => $line->item?->name,
                    'qty'       => (float) $line->qty,
                    'unit'      => $line->item?->base_unit_name,
                    'line_total' => (float) $line->line_total,
                ]),
                // ── PARKED with the "other costs" repeater ────────
                // See ProductionOrderService's class doc comment.
                // other_cost_total stays above: a legacy run entered
                // before the repeater was parked still has one, and
                // hiding it would misstate what that run cost.
                //
                // 'other_costs'      => $order->otherCostLines->map(fn ($line) => [
                //     'category_id' => $line->category_id,
                //     'description' => $line->description,
                //     'amount'      => (float) $line->amount,
                // ]),
            ]);

        return Inertia::render('App/ProductionOrders/Index', [
            'products'         => Item::query()->product()->orderBy('name')->get(['id', 'name', 'base_unit_name']),
            'rawMaterials'     => Item::query()->rawMaterial()->orderBy('name')->get(['id', 'name', 'base_unit_name']),
            // PARKED with the "other costs" repeater — the form has
            // no category picker left to feed.
            // 'expenseCategories' => Category::query()->expenseKind()->orderBy('name')->get(['id', 'name', 'name_ar']),
            'orders'           => $orders,
        ]);
    }

    public function store(StoreProductionOrderRequest $request): RedirectResponse
    {
        $this->productionOrders->create($request->validated());

        return back()->with('success', 'Production order recorded.');
    }

    /**
     * Correct a run that was entered wrong.
     *
     * Deliberately NOT behind authorizeDelete(): an edit reverses and
     * re-posts, leaving the whole trail in the ledger, so an employee
     * fixing their own typo is a normal correction. Deleting is the
     * restricted action, because that is the one that destroys the
     * record — see Controller::authorizeDelete().
     */
    public function update(UpdateProductionOrderRequest $request, ProductionOrder $productionOrder): RedirectResponse
    {
        $this->productionOrders->update($productionOrder, $request->validated());

        return back()->with('success', 'Production order updated.');
    }

    public function destroy(ProductionOrder $productionOrder): RedirectResponse
    {
        $this->authorizeDelete();

        $this->productionOrders->delete($productionOrder);

        return back()->with('success', 'Production order deleted.');
    }
}
