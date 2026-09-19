<?php

namespace App\Http\Requests\App;

use App\Models\OwnerTransaction;
use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — UpdateOwnerTransactionRequest
//  Location: app/Http/Requests/App/UpdateOwnerTransactionRequest.php
//  Used by App\Http\Controllers\App\OwnerTransactionController::update()
//  Same rules as StoreOwnerTransactionRequest — see its doc comment.
// ══════════════════════════════════════════════════════════════════
class UpdateOwnerTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'owner_id'  => ['required', Rule::exists('owners', 'id')->where('company_id', $companyId)],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'category'  => ['required', Rule::in([...OwnerTransaction::inCategories(), ...OwnerTransaction::outCategories()])],
            'amount'    => ['required', ...FinancialRules::amount()],
            'method'    => ['nullable', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet'])],
            'payment_channel_id' => ['nullable', Rule::exists('payment_channels', 'id')->where('company_id', $companyId)],
            'date'      => ['required', ...FinancialRules::date()],
            'note'      => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $direction = $this->input('direction');
            $category  = $this->input('category');

            if (! $direction || ! $category) {
                return;
            }

            $validCategories = $direction === 'in' ? OwnerTransaction::inCategories() : OwnerTransaction::outCategories();

            if (! in_array($category, $validCategories, true)) {
                $validator->errors()->add('category', "\"{$category}\" is not valid for direction \"{$direction}\".");
            }
        });
    }
}
