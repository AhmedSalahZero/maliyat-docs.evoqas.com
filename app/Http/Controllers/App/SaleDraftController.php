<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\SaveSaleDraftRequest;
use App\Models\SaleDraft;
use Illuminate\Http\RedirectResponse;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SaleDraftController
//
//  Save / update / delete an unfinished sale. None of this touches
//  the accounts: no journal entry, no stock movement, no customer
//  balance, nothing in any report — see the create_sale_drafts_table
//  migration. Drafts are shared by the whole company team, and any
//  team member may delete one (unlike a real sale, whose delete is
//  admin-only, a draft carries no money history to lose).
//
//  Recording a draft is NOT done here: the Sales form sends it to
//  SaleController::store() like any other sale, with draft_id, and
//  that removes the draft once the sale is saved.
// ══════════════════════════════════════════════════════════════════
class SaleDraftController extends Controller
{
    public function store(SaveSaleDraftRequest $request): RedirectResponse
    {
        SaleDraft::create([
            'data'       => $request->draftData(),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', __('common.sale_draft_saved'));
    }

    public function update(SaveSaleDraftRequest $request, SaleDraft $saleDraft): RedirectResponse
    {
        $saleDraft->update([
            'data'       => $request->draftData(),
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', __('common.sale_draft_saved'));
    }

    public function destroy(SaleDraft $saleDraft): RedirectResponse
    {
        $saleDraft->delete();

        return back()->with('success', __('common.sale_draft_deleted'));
    }
}
