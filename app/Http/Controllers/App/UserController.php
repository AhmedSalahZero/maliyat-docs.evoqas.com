<?php

namespace App\Http\Controllers\App;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreEmployeeRequest;
use App\Http\Requests\App\UpdateEmployeeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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

    /**
     * Correct an employee's name or email, and optionally reset their
     * password. Previously the only thing an admin could do to an
     * existing employee was switch them off — a misspelt email meant
     * deleting the account and starting again.
     *
     * The role is deliberately not editable: promoting an employee to
     * company_admin is a permission change, not a correction, and
     * belongs behind its own explicit action.
     */
    public function update(UpdateEmployeeRequest $request, User $user): RedirectResponse
    {
        // UpdateEmployeeRequest::authorize() already confirmed the
        // caller is a company_admin over someone in their own company.
        // Repeated here so the rule survives anyone later calling this
        // method from somewhere that doesn't use that request.
        abort_unless(
            auth()->user()->isCompanyAdmin() && $user->company_id === auth()->user()->company_id,
            403
        );

        $data = $request->validated();

        // Both writes land on the same row, so the practical risk of
        // a half-applied edit is small — but every other multi-write
        // path in this app is wrapped, and an unwrapped one is the
        // kind of exception that reads like an oversight to whoever
        // touches this next. Wrapped so the rule has no exceptions.
        DB::transaction(function () use ($user, $data) {
            $user->update([
                'name'  => $data['name'],
                'email' => $data['email'],
            ]);

            // Empty means "leave the password alone" — see the request.
            if (! empty($data['password'])) {
                $user->update(['password' => $data['password']]);
            }
        });

        return back()->with('success', 'Employee updated.');
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
