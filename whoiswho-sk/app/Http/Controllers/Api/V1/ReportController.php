<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ReportJob;
use App\Services\Billing\StripeCheckout;
use App\Services\Graph\GraphService;
use App\Services\Reports\DueDiligencePdf;
use App\Services\Risk\RiskService;
use App\Services\Support\Normalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * POST /api/v1/reports/due-diligence      — vytvorí job + PDF (lite/full)
 * GET  /api/v1/reports/{jobId}            — status jobu
 * GET  /api/v1/reports/{jobId}/download   — stiahnutie PDF
 * POST /api/v1/reports/{jobId}/checkout   — Stripe checkout session (ak enabled)
 */
class ReportController extends Controller
{
    public function __construct(
        protected RiskService $risk,
        protected GraphService $graph,
        protected DueDiligencePdf $pdf,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ico' => ['required', 'string', 'regex:/^\d{8}$/'],
            'tier' => ['nullable', 'string', 'in:lite,full'],
        ]);

        $ico = Normalizer::ico($validated['ico']);
        $tier = $validated['tier'] ?? 'lite';

        if ($ico === null) {
            return response()->json(['message' => 'Neplatné IČO.', 'data' => null], 422);
        }

        if ($this->graph->companyGraph($ico, 1)['counts']['nodes'] === 0) {
            return response()->json(['message' => 'Spoločnosť sa nenašla v databáze. Najprv vykonajte GET /companies/{ico}.', 'data' => null], 404);
        }

        try {
            $result = $this->pdf->generate($ico);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => null], 404);
        }

        $disk = (string) config('whoiswho.reports.storage_disk', 'local');
        $path = "reports/{$ico}/" . Str::uuid() . '.pdf';
        Storage::disk($disk)->put($path, $result['pdf']);

        $risk = $this->risk->assess($ico);
        $graph = $this->graph->companyGraph($ico, 1);

        $job = ReportJob::create([
            'id' => (string) Str::uuid(),
            'ico' => $ico,
            'status' => 'ready',
            'draft' => [
                'type' => 'due-diligence-report',
                'tier' => $tier,
                'company' => ['ico' => $ico, 'name' => $result['company_name']],
                'risk' => $risk,
                'graph_summary' => $graph['counts'],
                'pdf' => [
                    'sha256' => $result['sha256'],
                    'size_bytes' => $result['size'],
                    'path' => $path,
                ],
            ],
        ]);

        return response()->json([
            'data' => [
                'job_id' => $job->id,
                'status' => $job->status,
                'ico' => $ico,
                'tier' => $tier,
                'pdf' => [
                    'sha256' => $result['sha256'],
                    'size_bytes' => $result['size'],
                ],
                'download_url' => "/api/v1/reports/{$job->id}/download",
            ],
            'meta' => [
                'retrieved_at' => now()->toIso8601String(),
                'disclaimer' => (string) config('whoiswho.disclaimer'),
            ],
        ], 201);
    }

    public function show(string $jobId): JsonResponse
    {
        $job = ReportJob::query()->where('id', $jobId)->first();

        if ($job === null) {
            return response()->json(['message' => 'Report job neexistuje.', 'data' => null], 404);
        }

        return response()->json([
            'data' => [
                'job_id' => $job->id,
                'status' => $job->status,
                'ico' => $job->ico,
                'draft' => $job->draft,
            ],
            'meta' => [
                'retrieved_at' => now()->toIso8601String(),
                'disclaimer' => (string) config('whoiswho.disclaimer'),
            ],
        ]);
    }

    public function download(string $jobId)
    {
        $job = ReportJob::query()->where('id', $jobId)->first();

        if ($job === null || empty($job->draft['pdf']['path'] ?? null)) {
            return response()->json(['message' => 'Report job neexistuje alebo nemá PDF.', 'data' => null], 404);
        }

        $disk = (string) config('whoiswho.reports.storage_disk', 'local');
        $path = $job->draft['pdf']['path'];

        if (! Storage::disk($disk)->exists($path)) {
            return response()->json(['message' => 'PDF súbor neexistuje na disku.', 'data' => null], 404);
        }

        $pdf = Storage::disk($disk)->get($path);
        $hash = hash('sha256', $pdf);

        if (isset($job->draft['pdf']['sha256']) && $hash !== $job->draft['pdf']['sha256']) {
            return response()->json(['message' => 'Integrita PDF sa nezhoduje (sha256 mismatch).', 'data' => null], 500);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="whoiswho-dd-' . $job->ico . '.pdf"',
            'X-Report-Sha256' => $hash,
        ]);
    }

    public function checkout(Request $request, string $jobId): JsonResponse
    {
        $job = ReportJob::query()->where('id', $jobId)->first();

        if ($job === null) {
            return response()->json(['message' => 'Report job neexistuje.', 'data' => null], 404);
        }

        $stripe = StripeCheckout::make();

        if ($stripe === null) {
            return response()->json([
                'message' => 'Billing nie je aktivovaný. Nastav WHOISWHO_STRIPE_ENABLED=true a WHOISWHO_STRIPE_SECRET.',
                'data' => null,
            ], 501);
        }

        $tier = $job->draft['tier'] ?? 'lite';
        $pricing = config("whoiswho.reports.pricing.{$tier}");

        if ($pricing === null) {
            return response()->json(['message' => "Neznámy tier '{$tier}'.", 'data' => null], 422);
        }

        $validated = $request->validate([
            'success_url' => ['required', 'url'],
            'cancel_url' => ['required', 'url'],
        ]);

        $session = $stripe->createSession(
            $job->ico,
            $tier,
            (int) $pricing['amount_cents'],
            (string) $pricing['currency'],
            $validated['success_url'],
            $validated['cancel_url'],
        );

        if (! $session['ok']) {
            return response()->json(['message' => 'Stripe checkout zlyhal.', 'error' => $session['error'], 'data' => null], 502);
        }

        $draft = $job->draft;
        $draft['stripe_session_id'] = $session['session_id'];
        $job->draft = $draft;
        $job->save();

        return response()->json([
            'data' => [
                'job_id' => $job->id,
                'checkout_url' => $session['url'],
                'session_id' => $session['session_id'],
                'amount_cents' => $pricing['amount_cents'],
                'currency' => $pricing['currency'],
            ],
        ]);
    }
}
