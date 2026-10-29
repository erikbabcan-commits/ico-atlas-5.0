<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Graph\GraphService;
use App\Services\Support\Normalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GraphController extends Controller
{
    public function __construct(
        protected GraphService $graph,
    ) {}

    public function show(Request $request, string $ico): JsonResponse
    {
        $ico = Normalizer::ico($ico);
        if ($ico === null) {
            return response()->json([
                'message' => 'IČO musí mať presne 8 číslic.',
                'data' => null,
            ], 422);
        }

        $depth = (int) $request->query('depth', '1');
        if (!in_array($depth, [1, 2], true)) {
            return response()->json([
                'message' => 'Parameter depth musí byť 1 alebo 2.',
                'data' => null,
            ], 422);
        }

        $edgesParam = $request->query('edges');
        $edgeFilter = null;
        if (is_string($edgesParam) && $edgesParam !== '') {
            $edgeFilter = array_values(array_filter(array_map('trim', explode(',', $edgesParam))));
        }

        $graph = $this->graph->companyGraph($ico, $depth, $edgeFilter);

        return response()->json([
            'data' => $graph,
            'meta' => [
                'retrieved_at' => now()->toIso8601String(),
                'disclaimer' => (string) config('whoiswho.disclaimer'),
            ],
        ], 200);
    }
}
