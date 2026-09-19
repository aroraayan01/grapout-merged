<?php

namespace App\Http\Controllers\Api\V1\Grap;

use App\Http\Controllers\Controller;
use App\Models\Grap\Lead;
use App\Models\Grap\LeadList;
use App\Models\Grap\Reveal;
use App\Services\SubscriptionEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The lists a person files leads into, and getting them back out.
 *
 * This is the old My Contacts, with two differences. A list takes buyers
 * and suppliers together rather than one type each. And filing a lead is
 * separate from unlocking it: on the old site the only way into a folder
 * was to spend a credit, so there was no way to set something aside to
 * decide about later.
 */
class GrapListController extends Controller
{
    public function __construct(private SubscriptionEntitlementService $plans) {}

    private function row(LeadList $l): array
    {
        return [
            'uuid' => $l->uuid,
            'name' => $l->name,
            'note' => $l->note,
            'count' => $l->leads_count ?? $l->leads()->count(),
            'created_at' => $l->created_at?->toDateTimeString(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $rows = LeadList::where('user_id', $request->user()->id)
            ->withCount('leads')->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn ($l) => $this->row($l))]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        abort_if(LeadList::where('user_id', $me->id)->count() >= 50, 422, 'Fifty lists is the most. Remove one first.');
        abort_if(
            LeadList::where('user_id', $me->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->exists(),
            422,
            'You already have a list with that name.'
        );

        $list = LeadList::create($data + ['user_id' => $me->id]);

        return response()->json(['data' => $this->row($list)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $list = $this->mine($request, $uuid);
        $list->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ]));

        return response()->json(['data' => $this->row($list)]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->mine($request, $uuid)->delete();

        return response()->json(['message' => 'List removed.']);
    }

    /** The leads on one list, masked or not according to what has been unlocked. */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $list = $this->mine($request, $uuid);

        $leads = $list->leads()->orderBy('grap_list_lead.created_at', 'desc')->get();
        $revealed = Reveal::where('user_id', $me->id)
            ->whereIn('grap_lead_id', $leads->pluck('id'))->pluck('grap_lead_id')->flip();

        return response()->json([
            'data' => $this->row($list),
            'leads' => $leads->map(fn (Lead $l) => $l->forViewer($me, $revealed->has($l->id)) + ['note' => $l->pivot->note]),
        ]);
    }

    /** Put leads on a list, or take them off. Both directions, one endpoint. */
    public function assign(Request $request, string $uuid): JsonResponse
    {
        $list = $this->mine($request, $uuid);
        $data = $request->validate([
            'leads' => ['required', 'array', 'min:1', 'max:200'],
            'leads.*' => ['string'],
            'remove' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $ids = Lead::whereIn('uuid', $data['leads'])->pluck('id');
        abort_if($ids->isEmpty(), 422, 'None of those leads exist.');

        if ($data['remove'] ?? false) {
            $list->leads()->detach($ids);

            return response()->json(['message' => $ids->count() === 1 ? 'Taken off the list.' : "{$ids->count()} taken off the list."]);
        }

        // syncWithoutDetaching so adding the same lead twice is not an error;
        // people select a page of results and add it more than once.
        $list->leads()->syncWithoutDetaching(
            $ids->mapWithKeys(fn ($id) => [$id => ['note' => $data['note'] ?? null]])->all()
        );

        return response()->json(['message' => $ids->count() === 1 ? 'Added to the list.' : "{$ids->count()} added to the list."]);
    }

    /**
     * A list as a spreadsheet.
     *
     * CSV rather than the old site's xlsx: it opens in Excel just the same,
     * it streams instead of building the whole workbook in memory, and it
     * does not need PHPExcel — which the old site carried at 4MB and two
     * copies of.
     *
     * Only unlocked leads carry their contact details. Exporting is not a
     * way around the allowance.
     */
    public function export(Request $request, string $uuid): StreamedResponse
    {
        $me = $request->user();
        abort_if(! $this->plans->hasFeature($me, 'grap_export'), 403, 'Exporting is not on your plan.');

        $list = $this->mine($request, $uuid);
        $revealed = Reveal::where('user_id', $me->id)->pluck('grap_lead_id')->flip();
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $list->name) ?: 'list';

        return response()->streamDownload(function () use ($list, $me, $revealed) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel needs the BOM to read UTF-8.
            // The 28 columns, less the ones nobody exports: the same fields
            // the screen shows, in the order it shows them.
            fputcsv($out, [
                'Kind', 'Company', 'Country', 'Contact', 'Designation', 'Email', 'Phone', 'Mobile',
                'Second contact', 'Second designation', 'Second email', 'Second phone',
                'Category', 'Brief intro', 'Website', 'LinkedIn', 'Address', 'Source', 'Note',
            ]);

            $list->leads()->chunk(500, function ($chunk) use ($out, $me, $revealed) {
                foreach ($chunk as $lead) {
                    $r = $lead->forViewer($me, $revealed->has($lead->id));
                    fputcsv($out, [
                        $r['kind'], $r['company_name'], $r['country'], $r['contact_person'], $r['designation'],
                        $r['email'], $r['phone'], $r['mobile'],
                        $r['contact_person_2'], $r['designation_2'], $r['email_2'], $r['phone_2'],
                        $r['business_category'], $r['brief_intro'], $r['website'], $r['linkedin_url'],
                        $r['company_address'], $r['data_source'], $lead->pivot->note,
                    ]);
                }
            });

            fclose($out);
        }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function mine(Request $request, string $uuid): LeadList
    {
        return LeadList::where('user_id', $request->user()->id)->where('uuid', $uuid)->firstOrFail();
    }
}
