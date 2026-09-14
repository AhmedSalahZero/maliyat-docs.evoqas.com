<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailCodeNotification extends Notification
{
    public function __construct(
        private readonly string $plainCode
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->language ?? app()->getLocale();
        $minutes = config('auth_verification.expires_minutes');

        return (new MailMessage)
            ->subject(__('emails.verify_code.subject', [], $locale))
            ->view('emails.verify-email-code', [
                'user'           => $notifiable,
                'code'           => $this->plainCode,
                'expiresMinutes' => $minutes,
                'locale'         => $locale,
            ]);
    }
}
