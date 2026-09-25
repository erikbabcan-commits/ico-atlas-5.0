<?php

namespace Tests\Unit\Services;

use App\Services\Sources\OrsrScrapeProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrsrScrapePolicyTest extends TestCase
{
    public function test_scrape_is_disabled_by_default(): void
    {
        config(['whoiswho.orsr_scrape.enabled' => false]);

        $provider = $this->app->make(OrsrScrapeProvider::class);

        $this->assertFalse($provider->isEnabled());
    }

    public function test_scrape_fetch_returns_null_when_disabled(): void
    {
        config(['whoiswho.orsr_scrape.enabled' => false]);

        $provider = $this->app->make(OrsrScrapeProvider::class);

        $this->assertNull($provider->fetchByIco('31333532'));
    }

    public function test_scrape_ttl_config_is_at_least_24h(): void
    {
        $this->assertGreaterThanOrEqual(24, (int) config('whoiswho.orsr_scrape.cache_ttl_hours', 24));
        $this->assertLessThanOrEqual(1.0, (float) config('whoiswho.orsr_scrape.min_interval_seconds', 1));
    }

    public function test_scrape_is_not_default_path_when_rpo_available(): void
    {
        $this->assertTrue((bool) config('whoiswho.rpo.enabled', true));
        $this->assertFalse((bool) config('whoiswho.orsr_scrape.enabled', false));
    }
}
