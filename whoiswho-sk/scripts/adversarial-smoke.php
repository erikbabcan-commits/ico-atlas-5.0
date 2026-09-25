#!/usr/bin/env php
<?php
/*
 * Adversarial smoke test pre WhoIsWho SK API.
 * Pouzitie: WHOISWHO_API_KEY=... php scripts/adversarial-smoke.php [base-url]
 * Predvoleny base URL: http://localhost:8000/api/v1
 *
 * Testy: auth, validacia IČO, 404 vs ghost, CORS, security headers,
 * rate limit, struktura profilu/grafu/risk, determinizmus risk vypoctu.
 * Vrati exit code 0 ak vsetko PASS, 1 ak aspon jeden FAIL.
 */

$base = $argv[1] ?? getenv('WHOISWHO_BASE_URL') ?: 'http://localhost:8000/api/v1';
$key  = getenv('WHOISWHO_API_KEY') ?: '';
$exit = 0;
$n = 0;

function call(string $url, ?string $key = null, string $method = 'GET', ?string $body = null): array
{
    $h = "Accept: application/json\r\n";
    if ($key !== null) {
        $h .= "Authorization: Bearer $key\r\n";
    }
    if ($body !== null) {
        $h .= "Content-Type: application/json\r\n";
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => $h, 'timeout' => 90, 'ignore_errors' => true, 'content' => $body,
    ]]);
    $t = microtime(true);
    $r = @file_get_contents($url, false, $ctx);
    $ms = round((microtime(true) - $t) * 1000);
    $code = 0;
    foreach ($http_response_header ?? [] as $l) {
        if (preg_match('#HTTP/\S+\s+(\d+)#', $l, $m)) {
            $code = (int) $m[1];
        }
    }
    return ['code' => $code, 'ms' => $ms, 'json' => json_decode($r, true), 'raw' => $r, 'headers' => $http_response_header ?? []];
}

function check(string $name, bool $cond, string $info = ''): void
{
    global $n, $exit;
    $n++;
    echo ($cond ? 'PASS' : 'FAIL') . " | $name" . ($info ? " | $info" : '') . "\n";
    if (!$cond) {
        $exit = 1;
    }
}

if ($key === '') {
    fwrite(STDERR, "Chyba: nastav WHOISWHO_API_KEY env premennu\n");
    exit(2);
}

// --- 1. Health ---
$r = call("$base/health");
check('health 200', $r['code'] === 200, $r['ms'] . 'ms');
check('health db ok', ($r['json']['database'] ?? '') === 'ok');

// --- 2. Auth ---
$r = call("$base/companies/31333532");
check('bez auth 401', $r['code'] === 401, 'got ' . $r['code']);
$r = call("$base/companies/31333532", 'zly-kluc');
check('zlý kľúč 401', $r['code'] === 401, 'got ' . $r['code']);

// --- 3. Validácia IČO ---
foreach ([['1234', 422], ['313335320', 422], ['3133353X', 422]] as [$ico, $want]) {
    $r = call("$base/companies/$ico", $key);
    check("invalid ico $ico -> $want", $r['code'] === $want, 'got ' . $r['code']);
}

// --- 4. Neexistujúce IČO -> 404, nie ghost 200 ---
$r = call("$base/companies/76543210", $key);
check('neexistujúce IČO 404', $r['code'] === 404, 'got ' . $r['code']);

// --- 5. Profil ESET (konsolidácia + meta) ---
$r = call("$base/companies/31333532", $key);
check('ESET profil 200', $r['code'] === 200, $r['ms'] . 'ms');
$d = $r['json']['data'] ?? [];
check('ESET DIČ z RÚZ', ($d['dic'] ?? '') === '2020317068');
check('meta disclaimer', !empty($r['json']['meta']['disclaimer']));
check('meta source_url[]', !empty($r['json']['meta']['source_url']) && is_array($r['json']['meta']['source_url']));
check('meta retrieved_at', !empty($r['json']['meta']['retrieved_at']));

// --- 6. Graf ---
$r = call("$base/companies/31333532/graph?depth=1", $key);
$g = $r['json']['data'] ?? [];
check('graph 200 + nodes', $r['code'] === 200 && count($g['nodes'] ?? []) > 0, count($g['nodes'] ?? []) . ' nodes');
check('graph edges > 0', count($g['edges'] ?? []) > 0, count($g['edges'] ?? []) . ' edges');
$types = [];
foreach (($g['edges'] ?? []) as $e) {
    $types[$e['type']] = 1;
}
check('graph STATUTORY edge', isset($types['STATUTORY']));
$r = call("$base/companies/31333532/graph?depth=3", $key);
check('depth=3 -> 422', $r['code'] === 422, 'got ' . $r['code']);
$r = call("$base/companies/31333532/graph?depth=2", $key);
check('depth=2 -> 200', $r['code'] === 200, $r['code'] . ' ' . $r['ms'] . 'ms');

// --- 7. Risk ---
$r = call("$base/companies/31333532/risk", $key);
$ri = $r['json']['data'] ?? [];
check('risk 200 + score', $r['code'] === 200 && isset($ri['score']), 'score=' . ($ri['score'] ?? '?'));
check('risk flags array', is_array($ri['flags'] ?? null), count($ri['flags'] ?? []) . ' flags');
check('risk sources[]', !empty($ri['sources']) && is_array($ri['sources']));
$r2 = call("$base/companies/31333532/risk", $key);
check('risk deterministický', ($r2['json']['data']['score'] ?? 'a') === ($ri['score'] ?? 'b'));

// --- 8. DD stub ---
$r = call("$base/reports/due-diligence", $key, 'POST', json_encode(['ico' => '31333532']));
check('DD stub 202 + job_id', $r['code'] === 202 && !empty($r['json']['data']['job_id']), 'got ' . $r['code']);

// --- 9. Security headers ---
$r = call("$base/health");
$hdrs = implode("\n", $r['headers']);
check('bez X-Powered-By', stripos($hdrs, 'X-Powered-By') === false, stripos($hdrs, 'X-Powered-By') !== false ? 'ODHALENÉ PHP!' : '');
check('bez ACAO *', stripos($hdrs, 'Access-Control-Allow-Origin: *') === false, stripos($hdrs, 'Access-Control-Allow-Origin: *') !== false ? 'CORS otvorené pre celý internet!' : '');

// --- 10. Rate limit ---
$codes = [];
for ($i = 0; $i < 65; $i++) {
    $codes[] = call("$base/companies/31333532", $key)['code'];
}
$counts = array_count_values($codes);
check('rate limit 429 po 60/min', isset($counts[429]), json_encode($counts));

echo "\nDONE: $n checks, exit=$exit\n";
exit($exit);
