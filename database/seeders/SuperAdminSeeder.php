<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PasswordRules;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SuperAdminSeeder
//  Location: database/seeders/SuperAdminSeeder.php
//
//  Creates the one platform super_admin account (no company_id —
//  a super_admin isn't scoped to any single company; see
//  BelongsToCompany and EnsureAdmin).
//
//  QA audit fix (Sep 2026) — this seeder used to carry a working
//  password as a literal:
//
//      'password' => 'ChangeMe@2026!'
//      $this->command->info('... admin@maliyatdocs.app / ChangeMe@2026!');
//
//  Three separate problems, all of them fixed here:
//
//  1. The credential was in the repository, in plain text, next to
//     the email address it opened — for the one role that reads
//     every company's books on the platform. It now comes from
//     DEFAULT_PASSWORD in .env, which is gitignored.
//
//  2. It was `updateOrCreate`, so re-running `php artisan db:seed`
//     silently RESET the password back to the published one, even
//     after the owner had changed it. The warning comment in the
//     file did not prevent that and could not. The password is now
//     written only when the account is being created; a re-run
//     leaves an existing password exactly as it is, and says so.
//
//  3. The confirmation line printed the password to the console,
//     where it lands in deploy logs and CI output. Nothing here
//     prints a password any more.
//
//  Absent or weak configuration fails loudly rather than seeding a
//  super_admin nobody can sign in as — or worse, one with a
//  guessable password nobody noticed.
// ══════════════════════════════════════════════════════════════════
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email    = config('app.super_admin_email');
        $password = config('app.default_password');

        $this->guardConfiguration($email, $password);

        // firstOrNew, not updateOrCreate — see point 2 in the header.
        // Everything except the password is safe to refresh on a
        // re-run; the password is the one field that must survive it.
        $admin = User::firstOrNew(['email' => $email]);

        $isNew = ! $admin->exists;

        $admin->fill([
            'name'       => config('app.super_admin_name', 'Maliyat Docs Admin'),
            'role'       => UserRole::SuperAdmin->value,
            'company_id' => null,
            'language'   => 'en',
            'is_active'  => true,
        ]);

        // Assigned rather than filled: email_verified_at is not in
        // User::$fillable, so fill() drops it without saying so. The
        // old updateOrCreate passed it the same way and it never
        // landed — which left the seeded super_admin unverified and
        // routed into the verification flow it has no way through.
        $admin->email_verified_at = $admin->email_verified_at ?? now();

        if ($isNew) {
            $admin->password = $password;
        }

        $admin->save();

        if ($isNew) {
            $this->command?->info("✅ Super admin created: {$email}");
            $this->command?->warn('   Password taken from DEFAULT_PASSWORD. Change it after first login.');

            return;
        }

        $this->command?->info("✅ Super admin already exists: {$email}");
        $this->command?->line('   Its password was left untouched. To change it, sign in and change it there.');
    }

    /**
     * Refuse to seed rather than produce an account that cannot be
     * signed in as, or one whose password is weaker than what this
     * app demands of an ordinary employee.
     *
     * Loud on purpose. The failure this replaces was silent: with no
     * DEFAULT_PASSWORD set, the old code passed null straight into a
     * hashed cast and carried on, leaving a super_admin row behind
     * with nobody able to say what its password was.
     */
    private function guardConfiguration(?string $email, ?string $password): void
    {
        if (blank($password)) {
            throw new RuntimeException(
                'DEFAULT_PASSWORD is not set. Add it to .env before seeding — '
                .'this seeder creates a super_admin that can read every company on '
                .'the platform, and it will not invent a password for it.'
            );
        }

        if (blank($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                'SUPER_ADMIN_EMAIL is missing or is not a valid email address.'
            );
        }

        // The same bar every ordinary user has to clear. A platform
        // super_admin holding a password the app would reject from an
        // employee is the wrong way round.
        $validator = Validator::make(
            ['password' => $password],
            ['password' => PasswordRules::defaults()]
        );

        if ($validator->fails()) {
            throw new RuntimeException(
                'DEFAULT_PASSWORD does not meet this application\'s own password rules: '
                .implode(' ', $validator->errors()->get('password'))
            );
        }
    }
}
