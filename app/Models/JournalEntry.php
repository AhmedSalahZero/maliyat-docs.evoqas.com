<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JournalEntry extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id', 'date', 'memo', 'source_type', 'source_id', 'reverses_id', 'created_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversedBy(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_id');
    }

    public function isReversed(): bool
    {
        return $this->reversedBy()->exists();
    }

    public function totalDebits(): float
    {
        return (float) $this->lines()->sum('debit');
    }

    public function totalCredits(): float
    {
        return (float) $this->lines()->sum('credit');
    }

    public function isBalanced(): bool
    {
        return abs($this->totalDebits() - $this->totalCredits()) <= 0.004;
    }
}
