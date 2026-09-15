<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreCustomerRequest;
use App\Http\Requests\App\UpdateCustomerRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Inertia\Response;
use Inertia\Inertia;
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
     * Company's customers, alphabetical — the standalone Customers
     * page (linked from the app menu).
     *
     * This used to also branch on $request->wantsJson() to serve the
     * "Sell to" dropdown as JSON from this same route — but nothing
     * actually calls this route that way (the dropdowns get their
     * options as ordinary Inertia props from Sales/Expense/etc.'s own
     * controllers). That branch was actively harmful: Inertia's own
     * <Link> visits are themselves XHR requests whose default Accept
     * header satisfies wantsJson(), so clicking "Customers" in the
     * menu was matching the JSON branch and dropping a raw JSON
     * response on an Inertia visit instead of rendering the page.
     */
    public function index(): Response
    {
        $customers = Customer::query()->orderBy('name')->get(['id', 'name', 'phone']);

        return Inertia::render('App/Lookups/Index', [
            'tab'  => 'customers',
            'rows' => $customers,
        ]);
    }

    /**
     * Inline "+ add new…" — called two different ways:
     *   - the sentence form's ComboSelect, via a plain axios POST
     *     (no X-Inertia header) — wants JSON back.
     *   - the standalone Customers page's own "Add new" form, via an
     *     Inertia form post (X-Inertia header present) — wants the
     *     usual redirect-back so the page re-renders with the new row.
     */
    public function store(StoreCustomerRequest $request): RedirectResponse|JsonResponse
    {
        $customer = Customer::create($request->validated());

        if (! $request->header('X-Inertia')) {
            return response()->json($customer, 201);
        }

        return back()->with('success', 'Customer added.');
    }

    /**
     * Fix a typo without leaving the page — the pencil icon next to
     * the "Sell to" combo on Sales/Index.vue calls this via plain
     * axios (JSON back); the standalone Customers page's inline
     * editor calls it as an Inertia form (redirect back instead).
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse|JsonResponse
    {
        $customer->update($request->validated());

        if (! $request->header('X-Inertia')) {
            return response()->json($customer);
        }

        return back()->with('success', 'Customer updated.');
    }
}
