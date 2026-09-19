<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\SerializesBusiness;
use App\Http\Controllers\Controller;
use App\Models\Business\Page;
use App\Models\Business\Product;
use App\Models\Business\ProductImage;
use App\Support\BusinessImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Products and services on a page.
 *
 * Adding one is meant to feel like posting a photo. The keywords are the
 * part that decides whether anybody finds it, so the form makes them
 * unmissable rather than optional.
 */
class ProductController extends Controller
{
    use SerializesBusiness;

    /** Mine, every status, for the back office. */
    public function index(Request $request): JsonResponse
    {
        $page = $this->mine($request);
        $rows = $page->products()->with('images')->get()->map(fn ($x) => $this->productCard($x, $request->user()));

        return response()->json(['data' => $rows, 'hero_path' => $page->hero_path]);
    }

    /** Anybody's product, in full — the sheet that opens on tap. */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $viewer = $request->user();
        $product = Product::where('uuid', $uuid)
            ->where(fn ($q) => $q->live()->orWhereHas('page', fn ($p) => $p->where('user_id', $viewer->id)))
            ->firstOrFail();

        $more = Product::live()->where('page_id', $product->page_id)->whereKeyNot($product->id)
            ->with('images')->limit(6)->get()->map(fn ($x) => $this->productCard($x, $viewer));

