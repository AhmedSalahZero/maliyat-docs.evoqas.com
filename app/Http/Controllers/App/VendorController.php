<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreVendorRequest;
use App\Http\Requests\App\UpdateVendorRequest;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Inertia\Response;
use Inertia\Inertia;
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
    /**
     * Company's vendors/employees, alphabetical — the standalone
     * Suppliers page (linked from the app menu). See
     * CustomerController::index()'s doc comment for why this no
     * longer branches on wantsJson() — nothing ever called this
     * route expecting JSON; the dropdowns get their options as
     * ordinary Inertia props from the forms that use them.
     */
    public function index(): Response
    {
        // Paginated (was ->get(), loading every vendor/employee at
        // once — see QA audit M-4).
        $vendors = Vendor::query()
            ->orderBy('name')
            ->paginate(20, ['id', 'name', 'type']);

        return Inertia::render('App/Lookups/Index', [
            'tab'  => 'vendors',
            'rows' => $vendors,
        ]);
    }

    /**
     * Two real callers now: the sentence form's ComboSelect via plain
     * axios (no X-Inertia header, wants JSON), and the standalone
     * Suppliers page's own "Add new" form via an Inertia form post
     * (X-Inertia header present, wants the usual redirect-back).
     */
    public function store(StoreVendorRequest $request): RedirectResponse|JsonResponse
    {
        $vendor = Vendor::create($request->validated());

        if (! $request->header('X-Inertia')) {
            return response()->json($vendor, 201);
        }

        return back()->with('success', 'Vendor/employee added.');
    }

    /**
     * Fix a typo without leaving the page — the pencil icon next to
     * any Vendor/Employee combo calls this via plain axios (JSON
     * back); the standalone Suppliers page's inline editor calls it
     * as an Inertia form (redirect back instead).
     */
    public function update(UpdateVendorRequest $request, Vendor $vendor): RedirectResponse|JsonResponse
    {
        $vendor->update($request->validated());

        if (! $request->header('X-Inertia')) {
            return response()->json($vendor);
        }

        return back()->with('success', 'Vendor/employee updated.');
    }
}
