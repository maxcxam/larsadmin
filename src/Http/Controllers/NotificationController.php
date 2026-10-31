<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Notifications\Inbox;
use Maxcxam\Larsadmin\Notifications\NotificationPresenter;
use Throwable;

/**
 * The bell's endpoints (ADR-026). Every query goes through Inbox::for(): an id of another user, of a
 * notification that is not a panel one, or an unknown id answers 404.
 *
 * @internal Not part of the public API.
 */
class NotificationController
{
    /**
     * One page of the user's panel notifications, newest first (cursor pagination).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $cursor = $this->cursor($request);
        $timezone = Larsadmin::timezone($request);

        try {
            $page = Inbox::for($user)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->cursorPaginate(Inbox::perPage(), ['*'], 'cursor', $cursor);

            return $this->json([
                'items' => (new NotificationPresenter($user, $timezone))->present($page->items()),
                'unread' => Inbox::unreadCount($user),
                'nextCursor' => $page->nextCursor()?->encode(),
                'timezone' => $timezone,
            ]);
        } catch (Throwable $e) {
            Inbox::rethrow($e);
        }
    }

    /**
     * What the client polls: the unread count, the newest notification's time and the active runs.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $this->user($request);

        try {
            $latest = Inbox::for($user)->max('created_at');

            return $this->json([
                'unread' => Inbox::unreadCount($user),
                'latest' => $latest !== null
                    ? Carbon::parse($latest)->setTimezone(Larsadmin::timezone($request))->toIso8601String()
                    : null,
                // Stale runs are failed on the way, so a lost job does not keep the client polling.
                'activeRuns' => ActionRun::liveFor($user)->count(),
            ]);
        } catch (Throwable $e) {
            Inbox::rethrow($e);
        }
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $user = $this->user($request);

        try {
            $row = Inbox::for($user)->whereKey($notification)->first();
        } catch (Throwable $e) {
            Inbox::rethrow($e);
        }

        ($row ?? abort(404))->markAsRead();

        return $this->json(['unread' => Inbox::unreadCount($user)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $user = $this->user($request);

        try {
            Inbox::for($user)->whereNull('read_at')->update(['read_at' => now()]);
        } catch (Throwable $e) {
            Inbox::rethrow($e);
        }

        return $this->json(['unread' => 0]);
    }

    public function destroy(Request $request, string $notification): JsonResponse
    {
        $user = $this->user($request);

        try {
            $row = Inbox::for($user)->whereKey($notification)->first();
        } catch (Throwable $e) {
            Inbox::rethrow($e);
        }

        ($row ?? abort(404))->delete();

        return $this->json(['unread' => Inbox::unreadCount($user)]);
    }

    /**
     * The panel user, when the feature is enabled and the user can receive notifications (else 404).
     */
    protected function user(Request $request): Model
    {
        abort_unless(Larsadmin::notificationsEnabled(), 404);

        $user = $request->user(Larsadmin::guard());

        abort_unless(Inbox::supports($user), 404);

        /** @var Model $user */
        return $user;
    }

    /**
     * The validated cursor: a well-formed one over the list's order columns, else 422.
     */
    protected function cursor(Request $request): ?Cursor
    {
        $value = $request->query('cursor');

        if ($value === null || $value === '') {
            return null;
        }

        $cursor = is_string($value) && strlen($value) <= 1000 ? Cursor::fromEncoded($value) : null;
        $parameters = $cursor?->toArray() ?? [];
        unset($parameters['_pointsToNextItems']);

        // Both values reach the database: a date-time and a UUID only (pgsql rejects anything else with a 500).
        $valid = $cursor !== null
            && array_keys($parameters) === ['created_at', 'id']
            && is_string($parameters['created_at'])
            && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?\z/', $parameters['created_at']) === 1
            && strtotime($parameters['created_at']) !== false
            && is_string($parameters['id'])
            && Str::isUuid($parameters['id']);

        if (! $valid) {
            throw ValidationException::withMessages(['cursor' => __('validation.string', ['attribute' => 'cursor'])]);
        }

        return $cursor;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}
