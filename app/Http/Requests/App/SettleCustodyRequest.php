<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SettleCustodyRequest
//  Location: app/Http/Requests/App/SettleCustodyRequest.php
//  Used by App\Http\Controllers\App\CustodyController::settle()
// ══════════════════════════════════════════════════════════════════
class SettleCustodyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'lines'                 => ['required', 'array', 'min:1'],
            'lines.*.description'   => ['nullable', 'string', 'max:255'],
            'lines.*.category_id'   => ['nullable', Rule::exists('categories', 'id')->where('company_id', $companyId)->where('kind', 'expense')],
            'lines.*.amount'        => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
