<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — UpdateOwnerRequest
//  Location: app/Http/Requests/App/UpdateOwnerRequest.php
//  Used by App\Http\Controllers\App\OwnerController::update()
// ══════════════════════════════════════════════════════════════════
class UpdateOwnerRequest extends FormRequest
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
