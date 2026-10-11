<?php

namespace App\Http\Requests\Concerns;

use App\Support\NameNormalizer;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — NormalizesName
//  Location: app/Http/Requests/Concerns/NormalizesName.php
//
//  Cleans the `name` field BEFORE it is validated and saved, so a
//  name typed with spaces at the start or end (or doubled spaces
//  inside) is stored without them. See App\Support\NameNormalizer.
// ══════════════════════════════════════════════════════════════════
trait NormalizesName
{
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => NameNormalizer::clean($this->input('name'))]);
        }
    }
}
