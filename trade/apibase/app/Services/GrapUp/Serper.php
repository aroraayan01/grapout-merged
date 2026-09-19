<?php

namespace App\Services\GrapUp;

use App\Services\GrapUp\Http\VendorClient;
use Illuminate\Support\Facades\Log;

/**
 * Serper — Google results, as JSON.
 *
 * The `gl` parameter is the reason this is not a thin wrapper. Passing a
 * country code changes result ranking, and for this pipeline that decides
 * correctness rather than convenience: with `gl` set, a company's own website
 * tends to outrank the directory listings that would otherwise be mistaken for
 * it. An unrecognised country sends no `gl` at all, because no geo-targeting
 * beats the wrong country's results.
 */
class Serper
{
    private readonly VendorClient $client;

    public function __construct()
    {
        $this->client = new VendorClient('serper', (int) config('grapup.serper.max_rpm'));
    }

    /**
     * Run one search.
     *
     * Returns an empty list rather than throwing: a failed search means this
     * tier of the waterfall found nothing, which the caller already knows how
     * to handle.
     *
     * @return list<array{link: string, title: string, snippet: string}>
     */
    public function search(string $query, ?string $gl = null, ?int $num = null): array
    {
        $body = ['q' => $query];
        if ($gl !== null && $gl !== '') {
            $body['gl'] = $gl;
        }
        if ($num !== null) {
            // Serper bills per call, not per result.
            $body['num'] = $num;
        }

        $response = $this->client->request(
            url: (string) config('grapup.serper.url'),
            method: 'POST',
            headers: [
                'content-type' => 'application/json',
                'x-api-key' => (string) config('grapup.serper.key'),
            ],
            body: $body,
            timeoutSeconds: (int) config('grapup.serper.timeout_seconds'),
        );

        if ($response->skipped) {
            Log::warning('grapup: serper skipped, circuit is open', ['query' => $query]);

            return [];
        }

        if (! $response->ok || ! is_array($response->data)) {
            Log::warning('grapup: serper search failed', ['query' => $query, 'error' => $response->error]);

            return [];
        }

        $organic = $response->data['organic'] ?? [];
        if (! is_array($organic)) {
            return [];
        }

        return array_values(array_map(fn (array $r) => [
            'link' => (string) ($r['link'] ?? ''),
            'title' => (string) ($r['title'] ?? ''),
            'snippet' => (string) ($r['snippet'] ?? ''),
        ], array_filter($organic, 'is_array')));
    }

    public function isConfigured(): bool
    {
        return (string) config('grapup.serper.key') !== '';
    }

    public function circuit(): array
    {
        return $this->client->circuit();
    }
}
