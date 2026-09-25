<?php

namespace App\Services\Sources;

use App\Services\Support\RegistryClient;

/**
 * Register účtovných závierok (RÚZ) — Open API, CC0.
 * Flow: /uctovne-jednotky?zmenene-od=2000-01-01&ico={ico} → id → /uctovna-jednotka?id=
 * Poskytuje: DIČ, SK NACE, veľkosť org., konzolidovanosť, id závieroek.
 */
class RuzProvider
{
    public const SOURCE = 'ruz';

    public function __construct(
        protected RegistryClient $client,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('whoiswho.ruz.enabled', true);
    }

    public function sourceUrl(string $ico): string
    {
        return $this->baseUrl() . '/uctovne-jednotky?zmenene-od=2000-01-01&ico=' . $ico;
    }

    /**
     * @return array{
     *   ico: string,
     *   ruz_id: int|null,
     *   dic: string|null,
     *   sk_nace: string|null,
     *   legal_form_code: string|null,
     *   size_code: string|null,
     *   consolidated: bool|null,
     *   established_on: string|null,
     *   street: string|null,
     *   municipality: string|null,
     *   postal_code: string|null,
     *   financial_statement_ids: list<int>,
     *   source: string,
     *   source_url: string,
     * }|null
     */
    public function fetchByIco(string $ico): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $listUrl = $this->baseUrl() . '/uctovne-jednotky?zmenene-od=2000-01-01&ico=' . urlencode($ico);
        $list = $this->client->get(self::SOURCE, $listUrl);

        if (!$list['ok']) {
            return null;
        }

        $ids = $list['json']['id'] ?? [];
        if ($ids === []) {
            return null;
        }

        $id = (int) $ids[0];
        $detailUrl = $this->baseUrl() . '/uctovna-jednotka?id=' . $id;
        $detail = $this->client->get(self::SOURCE, $detailUrl);

        if (!$detail['ok']) {
            return null;
        }

        $d = $detail['json'];

        return [
            'ico' => $ico,
            'ruz_id' => $id,
            'dic' => $d['dic'] ?? null,
            'sk_nace' => $d['skNace'] ?? null,
            'legal_form_code' => $d['pravnaForma'] ?? null,
            'size_code' => $d['velkostOrganizacie'] ?? null,
            'consolidated' => $d['konsolidovana'] ?? null,
            'established_on' => $d['datumZalozenia'] ?? null,
            'street' => $d['ulica'] ?? null,
            'municipality' => $d['mesto'] ?? null,
            'postal_code' => $d['psc'] ?? null,
            'financial_statement_ids' => array_map('intval', (array) ($d['idUctovnychZavierok'] ?? [])),
            'source' => self::SOURCE,
            'source_url' => $detailUrl,
        ];
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('whoiswho.ruz.base_url'), '/');
    }
}
