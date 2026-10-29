<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Edge;
use App\Models\Person;
use App\Services\Sources\OrsrScrapeProvider;
use App\Services\Sources\RpoProvider;
use App\Services\Sources\RpvsProvider;
use App\Services\Sources\RuzProvider;
use App\Services\Support\Normalizer;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrator: paralelné volania RPO + RÚZ + RPVS (+ ORSR scrape ako P3 fallback),
 * konsolidácia profilu, perzistencia nodes/edges.
 */
class IngestOrchestrator
{
    public function __construct(
        protected RpoProvider $rpo,
        protected RuzProvider $ruz,
        protected RpvsProvider $rpvs,
        protected OrsrScrapeProvider $orsrScrape,
    ) {}

    /**
     * @return array{
     *   company: array<string, mixed>,
     *   sources: list<string>,
     *   source_urls: list<string>,
     *   retrieved_at: string,
     * }|null
     */
    public function ingest(string $ico): ?array
    {
        $ico = Normalizer::ico($ico);
        if ($ico === null) {
            return null;
        }

        $retrievedAt = now();

        [$rpoData, $ruzData, $rpvsData] = $this->fetchParallel($ico);

        if ($rpoData === null && $ruzData === null && $rpvsData === null) {
            $scrape = $this->orsrScrape->fetchByIco($ico);
            if ($scrape !== null) {
                return $this->persistScrapeOnly($scrape, $retrievedAt);
            }

            return null;
        }

        $company = $this->consolidate($ico, $rpoData, $ruzData);
        $sources = [];
        $sourceUrls = [];

        foreach ([$rpoData, $ruzData, $rpvsData] as $data) {
            if ($data !== null) {
                $sources[] = $data['source'];
                $sourceUrls[] = $data['source_url'];
            }
        }

        $company['sources'] = $sources;
        $company['source_urls'] = $sourceUrls;
        $company['retrieved_at'] = $retrievedAt->toIso8601String();

        $model = $this->persistCompany($ico, $company, $retrievedAt);

        $this->persistGraph($model, $rpoData, $rpvsData, $retrievedAt);

        return [
            'company' => $model->toArray() + [
                'source_urls' => $sourceUrls,
                'is_public_sector_partner' => $rpvsData !== null,
            ],
            'sources' => $sources,
            'source_urls' => $sourceUrls,
            'retrieved_at' => $retrievedAt->toIso8601String(),
        ];
    }

    /**
     * @return array{0: array|null, 1: array|null, 2: array|null}
     */
    protected function fetchParallel(string $ico): array
    {
        // HTTP fázy sú sekvenčné v rámci jedného requestu (Laravel HTTP client
        // nemá natívny paralelizmus), ale zdroje sú nezávislé — zlyhanie
        // jedného nebrání ostatným.
        $rpo = null;
        $ruz = null;
        $rpvs = null;

        try {
            $rpo = $this->rpo->fetchByIco($ico);
        } catch (\Throwable $e) {
            Log::channel('whoiswho')->warning('orchestrator.rpo_failed', ['ico' => $ico, 'error' => $e->getMessage()]);
        }

        try {
            $ruz = $this->ruz->fetchByIco($ico);
        } catch (\Throwable $e) {
            Log::channel('whoiswho')->warning('orchestrator.ruz_failed', ['ico' => $ico, 'error' => $e->getMessage()]);
        }

        try {
            $rpvs = $this->rpvs->fetchByIco($ico);
        } catch (\Throwable $e) {
            Log::channel('whoiswho')->warning('orchestrator.rpvs_failed', ['ico' => $ico, 'error' => $e->getMessage()]);
        }

        return [$rpo, $ruz, $rpvs];
    }

    protected function consolidate(string $ico, ?array $rpo, ?array $ruz): array
    {
        $company = [
            'ico' => $ico,
            'name' => $rpo['name'] ?? $ruz['name'] ?? null,
            'legal_form' => $rpo['legal_form'] ?? null,
            'legal_form_code' => $rpo['legal_form_code'] ?? $ruz['legal_form_code'] ?? null,
            'status' => $rpo['status'] ?? ($ruz !== null && empty($ruz['terminated']) ? 'Aktívna' : null),
            'street' => $rpo['street'] ?? $ruz['street'] ?? null,
            'municipality' => $rpo['municipality'] ?? $ruz['municipality'] ?? null,
            'postal_code' => $rpo['postal_code'] ?? $ruz['postal_code'] ?? null,
            'dic' => $ruz['dic'] ?? null,
            'established_on' => $rpo['established_on'] ?? $ruz['established_on'] ?? null,
            'terminated_on' => $rpo['terminated_on'] ?? null,
        ];

        $company['name_norm'] = $company['name'] !== null ? Normalizer::slug($company['name']) : null;
        $company['seat_norm'] = Normalizer::seatNorm($company['street'], $company['municipality'], $company['postal_code']);
        $company['sk_nace'] = $ruz['sk_nace'] ?? null;
        $company['consolidated'] = $ruz['consolidated'] ?? null;

        return $company;
    }

