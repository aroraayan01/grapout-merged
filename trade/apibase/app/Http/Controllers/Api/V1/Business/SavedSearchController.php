<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Models\SavedSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Standing questions, kept and answered daily.
 */
class SavedSearchController extends Controller
{
    private function row(SavedSearch $s): array
    {
        return [
            'uuid' => $s->uuid,
            'kind' => $s->kind,
            'q' => $s->q,
            'country' => $s->country,
            'category' => $s->category,
            'label' => $s->label(),
            'path' => $s->path(),
            'active' => $s->active,
            'last_run_at' => $s->last_run_at?->toDateTimeString(),
            'last_hits' => $s->last_hits,
            'created_at' => $s->created_at?->toDateTimeString(),
        ];
    }

    /** Mine, and my page's; the GrapOut team with no page picked sees everyone's. */
    private function owned(Request $request)
    {
        $me = $request->user();
        $myPage = $me->businessPage()->value('business_pages.id');

        return SavedSearch::query()->when(! ($me->isStaff() && ! $myPage), fn ($q) => $q->where(fn ($w) => $w->where('user_id', $me->id)->when($myPage, fn ($x) => $x->orWhere('page_id', $myPage))));
    }

    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $rows = $this->owned($request)->with('user')->orderByDesc('created_at')->get();

        return response()->json(['data' => $rows->map(fn ($s) => $this->row($s) + ['by' => $s->user_id === $me->id ? null : $s->user?->name])]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate([
            'kind' => ['required', Rule::in(SavedSearch::KINDS)],
            'q' => ['nullable', 'string', 'max:200'],
            'country' => ['nullable', 'string', 'size:2'],
            'category' => ['nullable', 'string', 'max:80'],
        ]);
        abort_if(empty($data['q']) && empty($data['country']) && empty($data['category']), 422, 'Say what to watch for.');
        abort_if(SavedSearch::where('user_id', $me->id)->count() >= 20, 422, 'Twenty saved searches is the most. Remove one first.');
        $data['country'] = isset($data['country']) ? strtoupper($data['country']) : null;

        $s = SavedSearch::firstOrCreate(
            ['user_id' => $me->id, 'kind' => $data['kind'], 'q' => $data['q'] ?? null, 'country' => $data['country'], 'category' => $data['category'] ?? null],
            ['active' => true, 'last_run_at' => now(), 'page_id' => $me->businessPage()->value('business_pages.id')],
        );

        return response()->json(['message' => $s->wasRecentlyCreated ? "Saved. You will hear when something new matches {$s->label()}." : 'You already watch this.', 'data' => $this->row($s)], $s->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $s = $this->owned($request)->where('uuid', $uuid)->firstOrFail();
        $s->update($request->validate(['active' => ['required', 'boolean']]));

        return response()->json(['message' => $s->active ? 'Alerts on.' : 'Alerts paused.', 'data' => $this->row($s)]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->owned($request)->where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Removed.']);
    }
}
