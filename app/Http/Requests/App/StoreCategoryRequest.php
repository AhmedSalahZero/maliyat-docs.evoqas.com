<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreCategoryRequest
//  Location: app/Http/Requests/App/StoreCategoryRequest.php
//  Used by App\Http\Controllers\App\CategoryController::store()
// ══════════════════════════════════════════════════════════════════
class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(['expense', 'equipment'])],
        ];
    }
}
