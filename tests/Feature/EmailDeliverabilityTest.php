<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\TrialEndingNotification;
use App\Notifications\VerifyEmailCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The parts of "why does this land in spam" that live in the code.
//
//  Most of that answer is DNS — SPF, DKIM and DMARC, and whether the
//  From domain matches the server actually sending. None of that can
//  be tested from here.
//
//  One part can. Every message this app sent was HTML-only, with no
//  text/plain alternative. Filters weigh that against a message:
//  legitimate senders produce multipart mail, bulk senders often do
//  not. It also decides what a screen reader, a smartwatch and a
//  plain-text client actually read.
//
//  The risk with a text part is that it drifts from the HTML one —
//  a code that does not match, an expiry that says something else —
//  so these tests check the two agree, not merely that both exist.
// ══════════════════════════════════════════════════════════════════
class EmailDeliverabilityTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $company = Company::factory()->create(['name' => 'Evoqas Trading']);

        return User::factory()->companyAdmin($company)->create([
            'name'     => 'Ahmed Salah',
            'language' => 'en',
        ]);
    }

    /**
     * The rendered message, both parts.
     *
     * @return array{html: string, text: string}
     */
    private function parts(\Illuminate\Notifications\Messages\MailMessage $mail): array
    {
        [$htmlView, $textView] = $mail->view;

        return [
            'html' => view($htmlView, $mail->viewData)->render(),
            'text' => view($textView, $mail->viewData)->render(),
        ];
    }

    // ── Every message is multipart ───────────────────────────────

    public function test_the_verification_email_has_a_text_part(): void
    {
        $mail = (new VerifyEmailCodeNotification('046771'))->toMail($this->user());

        $this->assertIsArray($mail->view, 'HTML-only: no text/plain alternative');
        $this->assertCount(2, $mail->view);
        $this->assertSame('emails.text.verify-email-code', $mail->view[1]);
    }

    public function test_the_reset_email_has_a_text_part(): void
    {
        $mail = (new ResetPasswordNotification('token-abc'))->toMail($this->user());

        $this->assertIsArray($mail->view);
        $this->assertSame('emails.text.reset-password', $mail->view[1]);
    }

    public function test_the_trial_email_has_a_text_part(): void
    {
        $user = $this->user();
        $mail = (new TrialEndingNotification($user->company, 3))->toMail($user);

        $this->assertIsArray($mail->view);
        $this->assertSame('emails.text.trial-ending', $mail->view[1]);
    }

    // ── The two parts say the same thing ─────────────────────────

    /**
     * A text part that disagrees with the HTML one is worse than
     * none: somebody reading the text sees a code that does not work.
     */
    public function test_the_verification_code_matches_in_both_parts(): void
    {
        $mail  = (new VerifyEmailCodeNotification('046771'))->toMail($this->user());
        $parts = $this->parts($mail);

        $this->assertStringContainsString('046771', $parts['html']);
        $this->assertStringContainsString('046771', $parts['text']);
    }

    public function test_the_reset_link_matches_in_both_parts(): void
    {
        $mail  = (new ResetPasswordNotification('token-abc'))->toMail($this->user());
        $parts = $this->parts($mail);

        $this->assertStringContainsString('token-abc', $parts['html']);
        $this->assertStringContainsString(
            'token-abc',
            $parts['text'],
            'The text part has no way to reset the password'
        );
    }

    public function test_the_text_part_is_actually_plain(): void
    {
        $mail = (new VerifyEmailCodeNotification('046771'))->toMail($this->user());
        $text = $this->parts($mail)['text'];

        $this->assertStringNotContainsString('<table', $text);
        $this->assertStringNotContainsString('style=', $text);
        $this->assertNotEmpty(trim($text));
    }

    public function test_the_text_part_still_names_the_product(): void
    {
        $mail = (new VerifyEmailCodeNotification('046771'))->toMail($this->user());

        $this->assertStringContainsString('Maliyat Docs', $this->parts($mail)['text']);
    }

    public function test_the_text_part_is_translated_too(): void
    {
        $user = $this->user();
        $user->update(['language' => 'ar']);

        $mail = (new VerifyEmailCodeNotification('046771'))->toMail($user->fresh());
        $text = $this->parts($mail)['text'];

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text, 'The Arabic text part is in English');
    }

    // ── The envelope ─────────────────────────────────────────────

    /**
     * A From address on a domain the sending server has no authority
     * over fails SPF and DMARC alignment, which is the single largest
     * reason legitimate mail is filtered.
     */
    public function test_the_from_address_is_configured(): void
    {
        $this->assertNotEmpty(config('mail.from.address'));
        $this->assertStringContainsString('@', (string) config('mail.from.address'));
    }

    public function test_every_message_has_a_real_subject(): void
    {
        $user = $this->user();

        foreach ([
            (new VerifyEmailCodeNotification('1'))->toMail($user),
            (new ResetPasswordNotification('t'))->toMail($user),
            (new TrialEndingNotification($user->company, 3))->toMail($user),
        ] as $mail) {
            $this->assertNotEmpty(trim((string) $mail->subject));
            $this->assertStringNotContainsString('emails.', (string) $mail->subject, 'A translation key leaked into the subject');
        }
    }

    public function test_a_registration_actually_queues_a_message(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name'                  => 'Ahmed Salah',
            'company_name'          => 'Evoqas',
            'email'                 => 'ahmed@example.test',
            'currency'              => 'EGP',
            'language'              => 'en',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            'business_types'        => ['trading'],
            '_hp'                   => '',
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo(
            User::query()->where('email', 'ahmed@example.test')->firstOrFail(),
            VerifyEmailCodeNotification::class
        );
    }
}
