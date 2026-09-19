<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreOwnerRequest;
use App\Http\Requests\App\UpdateOwnerRequest;
use App\Models\Owner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — OwnerController
//  Location: app/Http/Controllers/App/OwnerController.php
//
//  Backs the "Receive/Pay money {owner}" dropdown on the Owner
//  Transactions form and the standalone Owners list (a tab on
//  App/Lookups/Index — see that page's TABS array). Same shape as
//  CustomerController — see that class's doc comment for why the
//  X-Inertia header, not wantsJson(), decides JSON vs redirect-back.
// ══════════════════════════════════════════════════════════════════
class OwnerController extends Controller
{
    public function index(): Response
    {
        $owners = Owner::query()
            ->orderBy('name')
            ->paginate(20, ['id', 'name']);

        return Inertia::render('App/Lookups/Index', [
            'tab'  => 'owners',
            'rows' => $owners,
        ]);
    }

    /**
     * Inline "+ add new…" from the Owner Transactions form's
     * ComboSelect (plain axios, wants JSON back) or the standalone
     * Owners page's own "Add new" form (Inertia form post, wants the
     * usual redirect-back).
     */
    public function store(StoreOwnerRequest $request): RedirectResponse|JsonResponse
    {
        $owner = Owner::create($request->validated());

        if (! $request->header('X-Inertia')) {
            return response()->json($owner, 201);
        }

        return back()->with('success', 'Owner added.');
    }

    public function update(UpdateOwnerRequest $request, Owner $owner): RedirectResponse|JsonResponse
    {
        $owner->update($request->validated());

        if (! $request->header('X-Inertia')) {
            return response()->json($owner);
        }

        return back()->with('success', 'Owner updated.');
    }
}
