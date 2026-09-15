<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreItemRequest;
use App\Http\Requests\App\UpdateItemRequest;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Inertia\Response;
use Inertia\Inertia;
use Illuminate\Http\RedirectResponse;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ItemController
//  Location: app/Http/Controllers/App/ItemController.php
//
//  Backs the "Item" dropdown on Sales and Inventory Purchase lines.
// ══════════════════════════════════════════════════════════════════
class ItemController extends Controller
{
    /**
     * Company's items, alphabetical, plus every category — the
     * standalone "Items & categories" page (linked from the app
     * menu). See CustomerController::index()'s doc comment for why
     * this no longer branches on wantsJson().
     */
    public function index(): Response
    {
        $items = Item::query()->orderBy('name')->get([
            'id', 'name', 'uom', 'qty_per_uom', 'base_unit_name',
        ]);

        return Inertia::render('App/Lookups/Index', [
            'tab'        => 'items',
            'rows'       => $items,
            // The menu entry is "Items & categories", so the page
            // carries both rather than sending the user hunting.
            'categories' => \App\Models\Category::query()
                ->orderBy('name')->get(['id', 'name', 'kind']),
        ]);
    }

    /**
     * Two real callers: the sentence form's ComboSelect via plain
     * axios (no X-Inertia header, wants JSON), and the standalone
     * Items page's own "Add new" form via an Inertia form post
     * (X-Inertia header present, wants the usual redirect-back).
     */
    public function store(StoreItemRequest $request): RedirectResponse|JsonResponse
    {
        $item = Item::create($request->validated());

        if (! $request->header('X-Inertia')) {
            return response()->json($item, 201);
        }

        return back()->with('success', 'Item added.');
    }

    /**
     * Correct an item's name or its unit setup.
     *
     * Note on qty_per_uom: stock is held in BASE units, and every
     * purchase line stored its own qty_per_uom at the time it was
     * recorded (see inventory_purchase_lines). So changing it here
     * affects how NEW lines convert, and leaves history as it was
     * entered — which is the safe direction. Historic lines are not
     * silently re-valued behind the user's back.
     */
    public function update(UpdateItemRequest $request, Item $item): RedirectResponse|JsonResponse
    {
        $item->update($request->validated());

        if (! $request->header('X-Inertia')) {
            return response()->json($item->fresh());
        }

        return back()->with('success', 'Item updated.');
    }
}
