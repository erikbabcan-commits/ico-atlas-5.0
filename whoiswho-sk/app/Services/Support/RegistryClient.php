<?php

namespace App\Services\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RegistryClient
{
    public function __construct(
        protected CircuitBreaker $breaker,
    ) {}

    /**
     * GET s timeoutom, retry s exponenciálnym backoffom a circuit breakerom.
     *
     * @return array{ok: bool, status: int|null, body: string, json: array|null, url: string}
     */
    public function get(string $source, string $url, int $retries = 2, ?callable $sleeper = null): array
    {
        $sleep = $sleeper ?? static fn (int $ms) => usleep($ms * 1000);

        if ($this->breaker->isOpen($source)) {
            return ['ok' => false, 'status' => null, 'body' => '', 'json' => null, 'url' => $url, 'reason' => 'circuit_open'];
        }

        $attempt = 0;
        $timeout = (int) config('whoiswho.http_timeout', 12);

        while (true) {
            $attempt++;
            try {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->get($url);

                if ($response->successful()) {
                    $this->breaker->recordSuccess($source);

                    return [
                        'ok' => true,
                        'status' => $response->status(),
                        'body' => $response->body(),
                        'json' => $response->json(),
                        'url' => $url,
                    ];
                }

                if ($response->status() >= 500 && $attempt <= $retries) {
                    $sleep(100 * (2 ** $attempt));
                    continue;
                }

                if ($response->status() >= 500) {
                    $this->breaker->recordFailure(
                        $source,
                        (int) config('whoiswho.orsr_scrape.circuit_failure_threshold', 5),
                        (int) config('whoiswho.orsr_scrape.circuit_reset_seconds', 900)
                    );
                }

                return ['ok' => false, 'status' => $response->status(), 'body' => $response->body(), 'json' => null, 'url' => $url];
            } catch (\Throwable $e) {
                if ($attempt <= $retries) {
                    $sleep(100 * (2 ** $attempt));
                    continue;
                }

                Log::channel('whoiswho')->warning('registry.client.error', [
                    'source' => $source,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                $this->breaker->recordFailure(
                    $source,
                    (int) config('whoiswho.orsr_scrape.circuit_failure_threshold', 5),
                    (int) config('whoiswho.orsr_scrape.circuit_reset_seconds', 900)
                );

                return ['ok' => false, 'status' => null, 'body' => '', 'json' => null, 'url' => $url];
            }
        }
    }
}
