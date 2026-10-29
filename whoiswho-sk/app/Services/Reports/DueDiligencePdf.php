<?php

namespace App\Services\Reports;

use App\Models\Company;
use App\Models\ReportJob;
use App\Services\Graph\GraphService;
use App\Services\Risk\RiskService;
use App\Services\Support\Normalizer;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF generátor due-diligence reportu (F5).
 * Deterministický — len dáta z registrov, žiadny LLM.
 */
class DueDiligencePdf
{
    public function __construct(
        protected RiskService $risk,
        protected GraphService $graph,
    ) {}

    public function generate(string $ico): array
    {
        $company = Company::query()->where('ico', $ico)->first();

        if ($company === null) {
            throw new \RuntimeException("Spoločnosť {$ico} neexistuje v databáze. Najprv vykonajte ingest.");
        }

        $risk = $this->risk->assess($ico);
        $graph = $this->graph->companyGraph($ico, 1);
        $disclaimer = (string) config('whoiswho.disclaimer');

        $html = view('reports.due-diligence', [
            'company' => $company,
            'risk' => $risk,
            'graph' => $graph,
            'statutory' => $this->statutoryList($ico),
            'shareholders' => $this->shareholderList($ico),
            'disclaimer' => $disclaimer,
            'generatedAt' => now()->format('d.m.Y H:i'),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdf = $dompdf->output();
        $hash = hash('sha256', $pdf);

        return [
            'pdf' => $pdf,
            'sha256' => $hash,
            'size' => strlen($pdf),
            'company_name' => $company->name,
        ];
    }

    protected function statutoryList(string $ico): array
    {
        $out = [];
        $graph = $this->graph->companyGraph($ico, 1, ['STATUTORY']);
        foreach ($graph['edges'] as $edge) {
            if ($edge['type'] === 'STATUTORY') {
                $out[] = [
                    'name' => $this->nodeName($graph, $edge['to']),
                    'role' => $edge['payload']['role'] ?? '—',
                    'valid_from' => $edge['valid_from'] ?? null,
                    'source' => $edge['source'],
                    'source_url' => $edge['source_url'],
                ];
            }
        }

        return $out;
    }

    protected function shareholderList(string $ico): array
    {
        $out = [];
        $graph = $this->graph->companyGraph($ico, 1, ['SHAREHOLDER']);
        foreach ($graph['edges'] as $edge) {
            if ($edge['type'] === 'SHAREHOLDER') {
                $out[] = [
                    'name' => $edge['payload']['name'] ?? $this->nodeName($graph, $edge['to']),
                    'kind' => $edge['payload']['type'] ?? 'FO',
                    'valid_from' => $edge['valid_from'] ?? null,
                    'source' => $edge['source'],
                    'source_url' => $edge['source_url'],
                ];
            }
        }

        return $out;
    }

    protected function nodeName(array $graph, string $ref): string
    {
        foreach ($graph['nodes'] as $node) {
            if (($node['id'] ?? null) !== null && 'person:' . $node['id'] === $ref) {
                return $node['label'] ?? $node['name'] ?? '—';
            }
        }

        return $ref;
    }
}
