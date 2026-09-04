<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\User;
use App\Notifications\UserAlert;

/**
 * One entry point for "tell a user something happened".
 *
 *  - Always writes a row to `notifications` — the bell's source of truth.
 *  - Optionally emails the same message (config/notifications.php), but
 *    only AFTER the response is sent, so a slow SMTP handshake never
 *    delays an upload or a review decision. This works on the `sync`
 *    queue driver too.
 */
class Notifier
{
    public static function send(
        User|int $user,
        string $type,
        string $message,
        ?string $link = null,
        ?string $emailSubject = null,
    ): void {
        $userId = $user instanceof User ? $user->id : (int) $user;

        Notification::create([
            'user_id' => $userId,
            'message' => $message,
            'type' => $type,
            'link' => $link,
            'is_read' => false,
            'created_at' => now(),
        ]);

        if (! self::emailable($type)) {
            return;
        }

        $model = $user instanceof User ? $user : User::find($userId);

        if (! $model || ! filter_var($model->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $subject = $emailSubject ?: (config('app.name').' — notification');
        $actionUrl = $link ? self::absoluteUrl($link) : null;

        dispatch(static function () use ($model, $subject, $message, $actionUrl) {
            $model->notify(new UserAlert($subject, $message, $actionUrl));
        })->afterResponse();
    }

    /**
     * Fan a single message out to many users (e.g. the OSM review pool).
     *
     * @param  iterable<User|int>  $users
     */
    public static function sendMany(
        iterable $users,
        string $type,
        string $message,
        ?string $link = null,
        ?string $emailSubject = null,
    ): void {
        foreach ($users as $user) {
            self::send($user, $type, $message, $link, $emailSubject);
        }
    }

    private static function emailable(string $type): bool
    {
        return config('notifications.email_enabled')
            && in_array($type, (array) config('notifications.email_types', []), true);
    }

    private static function absoluteUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($path, '/');
    }
}
