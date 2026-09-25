<?php

namespace App\Services\Graph;

use App\Models\Company;
use App\Models\Edge;
use App\Models\Person;
use Illuminate\Support\Carbon;

/**
 * Graf vrcholov Person + Company (MVP: Postgres nodes/edges, bez Neo4j).
 * Killer query: „štatutár v N firmách, K v konkurze, A rovnaké sídlo".
 */
class GraphService
{
    /**
     * @param  list<string>|null  $edgeFilter
     * @return array{
     *   root: string,
     *   depth: int,
     *   nodes: list<array<string, mixed>>,
     *   edges: list<array<string, mixed>>,
     *   counts: array{nodes: int, edges: int},
     * }
     */
    public function companyGraph(string $ico, int $depth = 1, ?array $edgeFilter = null): array
    {
        $depth = max(1, min($depth, (int) config('whoiswho.graph.max_depth', 2)));
        $maxNodes = (int) config('whoiswho.graph.max_nodes', 200);

        $nodes = [];
        $edges = [];
        $visited = [];
        $frontier = [Edge::TYPE_COMPANY . ':' . $ico];

        $rootCompany = Company::find($ico);
        if ($rootCompany !== null) {
            $nodes[] = $this->companyNode($rootCompany);
            $visited[Edge::TYPE_COMPANY . ':' . $ico] = true;
        }

        $currentDepth = 0;
        while ($currentDepth < $depth && count($nodes) < $maxNodes) {
            $nextFrontier = [];

            foreach ($frontier as $nodeRef) {
                [$type, $id] = explode(':', $nodeRef, 2);

                $outgoing = Edge::where('from_type', $type)->where('from_id', $id)->get();
                $incoming = Edge::where('to_type', $type)->where('to_id', $id)->get();

                $all = $outgoing->concat($incoming);

                if ($edgeFilter !== null && $edgeFilter !== []) {
                    $all = $all->filter(fn ($e) => in_array($e->edge_type, $edgeFilter, true));
                }

                foreach ($all as $e) {
                    $edgeKey = $this->edgeKey($e);
                    if (!isset($edges[$edgeKey])) {
                        $edges[$edgeKey] = $this->edgeArray($e);
                    }

                    foreach ([[$e->from_type, $e->from_id], [$e->to_type, $e->to_id]] as [$nt, $nid]) {
                        $nodeRef = $nt . ':' . $nid;
                        if (!isset($visited[$nodeRef])) {
                            $node = $this->resolveNode($nt, (string) $nid);
                            if ($node !== null && count($nodes) < $maxNodes) {
                                $nodes[] = $node;
                                $visited[$nodeRef] = true;
                                $nextFrontier[] = $nodeRef;
                            }
                        }
                    }
                }
            }

            $frontier = $nextFrontier;
            $currentDepth++;
        }

        $this->attachDerivedSameSeat($ico, $nodes, $edges);

        $edgeList = array_values($edges);

        return [
            'root' => Edge::TYPE_COMPANY . ':' . $ico,
            'depth' => $depth,
            'nodes' => $nodes,
            'edges' => $edgeList,
            'counts' => [
                'nodes' => count($nodes),
                'edges' => count($edgeList),
            ],
        ];
    }

    /**
     * Killer query helper: firmy, ktoré majú rovnaké normalizované sídlo.
     *
     * @return list<array{ico: string, name: string|null, seat_norm: string}>
     */
    public function sameSeatCompanies(string $ico): array
    {
        $company = Company::find($ico);
        if ($company === null || $company->seat_norm === null) {
            return [];
        }

        return Company::where('seat_norm', $company->seat_norm)
            ->where('ico', '!=', $ico)
            ->limit(50)
            ->get(['ico', 'name', 'seat_norm'])
            ->map(fn ($c) => [
                'ico' => $c->ico,
                'name' => $c->name,
                'seat_norm' => $c->seat_norm,
            ])
            ->all();
    }

