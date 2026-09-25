<?php

namespace App\Services\Sources;

use App\Services\Support\RegistryClient;
use Illuminate\Support\Facades\Cache;

/**
 * LEGACY FALLBACK (P3): HTML scrape www.orsr.sk — starý portál bez verejného API.
 *
 * Pravidlá (povinné):
 *  - defaultne VYPNUTÝ (WHOISWHO_ORSR_SCRAPE_ENABLED=false)
 *  - backend only, nikdy z browsera
 *  - cache TTL >= 24h
 *  - max 1 req/s (min_interval_seconds)
 *  - exponential backoff + circuit breaker (zdedené z RegistryClient)
 *
 * Používa sa len keď RPO/RÚZ zlyhajú a endpoint je explicitne zapnutý.
 */
class OrsrScrapeProvider
{
    public const SOURCE = 'orsr_scrape';

    public function __construct(
        protected RegistryClient $client,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('whoiswho.orsr_scrape.enabled', false);
    }

    public function sourceUrl(string $ico): string
    {
        return config('whoiswho.orsr_scrape.search_url') . '?ICO=' . $ico;
    }

    /**
     * @return array{
     *   ico: string,
     *   name: string|null,
     *   seat: string|null,
     *   street: string|null,
     *   city: string|null,
     *   zip: string|null,
     *   legal_form: string|null,
     *   source: string,
     *   source_url: string,
     * }|null
     */
    public function fetchByIco(string $ico): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $cacheKey = 'whoiswho:orsr:' . $ico;
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $this->throttle();

        $searchUrl = (string) config('whoiswho.orsr_scrape.search_url');
        $search = $this->client->get(
            self::SOURCE,
            $searchUrl . '?ICO=' . urlencode($ico),
            (int) config('whoiswho.orsr_scrape.max_retries', 3)
        );

        if (!$search['ok']) {
            return null;
        }

        $detailPath = $this->extractDetailPath($search['body']);
        if ($detailPath === null) {
            return null;
        }

        $this->throttle();

        $baseUrl = rtrim((string) config('whoiswho.orsr_scrape.base_url'), '/');
        $detail = $this->client->get(
            self::SOURCE,
            $baseUrl . '/' . ltrim($detailPath, '/'),
            (int) config('whoiswho.orsr_scrape.max_retries', 3)
        );

        if (!$detail['ok']) {
            return null;
        }

        $parsed = $this->parseDetailHtml($detail['body']);
        if ($parsed === null) {
            return null;
        }

        $result = array_merge($parsed, [
            'ico' => $ico,
            'source' => self::SOURCE,
            'source_url' => $detail['url'],
        ]);

        $ttlHours = max(24, (int) config('whoiswho.orsr_scrape.cache_ttl_hours', 24));
        Cache::put($cacheKey, $result, now()->addHours($ttlHours));

        return $result;
    }

    protected function throttle(): void
    {
        $minInterval = (float) config('whoiswho.orsr_scrape.min_interval_seconds', 1);
        $lastKey = 'whoiswho:orsr:last_request';
        $last = (float) Cache::get($lastKey, 0);
        $elapsed = microtime(true) - $last;

        if ($elapsed < $minInterval) {
            usleep((int) (($minInterval - $elapsed) * 1_000_000));
        }

        Cache::put($lastKey, microtime(true), now()->addMinutes(10));
    }

    protected function extractDetailPath(string $html): ?string
    {
        if (preg_match('/href="(vypis\.asp\?ID=[^"]+)"/i', $html, $m)) {
            return html_entity_decode($m[1]);
        }

        return null;
    }

    /**
     * @return array{name: string|null, seat: string|null, street: string|null, city: string|null, zip: string|null, legal_form: string|null}|null
     */
    protected function parseDetailHtml(string $html): ?array
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));

        $name = null;
        if (preg_match('/Obchodné meno:\s*(.+?)\s*;/u', $text, $m)) {
            $name = trim($m[1]);
        }

        $seat = null;
        if (preg_match('/Sídlo:\s*(.+?)\s*;/u', $text, $m)) {
            $seat = trim($m[1]);
        }

        if ($name === null && $seat === null) {
            return null;
        }

        $street = null;
        $city = null;
        $zip = null;

        if ($seat !== null) {
            if (preg_match('/(\d{3}\s?\d{2})/u', $seat, $mZip)) {
                $zip = str_replace(' ', '', $mZip[1]);
                $beforeZip = trim(substr($seat, 0, strpos($seat, $mZip[0])));
                $parts = preg_split('/\s+/', $beforeZip) ?: [];
                if (count($parts) >= 2) {
                    $city = array_pop($parts);
                    $street = trim(implode(' ', $parts));
                } else {
                    $city = $beforeZip;
                }
            } else {
                $street = $seat;
            }
        }

        $legalForm = null;
        if ($name !== null && preg_match('/\b(a\.s\.|s\. r\. o\.|spol\. s r\. o\.|v\. o\. s\.|k\. s\.)\b/iu', $name, $mLf)) {
            $legalForm = trim($mLf[1]);
        }

        return [
            'name' => $name,
            'seat' => $seat,
            'street' => $street,
            'city' => $city,
            'zip' => $zip,
            'legal_form' => $legalForm,
        ];
    }
}
