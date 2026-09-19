<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Words in the reader's language, for anybody reading.
 *
 * Public, because the reader most in need of it is the buyer who has not
 * signed up yet. Throttled, batched, and answered from the cache whenever
 * the same sentence was asked before.
 */
class TranslateController extends Controller
{
    public function config(TranslationService $service): JsonResponse
    {
        return response()->json(['data' => [
            'translate' => $service->available(),
            'languages' => TranslationService::LANGUAGES,
            // One list for the whole network: what a product is, and what a company is.
            'categories' => \App\Support\TradeCategories::LIST,
            'kinds' => \App\Models\Business\Page::KINDS,
            'currencies' => array_values(array_filter(explode(',', \App\Models\AppSetting::get('trade_currencies')))),
        ]]);
    }

    public function translate(Request $request, TranslationService $service): JsonResponse
    {
        $data = $request->validate([
            'texts' => ['required', 'array', 'min:1', 'max:30'],
            'texts.*' => ['nullable', 'string', 'max:4000'],
            'target' => ['required', 'string', 'min:2', 'max:5', 'regex:/^[a-zA-Z-]+$/'],
            'source' => ['nullable', 'string', 'min:2', 'max:5', 'regex:/^[a-zA-Z-]+$/'],
        ]);

        return response()->json(['data' => $service->translate(
            array_map(fn ($t) => (string) $t, $data['texts']),
            $data['target'],
            $data['source'] ?? null,
        )]);
    }
}
