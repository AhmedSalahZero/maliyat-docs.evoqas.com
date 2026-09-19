<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreOwnerRequest
//  Location: app/Http/Requests/App/StoreOwnerRequest.php
//  Used by App\Http\Controllers\App\OwnerController::store()
//  Company scoping happens automatically (BelongsToCompany trait
//  fills company_id from the logged-in user on create).
// ══════════════════════════════════════════════════════════════════
class StoreOwnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
        ];
    }
}
