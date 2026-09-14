<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreVendorRequest;
use App\Http\Requests\App\UpdateVendorRequest;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — VendorController
//  Location: app/Http/Controllers/App/VendorController.php
//
//  Backs the "Vendor / Employee" dropdown shared by Expenses,
//  Inventory Purchase, Equipment, and Custody. `type` just labels
//  how the row was first added — both types are pickable everywhere.
// ══════════════════════════════════════════════════════════════════
class VendorController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Vendor::query()->orderBy('name')->get(['id', 'name', 'type'])
        );
    }

    public function store(StoreVendorRequest $request): RedirectResponse|JsonResponse
    {
        $vendor = Vendor::create($request->validated());

        if ($request->wantsJson()) {
            return response()->json($vendor, 201);
        }

        return back()->with('success', 'Vendor/employee added.');
    }

    /**
     * Fix a typo without leaving the page (pencil icon next to any
     * Vendor/Employee combo). JSON-only, same as CustomerController::update().
     */
    public function update(UpdateVendorRequest $request, Vendor $vendor): JsonResponse
    {
        $vendor->update($request->validated());

        return response()->json($vendor);
    }
}