        return response()->json(['data' => $this->productFull($product, $viewer) + ['more' => $more]]);
    }

    public function store(Request $request): JsonResponse
    {
        $page = $this->mine($request);
        $this->assertRoom($request->user(), 1);
        $data = $this->validated($request);

        $product = Product::create($data + ['page_id' => $page->id, 'slug' => Product::slugFor($data['name'])]);
        \App\Services\PageRanker::touch($page);

        return response()->json(['message' => 'Added. Now add its pictures.', 'data' => $this->productFull($product, $request->user())], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $product = $this->find($request, $uuid);
        $product->update($this->validated($request, true));
        \App\Services\PageRanker::touch($product->page);

        return response()->json(['message' => 'Saved.', 'data' => $this->productFull($product->fresh(), $request->user())]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $product = $this->find($request, $uuid);
        $product->delete();

        return response()->json(['message' => 'Removed.']);
    }

    /** "Interested" — one tap. Counted, and the owner is told who. */
    public function interested(Request $request, string $uuid): JsonResponse
    {
        $viewer = $request->user();
        $product = Product::live()->where('uuid', $uuid)->with('page.owner')->firstOrFail();
        abort_if($product->page->user_id === $viewer->id, 422, 'That is your own product.');

        $was = $product->interestedUsers()->where('users.id', $viewer->id)->exists();
        if ($was) {
            $product->interestedUsers()->detach($viewer->id);
        } else {
            $product->interestedUsers()->attach($viewer->id);
            $product->page->notifyTeam(new \App\Notifications\SocialNotification(
                'business_interest',
                "{$viewer->name} is interested in {$product->name}.",
                ['product_uuid' => $product->uuid, 'user_uuid' => $viewer->uuid],
                '/business?tab=products',
            ));
        }
        $product->update(['interested_count' => $product->interestedUsers()->count()]);

        return response()->json([
            'message' => $was ? 'Interest removed.' : 'Noted — ' . $product->page->name . ' has been told.',
            'data' => ['interested' => ! $was, 'interested_count' => $product->interested_count],
        ]);
    }

    // --- Pictures --------------------------------------------------------------------

    public function uploadImages(Request $request, string $uuid): JsonResponse
    {
        $product = $this->find($request, $uuid);
        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:' . Product::MAX_IMAGES],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $have = $product->images()->count();
        $incoming = count($request->file('images', []));
        abort_if($have + $incoming > Product::MAX_IMAGES, 422, 'A product carries ' . Product::MAX_IMAGES . ' pictures; this one has ' . $have . '.');

        $sort = (int) ($product->images()->max('sort') ?? -1);
        foreach ($request->file('images', []) as $file) {
            $product->images()->create(BusinessImage::store($file, 'business/products/' . $product->page_id) + ['sort' => ++$sort]);
        }
        $product->touch();
        \App\Services\PageRanker::touch($product->page);

        return response()->json(['message' => "{$incoming} picture" . ($incoming === 1 ? '' : 's') . ' added.', 'data' => $this->productFull($product->fresh(), $request->user())], 201);
    }

    public function deleteImage(Request $request, string $uuid, int $imageId): JsonResponse
    {
        $product = $this->find($request, $uuid);
        $image = $product->images()->findOrFail($imageId);
        BusinessImage::delete($image->path, $image->thumb_path);

        // A dashboard picture that was this file goes with it.
        $page = $product->page;
        if (in_array($page->hero_path, [$image->path, $image->thumb_path], true)) {
            $page->update(['hero_path' => null]);
        }
        $image->delete();
        \App\Services\PageRanker::touch($page);

        return response()->json(['message' => 'Picture removed.']);
    }

    public function reorderImages(Request $request, string $uuid): JsonResponse
    {
        $product = $this->find($request, $uuid);
        $data = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']]);
        foreach (array_values($data['order']) as $i => $id) {
            ProductImage::where('product_id', $product->id)->where('id', $id)->update(['sort' => $i]);
        }
        \App\Services\PageRanker::touch($product->page);

        return response()->json(['message' => 'Order saved.', 'data' => $this->productFull($product->fresh(), $request->user())]);
    }

    // --- Helpers ---------------------------------------------------------------------

    private function mine(Request $request): Page
    {
        $page = $request->user()->businessPage;
        abort_unless($page, 404, 'Join or open a company page first.');

        return $page;
    }

    private function find(Request $request, string $uuid): Product
    {
        return Product::where('page_id', $this->mine($request)->id)->where('uuid', $uuid)->firstOrFail();
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:160'],
            'kind' => ['nullable', Rule::in(Product::KINDS)],
            'summary' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:10000'],
            'category' => ['nullable', Rule::in(\App\Support\TradeCategories::LIST)],
            'specifications' => ['nullable', 'array', 'max:40'],
            'specifications.*.label' => ['required_with:specifications', 'string', 'max:80'],
            'specifications.*.value' => ['nullable', 'string', 'max:300'],
            'moq' => ['nullable', 'numeric', 'min:0'],
            'moq_unit' => ['nullable', 'string', 'max:40'],
            'price_type' => ['nullable', Rule::in(Product::PRICE_TYPES)],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0', 'gte:price_min'],
            'currency' => ['nullable', 'string', 'size:3'],
            'price_unit' => ['nullable', 'string', 'max:40'],
            'terms' => ['nullable', 'array', 'max:8'],
            'terms.*' => [Rule::in(Product::TERMS)],
            'keywords' => ['nullable', 'array', 'max:30'],
            'keywords.*' => ['string', 'max:40'],
            'status' => ['nullable', Rule::in(['active', 'hidden'])],
            'hs_code' => ['nullable', 'string', 'max:14', 'regex:/^[\d .]*$/'],
            'capacity' => ['nullable', 'string', 'max:80'],
            'certifications' => ['nullable', 'array', 'max:30'],
            'certifications.*' => ['string', 'max:60'],
            'export_markets' => ['nullable', 'array', 'max:60'],
            'export_markets.*' => ['string', 'size:2'],
        ]);
        if (array_key_exists('hs_code', $data)) {
            $data['hs_code'] = \App\Models\Business\TradeLine::normalizeCode($data['hs_code']);
        }
        if (isset($data['export_markets'])) {
            $data['export_markets'] = array_values(array_unique(array_map('strtoupper', $data['export_markets'])));
        }

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        if (($data['price_type'] ?? null) === 'fixed' && isset($data['price_min'])) {
            $data['price_max'] = $data['price_min'];
        }
        if (($data['price_type'] ?? null) === 'on_request') {
            $data['price_min'] = null;
            $data['price_max'] = null;
        }
        if (isset($data['keywords'])) {
            $data['keywords'] = collect($data['keywords'])->map(fn ($k) => trim($k))->filter()->unique()->values()->all();
        }

        return $data;
    }

    /** The plan decides how many products a page lists. */
    private function assertRoom(\App\Models\User $user, int $adding): void
    {
        $svc = app(\App\Services\SubscriptionEntitlementService::class);
        if ($svc->canAddProducts($user, $adding)) {
            return;
        }
        $limit = $svc->productLimit($user);
        $better = $svc->planWithHigherLimit('max_products', $limit ?? 0);
        abort(422, "Your plan lists up to {$limit} products." . ($better ? " The {$better->name} plan lists " . ($better->limit('max_products') === null ? 'unlimited' : $better->limit('max_products')) . '.' : ''));
    }

    // --- Bulk import --------------------------------------------------------------------------------

    public const IMPORT_COLUMNS = [
        'name', 'kind', 'category', 'summary', 'description', 'price_type', 'price_min', 'price_max', 'currency',
        'price_unit', 'moq', 'moq_unit', 'terms', 'keywords', 'status', 'hs_code', 'capacity', 'certifications', 'export_markets',
    ];

    /** The sheet to fill in: a header row and two examples. */
    public function importTemplate()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputs($out, "\xEF\xBB\xBF");
            fputcsv($out, self::IMPORT_COLUMNS);
            fputcsv($out, ['Brass Diya Lamp', 'product', 'Handicrafts', 'Hand-finished brass diya, 4 inch', 'Solid brass, gift boxed.', 'range', '2', '3', 'USD', 'pc', '500', 'pcs', 'FOB|CIF', 'brass|diya|lamp', 'active', '740699', '50,000 pcs/month', 'ISO 9001', 'US|GB|AE']);
            fputcsv($out, ['Export documentation', 'service', 'Services', 'DGFT and customs paperwork', '', 'on_request', '', '', 'INR', '', '', '', '', 'export|documentation|customs', 'active', '', '', '', '']);
            fclose($out);
        }, 'netvork-products-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Many products from one CSV.
     *
     * Excel saves as CSV; that is the whole requirement. Each row is checked
     * as if typed in; a bad row is skipped and named in the report, the rest
     * go in. Pictures come after, product by product — a spreadsheet cannot
     * carry them.
     */
    public function import(Request $request): JsonResponse
    {
        $page = $this->mine($request);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $raw = fgetcsv($handle);
        abort_unless($raw, 422, 'The file is empty.');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $raw);
        abort_unless(in_array('name', $header, true), 422, 'The first row must name the columns — download the template.');

        $rows = [];
        $n = 1;
        while (($line = fgetcsv($handle)) !== false) {
            $n++;
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $rows[$n] = array_combine($header, array_pad(array_map('trim', $line), count($header), ''));
            if (count($rows) > 300) {
                abort(422, 'Up to 300 rows at a time.');
            }
        }
        fclose($handle);
        abort_if($rows === [], 422, 'No rows under the header.');

        $this->assertRoom($request->user(), count($rows));

        $created = 0;
        $skipped = [];
        foreach ($rows as $line => $row) {
            $split = fn ($v) => array_values(array_filter(array_map('trim', preg_split('/[|;,]/', (string) $v))));
            $payload = [
                'name' => $row['name'] ?? '',
                'kind' => ($row['kind'] ?? '') ?: 'product',
                'category' => ($row['category'] ?? '') ?: null,
                'summary' => ($row['summary'] ?? '') ?: null,
                'description' => ($row['description'] ?? '') ?: null,
                'price_type' => ($row['price_type'] ?? '') ?: (($row['price_max'] ?? '') !== '' ? 'range' : (($row['price_min'] ?? '') !== '' ? 'fixed' : 'on_request')),
                'price_min' => ($row['price_min'] ?? '') !== '' ? $row['price_min'] : null,
                'price_max' => ($row['price_max'] ?? '') !== '' ? $row['price_max'] : null,
                'currency' => strtoupper(($row['currency'] ?? '') ?: 'USD'),
                'price_unit' => ($row['price_unit'] ?? '') ?: null,
                'moq' => ($row['moq'] ?? '') !== '' ? $row['moq'] : null,
                'moq_unit' => ($row['moq_unit'] ?? '') ?: null,
                'terms' => array_map('strtoupper', $split($row['terms'] ?? '')),
                'keywords' => array_map('mb_strtolower', $split($row['keywords'] ?? '')),
                'status' => ($row['status'] ?? '') ?: 'active',
                'hs_code' => \App\Models\Business\TradeLine::normalizeCode($row['hs_code'] ?? null),
                'capacity' => ($row['capacity'] ?? '') ?: null,
                'certifications' => $split($row['certifications'] ?? ''),
                'export_markets' => array_map('strtoupper', $split($row['export_markets'] ?? '')),
            ];
            $validator = \Illuminate\Support\Facades\Validator::make($payload, [
                'name' => ['required', 'string', 'min:2', 'max:160'],
                'kind' => [Rule::in(Product::KINDS)],
                'category' => ['nullable', 'string', 'max:120'],
                'summary' => ['nullable', 'string', 'max:300'],
                'description' => ['nullable', 'string', 'max:10000'],
                'price_type' => [Rule::in(Product::PRICE_TYPES)],
                'price_min' => ['nullable', 'numeric', 'min:0'],
                'price_max' => ['nullable', 'numeric', 'min:0', 'gte:price_min'],
                'currency' => ['string', 'size:3'],
                'price_unit' => ['nullable', 'string', 'max:40'],
                'moq' => ['nullable', 'numeric', 'min:0'],
                'moq_unit' => ['nullable', 'string', 'max:40'],
                'terms' => ['array', 'max:8'],
                'terms.*' => [Rule::in(Product::TERMS)],
                'keywords' => ['array', 'max:30'],
                'keywords.*' => ['string', 'max:40'],
                'status' => [Rule::in(['active', 'hidden'])],
                'hs_code' => ['nullable', 'string', 'max:10'],
                'capacity' => ['nullable', 'string', 'max:80'],
                'certifications' => ['array', 'max:30'],
                'certifications.*' => ['string', 'max:60'],
                'export_markets' => ['array', 'max:60'],
                'export_markets.*' => ['string', 'size:2'],
            ]);
            if ($validator->fails()) {
                $skipped[] = ['row' => $line, 'name' => $payload['name'], 'reason' => $validator->errors()->first()];
                continue;
            }
            $data = $validator->validated();
            if ($data['price_type'] === 'fixed') {
                $data['price_max'] = $data['price_min'];
            }
            if ($data['price_type'] === 'on_request') {
                $data['price_min'] = $data['price_max'] = null;
            }
            Product::create($data + ['page_id' => $page->id, 'slug' => Product::slugFor($data['name'])]);
            $created++;
        }
        if ($created > 0) {
            \App\Services\PageRanker::touch($page);
        }

        return response()->json([
            'message' => "{$created} product" . ($created === 1 ? '' : 's') . ' added.' . ($skipped ? ' ' . count($skipped) . ' row' . (count($skipped) === 1 ? '' : 's') . ' skipped.' : '') . ' Add pictures next — buyers decide on the picture first.',
            'data' => ['created' => $created, 'skipped' => $skipped],
        ], 201);
    }
}
