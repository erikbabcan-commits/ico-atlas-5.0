<?php

namespace App\Services\Risk;

use App\Models\Company;
use App\Models\Edge;
use App\Services\Graph\GraphService;
use Illuminate\Support\Str;

/**
 * Deterministické risk flags (žiadne LLM):
 *  - MULTI_BOARD: štatutár sedí v N firmách
 *  - INSOLVENT_LINKS: napojenie na konkurz/likvidáciu
 *  - SHARED_SEAT: rovnaké sídlo s inou firmou
 *  - RPVS_UBO: figura v Registri partnerov verejného sektora (KÚV)
 */
class RiskService
{
    public function __construct(
        protected GraphService $graph,
    ) {}

    /**
     * @return array{
     *   ico: string,
     *   score: float,
     *   flags: list<array{code: string, label: string, detail: string, evidence: array<string, mixed>}>,
     *   sources: list<string>,
     *   computed_at: string,
     * }
     */
    public function assess(string $ico): array
    {
        $company = Company::find($ico);
        $flags = [];
        $sources = [];

        if ($company !== null) {
            foreach ((array) ($company->sources ?? []) as $s) {
                $sources[] = $s;
            }
        }

        $statutory = $this->graph->statutoryWithBoards($ico);
        $minBoards = (int) config('whoiswho.risk.multi_board_min_companies', 2);

        $multiBoardPersons = array_filter($statutory, fn ($p) => $p['board_count'] >= $minBoards);
        if ($multiBoardPersons !== []) {
            $flags[] = [
                'code' => 'MULTI_BOARD',
                'label' => 'Štatutár v N firmách',
                'detail' => sprintf(
                    '%d štatutár(ov) pôsobí v %d+ spoločnostiach',
                    count($multiBoardPersons),
                    $minBoards
                ),
                'evidence' => array_values(array_map(fn ($p) => [
                    'person' => $p['name'],
                    'companies' => $p['companies'],
                ], $multiBoardPersons)),
            ];
            $sources[] = 'rpo';
        }

        $insolvent = $this->insolventLinks($ico, $company);
        if ($insolvent !== []) {
            $flags[] = [
                'code' => 'INSOLVENT_LINKS',
                'label' => 'Napojenie na insolvečné/likvidačné konanie',
                'detail' => sprintf('%d súvislosť(í) s konkurzom/likvidáciou', count($insolvent)),
                'evidence' => $insolvent,
            ];
            $sources[] = 'rpo';
        }

        $sameSeat = $this->graph->sameSeatCompanies($ico);
        if (count($sameSeat) > 0) {
            $flags[] = [
                'code' => 'SHARED_SEAT',
                'label' => 'Zdieľané sídlo s inou firmou',
                'detail' => sprintf('%d firiem má rovnaké normalizované sídlo', count($sameSeat)),
                'evidence' => $sameSeat,
            ];
            $sources[] = 'derived';
        }

        $uboEdges = Edge::where('to_type', Edge::TYPE_COMPANY)
            ->where('to_id', $ico)
            ->where('edge_type', Edge::EDGE_UBO)
            ->get();
        if ($uboEdges->isNotEmpty()) {
            $flags[] = [
                'code' => 'RPVS_UBO',
                'label' => 'Konečný užívateľ výhod v RPVS',
                'detail' => sprintf('%d KÚV záznam(ov) v Registri partnerov verejného sektora', $uboEdges->count()),
                'evidence' => $uboEdges->map(fn ($e) => [
                    'from' => $e->from_type . ':' . $e->from_id,
                    'valid_from' => $e->valid_from?->toDateString(),
                    'valid_to' => $e->valid_to?->toDateString(),
                    'source_url' => $e->source_url,
                ])->all(),
            ];
            $sources[] = 'rpvs';
        }

        $score = $this->score($flags);

        return [
            'ico' => $ico,
            'score' => $score,
            'flags' => $flags,
            'sources' => array_values(array_unique($sources)),
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function insolventLinks(string $ico, ?Company $company): array
    {
        $links = [];
        $keywords = (array) config('whoiswho.risk.insolvency_keywords', ['konkurz', 'likvid']);

        if ($company !== null && $company->status !== null) {
            $statusLower = Str::lower($company->status);
            foreach ($keywords as $kw) {
                if (Str::contains($statusLower, $kw)) {
                    $links[] = [
                        'type' => 'self',
                        'ico' => $ico,
                        'status' => $company->status,
                    ];
                    break;
                }
            }
        }

        $companyEdges = Edge::where('edge_type', Edge::EDGE_PREDECESSOR)
            ->where('to_id', $ico)
            ->get();
        foreach ($companyEdges as $e) {
            $pred = Company::find($e->from_id);
            if ($pred !== null && $pred->status !== null) {
                $statusLower = Str::lower($pred->status);
                foreach ($keywords as $kw) {
                    if (Str::contains($statusLower, $kw)) {
                        $links[] = [
                            'type' => 'predecessor',
                            'ico' => $pred->ico,
                            'name' => $pred->name,
                            'status' => $pred->status,
                        ];
                        break;
                    }
                }
            }
        }

        return $links;
    }

    /**
     * @param  list<array{code: string, ...}>  $flags
     */
    protected function score(array $flags): float
    {
        $weights = (array) config('whoiswho.risk.weights', []);
        $sum = 0.0;
        foreach ($flags as $flag) {
            $sum += (float) ($weights[$flag['code']] ?? 0.1);
        }

        return round(min(1.0, $sum), 3);
    }
}
