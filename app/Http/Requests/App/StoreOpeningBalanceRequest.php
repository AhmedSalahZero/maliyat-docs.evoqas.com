<?php

namespace App\Http\Requests\App;

use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreOpeningBalanceRequest
//
//  Validates the one-time opening-balance submission: starting cash
//  and bank, customers who already owe money, suppliers still owed,
//  stock on hand, and equipment already owned.
//
//  Shaped to match exactly what OpeningBalanceService::submit()
//  consumes — every key it reads is validated here, and nothing it
//  doesn't read is accepted.
//
//  Note on the empty rows: the service already skips any row whose
//  amount/qty is zero, so the arrays themselves are nullable and a
//  partially-filled form is legitimate. What the rules refuse is a
//  row that is present but malformed — an amount against no
//  customer, or a customer belonging to somebody else's company.
// ══════════════════════════════════════════════════════════════════
class StoreOpeningBalanceRequest extends FormRequest
{
    /**
     * Company admins only.
     *
     * The screen already hides the form from employees (its
     * `canManage` prop) and OpeningBalanceController::reset() checks
     * the same thing — but store() had no check of its own, so the
     * rule lived only in the UI. Anyone able to POST could have
     * posted a company's opening balance.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id
            && $this->user()->isCompanyAdmin();
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'opening_date' => ['required', ...FinancialRules::date()],

            // ── Money already in hand ─────────────────────────────
            'cash_amount' => ['nullable', ...FinancialRules::amount(0)],
            'bank_amount' => ['nullable', ...FinancialRules::amount(0)],

            // ── Customers who already owe the company ─────────────
            'customers'               => ['nullable', 'array'],
            'customers.*.customer_id' => [
                'required',
                Rule::exists('customers', 'id')->where('company_id', $companyId),
            ],
            'customers.*.amount'      => ['required', ...FinancialRules::amount(0)],

            // ── Suppliers the company already owes ────────────────
            'suppliers'             => ['nullable', 'array'],
            'suppliers.*.vendor_id' => [
                'required',
                Rule::exists('vendors', 'id')->where('company_id', $companyId),
            ],
            'suppliers.*.amount'    => ['required', ...FinancialRules::amount(0)],

            // ── Stock on hand ─────────────────────────────────────
            // The service reads the unit setup off the Item itself, so
            // only the identity, quantity and cost come from the form.
            'inventory'              => ['nullable', 'array'],
            'inventory.*.item_id'    => [
                'required',
                Rule::exists('items', 'id')->where('company_id', $companyId),
            ],
            'inventory.*.qty'        => ['required', ...FinancialRules::qty(0)],
            'inventory.*.unit_price' => ['required', ...FinancialRules::amount(0)],

            // ── Equipment already owned ───────────────────────────
            'equipment'                => ['nullable', 'array'],
            'equipment.*.name'         => ['required', 'string', 'max:150'],
            'equipment.*.category_id'  => [
                'required',
                Rule::exists('categories', 'id')->where('company_id', $companyId),
            ],
            'equipment.*.amount'       => ['required', ...FinancialRules::amount(0)],
            // Optional: an asset bought before the opening date keeps
            // its real purchase date so depreciation starts from the
            // right month. The service falls back to opening_date.
            'equipment.*.date'         => ['nullable', ...FinancialRules::date()],
        ];
    }

    /**
     * Drop rows the user never filled in.
     *
     * The form ships each section with one blank row already on
     * screen (see OpeningBalance.vue's useForm), so a company with no
     * outstanding customers still POSTs `customers: [{customer_id:
     * null, amount: null}]`. Validating that as-is would reject the
     * whole submission over a row the user deliberately left alone —
     * and the service skips empty rows anyway.
     *
     * A row that is PARTLY filled is left in place on purpose: an
     * amount typed against no customer is a mistake worth reporting,
     * not noise worth discarding.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'customers' => $this->pruneBlank($this->input('customers'), ['customer_id', 'amount']),
            'suppliers' => $this->pruneBlank($this->input('suppliers'), ['vendor_id', 'amount']),
            'inventory' => $this->pruneBlank($this->input('inventory'), ['item_id', 'qty', 'unit_price']),
            'equipment' => $this->pruneBlank($this->input('equipment'), ['name', 'category_id', 'amount']),
        ]);
    }

    /**
     * @param  list<string>  $keys  the fields that make a row "filled"
     */
    private function pruneBlank(mixed $rows, array $keys): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, function ($row) use ($keys) {
            if (! is_array($row)) {
                return false;
            }

            foreach ($keys as $key) {
                $value = $row[$key] ?? null;

                // 0 counts as blank here: a zero amount is the same
                // "nothing to record" the service treats it as.
                if ($value !== null && $value !== '' && $value !== 0 && $value !== '0') {
                    return true;
                }
            }

            return false;
        }));
    }

    public function messages(): array
    {
        return [
            'customers.*.customer_id.required' => __('validation.required', ['attribute' => 'customer']),
            'suppliers.*.vendor_id.required'   => __('validation.required', ['attribute' => 'supplier']),
            'inventory.*.item_id.required'     => __('validation.required', ['attribute' => 'item']),
            'equipment.*.category_id.required' => __('validation.required', ['attribute' => 'category']),
        ];
    }
}
