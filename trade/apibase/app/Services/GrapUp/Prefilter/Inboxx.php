<?php

namespace App\Services\GrapUp\Prefilter;

use App\Services\GrapUp\Http\VendorClient;
use App\Services\GrapUp\Verifier\Status;
use Illuminate\Support\Facades\Log;

/**
 * Inboxx — the free tiers of our own verification engine, used as a prefilter.
 *
 * The engine behind it runs four tiers: syntax and DNS, the Microsoft
 * directory endpoint over HTTPS, an optional SMTP probe, and a pattern model
 * that scores what none of those could prove. Only that last tier produces a
 * confidence number, which is why `confidence` is null on most answers — an
 * address that was actually proven does not carry a probability.
 *
 * The prize is visible in GrapUp's own ledger. Of its last 2,887 credits,
 * 2,528 — 88% — bought the verdict `undeliverable`. Those are exactly the
 * addresses syntax, DNS and the Microsoft tier settle for nothing.
 *
 * Ported from GrapUp's `services/verifier/inboxx.ts`.
 */
class Inboxx implements PrefilterProvider
{
    private readonly VendorClient $client;

    public function __construct()
    {
        $this->client = new VendorClient('inboxx', (int) config('grapup.inboxx.max_rpm'));
    }

    public function name(): string
    {
        return 'inboxx';
    }

    public function isConfigured(): bool
    {
        return (string) config('grapup.inboxx.key') !== '';
    }

    public function check(string $email): ?PrefilterVerdict
    {
        $response = $this->client->request(
            url: rtrim((string) config('grapup.inboxx.url'), '/') . '/api/v1/verify',
            method: 'POST',
            headers: [
                'content-type' => 'application/json',
                'x-api-key' => (string) config('grapup.inboxx.key'),
            ],
            body: ['email' => $email],
            timeoutSeconds: (int) config('grapup.inboxx.timeout_seconds'),
        );

        /*
         * Null rather than `unknown`, every time.
         *
         * The difference is the whole point of this class: `unknown` is a
         * finding, which would be cached and would stop the address ever
         * reaching the paid tier. Null means the prefilter said nothing and
         * Clearout should decide.
         */
        if ($response->skipped) {
            return null;
        }

        if (! $response->ok || ! is_array($response->data)) {
            // 429 is the daily quota, not a fault. It still means no answer.
            Log::warning('grapup: prefilter unavailable', [
                'email' => $email, 'status' => $response->status, 'error' => $response->error,
            ]);

            return null;
        }

        $body = $response->data;

        return new PrefilterVerdict(
            status: self::mapStatus($body['status'] ?? null),
            confidence: is_numeric($body['confidence'] ?? null) ? (int) $body['confidence'] : null,
            tier: (string) ($body['checked_by'] ?? ''),
        );
    }

    /**
     * Inboxx's four statuses onto our five.
     *
     * It has no `risky`: its design rule is that an address is only marked
     * invalid on positive proof and everything uncertain escalates, so
     * uncertainty arrives as `unknown` or `catch_all` rather than as a hedge.
     *
     * Public so the tests can assert the mapping without a network call.
     */
    public static function mapStatus(?string $status): string
    {
        return match (strtolower($status ?? '')) {
            'valid' => Status::DELIVERABLE,
            'invalid', 'disposable' => Status::UNDELIVERABLE,
            'catch_all' => Status::CATCH_ALL,
            default => Status::UNKNOWN,
        };
    }

    public function circuit(): array
    {
        return $this->client->circuit();
    }
}
