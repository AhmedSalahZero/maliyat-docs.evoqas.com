<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — DashboardController (super_admin side)
//  Location: app/Http/Controllers/Admin/DashboardController.php
// ══════════════════════════════════════════════════════════════════
class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'companies_count'        => Company::query()->count(),
            'active_companies_count' => Company::query()->where('is_active', true)->count(),
            'users_count'            => User::query()->whereNotNull('company_id')->count(),
        ]);
    }
}
