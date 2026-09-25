<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $dbOk = false;
        try {
            $dbOk = DB::select('select 1') !== [];
        } catch (\Throwable) {
            $dbOk = false;
        }

        return response()->json([
            'status' => 'ok',
            'service' => (string) config('whoiswho.name', 'WhoIsWho SK'),
            'version' => 'v1',
            'database' => $dbOk ? 'ok' : 'unavailable',
            'sources' => [
                'rpo' => (bool) config('whoiswho.rpo.enabled', true),
                'ruz' => (bool) config('whoiswho.ruz.enabled', true),
                'rpvs' => (bool) config('whoiswho.rpvs.enabled', true),
                'orsr_scrape_fallback' => (bool) config('whoiswho.orsr_scrape.enabled', false),
            ],
            'timestamp' => now()->toIso8601String(),
        ], 200);
    }
}
