<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\Edge;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['whoiswho.api_key' => 'test-key']);
    }

    public function test_company_show_requires_auth(): void
    {
        $this->getJson('/api/v1/companies/31333532')
            ->assertStatus(401);
    }

    public function test_company_show_rejects_invalid_ico(): void
    {
        $this->getJson('/api/v1/companies/123', ['Authorization' => 'Bearer test-key'])
            ->assertStatus(422);
    }

    public function test_company_show_returns_consolidated_profile_with_meta(): void
    {
        Company::create([
            'ico' => '31333532',
            'name' => 'ESET, spol. s r.o.',
            'name_norm' => 'eset-spol-s-r-o',
            'status' => 'Aktívna',
            'legal_form' => 'Spoločnosť s ručením obmedzeným',
            'seat_norm' => 'einsteinova-bratislava-85101',
            'street' => 'Einsteinova',
            'municipality' => 'Bratislava',
            'postal_code' => '85101',
            'dic' => '2020336754',
            'sources' => ['rpo', 'ruz'],
            'raw' => ['source_urls' => ['https://api.statistics.sk/rpo/v1/entity/937053']],
            'retrieved_at' => now(),
        ]);

        $this->getJson('/api/v1/companies/31333532', ['Authorization' => 'Bearer test-key'])
            ->assertStatus(200)
            ->assertJsonPath('data.ico', '31333532')
            ->assertJsonPath('data.name', 'ESET, spol. s r.o.')
            ->assertJsonPath('data.dic', '2020336754')
            ->assertJsonPath('meta.sources.0', 'rpo')
            ->assertJsonStructure([
                'data' => ['ico', 'name', 'status', 'legal_form', 'seat_norm', 'dic'],
                'meta' => ['sources', 'source_url', 'retrieved_at', 'disclaimer'],
            ]);
    }

    public function test_company_show_returns_404_for_unknown_company(): void
    {
        config(['whoiswho.rpo.enabled' => false, 'whoiswho.ruz.enabled' => false, 'whoiswho.rpvs.enabled' => false]);

        $this->getJson('/api/v1/companies/11111111', ['Authorization' => 'Bearer test-key'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Spoločnosť sa nenašla v žiadnom zdroji (RPO, RÚZ, RPVS).');
    }

    public function test_company_show_ignores_stale_cache(): void
    {
        Company::create([
            'ico' => '31333532',
            'name' => 'Old name',
            'sources' => ['rpo'],
            'raw' => [],
            'retrieved_at' => now()->subHours(48),
        ]);

        config(['whoiswho.rpo.enabled' => false, 'whoiswho.ruz.enabled' => false, 'whoiswho.rpvs.enabled' => false]);

        $this->getJson('/api/v1/companies/31333532', ['Authorization' => 'Bearer test-key'])
            ->assertStatus(404);
    }

    public function test_graph_depth1_returns_nodes_and_edges(): void
    {
        $company = Company::create([
            'ico' => '31333532',
            'name' => 'ESET, spol. s r.o.',
            'seat_norm' => 'einsteinova-bratislava-85101',
            'street' => 'Einsteinova',
            'municipality' => 'Bratislava',
            'postal_code' => '85101',
            'sources' => ['rpo'],
            'raw' => [],
            'retrieved_at' => now(),
        ]);

        $person = Person::create([
            'name_norm' => 'trnka-miroslav',
            'full_name' => 'Miroslav Trnka',
            'given_name' => 'Miroslav',
            'family_name' => 'Trnka',
        ]);

        Edge::create([
            'from_type' => 'company',
            'from_id' => '31333532',
            'to_type' => 'person',
            'to_id' => (string) $person->id,
            'edge_type' => 'STATUTORY',
            'source' => 'rpo',
            'source_url' => 'https://api.statistics.sk/rpo/v1/entity/937053',
            'retrieved_at' => now(),
            'confidence' => 0.95,
        ]);

        $this->getJson('/api/v1/companies/31333532/graph?depth=1', ['Authorization' => 'Bearer test-key'])
            ->assertStatus(200)
            ->assertJsonPath('data.root', 'company:31333532')
            ->assertJsonPath('data.depth', 1)
            ->assertJsonStructure([
                'data' => [
                    'root',
                    'depth',
                    'nodes' => [['id', 'type', 'label']],
                    'edges' => [['from', 'to', 'type', 'source', 'retrieved_at', 'confidence']],
                    'counts' => ['nodes', 'edges'],
                ],
                'meta' => ['retrieved_at', 'disclaimer'],
            ])
            ->assertJsonPath('data.counts.nodes', 2);
    }

    public function test_risk_returns_valid_structure_with_flags(): void
    {
        Company::create([
            'ico' => '31333532',
            'name' => 'ESET, spol. s r.o.',
            'seat_norm' => 'einsteinova-bratislava-85101',
            'street' => 'Einsteinova',
            'municipality' => 'Bratislava',
            'postal_code' => '85101',
            'sources' => ['rpo'],
            'raw' => [],
            'retrieved_at' => now(),
        ]);

        Company::create([
            'ico' => '99999999',
            'name' => 'Same Seat s.r.o.',
            'seat_norm' => 'einsteinova-bratislava-85101',
            'street' => 'Einsteinova',
            'municipality' => 'Bratislava',
            'postal_code' => '85101',
            'sources' => ['rpo'],
            'raw' => [],
            'retrieved_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/companies/31333532/risk', ['Authorization' => 'Bearer test-key']);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['ico', 'score', 'flags', 'sources', 'computed_at'],
                'meta' => ['retrieved_at', 'disclaimer'],
            ]);

        $flags = $response->json('data.flags');
        $this->assertIsArray($flags);
        $this->assertNotEmpty($flags);

        $codes = array_column($flags, 'code');
        $this->assertContains('SHARED_SEAT', $codes);
        $this->assertIsFloat($response->json('data.score'));
        $this->assertGreaterThanOrEqual(0, $response->json('data.score'));
        $this->assertLessThanOrEqual(1, $response->json('data.score'));
    }

    public function test_risk_empty_flags_is_valid(): void
    {
        Company::create([
            'ico' => '31333532',
            'name' => 'Solo s.r.o.',
            'seat_norm' => 'nova-ulica-bratislava-81101',
            'sources' => ['rpo'],
            'raw' => [],
            'retrieved_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/companies/31333532/risk', ['Authorization' => 'Bearer test-key']);

        $response->assertStatus(200)
            ->assertJsonPath('data.flags', []);
        $this->assertEquals(0.0, $response->json('data.score'));
    }

    public function test_due_diligence_stub_returns_job_id(): void
    {
        Company::create([
            'ico' => '31333532',
            'name' => 'ESET, spol. s r.o.',
            'seat_norm' => 'einsteinova-bratislava-85101',
            'sources' => ['rpo'],
            'raw' => [],
            'retrieved_at' => now(),
        ]);

        $this->postJson('/api/v1/reports/due-diligence', ['ico' => '31333532'], ['Authorization' => 'Bearer test-key'])
            ->assertStatus(202)
            ->assertJsonStructure([
                'data' => ['job_id', 'status', 'ico', 'draft'],
                'meta' => ['retrieved_at', 'disclaimer', 'note'],
            ])
            ->assertJsonPath('data.status', 'draft');
    }
}
