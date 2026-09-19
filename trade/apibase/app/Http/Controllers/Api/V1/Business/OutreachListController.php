<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\Page;
use App\Models\Outreach\Cohort;
use App\Models\Outreach\Contact;
use App\Models\Outreach\ContactList;
use App\Models\Outreach\InboxMessage;
use App\Models\Outreach\Sequence;
use App\Models\Outreach\SequenceStep;
use App\Models\Outreach\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The rest of a page's Hot Leads: its lists, its cohorts (saved slices
 * of the list), its sequences (a first email and the follow-ups), and
 * the inbox of what came back.
 */
class OutreachListController extends Controller
{
    private function managed(Request $request): Page
    {
        $page = $request->user()->businessPage()->first();
        abort_unless($page && $page->canManage($request->user()), 403, 'Only the owner or an admin runs the hot leads list.');

        return $page;
    }

    // --- Lists --------------------------------------------------------------------------

    public function lists(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $rows = ContactList::withCount('contacts')->where('page_id', $page->id)->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn ($l) => self::listRow($l))->values()]);
    }

    public function storeList(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:255']]);
        abort_if(ContactList::where('page_id', $page->id)->where('name', $data['name'])->exists(), 422, 'A list with that name exists.');
        $list = ContactList::create($data + ['page_id' => $page->id, 'created_by' => $request->user()->id]);

        return response()->json(['message' => 'List added.', 'data' => self::listRow($list->loadCount('contacts'))], 201);
    }

    public function updateList(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        $list = ContactList::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail();
        $list->update($request->validate(['name' => ['sometimes', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:255']]));

        return response()->json(['message' => 'Saved.', 'data' => self::listRow($list->fresh()->loadCount('contacts'))]);
    }

    public function destroyList(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        ContactList::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'List removed. The contacts stay.']);
    }

    /** Put contacts on a list, or take them off it. */
    public function assign(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        $list = ContactList::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(['contacts' => ['required', 'array', 'max:5000'], 'contacts.*' => ['string'], 'remove' => ['nullable', 'boolean']]);
        $ids = Contact::where('page_id', $page->id)->whereIn('uuid', $data['contacts'])->pluck('id')->all();
        if (! empty($data['remove'])) {
            $list->contacts()->detach($ids);
            $message = count($ids) . ' taken off ' . $list->name . '.';
        } else {
            $list->contacts()->syncWithoutDetaching($ids);
            $message = count($ids) . ' put on ' . $list->name . '.';
        }

        return response()->json(['message' => $message, 'data' => self::listRow($list->fresh()->loadCount('contacts'))]);
    }

    public static function listRow(ContactList $l): array
    {
        return ['uuid' => $l->uuid, 'name' => $l->name, 'description' => $l->description, 'contacts' => (int) ($l->contacts_count ?? 0), 'created_at' => $l->created_at?->toDateTimeString()];
    }

    // --- Cohorts ------------------------------------------------------------------------

    public function cohorts(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $rows = Cohort::where(fn ($q) => $q->whereNull('page_id')->orWhere('page_id', $page->id))->orderByRaw('page_id IS NULL')->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn ($c) => self::cohortRow($c, $page))->values(), 'filters' => Cohort::FILTERS]);
    }

    public function storeCohort(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $data = $request->validate(self::cohortRules());
        $c = Cohort::create(['page_id' => $page->id, 'name' => $data['name'], 'description' => $data['description'] ?? null, 'filters' => Cohort::cleanFilters($data['filters'] ?? []), 'created_by' => $request->user()->id]);

        return response()->json(['message' => 'Cohort saved.', 'data' => self::cohortRow($c, $page)], 201);
    }

    public function updateCohort(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        $c = Cohort::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(self::cohortRules(false));
        $c->update(array_filter(['name' => $data['name'] ?? null, 'description' => array_key_exists('description', $data) ? $data['description'] : $c->description, 'filters' => array_key_exists('filters', $data) ? Cohort::cleanFilters($data['filters'] ?? []) : null], fn ($v) => $v !== null));

        return response()->json(['message' => 'Saved.', 'data' => self::cohortRow($c->fresh(), $page)]);
    }

    public function destroyCohort(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        Cohort::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Cohort removed.']);
    }

    /** Who is in it right now: the count and the first few. */
    public function previewCohort(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        $c = Cohort::where(fn ($q) => $q->whereNull('page_id')->orWhere('page_id', $page->id))->where('uuid', $uuid)->firstOrFail();
        $q = $c->apply(Contact::where('page_id', $page->id)->whereNotIn('email_status', ['unsubscribed', 'bounced']));

        return response()->json(['data' => ['count' => (clone $q)->count(), 'sample' => $q->orderByDesc('id')->limit(8)->get()->map(fn ($x) => ['email' => $x->email, 'company_name' => $x->company_name, 'contact_name' => $x->contact_name])->values()]]);
    }

    public static function cohortRules(bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes';

        return [
            'name' => [$r, 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'filters' => [$r, 'array'],
            'filters.status' => ['nullable', Rule::in(['unknown', 'valid'])],
            'filters.country' => ['nullable', 'string', 'size:2'],
            'filters.lists' => ['nullable', 'array', 'max:50'],
            'filters.never_sent' => ['nullable', 'boolean'],
            'filters.replied' => ['nullable'],
            'filters.sent_days_ago' => ['nullable', 'integer', 'min:0', 'max:365'],
            'filters.no_reply_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }

    public static function cohortRow(Cohort $c, ?Page $page = null): array
    {
        $count = $page ? $c->apply(Contact::where('page_id', $page->id)->whereNotIn('email_status', ['unsubscribed', 'bounced']))->count() : null;

        return [
            'uuid' => $c->uuid, 'name' => $c->name, 'description' => $c->description, 'filters' => $c->filters ?? [], 'summary' => $c->describe(),
            'own' => $c->page_id !== null, 'count' => $count, 'created_at' => $c->created_at?->toDateTimeString(),
        ];
    }

    // --- Sequences ----------------------------------------------------------------------

    public function sequences(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $rows = Sequence::with('steps.template')->withCount('campaigns')->where(fn ($q) => $q->whereNull('page_id')->orWhere('page_id', $page->id))->orderByRaw('page_id IS NULL')->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn ($s) => self::sequenceRow($s))->values()]);
    }

    public function storeSequence(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $data = $request->validate(self::sequenceRules());
        $s = Sequence::create(['page_id' => $page->id, 'name' => $data['name'], 'description' => $data['description'] ?? null, 'stop_on_reply' => $data['stop_on_reply'] ?? true, 'stop_on_click' => $data['stop_on_click'] ?? false, 'active' => $data['active'] ?? true, 'created_by' => $request->user()->id]);
        self::putSteps($s, $data['steps'], $page);

        return response()->json(['message' => 'Sequence saved.', 'data' => self::sequenceRow($s->fresh(['steps.template'])->loadCount('campaigns'))], 201);
    }

    public function updateSequence(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        $s = Sequence::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(self::sequenceRules(false));
        $s->update(array_intersect_key($data, array_flip(['name', 'description', 'stop_on_reply', 'stop_on_click', 'active'])));
        if (isset($data['steps'])) {
            self::putSteps($s, $data['steps'], $page);
        }

        return response()->json(['message' => 'Saved.', 'data' => self::sequenceRow($s->fresh(['steps.template'])->loadCount('campaigns'))]);
    }

    public function destroySequence(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        $s = Sequence::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail();
        abort_if($s->campaigns()->where('status', 'sending')->exists(), 422, 'A campaign is sending this sequence. Pause it first.');
        $s->delete();

        return response()->json(['message' => 'Sequence removed. Campaigns that used it stop after the emails already sent.']);
    }

    public static function sequenceRules(bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes';

        return [
            'name' => [$r, 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'stop_on_reply' => ['nullable', 'boolean'],
            'stop_on_click' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'steps' => [$r, 'array', 'min:1', 'max:8'],
            'steps.*.template' => ['nullable', 'string'],
            'steps.*.template_kind' => ['nullable', Rule::in(['invitation', 'catalogue'])],
            'steps.*.delay_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'steps.*.only_if_no_reply' => ['nullable', 'boolean'],
        ];
    }

    /** Replace the steps. A template named must be the page's own or the platform's. */
    public static function putSteps(Sequence $s, array $steps, ?Page $page): void
    {
        $s->steps()->delete();
        foreach (array_values($steps) as $i => $st) {
            $template = null;
            if (! empty($st['template'])) {
                $template = Template::where('active', true)->where(fn ($q) => $q->whereNull('page_id')->when($page, fn ($qq) => $qq->orWhere('page_id', $page->id)))->where('uuid', $st['template'])->first();
                abort_if(! $template, 422, 'Step ' . ($i + 1) . ': that template is not yours to use.');
            }
            SequenceStep::create([
                'sequence_id' => $s->id, 'position' => $i + 1, 'template_id' => $template?->id,
                'template_kind' => $template ? ($template->kind === 'catalogue' ? 'catalogue' : 'invitation') : ($st['template_kind'] ?? 'invitation'),
                'delay_days' => $i === 0 ? 0 : (int) ($st['delay_days'] ?? 3),
                'only_if_no_reply' => $i === 0 ? false : (bool) ($st['only_if_no_reply'] ?? true),
            ]);
        }
    }

    public static function sequenceRow(Sequence $s): array
    {
        return [
            'uuid' => $s->uuid, 'name' => $s->name, 'description' => $s->description, 'stop_on_reply' => $s->stop_on_reply, 'stop_on_click' => $s->stop_on_click, 'active' => $s->active,
            'own' => $s->page_id !== null, 'campaigns' => (int) ($s->campaigns_count ?? 0), 'created_at' => $s->created_at?->toDateTimeString(),
            'steps' => $s->steps->map(fn (SequenceStep $st) => [
                'position' => $st->position, 'template' => $st->template ? ['uuid' => $st->template->uuid, 'name' => $st->template->name, 'kind' => $st->template->kind] : null,
                'template_kind' => $st->template_kind, 'delay_days' => (int) $st->delay_days, 'only_if_no_reply' => (bool) $st->only_if_no_reply,
            ])->values(),
        ];
    }

    // --- Inbox --------------------------------------------------------------------------

    /** What came back to this page's emails, newest first. */
    public function inbox(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $rows = InboxMessage::with(['contact', 'send', 'campaign', 'mailbox'])->where('page_id', $page->id)
            ->when($request->query('unread'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('received_at')->orderByDesc('id')->paginate(30);
        $rows->getCollection()->transform(fn ($m) => self::inboxRow($m));

        return response()->json($rows);
    }

    public function readInbox(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        $m = InboxMessage::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail();
        $m->update(['read_at' => $m->read_at ?? now()]);

        return response()->json(['message' => 'Read.', 'data' => self::inboxRow($m->fresh(['contact', 'send', 'campaign', 'mailbox']))]);
    }

    public static function inboxRow(InboxMessage $m): array
    {
        return [
            'uuid' => $m->uuid, 'from_email' => $m->from_email, 'from_name' => $m->from_name, 'subject' => $m->subject, 'snippet' => $m->snippet, 'body_text' => $m->body_text,
            'received_at' => $m->received_at?->toDateTimeString(), 'read_at' => $m->read_at?->toDateTimeString(), 'is_reply' => $m->is_reply,
            'contact' => $m->contact ? ['uuid' => $m->contact->uuid, 'company_name' => $m->contact->company_name, 'contact_name' => $m->contact->contact_name, 'email' => $m->contact->email] : null,
            'answers' => $m->send ? ['subject' => $m->send->subject, 'sent_at' => $m->send->sent_at?->toDateTimeString(), 'template' => $m->send->template, 'step' => (int) $m->send->step_position] : null,
            'campaign' => $m->campaign?->name, 'mailbox' => $m->mailbox?->label,
            'page' => $m->relationLoaded('page') && $m->page ? ['name' => $m->page->name, 'slug' => $m->page->slug] : null,
        ];
    }
}
