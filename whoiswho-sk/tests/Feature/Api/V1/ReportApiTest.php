<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\Edge;
use App\Models\Person;
use App\Models\ReportJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['whoiswho.api_key' => 'test-key', 'whoiswho.reports.storage_disk' => 'local']);
    }

    protected function seedCompany(): void
    {
        $company = Company::query()->create([
            'ico' => '31333532',
            'name' => 'ESET, spol. s r.o.',
            'name_norm' => 'eset-spol-s-r-o',
            'status' => 'Aktívna',
            'legal_form' => 'Spoločnosť s ručením obmedzeným',
            'seat_norm' => 'einsteinova-bratislava-85101',
            'street' => 'Einsteinova',
            'municipality' => 'Bratislava',
            'postal_code' => '85101',
            'sources' => ['rpo'],
            'raw' => ['ico' => '31333532'],
        ]);

        $person = Person::query()->create([
            'name_norm' => 'miroslav-trnka',
            'full_name' => 'Miroslav Trnka',
        ]);

        Edge::query()->create([
            'from_type' => 'company',
            'from_id' => '31333532',
            'to_type' => 'person',
            'to_id' => (string) $person->id,
            'edge_type' => Edge::EDGE_STATUTORY,
            'source' => 'rpo',
            'source_url' => 'https://api.statistics.sk/rpo/v1/entity/937053',
            'retrieved_at' => now(),
            'confidence' => 0.95,
            'payload' => ['role' => 'Konateľ'],
        ]);
    }

    public function test_due_diligence_generates_pdf_with_sha256(): void
    {
        $this->seedCompany();

        $response = $this->postJson('/api/v1/reports/due-diligence', ['ico' => '31333532'], [
            'Authorization' => 'Bearer ' . config('whoiswho.api_key'),
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertNotEmpty($data['job_id']);
        $this->assertSame('ready', $data['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $data['pdf']['sha256']);
        $this->assertGreaterThan(1000, $data['pdf']['size_bytes']);
        $this->assertSame("/api/v1/reports/{$data['job_id']}/download", $data['download_url']);
    }

    public function test_due_diligence_unknown_company_404(): void
    {
        $response = $this->postJson('/api/v1/reports/due-diligence', ['ico' => '99999999'], [
            'Authorization' => 'Bearer ' . config('whoiswho.api_key'),
        ]);

        $response->assertStatus(404);
    }

    public function test_download_returns_pdf_with_integrity_header(): void
    {
        $this->seedCompany();

        $create = $this->postJson('/api/v1/reports/due-diligence', ['ico' => '31333532'], [
            'Authorization' => 'Bearer ' . config('whoiswho.api_key'),
        ]);
        $jobId = $create->json('data.job_id');

        $response = $this->get("/api/v1/reports/{$jobId}/download", [
            'Authorization' => 'Bearer ' . config('whoiswho.api_key'),
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(
            $create->json('data.pdf.sha256'),
            $response->headers->get('X-Report-Sha256'),
        );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_show_returns_job_status(): void
    {
        $this->seedCompany();

        $create = $this->postJson('/api/v1/reports/due-diligence', ['ico' => '31333532'], [
            'Authorization' => 'Bearer ' . config('whoiswho.api_key'),
        ]);
        $jobId = $create->json('data.job_id');

        $response = $this->get("/api/v1/reports/{$jobId}", [
            'Authorization' => 'Bearer ' . config('whoiswho.api_key'),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.job_id', $jobId)
            ->assertJsonPath('data.status', 'ready');
    }

    public function test_checkout_returns_501_when_disabled(): void
    {
        config(['whoiswho.reports.stripe_enabled' => false, 'whoiswho.reports.stripe_secret' => '']);

        $this->seedCompany();
        $create = $this->postJson('/api/v1/reports/due-diligence', ['ico' => '31333532'], [
            'Authorization' => 'Bearer ' . config('whoiswho.api_key'),
        ]);
        $jobId = $create->json('data.job_id');

        $response = $this->postJson("/api/v1/reports/{$jobId}/checkout", [
            'success_url' => 'https://example.com/success',
            'cancel_url' => 'https://example.com/cancel',
        ], ['Authorization' => 'Bearer ' . config('whoiswho.api_key')]);

        $response->assertStatus(501);
    }

    public function test_unknown_job_404(): void
    {
        $response = $this->get('/api/v1/reports/00000000-0000-0000-0000-000000000000', [
            'Authorization' => 'Bearer ' . config('whoiswho.api_key'),
        ]);

        $response->assertStatus(404);
    }
}
