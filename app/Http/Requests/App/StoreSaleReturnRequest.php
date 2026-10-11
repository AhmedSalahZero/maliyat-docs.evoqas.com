<?php

namespace App\Http\Requests\App;

use App\Models\Sale;
use App\Models\SaleReturn;
use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreSaleReturnRequest
//  Location: app/Http/Requests/App/StoreSaleReturnRequest.php
//  Used by App\Http\Controllers\App\SaleReturnController::store()
//
//  The "Apply quantity return" pop-up on a sale. The pop-up lists
//  every line of the sale; the user types a quantity on the ones
//  that came back and leaves the rest blank, so blank/zero lines
//  are dropped here before anything is checked.
//
//  The business rules that need the database (not more than was
//  sold, nothing returned twice) are enforced by SaleReturnService,
//  inside the transaction, where they cannot race.
// ══════════════════════════════════════════════════════════════════
class StoreSaleReturnRequest extends FormRequest
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
        /** @var Sale $sale */
        $sale = $this->route('sale');

        return [
            'date'  => [
                'required', ...FinancialRules::date(),
                // A return cannot come before the sale it belongs to.
                'after_or_equal:'.$sale->date->toDateString(),
            ],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_line_id' => ['required', Rule::exists('sale_lines', 'id')->where('sale_id', $sale->id)],
            'lines.*.qty'          => ['required', ...FinancialRules::qty()],
            'refund_method'      => ['nullable', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet', SaleReturn::CREDIT])],
            'payment_channel_id' => ['nullable', Rule::exists('payment_channels', 'id')->where('company_id', $this->user()->company_id)],
            'note'               => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var Sale $sale */
            $sale = $this->route('sale');

            if ($sale->is_opening_balance) {
                $validator->errors()->add('lines', __('errors.return_opening_balance'));
            }

            // The same line listed twice would slip past the
            // remaining-quantity check one row at a time.
            $ids = collect($this->input('lines', []))->pluck('sale_line_id');
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
