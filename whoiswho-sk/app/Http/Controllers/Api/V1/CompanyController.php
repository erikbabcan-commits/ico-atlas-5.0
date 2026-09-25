<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Services\IngestOrchestrator;
use App\Services\Support\Normalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function __construct(
        protected IngestOrchestrator $orchestrator,
    ) {}

    public function show(Request $request, string $ico): JsonResponse
    {
        $ico = Normalizer::ico($ico);
        if ($ico === null) {
            return $this->error($ico ?? '', 'IČO musí mať presne 8 číslic.', 422, $request);
        }

        $existing = $this->freshCompany($ico);

        if ($existing !== null) {
            return response()->json(
                $this->envelope($existing['company'], $existing['sources'], $existing['source_urls'], $existing['retrieved_at']),
                200
            );
        }

        $ingested = $this->orchestrator->ingest($ico);

        if ($ingested === null) {
            return $this->error($ico, 'Spoločnosť sa nenašla v žiadnom zdroji (RPO, RÚZ, RPVS).', 404, $request);
        }

        AuditLog::create([
            'ico' => $ico,
            'caller' => $request->header('X-Caller') ?? 'api',
            'http_status' => 200,
            'endpoint' => '/api/v1/companies/{ico}',
            'retrieved_at' => now(),
            'raw_hash' => hash('sha256', json_encode($ingested['company'])),
        ]);

        return response()->json(
            $this->envelope($ingested['company'], $ingested['sources'], $ingested['source_urls'], $ingested['retrieved_at']),
            200
        );
    }

    protected function freshCompany(string $ico): ?array
    {
        $company = Company::find($ico);
        if ($company === null) {
            return null;
        }

        $ttlHours = (int) config('whoiswho.cache_ttl_hours', 24);
        if ($company->retrieved_at !== null && $company->retrieved_at->lt(now()->subHours($ttlHours))) {
            return null;
        }

        $sources = (array) ($company->sources ?? []);
        $sourceUrls = [];
        $raw = (array) ($company->raw ?? []);
        if (isset($raw['source_urls']) && is_array($raw['source_urls'])) {
            $sourceUrls = $raw['source_urls'];
        }

        return [
            'company' => $company->toArray() + ['source_urls' => $sourceUrls],
            'sources' => $sources,
            'source_urls' => $sourceUrls,
            'retrieved_at' => $company->retrieved_at?->toIso8601String(),
        ];
    }

    protected function envelope(array $company, array $sources, array $sourceUrls, ?string $retrievedAt): array
    {
        return [
            'data' => $company,
            'meta' => [
                'sources' => $sources,
                'source_url' => $sourceUrls,
                'retrieved_at' => $retrievedAt ?? now()->toIso8601String(),
                'disclaimer' => (string) config('whoiswho.disclaimer'),
            ],
        ];
    }

    protected function error(string $ico, string $message, int $status, Request $request): JsonResponse
    {
        AuditLog::create([
            'ico' => $ico !== '' ? $ico : null,
            'caller' => $request->header('X-Caller') ?? 'api',
            'http_status' => $status,
            'endpoint' => '/api/v1/companies/{ico}',
            'retrieved_at' => now(),
            'meta' => ['message' => $message],
        ]);

        $body = [
            'message' => $message,
            'data' => null,
        ];

        if ($status !== 404) {
            $body['meta']['disclaimer'] = (string) config('whoiswho.disclaimer');
        }

        return response()->json($body, $status);
    }
}
