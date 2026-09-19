<?php

namespace App\Services\GrapUp\Http;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The shared outbound HTTP client for Grap Company's vendors.
 *
 * Every vendor call goes through here, and the contract is that it never
 * throws. A flaky vendor must degrade the pipeline, not kill the request that
 * is halfway through spending money — the caller decides what a failure means,
 * because only the caller knows whether a failure is "no data" or "do not mark
 * this address bad".
 *
 * Wrapped around each call: a rate gate, a circuit breaker, a timeout, and one
 * retry on the failures worth retrying.
 *
 * One client per vendor, so a Serper outage cannot open the circuit on
 * Clearout and vice versa.
 */
class VendorClient
{
    private readonly CircuitBreaker $breaker;

    private readonly RateGate $gate;

    public function __construct(
        private readonly string $name,
        int $maxRpm,
        int $failureThreshold = 5,
        int $cooldownSeconds = 30,
    ) {
        $this->breaker = new CircuitBreaker($name, $failureThreshold, $cooldownSeconds);
        $this->gate = new RateGate($name, $maxRpm);
    }

    /** 429 and 5xx are worth another shot; 4xx are the request's fault. */
    private function isRetryable(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500;
    }

    /**
     * Make one call. Returns a VendorResponse, never throws.
     *
     * `skipped` is the field that matters to the caller: it means the circuit
     * was open, so no call was made and — crucially — nothing was spent.
     *
     * @param  array<string, string>  $headers
     */
    public function request(
        string $url,
        string $method = 'POST',
        array $headers = [],
        mixed $body = null,
        int $timeoutSeconds = 20,
        int $retries = 1,
    ): VendorResponse {
        if (! $this->breaker->canAttempt()) {
            Log::debug("grapup: {$this->name} circuit open, call skipped");

            return new VendorResponse(ok: false, status: 0, data: null, error: 'circuit open', skipped: true);
        }

        $attempt = 0;
        $lastStatus = 0;
        $lastError = null;

        while ($attempt <= $retries) {
            $this->gate->wait();

            try {
                $request = Http::withHeaders($headers)->timeout($timeoutSeconds);
                $response = $method === 'GET'
                    ? $request->get($url, is_array($body) ? $body : [])
                    : $request->send($method, $url, ['json' => $body]);

                $status = $response->status();

                if ($response->successful()) {
                    $this->breaker->recordSuccess();

                    return new VendorResponse(ok: true, status: $status, data: $response->json(), error: null, skipped: false);
                }

                $lastStatus = $status;
                $lastError = "HTTP {$status}";

                if (! $this->isRetryable($status)) {
                    /*
                     * A 4xx is the request's fault, not the vendor's. Returned
                     * without touching the breaker: tripping on these would
                     * take the pipeline down over one malformed domain.
                     */
                    return new VendorResponse(ok: false, status: $status, data: $response->json(), error: $lastError, skipped: false);
                }
            } catch (\Throwable $e) {
                // A timeout or a DNS failure: no response at all.
                $lastStatus = 0;
                $lastError = $e->getMessage();
            }

            $attempt++;
            if ($attempt <= $retries) {
                usleep(250_000 * $attempt); // A short, widening backoff.
            }
        }

        $this->breaker->recordFailure();
        Log::warning("grapup: {$this->name} call failed", ['url' => $url, 'error' => $lastError]);

        return new VendorResponse(ok: false, status: $lastStatus, data: null, error: $lastError, skipped: false);
    }

    public function circuit(): array
    {
        return $this->breaker->snapshot();
    }

    public function resetCircuit(): void
    {
        $this->breaker->close();
    }
}
