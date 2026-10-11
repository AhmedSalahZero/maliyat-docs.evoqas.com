<?php

namespace App\Http\Requests\App;

use App\Models\InventoryPurchase;
use App\Models\PurchaseReturn;
use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StorePurchaseReturnRequest
//  Location: app/Http/Requests/App/StorePurchaseReturnRequest.php
//  Used by App\Http\Controllers\App\PurchaseReturnController::store()
//
//  The "Apply quantity return" pop-up on an inventory purchase. The
//  pop-up lists every line of the purchase; the user types a quantity
//  on the ones sent back and leaves the rest blank, so blank/zero
//  lines are dropped here before anything is checked.
//
//  The business rules that need the database (not more than was
//  bought, nothing returned twice, goods not already sold or used)
//  are enforced by PurchaseReturnService, inside the transaction.
// ══════════════════════════════════════════════════════════════════
class StorePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    protected function prepareForValidation(): void
    {
        $lines = collect($this->input('lines', []))
            ->filter(fn ($line) => is_array($line) && (float) ($line['qty'] ?? 0) > 0)
            ->values()
            ->all();

        $this->merge(['lines' => $lines]);
    }

    public function rules(): array
    {
        /** @var InventoryPurchase $purchase */
        $purchase = $this->route('inventoryPurchase');

        return [
            'date'  => [
                'required', ...FinancialRules::date(),
                // A return cannot come before the purchase it belongs to.
                'after_or_equal:'.$purchase->date->toDateString(),
            ],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_line_id' => ['required', Rule::exists('inventory_purchase_lines', 'id')->where('inventory_purchase_id', $purchase->id)],
            'lines.*.qty'              => ['required', ...FinancialRules::qty()],
            'refund_method'      => ['nullable', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet', PurchaseReturn::CREDIT])],
            'payment_channel_id' => ['nullable', Rule::exists('payment_channels', 'id')->where('company_id', $this->user()->company_id)],
            'note'               => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var InventoryPurchase $purchase */
            $purchase = $this->route('inventoryPurchase');

            if ($purchase->is_opening_balance) {
                $validator->errors()->add('lines', __('errors.return_opening_balance'));
            }

            // The same line listed twice would slip past the
            // remaining-quantity check one row at a time.
            $ids = collect($this->input('lines', []))->pluck('purchase_line_id');
            if ($ids->count() !== $ids->unique()->count()) {
                $validator->errors()->add('lines', __('errors.return_nothing'));
            }
        });
    }

    public function messages(): array
    {
        return [
            'lines.required' => __('errors.return_nothing'),
            'lines.min'      => __('errors.return_nothing'),
            'date.after_or_equal' => __('errors.return_before_original'),
        ];
    }
}
