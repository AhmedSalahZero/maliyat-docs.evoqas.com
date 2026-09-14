<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreItemRequest;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ItemController
//  Location: app/Http/Controllers/App/ItemController.php
//
//  Backs the "Item" dropdown on Sales and Inventory Purchase lines.
// ══════════════════════════════════════════════════════════════════
class ItemController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Item::query()->orderBy('name')->get([
                'id', 'name', 'uom', 'qty_per_uom', 'base_unit_name',
            ])
        );
    }

    public function store(StoreItemRequest $request): RedirectResponse|JsonResponse
    {
        $item = Item::create($request->validated());

        if ($request->wantsJson()) {
            return response()->json($item, 201);
        }

        return back()->with('success', 'Item added.');
    }
}
