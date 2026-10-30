<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maxcxam\Larsadmin\Query\EntityQuery;
use Maxcxam\Larsadmin\Search\GlobalSearch;

class GlobalSearchController
{
    /**
     * Search the opt-in entities the user may list (ADR-019).
     */
    public function index(Request $request, GlobalSearch $search): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'search' => ['required', 'string', 'max:'.EntityQuery::MAX_SEARCH_LENGTH],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422)
                ->header('Cache-Control', 'no-store, private');
        }

        return response()->json($search->run($request, (string) $request->query('search')))
            ->header('Cache-Control', 'no-store, private');
    }
}
