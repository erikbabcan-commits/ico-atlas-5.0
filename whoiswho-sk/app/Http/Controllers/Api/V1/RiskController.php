<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Risk\RiskService;
use App\Services\Support\Normalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RiskController extends Controller
{
    public function __construct(
        protected RiskService $risk,
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

        $assessment = $this->risk->assess($ico);

        AuditLog::create([
            'ico' => $ico,
            'caller' => $request->header('X-Caller') ?? 'api',
            'http_status' => 200,
            'endpoint' => '/api/v1/companies/{ico}/risk',
            'retrieved_at' => now(),
            'meta' => ['flags' => array_column($assessment['flags'], 'code')],
        ]);

        return response()->json([
            'data' => $assessment,
            'meta' => [
                'retrieved_at' => now()->toIso8601String(),
                'disclaimer' => (string) config('whoiswho.disclaimer'),
            ],
        ], 200);
    }
}
