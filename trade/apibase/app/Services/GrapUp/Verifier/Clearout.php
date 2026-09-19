<?php

namespace App\Services\GrapUp\Verifier;

use App\Services\GrapUp\Http\VendorClient;
use Illuminate\Support\Facades\Log;

/**
 * Clearout, normalised.
 *
 * The mapping below is the whole point of the class. Clearout distinguishes
 * "invalid" — the mailbox does not exist — from every kind of "we could not
 * find out", and collapsing those two is how a billing lapse quietly deletes a
 * week of good contacts.
 */
class Clearout implements VerificationProvider
{
    private readonly VendorClient $client;

    public function __construct()
    {
        $this->client = new VendorClient('clearout', (int) config('grapup.verifier.max_rpm'));
    }

    public function name(): string
    {
        return 'clearout';
    }

    public function isConfigured(): bool
    {
        return (string) config('grapup.clearout.token') !== '';
    }

    public function verify(string $email): string
    {
        $response = $this->client->request(
            url: (string) config('grapup.clearout.url'),
            method: 'POST',
            headers: [
                'content-type' => 'application/json',
                'authorization' => 'Bearer:' . config('grapup.clearout.token'),
            ],
            body: ['email' => $email, 'timeout' => (int) config('grapup.clearout.verify_timeout_ms')],
            timeoutSeconds: (int) config('grapup.clearout.timeout_seconds'),
        );

        if ($response->skipped) {
            return Status::UNKNOWN;
        }

        if (! $response->ok || ! is_array($response->data)) {
            Log::warning('grapup: clearout verify failed', ['email' => $email, 'error' => $response->error]);

            return Status::UNKNOWN;
        }

        $body = $response->data;

        if (strtolower((string) ($body['status'] ?? '')) === 'failed') {
            // Out of credits, bad token, blocked account. All of them mean we
            // learned nothing about the address.
            Log::warning('grapup: clearout reported failure', [
                'email' => $email,
                'message' => $body['error']['message'] ?? '',
            ]);

            return Status::UNKNOWN;
        }

        return self::mapStatus(
            $body['data']['status'] ?? null,
            $body['data']['safe_to_send'] ?? null,
        );
    }

    /**
     * Clearout's verdict vocabulary.
     *
     * `catch_all` is separate from `deliverable` because it proves nothing:
     * the domain accepts every address, so a positive result on one
     * permutation says only that the domain exists.
     *
     * Public so the tests can assert the mapping without a network call — it
     * is the part most likely to drift when a vendor adds a word.
     */
    public static function mapStatus(?string $raw, ?string $safeToSend): string
    {
        return match (strtolower($raw ?? '')) {
            // Clearout marks some valid addresses as risky to send to — role
            // mailboxes, full inboxes. Worth showing, not worth discarding.
            'valid' => strtolower($safeToSend ?? '') === 'risky' ? Status::RISKY : Status::DELIVERABLE,
            'catch_all', 'accept_all' => Status::CATCH_ALL,
            'risky', 'disposable', 'role' => Status::RISKY,
            'invalid' => Status::UNDELIVERABLE,
            // "unknown", and anything the vendor adds later.
            default => Status::UNKNOWN,
        };
    }

    public function circuit(): array
    {
        return $this->client->circuit();
    }
}
