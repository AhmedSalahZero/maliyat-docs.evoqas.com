<?php

namespace Tests\Feature\Auth;

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\VerifyEmailCodeNotification;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Email verification by one-time code.
//
//  This replaces the Breeze signed-link tests the project was copied
//  with — this app verifies with a numeric code, not a magic link.
//
//  It is also the regression net for a fatal gap: the service and the
//  User relation both referenced App\Models\EmailVerificationCode and
//  an email_verification_codes table, and neither existed. Since
//  verification is on by default and runs at sign-up, registering
//  raised a fatal error before anyone could finish creating an
//  account.
// ══════════════════════════════════════════════════════════════════
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_code_store_exists(): void
    {
        $this->assertTrue(
            \Schema::hasTable('email_verification_codes'),
            'The verification code table is missing — registration dies at sign-up.'
        );

        $this->assertTrue(class_exists(EmailVerificationCode::class));
    }

    public function test_the_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->companyAdmin()->unverified()->create();

        $this->actingAs($user)->get('/verify-email')->assertOk();
    }

    public function test_a_verified_user_is_sent_on_to_their_dashboard(): void
    {
        $user = User::factory()->companyAdmin()->create();

        $this->actingAs($user)
            ->get('/verify-email')
            ->assertRedirect(route('app.dashboard'));
    }

    public function test_issuing_a_code_stores_only_its_hash_and_emails_the_plain_one(): void
    {
        Notification::fake();

        $user = User::factory()->companyAdmin()->unverified()->create();

        $record = app(EmailVerificationService::class)->issueAndSend($user);

        Notification::assertSentTo($user, VerifyEmailCodeNotification::class);

        $this->assertSame(0, $record->attempts);
        $this->assertNull($record->verified_at);
        $this->assertTrue($record->expires_at->isFuture());

        // The column must never hold the code itself.
        $this->assertNotEmpty($record->code_hash);
        $this->assertDoesNotMatchRegularExpression('/^\d{6}$/', $record->code_hash);
    }

    public function test_a_correct_code_verifies_the_account(): void
    {
        Event::fake();

        $user = User::factory()->companyAdmin()->unverified()->create();
        $code = $this->issueKnownCode($user);

        app(EmailVerificationService::class)->verify($user, $code);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);

        $this->assertSame(
            0,
            EmailVerificationCode::where('user_id', $user->id)->whereNull('verified_at')->count(),
            'Spent codes should not stay usable.'
        );
    }

    public function test_a_wrong_code_is_rejected_and_counted(): void
    {
        $user = User::factory()->companyAdmin()->unverified()->create();
        $this->issueKnownCode($user);

        try {
            app(EmailVerificationService::class)->verify($user, '000000');
            $this->fail('A wrong code should not be accepted.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertNotSame(
                'auth.verification_code_invalid',
                $e->errors()['code'][0],
                'The error message is a raw translation key.'
            );
        }

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertSame(1, EmailVerificationCode::where('user_id', $user->id)->sole()->attempts);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $user = User::factory()->companyAdmin()->unverified()->create();
        $code = $this->issueKnownCode($user);

        EmailVerificationCode::where('user_id', $user->id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(EmailVerificationService::class)->verify($user, $code);
    }

    public function test_too_many_wrong_attempts_locks_the_code(): void
    {
        $user = User::factory()->companyAdmin()->unverified()->create();
        $code = $this->issueKnownCode($user);

        EmailVerificationCode::where('user_id', $user->id)
            ->update(['attempts' => config('auth_verification.max_attempts')]);

        try {
            // Even the RIGHT code must be refused once locked.
            app(EmailVerificationService::class)->verify($user, $code);
            $this->fail('A locked code should be refused.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringNotContainsString('auth.', $e->errors()['code'][0]);
        }

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_issuing_a_new_code_invalidates_the_previous_one(): void
    {
        Notification::fake();

        $user = User::factory()->companyAdmin()->unverified()->create();

        app(EmailVerificationService::class)->issueAndSend($user);
        app(EmailVerificationService::class)->issueAndSend($user);

        $this->assertSame(
            1,
            EmailVerificationCode::where('user_id', $user->id)->whereNull('verified_at')->count(),
            'Only the newest code should remain usable.'
        );
    }

    /**
     * Issue a code and return the plain value, by writing a hash we
     * control — the service never hands the plain code back.
     */
    private function issueKnownCode(User $user, string $code = '424242'): string
    {
        EmailVerificationCode::create([
            'user_id'    => $user->id,
            'code_hash'  => Hash::make($code),
            'expires_at' => now()->addMinutes(15),
        ]);

        return $code;
    }
}
