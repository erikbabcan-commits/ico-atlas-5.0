<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\Http;

/**
 * Stripe Checkout (F5) — bez SDK, priame REST volanie.
 * Zdroj dát: cenová konfigurácia v config/whoiswho.php (reports.pricing).
 */
class StripeCheckout
{
    public function __construct(
        protected string $secret,
        protected string $baseUrl,
    ) {}

    public static function make(): ?self
    {
        $secret = (string) config('whoiswho.reports.stripe_secret');
        if ($secret === '' || ! (bool) config('whoiswho.reports.stripe_enabled')) {
            return null;
        }

        return new self($secret, 'https://api.stripe.com');
    }

    public function isEnabled(): bool
    {
        return $this->secret !== '';
    }

    /**
     * @return array{ok: bool, status: int, url: ?string, session_id: ?string, error: ?string}
     */
    public function createSession(string $ico, string $tier, int $amountCents, string $currency, string $successUrl, string $cancelUrl): array
    {
        $res = Http::withToken($this->secret)
            ->asForm()
            ->timeout(15)
            ->post("{$this->baseUrl}/v1/checkout/sessions", [
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => $ico,
                'metadata[ico]' => $ico,
                'metadata[tier]' => $tier,
                'line_items[0][price_data][currency]' => $currency,
                'line_items[0][price_data][unit_amount]' => $amountCents,
                'line_items[0][price_data][product_data][name]' => 'WhoIsWho SK — Due Diligence report (' . strtoupper($tier) . ')',
                'line_items[0][quantity]' => 1,
            ]);

        if ($res->failed()) {
            return ['ok' => false, 'status' => $res->status(), 'url' => null, 'session_id' => null, 'error' => $res->body()];
        }

        $json = $res->json();

        return [
            'ok' => true,
            'status' => 201,
            'url' => $json['url'] ?? null,
            'session_id' => $json['id'] ?? null,
            'error' => null,
        ];
    }
}
