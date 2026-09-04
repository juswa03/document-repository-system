<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $url)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your password')
            ->line('You requested a password reset for your Records & Approvals account.')
            ->action('Reset password', $this->url)
            ->line('This link expires in 60 minutes.')
            ->line("If you didn't request this, you can safely ignore this email.");
    }
}
