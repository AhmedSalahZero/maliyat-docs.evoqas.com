<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StorePaymentChannelRequest;
use App\Http\Requests\App\UpdatePaymentChannelRequest;
use App\Models\PaymentChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — PaymentChannelController
//  Location: app/Http/Controllers/App/PaymentChannelController.php
//
//  "Which bank / which wallet operator" — CIB, NBE, Vodafone Cash,
//  Orange Money, etc. Shown on every payment-method field once
//  something other than Cash is picked; optional everywhere.
// ══════════════════════════════════════════════════════════════════
class PaymentChannelController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            PaymentChannel::query()->orderBy('name')->get(['id', 'name'])
        );
    }

    public function store(StorePaymentChannelRequest $request): RedirectResponse|JsonResponse
    {
        $channel = PaymentChannel::create($request->validated());

        if ($request->wantsJson()) {
            return response()->json($channel, 201);
        }

        return back()->with('success', 'Bank/wallet added.');
    }

    public function update(UpdatePaymentChannelRequest $request, PaymentChannel $paymentChannel): JsonResponse
    {
        $paymentChannel->update($request->validated());

        return response()->json($paymentChannel);
    }
}
