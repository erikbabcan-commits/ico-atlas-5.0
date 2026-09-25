<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer service API key auth. Health endpoint je verejný.
 */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('whoiswho.api_key');

        if ($expected === '') {
            return response()->json([
                'message' => 'API key is not configured. Set WHOISWHO_API_KEY.',
            ], 503);
        }

        $token = $request->bearerToken();

        if ($token === null || !hash_equals($expected, $token)) {
            return response()->json([
                'message' => 'Invalid or missing API key.',
            ], 401);
        }

        return $next($request);
    }
}
