<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreCustomerRequest;
use App\Http\Requests\App\UpdateCustomerRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — CustomerController
//  Location: app/Http/Controllers/App/CustomerController.php
//
//  Backs the "Sell to {customer}" dropdown on the Sales entry form
//  and the standalone Customers list. Company scoping is automatic
//  (Customer uses BelongsToCompany).
// ══════════════════════════════════════════════════════════════════
class CustomerController extends Controller
{
    /**
     * Company's customers, alphabetical — used to populate the
     * "Sell to" dropdown and the plain Customers list page.
     */
    public function index(): JsonResponse
    {
        return response()->json(
            Customer::query()->orderBy('name')->get(['id', 'name', 'phone'])
        );
    }

    /**
     * Inline "+ add new…" from the sentence form.
     */
    public function store(StoreCustomerRequest $request): RedirectResponse|JsonResponse
    {
        $customer = Customer::create($request->validated());

        if ($request->wantsJson()) {
            return response()->json($customer, 201);
        }

        return back()->with('success', 'Customer added.');
    }

    /**
     * Fix a typo without leaving the page — the pencil icon next to
     * the "Sell to" combo on Sales/Index.vue. JSON-only: there's no
     * traditional form that posts here, only the rename popup's
     * axios call.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());

        return response()->json($customer);
    }
}
