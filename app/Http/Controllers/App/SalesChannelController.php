<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreSalesChannelRequest;
use App\Http\Requests\App\UpdateSalesChannelRequest;
use App\Models\SalesChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SalesChannelController
//  Location: app/Http/Controllers/App/SalesChannelController.php
//
//  Where a sale came from — Direct, Delivery, an online platform, a
//  WhatsApp group, or a channel the company adds itself. Shown on
//  the Sales form; "Direct Sales" is what a new sale defaults to
//  when nothing else is picked — see SalesChannel::defaultChannel().
// ══════════════════════════════════════════════════════════════════
class SalesChannelController extends Controller
{
    public function index(): JsonResponse
    {
        // Feeds a dropdown (ComboSelect) on the Sales form, which
        // needs the full list at once to filter client-side — same
        // reasoning as PaymentChannelController::index()'s capped
        // limit rather than real pagination (QA audit M-4).
        return response()->json(
            SalesChannel::query()->orderBy('id')->limit(500)->get(['id', 'name', 'name_ar'])
        );
    }

    public function store(StoreSalesChannelRequest $request): RedirectResponse|JsonResponse
    {
        $channel = SalesChannel::create($request->validated());

        if ($request->wantsJson()) {
            return response()->json($channel, 201);
        }

        return back()->with('success', 'Sales channel added.');
    }

    public function update(UpdateSalesChannelRequest $request, SalesChannel $salesChannel): JsonResponse
    {
        $salesChannel->update($request->validated());

        return response()->json($salesChannel);
    }
}
