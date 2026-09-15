<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — TrialEndingNotification
//
//  Sent to a company's admins while their free trial is inside the
//  warning window (config/subscription.php → notify_days_before),
//  so nobody discovers they've lost access by being locked out one
//  morning. Dispatched by the subscriptions:notify-expiring command.
// ══════════════════════════════════════════════════════════════════
class TrialEndingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Company $company,
        private readonly int $daysLeft,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->language ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('emails.trial_ending.subject', ['days' => $this->daysLeft], $locale))
            ->view(['emails.trial-ending', 'emails.text.trial-ending'], [
                'user'      => $notifiable,
                'company'   => $this->company,
                'daysLeft'  => $this->daysLeft,
                'endsOn'    => $this->company->trial_ends_at?->toDateString(),
                'locale'    => $locale,
            ]);
    }
}
