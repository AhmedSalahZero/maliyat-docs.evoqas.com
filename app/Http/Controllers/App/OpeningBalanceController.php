<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreOpeningBalanceRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Vendor;
use App\Services\OpeningBalanceService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — OpeningBalanceController
//  Location: app/Http/Controllers/App/OpeningBalanceController.php
//
//  One screen, one submission: cash/bank + any customers who owe
//  money + any suppliers still owed money + starting inventory +
//  already-owned equipment, all entered once and posted together
//  by OpeningBalanceService. See that class's doc comment for how
//  "posted" and "reset" work.
// ══════════════════════════════════════════════════════════════════
class OpeningBalanceController extends Controller
{
    public function __construct(private readonly OpeningBalanceService $openingBalances) {}

    public function index(): Response
    {
        $companyId = auth()->user()->company_id;

        // Self-heal for companies created before the default
        // equipment-category list existed — same as
        // EquipmentPurchaseController::index().
        Category::seedDefaults($companyId);

        return Inertia::render('App/Settings/OpeningBalance', [
            ...$this->openingBalances->data($companyId),
            'customers'  => Customer::query()->orderBy('name')->get(['id', 'name']),
            'vendors'    => Vendor::query()->orderBy('name')->get(['id', 'name']),
            'items'      => Item::query()->orderBy('name')->get(['id', 'name', 'uom', 'qty_per_uom', 'base_unit_name']),
            'equipmentCategories' => Category::query()->equipmentKind()->orderBy('name')->get(['id', 'name', 'name_ar']),
            'canManage'  => auth()->user()->isCompanyAdmin(),
        ]);
    }

    public function store(StoreOpeningBalanceRequest $request): RedirectResponse
    {
        $this->openingBalances->submit(auth()->user()->company_id, $request->validated());

        return redirect()
            ->route('app.opening-balance.index')
            ->with('success', 'Opening balance posted.');
    }

    public function reset(): RedirectResponse
    {
        abort_unless(auth()->user()->isCompanyAdmin(), 403);

        $this->openingBalances->reset(auth()->user()->company_id);

        return redirect()
            ->route('app.opening-balance.index')
            ->with('success', 'Opening balance cleared — you can enter it again.');
    }
}
