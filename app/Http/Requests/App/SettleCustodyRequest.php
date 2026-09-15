<?php

namespace App\Http\Requests\App;

use App\Support\FinancialRules;
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
            // When the custody was actually squared up. Required, and
            // never earlier than the day it was handed out — money
            // cannot come back before it went out. See Custody::settle()
            // for what went wrong while this field did not exist.
            'settlement_date'       => ['required', ...FinancialRules::date(), 'after_or_equal:'.$this->custodyGivenAt()],
            'lines'                 => ['required', 'array', 'min:1'],
            'lines.*.description'   => ['nullable', 'string', 'max:255'],
            'lines.*.category_id'   => ['nullable', Rule::exists('categories', 'id')->where('company_id', $companyId)->where('kind', 'expense')],
            'lines.*.amount'        => ['required', ...FinancialRules::amount()],
        ];
    }

    /**
     * The hand-out date of the custody this request is settling.
     * Resolved from the bound route model, so the company scope has
     * already vouched for it.
     */
    private function custodyGivenAt(): string
    {
        return $this->route('custody')?->given_at?->toDateString()
            ?? FinancialRules::MIN_DATE;
    }
}
