<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\SerializesBusiness;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Business\CompanyMember;
use App\Models\Business\Page;
use App\Models\Business\TradeLine;
use App\Notifications\SocialNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GrapOut's research, turned into pages — and the claims on them.
 *
 * A CSV of companies becomes unclaimed pages, one per row: name, country,
 * what it buys and sells with HS codes, and how to reach it. Buyers find
 * them; enquiries reach them by email; the people who work there claim
 * them. A super admin loads the file and decides the claims that could
 * not prove themselves by email domain.
 */
class SeedController extends Controller
{
    use SerializesBusiness;

    public const COLUMNS = ['ref', 'name', 'country', 'city', 'kind', 'tagline', 'website', 'email', 'phone', 'sells', 'buys', 'keywords', 'markets'];

    public function template()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputs($out, "\xEF\xBB\xBF");
            fputcsv($out, self::COLUMNS);
            fputcsv($out, ['GO-10021', 'ABC Hardware Inc', 'US', 'Houston', 'importer', 'Architectural hardware importer', 'abchardware.example', 'purchasing@abchardware.example', '+1 713 555 0100', '', '8302 Door handles and hinges; 7318 Screws and bolts', 'door hardware|hinges', 'US|CA']);
            fputcsv($out, ['GO-10022', 'XYZ Hardware Pvt Ltd', 'IN', 'Aligarh', 'manufacturer', 'Brass and stainless door hardware', 'xyzhardware.example', 'export@xyzhardware.example', '', '8302 Stainless steel door handles; 830210 Hinges', '', 'brass|handles', 'GB|AE|US']);
            fclose($out);
        }, 'grapout-companies-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function status(): JsonResponse
    {
        return response()->json(['data' => [
            'seeded' => Page::whereNotNull('seeded_source')->count(),
            'claimed' => Page::whereNotNull('seeded_source')->whereNotNull('claimed_at')->count(),
            'unclaimed' => Page::whereNotNull('seeded_source')->whereNull('claimed_at')->count(),
            'claims_waiting' => CompanyMember::where('is_claim', true)->where('status', 'requested')->count(),
            'sources' => Page::whereNotNull('seeded_source')->selectRaw('seeded_source, COUNT(*) as n, MAX(created_at) as at')->groupBy('seeded_source')->orderByDesc('at')->limit(10)->get(),
        ]]);
    }

    /** One CSV, many pages. A row with a ref is updated when loaded again. */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'], 'source' => ['nullable', 'string', 'max:80']]);
        $admin = $request->user();
        $source = trim((string) ($request->input('source') ?: $request->file('file')->getClientOriginalName()));
        $source = substr($source, 0, 80);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $raw = fgetcsv($handle);
        abort_unless($raw, 422, 'The file is empty.');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $raw);
        abort_unless(in_array('name', $header, true) && in_array('country', $header, true), 422, 'The first row must name the columns — download the template.');

        $created = 0;
        $updated = 0;
        $skipped = [];
        $n = 1;
        $split = fn ($v) => array_values(array_filter(array_map('trim', preg_split('/[|]/', (string) $v))));
        while (($line = fgetcsv($handle)) !== false) {
            $n++;
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $row = array_combine($header, array_pad(array_map('trim', $line), count($header), ''));
            $name = $row['name'] ?? '';
            $country = strtoupper($row['country'] ?? '');
            if (mb_strlen($name) < 2 || strlen($country) !== 2) {
                $skipped[] = ['row' => $n, 'name' => $name, 'reason' => 'Needs a name and a two-letter country.'];
                continue;
            }
            if ($n > 5001) {
                abort(422, 'Up to 5,000 rows at a time.');
            }
            $ref = ($row['ref'] ?? '') ?: null;
            $kind = in_array($row['kind'] ?? '', Page::KINDS, true) ? $row['kind'] : 'trader';

            $attrs = [
                'name' => $name, 'country' => $country, 'city' => ($row['city'] ?? '') ?: null, 'kind' => $kind,
                'tagline' => ($row['tagline'] ?? '') ?: null, 'website' => ($row['website'] ?? '') ?: null,
                'email' => ($row['email'] ?? '') ?: null, 'phone' => ($row['phone'] ?? '') ?: null,
                'keywords' => array_map('mb_strtolower', $split($row['keywords'] ?? '')),
                'markets' => array_map('strtoupper', $split($row['markets'] ?? '')),
                'seeded_source' => $source, 'seeded_ref' => $ref,
            ];
            $page = $ref ? Page::where('seeded_ref', $ref)->whereNotNull('seeded_source')->first() : null;
            $page ??= Page::whereNotNull('seeded_source')->whereNull('claimed_at')->where('name', $name)->where('country', $country)->first();

            if ($page) {
                // A claimed page belongs to its people now; the research does not overwrite them.
                if ($page->claimed_at) {
                    $skipped[] = ['row' => $n, 'name' => $name, 'reason' => 'Already claimed — left as its owner set it.'];
                    continue;
                }
                $page->update($attrs);
                $updated++;
            } else {
                $page = Page::create($attrs + ['user_id' => $admin->id, 'slug' => Page::slugFor($name), 'status' => 'active', 'show_contacts' => false]);
                $created++;
            }

            $page->tradeLines()->delete();
            $sort = 0;
            foreach (['sell' => 'sells', 'buy' => 'buys'] as $direction => $col) {
                foreach (array_filter(array_map('trim', explode(';', (string) ($row[$col] ?? '')))) as $item) {
                    preg_match('/^(\d[\d.]{1,11})?\s*(.*)$/', $item, $m);
                    $code = TradeLine::normalizeCode($m[1] ?? null);
                    $desc = trim($m[2] ?? '') ?: ($code ? "HS {$code}" : $item);
                    $page->tradeLines()->create(['direction' => $direction, 'hs_code' => $code, 'description' => mb_substr($desc, 0, 200), 'sort' => $sort++]);
                }
            }
        }
        fclose($handle);
        AuditLog::record($admin, 'business.seed.imported', null, ['source' => $source, 'created' => $created, 'updated' => $updated, 'skipped' => count($skipped)]);

        return response()->json([
            'message' => "{$created} companies added, {$updated} updated" . ($skipped ? ', ' . count($skipped) . ' rows skipped' : '') . '.',
            'data' => ['created' => $created, 'updated' => $updated, 'skipped' => array_slice($skipped, 0, 50)],
        ], 201);
    }

    // --- Claims ------------------------------------------------------------------------------------------

    public function claims(Request $request): JsonResponse
    {
        $status = $request->query('status', 'requested');
        $rows = CompanyMember::with(['page', 'user.profile'])->where('is_claim', true)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('created_at')->paginate(30);
        $rows->getCollection()->transform(fn (CompanyMember $m) => [
            'id' => $m->id,
            'status' => $m->status,
            'note' => $m->note,
            'function' => $m->function,
            'title' => $m->title,
            'created_at' => $m->created_at?->toDateTimeString(),
            'page' => $m->page ? ['uuid' => $m->page->uuid, 'slug' => $m->page->slug, 'name' => $m->page->name, 'country' => $m->page->country, 'website' => $m->page->website, 'email' => $m->page->email, 'claimed' => $m->page->claimed_at !== null] : null,
            'user' => $m->user ? ['uuid' => $m->user->uuid, 'name' => $m->user->name, 'email' => $m->user->email, 'verified' => $m->user->email_verified_at !== null, 'headline' => $m->user->profile?->headline, 'company_name' => $m->user->profile?->company_name] : null,
        ]);

        return response()->json($rows);
    }

    public function decideClaim(Request $request, int $id): JsonResponse
    {
        $m = CompanyMember::with(['page', 'user'])->where('is_claim', true)->findOrFail($id);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:500']]);
        abort_if($m->status !== 'requested', 422, 'This claim was already decided.');
        abort_if($data['decision'] === 'approve' && $m->page->claimed_at, 422, 'The page was claimed by somebody else meanwhile.');

        if ($data['decision'] === 'approve') {
            $m->page->grantTo($m->user, $m->function, $m->title);
            $m->user?->notify(new SocialNotification('company_claim', "{$m->page->name} is yours: the GrapOut team approved your claim.", ['page_slug' => $m->page->slug], '/business', 'company-claim-' . $m->id));
        } else {
            $m->update(['status' => 'invited', 'note' => trim(($m->note ? $m->note . "\n" : '') . 'Rejected: ' . ($data['note'] ?? ''))]);
            $m->delete();
            $m->user?->notify(new SocialNotification('company_claim', "Your claim on {$m->page->name} was not approved." . (! empty($data['note']) ? " Note: {$data['note']}" : ''), ['page_slug' => $m->page->slug], '/c/' . $m->page->slug, 'company-claim-' . $m->id));
        }
        AuditLog::record($request->user(), 'business.claim.' . $data['decision'], $m->user, ['page' => $m->page->slug, 'note' => $data['note'] ?? null]);

        return response()->json(['message' => $data['decision'] === 'approve' ? "{$m->user?->name} now owns {$m->page->name}." : 'Rejected.']);
    }
}
