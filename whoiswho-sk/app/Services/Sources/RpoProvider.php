<?php

namespace App\Services\Sources;

use App\Services\Support\Normalizer;
use App\Services\Support\RegistryClient;

/**
 * Register právnických osôb, podnikateľov a orgánov verejnej moci (RPO).
 * Verejné REST API: https://api.statistics.sk/rpo/v1 (ŠÚSR / MV SR, cc-by 4.0).
 *
 * Flow: GET /search?identifier={ico} → id → GET /entity/{id}
 * Poskytuje: meno, právnu formu, sídlo, štatutárov (statutoryBodies),
 * zainteresované osoby (stakeholders), vklady (deposits), predchodcov/nástupcov.
 */
class RpoProvider
{
    public const SOURCE = 'rpo';

    public function __construct(
        protected RegistryClient $client,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('whoiswho.rpo.enabled', true);
    }

    public function sourceUrl(string $ico): string
    {
        return $this->baseUrl() . '/search?identifier=' . $ico;
    }

    /**
     * @return array{
     *   ico: string,
     *   rpo_id: int|null,
     *   name: string|null,
     *   legal_form: string|null,
     *   legal_form_code: string|null,
     *   status: string|null,
     *   established_on: string|null,
     *   terminated_on: string|null,
     *   street: string|null,
     *   municipality: string|null,
     *   postal_code: string|null,
     *   statutory: list<array{given:string,family:string,valid_from:string|null,valid_to:string|null,type:string|null}>,
     *   shareholders: list<array{given:string,family:string,ico:string|null,company:string|null,valid_from:string|null,valid_to:string|null,type:string|null}>,
     *   deposits: list<array{person_norm:string,amount:float|null,currency:string|null}>,
     *   predecessors: list<array{ico:string|null,name:string|null}>,
     *   successors: list<array{ico:string|null,name:string|null}>,
     *   source: string,
     *   source_url: string,
     * }|null
     */
    public function fetchByIco(string $ico): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $searchUrl = $this->baseUrl() . '/search?identifier=' . urlencode($ico);
        $search = $this->client->get(self::SOURCE, $searchUrl);

        if (!$search['ok']) {
            return null;
        }

        $results = $search['json']['results'] ?? [];
        if ($results === []) {
            return null;
        }

        $entityId = $results[0]['id'] ?? null;
        if ($entityId === null) {
            return null;
        }

        $entityUrl = $this->baseUrl() . '/entity/' . $entityId;
        $entity = $this->client->get(self::SOURCE, $entityUrl);

        if (!$entity['ok']) {
            return null;
        }

        $e = $entity['json'];

        $address = collect($e['addresses'] ?? [])
            ->first(fn ($a) => empty($a['validTo']) || $a['validTo'] === '' || $a['validTo'] >= date('Y-m-d'))
            ?? ($e['addresses'][0] ?? []);

        $statutory = [];
        foreach ((array) ($e['statutoryBodies'] ?? []) as $sb) {
            $given = $sb['personName']['givenNames'][0] ?? null;
            $family = $sb['personName']['familyNames'][0] ?? null;
            if ($given === null && $family === null) {
                continue;
            }
            $statutory[] = [
                'given' => (string) $given,
                'family' => (string) $family,
                'valid_from' => $sb['validFrom'] ?? null,
                'valid_to' => $sb['validTo'] ?? null,
                'type' => $sb['stakeholderType']['value'] ?? null,
            ];
        }

        $shareholders = [];
        foreach ((array) ($e['stakeholders'] ?? []) as $sh) {
            $given = $sh['personName']['givenNames'][0] ?? null;
            $family = $sh['personName']['familyNames'][0] ?? null;
            $companyIco = $sh['identifier'] ?? null;
            if ($given === null && $family === null && $companyIco === null) {
                continue;
            }
            $shareholders[] = [
                'given' => $given !== null ? (string) $given : null,
                'family' => $family !== null ? (string) $family : null,
                'ico' => $companyIco,
                'company' => $sh['fullName'] ?? null,
                'valid_from' => $sh['validFrom'] ?? null,
                'valid_to' => $sh['validTo'] ?? null,
                'type' => $sh['stakeholderType']['value'] ?? null,
            ];
        }

        $deposits = [];
        foreach ((array) ($e['deposits'] ?? []) as $d) {
            $given = $d['personName']['givenNames'][0] ?? null;
            $family = $d['personName']['familyNames'][0] ?? null;
            if ($given === null || $family === null) {
                continue;
            }
            $deposits[] = [
                'person_norm' => Normalizer::personNameNorm($given, $family),
                'amount' => isset($d['amount']) ? (float) $d['amount'] : null,
                'currency' => $d['currency']['value'] ?? null,
            ];
        }

        $predecessors = [];
        foreach ((array) ($e['predecessors'] ?? []) as $p) {
            $predecessors[] = [
                'ico' => isset($p['identifier']) ? Normalizer::ico($p['identifier']) : null,
                'name' => $p['fullName'] ?? null,
            ];
        }

        $successors = [];
        foreach ((array) ($e['successors'] ?? []) as $s) {
            $successors[] = [
                'ico' => isset($s['identifier']) ? Normalizer::ico($s['identifier']) : null,
                'name' => $s['fullName'] ?? null,
            ];
        }

        $legalForm = collect($e['legalForms'] ?? [])->last();
        $legalStatus = collect($e['legalStatuses'] ?? [])->last();

        return [
            'ico' => $ico,
            'rpo_id' => $entityId,
            'name' => collect($e['fullNames'] ?? [])->last()['value'] ?? $results[0]['fullNames'][0]['value'] ?? null,
            'legal_form' => $legalForm['value']['value'] ?? null,
            'legal_form_code' => $legalForm['value']['code'] ?? null,
            'status' => $legalStatus['value']['value'] ?? (empty($e['termination']) ? 'Aktívna' : 'Zaniknutá'),
            'established_on' => $e['establishment'] ?? null,
            'terminated_on' => $e['termination'] ?? null,
            'street' => $address['street'] ?? null,
            'municipality' => $address['municipality']['value'] ?? null,
            'postal_code' => $address['postalCodes'][0] ?? null,
            'statutory' => $statutory,
            'shareholders' => $shareholders,
            'deposits' => $deposits,
            'predecessors' => $predecessors,
            'successors' => $successors,
            'source' => self::SOURCE,
            'source_url' => $entityUrl,
        ];
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('whoiswho.rpo.base_url'), '/');
    }
}
