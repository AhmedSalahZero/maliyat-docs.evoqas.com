<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'vendor_id' => ['required', Rule::exists('vendors', 'id')->where('company_id', $companyId)],
            'date'      => ['required', 'date'],

            'lines'                  => ['required', 'array', 'min:1'],
            'lines.*.item_id'        => ['required', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.qty'            => ['required', 'numeric', 'min:0.01'],
            'lines.*.uom'            => ['nullable', 'string', 'max:40'],
            'lines.*.qty_per_uom'    => ['nullable', 'numeric', 'min:0.01'],
            'lines.*.base_unit_name' => ['nullable', 'string', 'max:40'],
            'lines.*.unit_price'     => ['required', 'numeric', 'min:0'],

            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
