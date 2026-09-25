<?php

namespace App\Services\Support;

use Illuminate\Support\Facades\Cache;

class CircuitBreaker
{
    public function isOpen(string $name): bool
    {
        return (bool) Cache::get("cb:{$name}:open", false);
    }

    public function recordFailure(string $name, int $threshold, int $resetSeconds): void
    {
        $key = "cb:{$name}:failures";
        $failures = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $failures, now()->addSeconds($resetSeconds));

        if ($failures >= $threshold) {
            Cache::put("cb:{$name}:open", true, now()->addSeconds($resetSeconds));
            Cache::forget($key);
        }
    }

    public function recordSuccess(string $name): void
    {
        Cache::forget("cb:{$name}:failures");
        Cache::put("cb:{$name}:open", false, now()->addMinutes(30));
    }
}
