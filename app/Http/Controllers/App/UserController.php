<?php

namespace App\Http\Controllers\App;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreEmployeeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — UserController (company side)
//  Location: app/Http/Controllers/App/UserController.php
//
//  A company_admin's own team management: create employee accounts
//  and toggle them active/inactive. Scoped to the admin's own
//  company_id — see StoreEmployeeRequest::authorize() and the
//  explicit company_id check in toggleActive().
// ══════════════════════════════════════════════════════════════════
class UserController extends Controller
{
    public function index(): Response
    {
        $employees = User::query()
            ->where('company_id', auth()->user()->company_id)
            ->where('id', '!=', auth()->id())
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'is_active', 'last_login_at']);

        return Inertia::render('App/Team/Index', ['employees' => $employees]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        User::create([
            'name'       => $request->validated('name'),
            'email'      => $request->validated('email'),
            'password'   => $request->validated('password'),
            'role'       => UserRole::Employee->value,
            'company_id' => auth()->user()->company_id,
            'created_by' => auth()->id(),
            'language'   => auth()->user()->language,
        ]);

        return back()->with('success', 'Employee account created.');
    }

    public function toggleActive(User $user): RedirectResponse
    {
        abort_unless(
            auth()->user()->isCompanyAdmin() && $user->company_id === auth()->user()->company_id,
            403
        );

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', $user->is_active ? 'Employee reactivated.' : 'Employee deactivated.');
    }
}
