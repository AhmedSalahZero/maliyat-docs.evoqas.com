<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Models\Category;
use App\Models\Company;
use App\Models\User;
use App\Services\JournalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — CompanyController (super_admin side)
//  Location: app/Http/Controllers/Admin/CompanyController.php
//
//  A super_admin's view of every company on the platform. Most
//  companies arrive via public self-signup (RegisterService) — this
//  controller is the manual alternative (e.g. onboarding a company
//  over the phone) plus ongoing management (deactivate a company
//  that stopped paying, etc.).
// ══════════════════════════════════════════════════════════════════
class CompanyController extends Controller
{
    public function __construct(
        private readonly JournalService $journal,
    ) {}

    public function index(): Response
    {
        $companies = Company::query()
            ->withCount(['users'])
            ->orderByDesc('id')
            ->paginate(30)
            ->through(fn (Company $company) => [
                'id'         => $company->id,
                'name'       => $company->name,
                'name_ar'    => $company->name_ar,
                'currency'   => $company->currency,
                'is_active'  => $company->is_active,
                'users_count'=> $company->users_count,
                'created_at' => $company->created_at->toDateString(),
            ]);

        return Inertia::render('Admin/Companies/Index', ['companies' => $companies]);
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $company = DB::transaction(function () use ($data) {
            $company = Company::create([
                'name'     => $data['name'],
                'name_ar'  => $data['name_ar'] ?? null,
                'currency' => $data['currency'] ?? 'EGP',
                'business_types' => $data['business_types'] ?? ['trading'],
            ]);

            $admin = User::create([
                'name'       => $data['admin_name'],
                'email'      => $data['admin_email'],
                'password'   => $data['admin_password'],
                'role'       => UserRole::CompanyAdmin->value,
                'company_id' => $company->id,
                'created_by' => auth()->id(),
            ]);

            $company->forceFill(['created_by' => $admin->id])->save();

            return $company;
        });

        $this->journal->seedChartOfAccounts($company);
        Category::seedDefaults($company->id);

        return back()->with('success', 'Company created.');
    }

    public function toggleActive(Company $company): RedirectResponse
    {
        $company->update(['is_active' => ! $company->is_active]);

        return back()->with('success', $company->is_active ? 'Company reactivated.' : 'Company deactivated.');
    }
}
