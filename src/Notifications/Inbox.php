<?php

namespace Maxcxam\Larsadmin\Notifications;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\DatabaseNotification;
use RuntimeException;
use Throwable;

/**
 * The panel notifications of one user (ADR-026): every query is scoped to the notifiable's morph type,
 * its key and type "larsadmin", so an id outside it answers 404.
 *
 * @internal Not part of the public API.
 */
final class Inbox
{
    /**
     * Whether the user can receive panel notifications: an Eloquent model using Notifiable.
     */
    public static function supports(?Authenticatable $user): bool
    {
        return $user instanceof Model
            && method_exists($user, 'routeNotificationFor')
            && method_exists($user, 'notifications');
    }

    /**
     * @return Builder<DatabaseNotification>
     */
    public static function for(Model $user): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where('type', LarsadminNotification::TYPE);
    }

    public static function unreadCount(Model $user): int
    {
        return self::for($user)->whereNull('read_at')->count();
    }

    /**
     * The token naming the user's realtime channel: HMAC-SHA256(app key, "larsadmin.inbox|morph|id"), 40 hex characters.
     * It does not reveal the id to a third-party relay, and equal ids of different models never collide.
     */
    public static function channelToken(Authenticatable $user): string
    {
        return self::tokenFor(
            $user instanceof Model ? $user->getMorphClass() : $user::class,
            (string) $user->getAuthIdentifier(),
        );
    }

    public static function tokenFor(string $type, string $id): string
    {
        // Purpose-labelled, so the app key's HMAC is never reused across features. Rotating APP_KEY changes
        // every token: open tabs lose realtime (polling goes on) until they reload.
        return substr(hash_hmac('sha256', 'larsadmin.inbox|'.$type.'|'.$id, self::key()), 0, 40);
    }

    /**
     * The bell's page size ("larsadmin.notifications.per_page", clamped to 5..50).
     */
    public static function perPage(): int
    {
        return min(50, max(5, (int) config('larsadmin.notifications.per_page', 15)));
    }

    /**
     * The polling interval in seconds: 0 (only on page loads) or 15..3600.
     */
    public static function pollInterval(): int
    {
        $seconds = (int) config('larsadmin.notifications.poll_interval', 60);

        return $seconds <= 0 ? 0 : min(3600, max(15, $seconds));
    }

    /**
     * Whether the exception says a table is missing (the migrations were not run).
     */
    public static function isMissingTable(Throwable $e): bool
    {
        return $e instanceof QueryException && (
            in_array($e->errorInfo[0] ?? null, ['42S02', '42P01'], true)
            || str_contains(strtolower($e->getMessage()), 'no such table')
        );
    }

    /**
     * Turn a missing table into an exception that says how to fix it; rethrow anything else.
     *
     * @throws Throwable
     */
    public static function rethrow(Throwable $e): never
    {
        if (self::isMissingTable($e)) {
            throw new RuntimeException(
                'The Larsadmin notification tables do not exist: run the larsadmin migrations '
                .'(php artisan vendor:publish --tag=larsadmin-migrations), php artisan make:notifications-table '
                .'and php artisan migrate, or disable notifications (LARSADMIN_NOTIFICATIONS=false).',
                0,
                $e,
            );
        }

        throw $e;
    }

    private static function key(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }
}