    /**
     * Killer query helper: štatutári spoločnosti a počet ich ďalších štatutárstiev.
     *
     * @return list<array{person_id: int, name: string|null, companies: list<array{ico: string, name: string|null}>}>
     */
    public function statutoryWithBoards(string $ico): array
    {
        $statutoryEdges = Edge::where('to_type', Edge::TYPE_PERSON)
            ->where('from_type', Edge::TYPE_COMPANY)
            ->where('from_id', $ico)
            ->where('edge_type', Edge::EDGE_STATUTORY)
            ->where(function ($q) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()->toDateString());
            })
            ->get();

        $result = [];
        foreach ($statutoryEdges as $se) {
            $person = Person::find($se->to_id);
            if ($person === null) {
                continue;
            }

            $boards = Edge::where('from_type', Edge::TYPE_COMPANY)
                ->where('to_type', Edge::TYPE_PERSON)
                ->where('to_id', $person->id)
                ->where('edge_type', Edge::EDGE_STATUTORY)
                ->pluck('from_id')
                ->unique()
                ->values();

            $companies = Company::whereIn('ico', $boards->all())
                ->get(['ico', 'name'])
                ->map(fn ($c) => ['ico' => $c->ico, 'name' => $c->name])
                ->all();

            $result[] = [
                'person_id' => $person->id,
                'name' => $person->full_name,
                'companies' => $companies,
                'board_count' => count($companies),
            ];
        }

        return $result;
    }

    protected function attachDerivedSameSeat(string $ico, array &$nodes, array &$edges): void
    {
        $sameSeat = $this->sameSeatCompanies($ico);

        foreach ($sameSeat as $peer) {
            $nodeRef = Edge::TYPE_COMPANY . ':' . $peer['ico'];
            if (!isset($nodes[$nodeRef]) && !collect($nodes)->contains(fn ($n) => $n['id'] === $peer['ico'])) {
                $nodes[] = [
                    'id' => $peer['ico'],
                    'type' => Edge::TYPE_COMPANY,
                    'label' => $peer['name'] ?? $peer['ico'],
                    'ico' => $peer['ico'],
                    'name' => $peer['name'],
                    'derived' => true,
                ];
            }

            $edgeKey = Edge::TYPE_COMPANY . ':' . $ico . '=>SAME_SEAT=>' . $peer['ico'];
            if (!isset($edges[$edgeKey])) {
                $edges[$edgeKey] = [
                    'from' => Edge::TYPE_COMPANY . ':' . $ico,
                    'to' => Edge::TYPE_COMPANY . ':' . $peer['ico'],
                    'type' => Edge::EDGE_SAME_SEAT,
                    'source' => 'derived',
                    'source_url' => null,
                    'retrieved_at' => now()->toIso8601String(),
                    'confidence' => 0.7,
                    'derived' => true,
                    'valid_from' => null,
                    'valid_to' => null,
                ];
            }
        }
    }

    protected function resolveNode(string $type, string $id): ?array
    {
        if ($type === Edge::TYPE_COMPANY) {
            $company = Company::find($id);
            if ($company === null) {
                return [
                    'id' => $id,
                    'type' => Edge::TYPE_COMPANY,
                    'label' => $id,
                    'ico' => $id,
                    'name' => null,
                    'known' => false,
                ];
            }

            return $this->companyNode($company);
        }

        if ($type === Edge::TYPE_PERSON) {
            $person = Person::find($id);

            return [
                'id' => $id,
                'type' => Edge::TYPE_PERSON,
                'label' => $person?->full_name ?? ('person:' . $id),
                'name' => $person?->full_name,
                'known' => $person !== null,
            ];
        }

        return null;
    }

    protected function companyNode(Company $company): array
    {
        return [
            'id' => $company->ico,
            'type' => Edge::TYPE_COMPANY,
            'label' => $company->name ?? $company->ico,
            'ico' => $company->ico,
            'name' => $company->name,
            'status' => $company->status,
            'legal_form' => $company->legal_form,
            'seat' => trim(($company->street ?? '') . ' ' . ($company->municipality ?? '')) ?: null,
            'retrieved_at' => $company->retrieved_at?->toIso8601String(),
        ];
    }

    protected function edgeArray(Edge $e): array
    {
        return [
            'from' => $e->from_type . ':' . $e->from_id,
            'to' => $e->to_type . ':' . $e->to_id,
            'type' => $e->edge_type,
            'source' => $e->source,
            'source_url' => $e->source_url,
            'retrieved_at' => $e->retrieved_at?->toIso8601String(),
            'confidence' => $e->confidence,
            'valid_from' => $e->valid_from?->toDateString(),
            'valid_to' => $e->valid_to?->toDateString(),
            'payload' => $e->payload,
        ];
    }

    protected function edgeKey(Edge $e): string
    {
        return $e->from_type . ':' . $e->from_id . '=>' . $e->edge_type . '=>' . $e->to_type . ':' . $e->to_id;
    }
}
