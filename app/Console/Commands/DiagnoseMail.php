<?php

namespace App\Console\Commands;

use App\Support\AuthVerification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — mail:diagnose
//
//  Answers "why did no email arrive" on a server you cannot open a
//  browser on.
//
//  The question is almost never "are the SMTP credentials right" —
//  those can be checked in a minute with a terminal. It is usually
//  one of the quiet ones: the config is cached so .env is being
//  ignored entirely, or MAIL_MAILER is not what the file appears to
//  say, or a key was written under a name this version of Laravel no
//  longer reads.
//
//  So this reports what the application ACTUALLY RESOLVED, not what
//  the .env file contains. Those two disagreeing is the whole
//  problem most of the time, and nothing else in the app will ever
//  tell you they have.
//
//  Passwords are never printed — only whether one is set.
// ══════════════════════════════════════════════════════════════════
class DiagnoseMail extends Command
{
    protected $signature = 'mail:diagnose
                            {--to= : Send a real test message to this address}';

    protected $description = 'Report the mail configuration the app actually resolved, and optionally send a test message';

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('Mail configuration as the application resolved it');

        $cached = file_exists($this->laravel->getCachedConfigPath());

        $this->table(['Setting', 'Value'], [
            ['config cached', $cached ? 'YES — .env is being IGNORED' : 'no'],
            ['APP_ENV', config('app.env')],
            ['MAIL_MAILER (default)', config('mail.default')],
            ['host', config('mail.mailers.smtp.host')],
            ['port', config('mail.mailers.smtp.port')],
            ['scheme', config('mail.mailers.smtp.scheme') ?: '(unset — STARTTLS is chosen from the port)'],
            ['username', config('mail.mailers.smtp.username') ?: '(none)'],
            ['password', config('mail.mailers.smtp.password') ? 'set' : '(NONE)'],
            ['verify_peer', config('mail.mailers.smtp.verify_peer') ? 'true' : 'false'],
            ['from address', config('mail.from.address')],
            ['from name', config('mail.from.name')],
            ['QUEUE_CONNECTION', config('queue.default')],
            ['verification enabled', AuthVerification::enabled() ? 'yes' : 'NO — no codes are ever sent'],
        ]);

        $this->reportProblems($cached);

        $to = $this->option('to');

        if (! $to) {
            $this->newLine();
            $this->components->info('Add --to=you@example.com to actually send a test message.');

            return self::SUCCESS;
        }

        return $this->sendTestTo($to);
    }

    /**
     * The things that are wrong often enough to be worth naming
     * outright rather than leaving somebody to spot in a table.
     */
    private function reportProblems(bool $cached): void
    {
        $problems = [];

        if ($cached) {
            $problems[] = 'Config is cached, so every value above came from bootstrap/cache/config.php — '
                .'NOT from .env. Editing .env changes nothing until you run: php artisan config:clear';
        }

        if (config('mail.default') === 'log') {
            $problems[] = 'MAIL_MAILER is "log": mail is written to storage/logs/laravel.log and never delivered.';
        }

        if (config('mail.default') === 'array') {
            $problems[] = 'MAIL_MAILER is "array": mail is kept in memory and thrown away.';
        }

        // MAIL_ENCRYPTION was removed in Laravel 11. Someone carrying
        // it forward from an older project gets no warning — the key
        // is simply never read.
        if (env('MAIL_ENCRYPTION') && ! env('MAIL_SCHEME')) {
            $problems[] = 'MAIL_ENCRYPTION is set but this version of Laravel does not read it — it uses MAIL_SCHEME. '
                .'On port 587 that is usually harmless (STARTTLS is chosen automatically), but the setting is doing nothing.';
        }

        if (config('mail.default') === 'smtp' && ! config('mail.mailers.smtp.password')) {
            $problems[] = 'SMTP is selected but no password is set.';
        }

        if (! $problems) {
            $this->components->info('Nothing obviously wrong with the configuration.');

            return;
        }

        $this->newLine();

        foreach ($problems as $problem) {
            $this->components->warn($problem);
        }
    }

    private function sendTestTo(string $to): int
    {
        $this->newLine();
        $this->components->task("Sending a test message to {$to}", function () use ($to) {
            Mail::raw(
                "This is a test from Maliyat Docs.\n\n"
                ."If you are reading it, SMTP delivery works and the problem is elsewhere.\n"
                .'Sent at '.now()->toDateTimeString(),
                fn ($message) => $message->to($to)->subject('Maliyat Docs — mail test')
            );

            return true;
        });

        try {
            // Surface anything the task above swallowed.
            if (count(Mail::failures() ?: [])) {
                $this->components->error('The mailer reported failures: '.implode(', ', Mail::failures()));

                return false;
            }
        } catch (Throwable) {
            // Not every transport implements failures(); not a problem.
        }

        $this->newLine();
        $this->components->info(
            'Handed to the mailer without an exception. If nothing arrives, the message left this server '
            .'and was dropped afterwards — check the mail account\'s own logs and the spam folder.'
        );

        return self::SUCCESS;
    }
}