    protected function persistCompany(string $ico, array $company, $retrievedAt): Company
    {
        $model = Company::updateOrCreate(
            ['ico' => $ico],
            [
                'name' => $company['name'],
                'name_norm' => $company['name_norm'],
                'status' => $company['status'],
                'legal_form' => $company['legal_form'],
                'legal_form_code' => $company['legal_form_code'],
                'seat_norm' => $company['seat_norm'],
                'street' => $company['street'],
                'municipality' => $company['municipality'],
                'postal_code' => $company['postal_code'],
                'dic' => $company['dic'],
                'established_on' => $company['established_on'],
                'terminated_on' => $company['terminated_on'],
                'raw' => $company,
                'sources' => $company['sources'],
                'retrieved_at' => $retrievedAt,
            ]
        );

        return $model;
    }

    protected function persistGraph(Company $company, ?array $rpo, ?array $rpvs, $retrievedAt): void
    {
        if ($rpo !== null) {
            foreach ($rpo['statutory'] as $sb) {
                $person = $this->ensurePerson($sb['given'], $sb['family']);
                $this->upsertEdge(
                    fromType: Edge::TYPE_COMPANY,
                    fromId: $company->ico,
                    toType: Edge::TYPE_PERSON,
                    toId: (string) $person->id,
                    edgeType: Edge::EDGE_STATUTORY,
                    source: 'rpo',
                    sourceUrl: $rpo['source_url'],
                    validFrom: $sb['valid_from'],
                    validTo: $sb['valid_to'],
                    retrievedAt: $retrievedAt,
                    payload: ['role' => $sb['type']],
                    confidence: 0.95
                );
            }

            foreach ($rpo['shareholders'] as $sh) {
                if ($sh['ico'] !== null && Normalizer::ico($sh['ico']) !== null) {
                    $this->upsertEdge(
                        fromType: Edge::TYPE_COMPANY,
                        fromId: Normalizer::ico($sh['ico']),
                        toType: Edge::TYPE_COMPANY,
                        toId: $company->ico,
                        edgeType: Edge::EDGE_SHAREHOLDER,
                        source: 'rpo',
                        sourceUrl: $rpo['source_url'],
                        validFrom: $sh['valid_from'],
                        validTo: $sh['valid_to'],
                        retrievedAt: $retrievedAt,
                        payload: ['name' => $sh['company'], 'type' => $sh['type']],
                        confidence: 0.9
                    );
                } elseif ($sh['family'] !== null) {
                    $person = $this->ensurePerson($sh['given'] ?? '', $sh['family']);
                    $this->upsertEdge(
                        fromType: Edge::TYPE_PERSON,
                        fromId: (string) $person->id,
                        toType: Edge::TYPE_COMPANY,
                        toId: $company->ico,
                        edgeType: Edge::EDGE_SHAREHOLDER,
                        source: 'rpo',
                        sourceUrl: $rpo['source_url'],
                        validFrom: $sh['valid_from'],
                        validTo: $sh['valid_to'],
                        retrievedAt: $retrievedAt,
                        payload: ['type' => $sh['type']],
                        confidence: 0.9
                    );
                }
            }

            foreach ($rpo['deposits'] as $dep) {
                $person = Person::where('name_norm', $dep['person_norm'])->first();
                if ($person !== null) {
                    $this->upsertEdge(
                        fromType: Edge::TYPE_PERSON,
                        fromId: (string) $person->id,
                        toType: Edge::TYPE_COMPANY,
                        toId: $company->ico,
                        edgeType: Edge::EDGE_SHAREHOLDER,
                        source: 'rpo',
                        sourceUrl: $rpo['source_url'],
                        retrievedAt: $retrievedAt,
                        payload: ['deposit_amount' => $dep['amount'], 'currency' => $dep['currency']],
                        confidence: 0.85
                    );
                }
            }

            foreach ($rpo['predecessors'] as $p) {
                if ($p['ico'] !== null) {
                    $this->upsertEdge(
                        fromType: Edge::TYPE_COMPANY,
                        fromId: $p['ico'],
                        toType: Edge::TYPE_COMPANY,
                        toId: $company->ico,
                        edgeType: Edge::EDGE_PREDECESSOR,
                        source: 'rpo',
                        sourceUrl: $rpo['source_url'],
                        retrievedAt: $retrievedAt,
                        payload: ['name' => $p['name']],
                        confidence: 0.9
                    );
                }
            }

            foreach ($rpo['successors'] as $s) {
                if ($s['ico'] !== null) {
                    $this->upsertEdge(
                        fromType: Edge::TYPE_COMPANY,
                        fromId: $company->ico,
                        toType: Edge::TYPE_COMPANY,
                        toId: $s['ico'],
                        edgeType: Edge::EDGE_SUCCESSOR,
                        source: 'rpo',
                        sourceUrl: $rpo['source_url'],
                        retrievedAt: $retrievedAt,
                        payload: ['name' => $s['name']],
                        confidence: 0.9
                    );
                }
            }
        }

        if ($rpvs !== null) {
            foreach ($rpvs['konecni_uzivatelia'] as $ku) {
                $given = $ku['given'];
                $family = $ku['family'];

                if ($family === null && ($ku['ico'] ?? null) !== null) {
                    $this->upsertEdge(
                        fromType: Edge::TYPE_COMPANY,
                        fromId: $ku['ico'],
                        toType: Edge::TYPE_COMPANY,
                        toId: $company->ico,
                        edgeType: Edge::EDGE_UBO,
                        source: 'rpvs',
                        sourceUrl: $rpvs['source_url'],
                        validFrom: isset($ku['valid_from']) ? substr($ku['valid_from'], 0, 10) : null,
                        validTo: isset($ku['valid_to']) ? substr($ku['valid_to'], 0, 10) : null,
                        retrievedAt: $retrievedAt,
                        payload: ['company' => $ku['company']],
                        confidence: 0.95
                    );
                    continue;
                }

                if ($family !== null) {
                    $person = $this->ensurePerson($given ?? '', $family, $ku['born_on'] ?? null);
                    $this->upsertEdge(
                        fromType: Edge::TYPE_PERSON,
                        fromId: (string) $person->id,
                        toType: Edge::TYPE_COMPANY,
                        toId: $company->ico,
                        edgeType: Edge::EDGE_UBO,
                        source: 'rpvs',
                        sourceUrl: $rpvs['source_url'],
                        validFrom: isset($ku['valid_from']) ? substr($ku['valid_from'], 0, 10) : null,
                        validTo: isset($ku['valid_to']) ? substr($ku['valid_to'], 0, 10) : null,
                        retrievedAt: $retrievedAt,
                        payload: ['je_verejny_cinitel' => true],
                        confidence: 0.95
                    );
                }
            }
        }
    }

