<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ReportJob;
use App\Services\Graph\GraphService;
use App\Services\Risk\RiskService;
use App\Services\Support\Normalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * POST /api/v1/reports/due-diligence — STUB (monetizácia neskôr).
 * Vráti job id + JSON draft; žiadny Stripe/billing.
 */
class ReportController extends Controller
{
    public function __construct(
        protected RiskService $risk,
        protected GraphService $graph,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ico' => ['required', 'string', 'regex:/^\d{8}$/'],
        ]);

        $ico = Normalizer::ico($validated['ico']);
        if ($ico === null) {
            return response()->json([
                'message' => 'Neplatné IČO.',
                'data' => null,
            ], 422);
        }

        $risk = $this->risk->assess($ico);
        $graph = $this->graph->companyGraph($ico, 1);

        $draft = [
            'type' => 'due-diligence-draft',
            'company' => [
                'ico' => $ico,
            ],
            'risk' => $risk,
            'graph_summary' => $graph['counts'],
            'sections' => [
                ['id' => 'identity', 'title' => 'Identifikácia subjektu', 'status' => 'stub'],
                ['id' => 'statutory', 'title' => 'Štatutárne orgány a prepojenia', 'status' => 'stub'],
                ['id' => 'ownership', 'title' => 'Vlastnícka štruktúra', 'status' => 'stub'],
                ['id' => 'risk_flags', 'title' => 'Rizikové indikátory', 'status' => 'computed', 'payload' => $risk['flags']],
            ],
        ];

        $job = ReportJob::create([
            'id' => (string) Str::uuid(),
            'ico' => $ico,
            'status' => 'draft',
            'draft' => $draft,
        ]);

        return response()->json([
            'data' => [
                'job_id' => $job->id,
                'status' => $job->status,
                'ico' => $ico,
                'draft' => $draft,
            ],
            'meta' => [
                'retrieved_at' => now()->toIso8601String(),
                'disclaimer' => (string) config('whoiswho.disclaimer'),
                'note' => 'Stub endpoint — platené DD reporty a billing prídu v neskoršej fáze.',
            ],
        ], 202);
    }
}
