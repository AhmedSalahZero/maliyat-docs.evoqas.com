<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StorePurchaseReturnRequest;
use App\Models\InventoryPurchase;
use App\Models\PurchaseReturn;
use App\Services\PurchaseReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — PurchaseReturnController
//  Location: app/Http/Controllers/App/PurchaseReturnController.php
//
//  "Apply quantity return" on an inventory purchase: goods sent back
//  to the supplier. All the accounting lives in PurchaseReturnService.
//
//    store    record a return against one purchase
//    destroy  take a return back out (entered by mistake) —
//             company-admin only, logged in the deletion log.
// ══════════════════════════════════════════════════════════════════
class PurchaseReturnController extends Controller
{
    public function __construct(private readonly PurchaseReturnService $returns) {}

    public function store(StorePurchaseReturnRequest $request, InventoryPurchase $inventoryPurchase): RedirectResponse
    {
        $this->returns->record($inventoryPurchase, $request->validated());

        return back()->with('success', __('common.purchase_return_recorded'));
    }

    public function destroy(PurchaseReturn $purchaseReturn): RedirectResponse
    {
        $this->authorizeDelete();

        DB::transaction(function () use ($purchaseReturn) {
            $this->logDeletion(
                $purchaseReturn,
                "Purchase return #{$purchaseReturn->id} — purchase #{$purchaseReturn->inventory_purchase_id} — ".number_format((float) $purchaseReturn->amount, 2),
                ['lines' => $purchaseReturn->lines->toArray()]
            );

            $this->returns->delete($purchaseReturn);
        });

        return back()->with('success', __('common.purchase_return_deleted'));
    }
}
