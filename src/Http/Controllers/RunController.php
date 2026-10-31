<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Actions\Queued\RunPresenter;
use Maxcxam\Larsadmin\Actions\Queued\RunQueuedAction;
use Maxcxam\Larsadmin\Files\FileResponse;
use Maxcxam\Larsadmin\Files\StoredFile;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Notifications\Inbox;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The user's own queued action runs (ADR-028, ADR-029): progress, cancellation and the file result. A run
 * of another user, or an unknown id, answers 404.
 *
 * @internal Not part of the public API.
 */
class RunController
{
    /** At most this many runs are listed. */
    public const int LIMIT = 10;

    /**
     * The active runs and those finished in the last 24 hours, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $timezone = Larsadmin::timezone($request);

        try {
            $runs = ActionRun::query()
                ->ownedBy($user)
                ->where(fn ($query) => $query->whereIn('status', ActionRun::ACTIVE)->orWhere('finished_at', '>=', now()->subDay()))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(self::LIMIT)
                ->get();
        } catch (Throwable $e) {
            Inbox::rethrow($e);
        }

        // A lost job must not look active forever: stale runs are failed (once, notified) as they are listed.
        foreach ($runs as $run) {
            if ($run->isStale()) {
                RunQueuedAction::abandon($run);
            }
        }

        return $this->json(['runs' => $runs->map(fn (ActionRun $run) => RunPresenter::toArray($run, $timezone))->values()->all()]);
    }

    public function show(Request $request, string $run): JsonResponse
    {
        $run = $this->find($request, $run);

        if ($run->isStale()) {
            RunQueuedAction::abandon($run);
        }

        return $this->json(RunPresenter::toArray($run, Larsadmin::timezone($request)));
    }

    /**
     * Cancel a run: a queued one at once, a running one after its current chunk, a stale (abandoned) one at
     * once (409 when finished).
     */
    public function cancel(Request $request, string $run): JsonResponse
    {
        $run = $this->find($request, $run);
        $user = $this->user($request);

        $cancelled = ActionRun::query()->whereKey($run->getKey())->where('status', ActionRun::QUEUED)->update([
            'status' => ActionRun::RUNNING,
            'cancel_requested_at' => now(),
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        if ($cancelled === 1) {
            // Claimed from the queue, so the job will find nothing to do.
            $run->refresh();
            RunQueuedAction::inLocale($run, fn () => RunQueuedAction::finish(
                $run,
                ActionRun::CANCELLED,
                'warning',
                __('larsadmin::actions.run_cancelled', ['done' => 0, 'total' => (int) $run->total]),
                null,
                $user,
            ));
        } elseif (! ($run->isStale() && RunQueuedAction::abandon($run, ActionRun::CANCELLED))) {
            // Without touching updated_at: the flag is not a heartbeat.
            $requested = ActionRun::query()->whereKey($run->getKey())->where('status', ActionRun::RUNNING)->toBase()->update([
                'cancel_requested_at' => now(),
            ]);

            abort_if($requested === 0, 409);

            $run->refresh();
        }

        return $this->json(RunPresenter::toArray($run, Larsadmin::timezone($request)));
    }

    /**
     * The file result of a completed run, to its owner only, while it exists.
     */
    public function download(Request $request, string $run): StreamedResponse
    {
        $run = $this->find($request, $run);

        abort_unless($run->status === ActionRun::COMPLETED && ($run->result['type'] ?? null) === ActionResponse::FILE && RunPresenter::fileExists($run), 404);

        return FileResponse::make(
            Storage::disk($run->result['disk']),
            new StoredFile($run->result['path'], RunPresenter::filename($run)),
            true,
            max(1, (int) config('larsadmin.files.url_ttl', 30)),
        );
    }

    protected function find(Request $request, string $id): ActionRun
    {
        try {
            $run = ActionRun::query()->ownedBy($this->user($request))->whereKey(strtolower($id))->first();
        } catch (Throwable $e) {
            Inbox::rethrow($e);
        }

        return $run ?? abort(404);
    }

    protected function user(Request $request): Authenticatable
    {
        abort_unless(Larsadmin::notificationsEnabled(), 404);

        return $request->user(Larsadmin::guard()) ?? abort(401);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}
