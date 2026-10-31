<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Metrics\Card;
use Maxcxam\Larsadmin\Metrics\Cards;
use Maxcxam\Larsadmin\Metrics\DataCard;
use Maxcxam\Larsadmin\Metrics\Metric;

/**
 * @internal Not part of the public API.
 */
class MetricController
{
    /**
     * Compute a metric (or the data of a DataCard) of a dashboard. An invisible dashboard or card does not exist (404).
     */
    public function dashboard(Request $request, string $dashboard, string $metric): JsonResponse
    {
        $dashboard = Larsadmin::dashboardForKey($dashboard);

        abort_unless($dashboard !== null && $dashboard->authorizedToSee($request), 404);

        return $this->respond($request, Cards::find($request, $dashboard->cards($request), $metric), 'dashboard:'.$dashboard->uriKey());
    }

    /**
     * Compute a metric (or the data of a DataCard) shown on an entity's index. Needs viewAny on the entity (403);
     * an invisible card is a 404.
     */
    public function entity(Request $request, string $entity, string $metric): JsonResponse
    {
        $entity = Larsadmin::entityForKey($entity) ?? abort(404);

        abort_unless($entity::authorizedToViewAny($request), 403);

        return $this->respond($request, Cards::find($request, $entity::make()->cards($request), $metric), 'entity:'.$entity::uriKey());
    }

    protected function respond(Request $request, ?Card $card, string $owner): JsonResponse
    {
        if ($card instanceof DataCard) {
            return $this->respondWithData($request, $card, $owner);
        }

        abort_unless($card instanceof Metric, 404);

        // Anything but a plain scalar (e.g. range[]=1) is an invalid range, which falls back to the default.
        $range = $request->query('range');
        $metricRequest = $card->metricRequest($request, is_string($range) ? $range : null, $owner);

        try {
            $result = $card->resolve($metricRequest);
        } catch (AuthorizationException) {
            return response()->json(['message' => __('larsadmin::messages.forbidden')], 403);
        }

        return response()->json(['range' => $metricRequest->range, 'result' => $result]);
    }

    /**
     * The data of a DataCard: 200 {"data": ...}; 422 for invalid parameters (ADR-033).
     */
    protected function respondWithData(Request $request, DataCard $card, string $owner): JsonResponse
    {
        try {
            $data = $card->resolve($card->cardRequest($request, $owner));
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (AuthorizationException) {
            return response()->json(['message' => __('larsadmin::messages.forbidden')], 403);
        }

        return response()->json(['data' => $data]);
    }
}
