<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email side of an in-app notification. The DB row is written
 * separately by App\Support\Notifier; this class only renders the mail.
 * Sent from inside an after-response closure, so it deliberately does
 * NOT implement ShouldQueue — it runs once the user already has their
 * response.
 */
class UserAlert extends Notification
{
    use Queueable;

    public function __construct(
        public string $subjectLine,
        public string $body,
        public ?string $actionUrl = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = trim((string) ($notifiable->full_name ?? '')) ?: 'there';

        $mail = (new MailMessage)
            ->subject($this->subjectLine)
            ->greeting("Hi {$name},")
            ->line($this->body);

        if ($this->actionUrl) {
            $mail->action('Open '.config('app.name'), $this->actionUrl);
        }

        return $mail->line('You are receiving this because you have an account on '.config('app.name').'.');
    }
}
