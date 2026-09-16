<?php

namespace App\Enums;

enum CaseDifficulty: string
{
    case Beginner     = 'beginner';      // Final year students — hints provided
    case Intermediate = 'intermediate';  // 1–2 years experience — less guidance
    case Advanced     = 'advanced';      // 3–5 years — ambiguous data, multiple answers

    public function label(): string
    {
        return match($this) {
            CaseDifficulty::Beginner     => 'Beginner',
            CaseDifficulty::Intermediate => 'Intermediate',
            CaseDifficulty::Advanced     => 'Advanced',
        };
    }

    public function labelAr(): string
    {
        return match($this) {
            CaseDifficulty::Beginner     => 'مبتدئ',
            CaseDifficulty::Intermediate => 'متوسط',
            CaseDifficulty::Advanced     => 'متقدم',
        };
    }

    // Badge color for the UI
    public function color(): string
    {
        return match($this) {
            CaseDifficulty::Beginner     => 'green',
            CaseDifficulty::Intermediate => 'amber',
            CaseDifficulty::Advanced     => 'red',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