    protected function persistScrapeOnly(array $scrape, $retrievedAt): array
    {
        $ico = $scrape['ico'];
        $seatNorm = Normalizer::seatNorm($scrape['street'] ?? null, $scrape['city'] ?? null, $scrape['zip'] ?? null);

        $model = Company::updateOrCreate(
            ['ico' => $ico],
            [
                'name' => $scrape['name'],
                'name_norm' => $scrape['name'] !== null ? Normalizer::slug($scrape['name']) : null,
                'legal_form' => $scrape['legal_form'],
                'seat_norm' => $seatNorm,
                'street' => $scrape['street'] ?? null,
                'municipality' => $scrape['city'] ?? null,
                'postal_code' => $scrape['zip'] ?? null,
                'raw' => $scrape,
                'sources' => [$scrape['source']],
                'retrieved_at' => $retrievedAt,
            ]
        );

        return [
            'company' => $model->toArray() + ['source_urls' => [$scrape['source_url']]],
            'sources' => [$scrape['source']],
            'source_urls' => [$scrape['source_url']],
            'retrieved_at' => $retrievedAt->toIso8601String(),
        ];
    }

    protected function ensurePerson(?string $given, ?string $family, ?string $bornOn = null): Person
    {
        $nameNorm = Normalizer::personNameNorm($given ?? 'unknown', $family ?? 'unknown');

        return Person::updateOrCreate(
            ['name_norm' => $nameNorm],
            array_filter([
                'full_name' => trim(($given ?? '') . ' ' . ($family ?? '')) ?: null,
                'given_name' => $given,
                'family_name' => $family,
                'born_on' => $bornOn,
            ])
        );
    }

    protected function upsertEdge(
        string $fromType,
        string $fromId,
        string $toType,
        string $toId,
        string $edgeType,
        string $source,
        ?string $sourceUrl,
        $retrievedAt,
        ?string $validFrom = null,
        ?string $validTo = null,
        array $payload = [],
        float $confidence = 1.0
    ): void {
        Edge::updateOrCreate(
            [
                'from_type' => $fromType,
                'from_id' => $fromId,
                'to_type' => $toType,
                'to_id' => $toId,
                'edge_type' => $edgeType,
                'source' => $source,
            ],
            [
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
                'source_url' => $sourceUrl,
                'retrieved_at' => $retrievedAt,
                'raw_hash' => hash('sha256', $fromType . '|' . $fromId . '|' . $toType . '|' . $toId . '|' . $edgeType . '|' . $source),
                'confidence' => $confidence,
                'payload' => $payload,
            ]
        );
    }
}
