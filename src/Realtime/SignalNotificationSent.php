<?php

namespace Maxcxam\Larsadmin\Realtime;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationSent;
use Maxcxam\Larsadmin\Notifications\LarsadminNotification;
use Throwable;

/**
 * Signals a panel notification stored by the "database" channel to its notifiable's inbox channel. A
 * broadcaster failure is reported and never fails the notification (or the worker sending it).
 *
 * @internal Not part of the public API.
 */
final class SignalNotificationSent
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof Model) {
            return;
        }

        try {
            if (! Realtime::enabled()) {
                return;
            }

            $notification = $event->notification;

            if (! method_exists($notification, 'databaseType') || $notification->databaseType($event->notifiable) !== LarsadminNotification::TYPE) {
                return;
            }

            $id = $event->response instanceof Model ? (string) $event->response->getKey() : (string) $notification->id;

            Realtime::signal(PanelSignal::notification(
                $event->notifiable->getMorphClass(),
                (string) $event->notifiable->getKey(),
                $id,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
