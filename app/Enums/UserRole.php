<?php

namespace App\Enums;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — User Roles
//  super_admin   → you. Creates companies and their first admin.
//  company_admin → runs one company, can create employee accounts.
//  employee      → created by an admin, works within one company.
// ══════════════════════════════════════════════════════════════════

enum UserRole: string
{
    case SuperAdmin   = 'super_admin';
    case CompanyAdmin = 'company_admin';
    case Employee     = 'employee';

    // Returns a human-readable label
    public function label(): string
    {
        return match($this) {
            UserRole::SuperAdmin   => 'Super Admin',
            UserRole::CompanyAdmin => 'Company Admin',
            UserRole::Employee     => 'Employee',
        };
    }

    // Returns all values as a plain array — useful for validation rules
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
