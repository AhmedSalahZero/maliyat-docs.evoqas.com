<?php

namespace Tests\Feature;

use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  "It says it sent, but nothing arrived."
//
//  There is no way to answer that from a browser, and on a hosted
//  server there is often no way to answer it at all: the .env file
//  says one thing, the running application may be using another, and
//  nothing anywhere reports the difference.
//
//  The usual causes are all silent:
//    - the config is cached, so .env is ignored entirely
//    - MAIL_MAILER is "log", which writes to a file and reports
//      success
//    - a key was carried over from an older Laravel under a name
//      this version no longer reads (MAIL_ENCRYPTION → MAIL_SCHEME)
//
//  mail:diagnose reports what the app ACTUALLY RESOLVED rather than
//  what the file appears to say, because those two disagreeing is
//  the problem most of the time.
// ══════════════════════════════════════════════════════════════════
class MailDiagnosticTest extends TestCase
{
    public function test_the_command_exists(): void
    {
        $this->artisan('mail:diagnose')->assertSuccessful();
    }

    public function test_it_reports_the_resolved_mailer(): void
    {
        config(['mail.default' => 'log']);

        $this->artisan('mail:diagnose')
            ->expectsOutputToContain('log')
            ->assertSuccessful();
    }

    /**
     * The one that silently swallows every message while reporting
     * success.
     */
    public function test_it_warns_when_mail_is_only_being_written_to_a_file(): void
    {
        config(['mail.default' => 'log']);

        $this->artisan('mail:diagnose')
            ->expectsOutputToContain('never delivered')
            ->assertSuccessful();
    }

    public function test_it_warns_when_smtp_has_no_password(): void
    {
        config([
            'mail.default'                 => 'smtp',
            'mail.mailers.smtp.password'   => null,
        ]);

        $this->artisan('mail:diagnose')
            ->expectsOutputToContain('no password')
            ->assertSuccessful();
    }

    /**
     * Never print a password, even to somebody who already has the
     * server — a terminal transcript gets pasted into chats.
     */
    public function test_it_never_prints_the_password(): void
    {
        config([
            'mail.default'               => 'smtp',
            'mail.mailers.smtp.password' => 'super-secret-value',
        ]);

        $this->artisan('mail:diagnose')
            ->doesntExpectOutputToContain('super-secret-value')
            ->assertSuccessful();
    }

    public function test_it_reports_whether_verification_is_switched_on_at_all(): void
    {
        config(['auth_verification.enabled' => false]);

        $this->artisan('mail:diagnose')
            ->expectsOutputToContain('no codes are ever sent')
            ->assertSuccessful();
    }

    /**
     * MAIL_ENCRYPTION stopped being read in Laravel 11. Carrying it
     * forward produces no error and no warning — the key is simply
     * never looked at.
     */
    public function test_the_env_template_points_at_the_key_this_version_reads(): void
    {
        $template = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('MAIL_SCHEME', $template);
        $this->assertStringContainsString(
            'not MAIL_ENCRYPTION',
            $template,
            'Nothing warns the next person that MAIL_ENCRYPTION is ignored'
        );
    }

    /**
     * The template must not ship a value that silently discards mail
     * without saying what it does.
     */
    public function test_the_env_template_explains_the_log_mailer(): void
    {
        $template = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('sends nothing', $template);
    }

    /**
     * The image host is the one setting that is wrong by DEFAULT on
     * every development machine and silently correct-looking.
     */
    public function test_it_warns_when_email_images_point_somewhere_unreachable(): void
    {
        config(['mail.asset_url' => 'http://maliyat-docs.test']);

        $this->artisan('mail:diagnose')
            ->expectsOutputToContain('broken image')
            ->assertSuccessful();
    }

    public function test_it_stays_quiet_when_the_image_host_is_public(): void
    {
        config([
            'mail.default'    => 'smtp',
            'mail.asset_url'  => 'https://maliyat-docs.evoqas.com',
            'mail.mailers.smtp.password' => 'set',
        ]);

        $this->artisan('mail:diagnose')
            ->doesntExpectOutputToContain('broken image')
            ->assertSuccessful();
    }

    public function test_the_mail_config_reads_the_modern_keys(): void
    {
        $config = file_get_contents(config_path('mail.php'));

        $this->assertStringContainsString("env('MAIL_SCHEME')", $config);
        $this->assertStringNotContainsString(
            "env('MAIL_ENCRYPTION')",
            $config,
            'The config reads a key Laravel no longer supports'
        );
    }
}
