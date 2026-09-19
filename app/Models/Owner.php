<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Owner
//  Who a company's capital belongs to. Deliberately separate from
//  Customer and Vendor — see the owners table migration's doc
//  comment for why. Scoped to one company each via BelongsToCompany,
//  same as Customer/Vendor.
// ══════════════════════════════════════════════════════════════════
class Owner extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = ['company_id', 'name'];

    public function transactions(): HasMany
    {
        return $this->hasMany(OwnerTransaction::class);
    }
}
