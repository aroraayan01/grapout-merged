<?php

namespace App\Services\GrapUp\Verifier;

use App\Services\GrapUp\Http\VendorClient;
use Illuminate\Support\Facades\Log;

/**
 * Hunter, normalised to the same five verdicts as Clearout.
 *
 * The ordering in `mapStatus` is the load-bearing part: Hunter reports a
 * deliverable result on a catch-all domain, and taking that at face value
 * would end the permutation walk on an address nothing has confirmed — and
 * then teach the domain a naming pattern from it.
 */
class Hunter implements VerificationProvider
{
    private readonly VendorClient $client;

    public function __construct()
    {
        $this->client = new VendorClient('hunter', (int) config('grapup.verifier.max_rpm'));
    }

    public function name(): string
    {
        return 'hunter';
    }

    public function isConfigured(): bool
    {
        return (string) config('grapup.hunter.key') !== '';
    }

    public function verify(string $email): string
    {
        $response = $this->client->request(
            url: (string) config('grapup.hunter.url'),
            method: 'GET',
            headers: ['accept' => 'application/json'],
            body: ['email' => $email, 'api_key' => (string) config('grapup.hunter.key')],
            timeoutSeconds: (int) config('grapup.hunter.timeout_seconds'),
        );

        if ($response->skipped) {
            return Status::UNKNOWN;
        }

        if (! $response->ok || ! is_array($response->data)) {
            Log::warning('grapup: hunter verify failed', ['email' => $email, 'error' => $response->error]);

            return Status::UNKNOWN;
        }

        $data = $response->data['data'] ?? [];

        return self::mapStatus(
            $data['status'] ?? null,
            $data['result'] ?? null,
            $data['accept_all'] ?? null,
        );
    }

    /** Public so the tests can assert the mapping without a network call. */
    public static function mapStatus(?string $status, ?string $result, mixed $acceptAll): string
    {
        // accept_all is checked first: Hunter reports a deliverable result on
        // a catch-all domain, and taking that at face value would end the walk
        // on an address nothing has confirmed.
        if ($acceptAll === true) {
            return Status::CATCH_ALL;
        }

        return match (strtolower($status ?? $result ?? '')) {
            'valid', 'deliverable' => Status::DELIVERABLE,
            'accept_all', 'webmail' => Status::CATCH_ALL,
            'risky', 'disposable' => Status::RISKY,
            'invalid', 'undeliverable' => Status::UNDELIVERABLE,
            default => Status::UNKNOWN,
        };
    }

    public function circuit(): array
    {
        return $this->client->circuit();
    }
}
