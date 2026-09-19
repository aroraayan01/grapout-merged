<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\TradeEvent;
use App\Models\Event;
use App\Models\User;
use App\Support\BooleanQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Trade shows and buyer meets.
 *
 * Anybody can read the calendar; a member posts one and marks the ones
 * they are going to. Going puts the dates into their own GrapOut
 * calendar, so the reminders they already have cover it.
 */
class TradeEventController extends Controller
{
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'kind' => ['nullable', Rule::in(TradeEvent::KINDS)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'venue' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'size:2'],
            'website' => ['nullable', 'string', 'max:200'],
            'keywords' => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:40'],
        ];
    }

    private function viewer(Request $request): ?User
    {
        if ($request->user()) {
            return $request->user();
        }
        $bearer = $request->bearerToken();
        $user = $bearer ? PersonalAccessToken::findToken($bearer)?->tokenable : null;

        return $user instanceof User && $user->status === 'active' ? $user : null;
    }

    private function row(TradeEvent $e, ?User $viewer): array
    {
        $e->loadMissing(['page', 'poster']);

        return [
            'uuid' => $e->uuid,
            'title' => $e->title,
            'description' => $e->description,
            'kind' => $e->kind,
            'starts_on' => $e->starts_on?->toDateString(),
            'ends_on' => $e->ends_on?->toDateString(),
            'venue' => $e->venue,
            'city' => $e->city,
            'country' => $e->country,
            'website' => $e->website,
            'keywords' => $e->keywords ?? [],
            'status' => $e->status,
            'attendees_count' => $e->attendees_count,
            'page' => $e->page ? ['slug' => $e->page->slug, 'name' => $e->page->name, 'logo_path' => $e->page->logo_path] : null,
            'poster' => $viewer && $e->poster ? ['name' => $e->poster->name] : null,
            'is_mine' => $viewer ? $e->user_id === $viewer->id : false,
            'attending' => $viewer ? $e->attendees()->where('users.id', $viewer->id)->exists() : false,
            'created_at' => $e->created_at?->toDateTimeString(),
        ];
    }

    /** Upcoming, soonest first. Public. */
    public function index(Request $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $q = trim((string) $request->query('q'));
        $rows = TradeEvent::with(['page', 'poster'])
            ->when($request->boolean('mine') && $viewer, function ($b) use ($viewer) {
                $myPage = $viewer->businessPage()->value('business_pages.id');
                if (! $myPage && $viewer->isStaff()) {
                    return $b->whereNotNull('page_id'); // the GrapOut team, no page picked: every page's
                }

                return $b->where(fn ($w) => $w->where('user_id', $viewer->id)->when($myPage, fn ($x) => $x->orWhere('page_id', $myPage))->orWhereHas('attendees', fn ($a) => $a->where('users.id', $viewer->id)));
            })
            ->when(! ($request->boolean('mine') && $viewer), fn ($b) => $b->upcoming())
            ->when(mb_strlen($q) >= 2, fn ($b) => BooleanQuery::apply($b, BooleanQuery::parse($q), function (Builder $w, string $t) {
                $like = '%' . mb_strtolower($t) . '%';
                $w->whereRaw('LOWER(title) LIKE ?', [$like])->orWhereRaw('LOWER(description) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(keywords) LIKE ?', [$like])->orWhereRaw('LOWER(city) LIKE ?', [$like])->orWhereRaw('LOWER(venue) LIKE ?', [$like]);
            }))
            ->when($request->query('country'), fn ($b, $cc) => $b->where('country', strtoupper($cc)))
            ->when(preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : null, function ($b, $m) {
                $from = \Carbon\Carbon::createFromFormat('Y-m-d', $m . '-01')->startOfMonth();
                $b->whereBetween('starts_on', [$from->toDateString(), $from->copy()->endOfMonth()->toDateString()]);
            })
            ->orderBy('starts_on')
            ->paginate(20);
        $rows->getCollection()->transform(fn (TradeEvent $e) => $this->row($e, $viewer));

        return response()->json($rows);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $viewer = $this->viewer($request);
        $e = TradeEvent::with(['page', 'poster'])->where('uuid', $uuid)->firstOrFail();
        abort_if($e->status !== 'listed' && ! ($viewer && ($e->user_id === $viewer->id || $viewer->isSuperAdmin())), 404);
        $row = $this->row($e, $viewer);
        // Who else is going, as pages — the point of a fair is who you meet.
        $row['attending_pages'] = $e->attendees()->with('businessPage')->get()
            ->map(fn (User $u) => $u->businessPage)->filter()
            ->map(fn ($p) => ['slug' => $p->slug, 'name' => $p->name, 'logo_path' => $p->logo_path, 'country' => $p->country])->values();

        return response()->json(['data' => $row]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate($this->rules());
        $data['country'] = isset($data['country']) ? strtoupper($data['country']) : null;
        $e = TradeEvent::create($data + ['user_id' => $me->id, 'page_id' => $me->businessPage()->live()->value('business_pages.id')]);

        return response()->json(['message' => 'Listed.', 'data' => $this->row($e, $me)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $e = TradeEvent::with('page')->where('uuid', $uuid)->firstOrFail();
        abort_unless($e->user_id === $me->id || $me->isStaff() || ($e->page && $e->page->canManage($me)), 403);
        $data = $request->validate(array_map(fn ($r) => array_merge(['sometimes'], $r), $this->rules()) + ['status' => ['sometimes', Rule::in(['listed', 'hidden'])]]);
        if (isset($data['country'])) {
            $data['country'] = strtoupper($data['country']);
        }
        $e->update($data);

        return response()->json(['message' => 'Saved.', 'data' => $this->row($e->fresh(), $me)]);
    }

    /** Going / not going. Going writes the dates into the personal calendar. */
    public function attend(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $e = TradeEvent::where('uuid', $uuid)->firstOrFail();
        $pivot = $e->attendees()->where('users.id', $me->id)->first()?->pivot;

        if ($pivot) {
            if ($pivot->calendar_event_id) {
                Event::whereKey($pivot->calendar_event_id)->where('user_id', $me->id)->delete();
            }
            $e->attendees()->detach($me->id);
            $e->decrement('attendees_count');

            return response()->json(['message' => 'Removed from your calendar.', 'data' => ['attending' => false, 'attendees_count' => $e->fresh()->attendees_count]]);
        }

        $calendar = $me->events()->create([
            'title' => $e->title,
            'description' => trim(($e->description ? $e->description . "\n\n" : '') . ($e->website ? "Website: {$e->website}\n" : '') . 'From GrapOut Trade.'),
            'type' => in_array('event', Event::TYPES, true) ? 'event' : Event::TYPES[0],
            'starts_at' => $e->starts_on->copy()->startOfDay(),
            'ends_at' => ($e->ends_on ?? $e->starts_on)->copy()->endOfDay(),
            'all_day' => true,
            'location' => implode(', ', array_filter([$e->venue, $e->city, $e->country])),
        ]);
        $e->attendees()->attach($me->id, ['calendar_event_id' => $calendar->id]);
        $e->increment('attendees_count');

        return response()->json(['message' => 'Added to your calendar.', 'data' => ['attending' => true, 'attendees_count' => $e->fresh()->attendees_count]]);
    }
}
