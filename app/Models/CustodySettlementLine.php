<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustodySettlementLine extends Model
{
    protected $fillable = ['custody_id', 'description', 'category_id', 'amount'];

    public function custody(): BelongsTo
    {
        return $this->belongsTo(Custody::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
