<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_returns_200_without_auth(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'WhoIsWho SK')
            ->assertJsonStructure([
                'status',
                'service',
                'version',
                'database',
                'sources' => ['rpo', 'ruz', 'rpvs', 'orsr_scrape_fallback'],
                'timestamp',
            ]);
    }
}
