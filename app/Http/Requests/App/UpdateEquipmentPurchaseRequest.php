<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEquipmentPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'vendor_id'   => ['required', Rule::exists('vendors', 'id')->where('company_id', $companyId)],
            'category_id' => ['required', Rule::exists('categories', 'id')->where('company_id', $companyId)->where('kind', 'equipment')],
            'name'        => ['required', 'string', 'max:150'],
            'qty'         => ['nullable', 'numeric', 'min:0.01'],
            'unit_price'  => ['required', 'numeric', 'min:0'],
            'date'        => ['required', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['qty' => $this->qty ?: 1]);
    }
}
