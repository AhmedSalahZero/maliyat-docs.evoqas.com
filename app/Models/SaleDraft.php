<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// An unfinished sale — see the create_sale_drafts_table migration
// for why this is its own table and has no accounting effect.
class SaleDraft extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'created_by', 'updated_by', 'data'];

    protected $casts = [
        'data' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
