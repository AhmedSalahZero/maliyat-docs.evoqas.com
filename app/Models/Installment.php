<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Installment extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id', 'payable_type', 'payable_id',
        'sequence', 'due_date', 'amount',
    ];

    // decimal(12,2) in the database (2026_09_16_000001 migration).
    protected $casts = [
        'due_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }
}
