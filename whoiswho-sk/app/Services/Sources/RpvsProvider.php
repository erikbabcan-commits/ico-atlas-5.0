<?php

namespace App\Services\Sources;

use App\Services\Support\RegistryClient;

/**
 * Register partnerov verejného sektora (RPVS) — Open Data v2 OData.
 * Flow: /PartneriVerejnehoSektora?$filter=Ico eq '{ico}' → CisloVlozky
 *       → /Partneri({id})?$expand=* (KonecniUzivateliaVyhod, OpravneneOsoby, ...)
 */
class RpvsProvider
{
    public const SOURCE = 'rpvs';

    public function __construct(
        protected RegistryClient $client,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('whoiswho.rpvs.enabled', true);
    }

    public function sourceUrl(string $ico): string
    {
        return $this->baseUrl() . "/PartneriVerejnehoSektora?\$filter=Ico%20eq%20'" . urlencode($ico) . "'";
    }

    /**
     * @return array{
     *   ico: string,
     *   vlozky: list<int>,
     *   is_partner: bool,
     *   konecni_uzivatelia: list<array{given:string,family:string,born_on:string|null,valid_from:string|null,valid_to:string|null}>,
     *   opravnene_osoby: list<array{...}>,
     *   source: string,
     *   source_url: string,
     * }|null
     */
    public function fetchByIco(string $ico): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $filterUrl = $this->baseUrl() . "/PartneriVerejnehoSektora?\$filter=" . urlencode("Ico eq '{$ico}'");
        $filter = $this->client->get(self::SOURCE, $filterUrl);

        if (!$filter['ok']) {
            return null;
        }

        $entries = $filter['json']['value'] ?? [];
        if ($entries === []) {
            return null;
        }

        $vlozky = array_values(array_unique(array_filter(array_column($entries, 'CisloVlozky'))));
        $uzivatelia = [];
        $oprawnene = [];

        foreach ($vlozky as $vlozka) {
            $detailUrl = $this->baseUrl() . "/Partneri({$vlozka})?\$expand=*";
            $detail = $this->client->get(self::SOURCE, $detailUrl);

            if (!$detail['ok']) {
                continue;
            }

            $partner = $detail['json'];

            foreach ((array) ($partner['KonecniUzivateliaVyhod'] ?? []) as $ku) {
                if (empty($ku['Priezvisko']) && empty($ku['ObchodneMeno'])) {
                    continue;
                }
                $uzivatelia[] = [
                    'given' => $ku['Meno'] ?? null,
                    'family' => $ku['Priezvisko'] ?? null,
                    'company' => $ku['ObchodneMeno'] ?? null,
                    'ico' => $ku['Ico'] ?? null,
                    'born_on' => $ku['DatumNarodenia'] ?? null,
                    'valid_from' => $ku['PlatnostOd'] ?? null,
                    'valid_to' => $ku['PlatnostDo'] ?? null,
                ];
            }

            foreach ((array) ($partner['OpravneneOsoby'] ?? []) as $oo) {
                if (empty($oo['Priezvisko']) && empty($oo['ObchodneMeno'])) {
                    continue;
                }
                $oprawnene[] = [
                    'given' => $oo['Meno'] ?? null,
                    'family' => $oo['Priezvisko'] ?? null,
                    'company' => $oo['ObchodneMeno'] ?? null,
                    'ico' => $oo['Ico'] ?? null,
                    'valid_from' => $oo['PlatnostOd'] ?? null,
                    'valid_to' => $oo['PlatnostDo'] ?? null,
                ];
            }
        }

        return [
            'ico' => $ico,
            'vlozky' => $vlozky,
            'is_partner' => true,
            'konecni_uzivatelia' => $uzivatelia,
            'opravnene_osoby' => $oprawnene,
            'source' => self::SOURCE,
            'source_url' => $filterUrl,
        ];
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('whoiswho.rpvs.base_url'), '/');
    }
}
