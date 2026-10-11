<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\NormalizesName;
use App\Models\Vendor;
use App\Rules\UniqueNameInCompany;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVendorRequest extends FormRequest
{
    use NormalizesName;

    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:150',
                new UniqueNameInCompany(Vendor::class, $this->user()?->company_id, 'errors.duplicate_vendor', $this->route('vendor')?->id),
            ],
        ];
    }
}
