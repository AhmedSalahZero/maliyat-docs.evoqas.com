<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreSaleReturnRequest;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\SaleReturnService;
use Illuminate\Http\RedirectResponse;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SaleReturnController
//  Location: app/Http/Controllers/App/SaleReturnController.php
//
//  "Apply quantity return" on a sale: goods a customer sent back.
//  All the accounting lives in SaleReturnService.
//
//    store    record a return against one sale
//    destroy  take a return back out (entered by mistake) —
//             company-admin only, like every other delete, and
//             logged in the deletion log.
// ══════════════════════════════════════════════════════════════════
class SaleReturnController extends Controller
{
    public function __construct(private readonly SaleReturnService $returns) {}

    public function store(StoreSaleReturnRequest $request, Sale $sale): RedirectResponse
    {
        $this->returns->record($sale, $request->validated());

        return back()->with('success', __('common.sale_return_recorded'));
    }

    public function destroy(SaleReturn $saleReturn): RedirectResponse
    {
        $this->authorizeDelete();

        // Taking a return out puts the goods back out of stock — refused
        // (with the reason shown as a red message) if a later sale or
        // production run is already using them.
        if ($problem = $this->returns->deleteProblem($saleReturn)) {
            return back()->with('error', $problem);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($saleReturn) {
            $this->logDeletion(
                $saleReturn,
                "Sales return #{$saleReturn->id} — sale #{$saleReturn->sale_id} — ".number_format((float) $saleReturn->amount, 2),
                ['lines' => $saleReturn->lines->toArray()]
            );

            $this->returns->delete($saleReturn);
        });

        return back()->with('success', __('common.sale_return_deleted'));
    }
}
