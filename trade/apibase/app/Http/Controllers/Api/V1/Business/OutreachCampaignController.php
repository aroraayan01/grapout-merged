<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Jobs\SendOutreachMail;
use App\Models\Business\Page;
use App\Models\Outreach\Campaign;
use App\Models\Outreach\Contact;
use App\Models\Outreach\Mailbox;
use App\Models\Outreach\Send;
use App\Models\Outreach\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Campaigns, templates and figures, for the company that owns the list.
 *
 * A template is words with placeholders; a campaign is a template, a
 * slice of the list and a time; the figures say what came of it. The
 * mailbox and the limits stay the GrapOut team's to set.
 */
class OutreachCampaignController extends Controller
{
    public const MAX_PER_CAMPAIGN = 2000;

    private function managed(Request $request): Page
    {
        $page = $request->user()->businessPage()->first();
        abort_unless($page && $page->canManage($request->user()), 403, 'Only the owner or an admin runs the hot leads list.');

        return $page;
    }

    // --- Templates ------------------------------------------------------------------

    /** The platform's templates and the page's own. */
    public function templates(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $rows = Template::where('active', true)->where(fn ($q) => $q->whereNull('page_id')->orWhere('page_id', $page->id))
            ->orderByRaw('page_id IS NULL DESC')->orderBy('name')->get();

        return response()->json(['data' => [
            'templates' => $rows->map(fn (Template $t) => self::templateRow($t))->values(),
            'placeholders' => Template::PLACEHOLDERS,
        ]]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $data = $request->validate(self::templateRules());
        abort_if(Template::where('page_id', $page->id)->count() >= 20, 422, 'Twenty templates is plenty. Remove one to add another.');
        $t = Template::create($data + ['page_id' => $page->id, 'created_by' => $request->user()->id]);

        return response()->json(['message' => 'Template saved.', 'data' => self::templateRow($t)], 201);
    }

    public function updateTemplate(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        $t = Template::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail();
        $t->update($request->validate(self::templateRules(false)));

        return response()->json(['message' => 'Saved.', 'data' => self::templateRow($t->fresh())]);
    }

    public function destroyTemplate(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        Template::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Removed.']);
    }

    /** What a template would say to one contact: the first on the list, or a sample. */
    public function preview(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        $t = Template::where('active', true)->where(fn ($q) => $q->whereNull('page_id')->orWhere('page_id', $page->id))->where('uuid', $uuid)->firstOrFail();
        $contact = Contact::where('page_id', $page->id)->first();
        $send = new Send(['page_id' => $page->id, 'contact_id' => $contact?->id, 'template' => $t->kind === 'catalogue' ? 'catalogue' : 'invitation', 'template_id' => $t->id, 'to_email' => $contact?->email ?? 'preview@example.com', 'subject' => '', 'with_prices' => $t->with_prices, 'note' => $request->query('note')]);
        $send->uuid = 'preview';
        $send->setRelation('page', $page->load(['owner', 'tradeLines', 'products.images']));
        $send->setRelation('contact', $contact);
        $send->setRelation('tpl', $t);
        $send->setRelation('campaign', null);
        [$subject, $html] = app(\App\Services\OutreachMailer::class)->render($send);

        return response()->json(['data' => ['subject' => $subject, 'html' => $html]]);
    }

    public static function templateRules(bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes';

        return [
            'name' => [$r, 'string', 'max:120'],
            'kind' => ['nullable', Rule::in(Template::KINDS)],
            'subject' => [$r, 'string', 'max:255'],
            'body' => [$r, 'string', 'max:20000'],
            'with_prices' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    public static function templateRow(Template $t): array
    {
        return [
            'uuid' => $t->uuid, 'name' => $t->name, 'kind' => $t->kind, 'subject' => $t->subject, 'body' => $t->body,
            'with_prices' => $t->with_prices, 'active' => $t->active, 'own' => $t->page_id !== null, 'page' => $t->page_id,
            'updated_at' => $t->updated_at?->toDateTimeString(),
        ];
    }

    // --- Campaigns ------------------------------------------------------------------

    public function campaigns(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $rows = Campaign::with(['template', 'creator', 'sequence', 'cohort', 'list'])->where('page_id', $page->id)->orderByDesc('id')->paginate(30);
        $rows->getCollection()->transform(fn (Campaign $c) => self::campaignRow($c));

        return response()->json($rows);
    }

    public function storeCampaign(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $data = $request->validate(self::campaignRules());
        $campaign = Campaign::create($this->campaignData($page, $data) + ['page_id' => $page->id, 'created_by' => $request->user()->id, 'status' => 'draft']);
        $campaign->update(['total' => $campaign->audience()->count()]);

        return response()->json(['message' => 'Campaign saved as a draft.', 'data' => self::campaignRow($campaign->fresh(['template']))], 201);
    }

    public function updateCampaign(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        $campaign = Campaign::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail();
        abort_unless(in_array($campaign->status, ['draft', 'scheduled', 'paused'], true), 422, 'A campaign that has started cannot be changed.');
        $data = $request->validate(self::campaignRules(false));
        $campaign->update($this->campaignData($page, $data));
        $campaign->update(['total' => $campaign->audience()->count()]);

        return response()->json(['message' => 'Saved.', 'data' => self::campaignRow($campaign->fresh(['template']))]);
    }

    public function destroyCampaign(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        $campaign = Campaign::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail();
        abort_if($campaign->status === 'sending', 422, 'Pause it first.');
        $campaign->delete();

        return response()->json(['message' => 'Removed.']);
    }

    /** Now, or at the hour set. */
    public function launch(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        $campaign = Campaign::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail();
        abort_unless(in_array($campaign->status, ['draft', 'scheduled', 'paused'], true), 422, 'This campaign is already on its way.');
        abort_unless($page->outreach_enabled, 403, 'Emailing your list is not switched on for this page yet. Ask the GrapOut team.');
        abort_unless($page->outreach_bulk_allowed || $campaign->audience()->count() <= 1, 403, 'Bulk sending is not switched on for this page. Ask the GrapOut team.');

        if ($campaign->scheduled_at && $campaign->scheduled_at->isFuture() && ! $request->boolean('now')) {
            $campaign->update(['status' => 'scheduled', 'total' => $campaign->audience()->count()]);

            return response()->json(['message' => 'Scheduled for ' . $campaign->scheduled_at->format('D j M, H:i') . '.', 'data' => self::campaignRow($campaign->fresh(['template']))]);
        }

        $queued = self::start($campaign, $request->user()->id);

        return response()->json(['message' => $queued > 0 ? "Sending to {$queued} contacts. They go out one by one over the next minutes." : 'Nobody left to write to in this campaign.', 'data' => self::campaignRow($campaign->fresh(['template']))]);
    }

    public function pause(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        $campaign = Campaign::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail();
        abort_unless(in_array($campaign->status, ['sending', 'scheduled'], true), 422, 'Nothing to pause.');
        // What is still queued does not go; what went, went.
        $dropped = Send::where('campaign_id', $campaign->id)->where('status', 'queued')->update(['status' => 'failed', 'error' => 'Paused before it went out.']);
        $campaign->update(['status' => 'paused', 'failed' => $campaign->failed + $dropped]);

        return response()->json(['message' => 'Paused. What was still waiting will not go out.', 'data' => self::campaignRow($campaign->fresh(['template']))]);
    }

    /**
     * Queue every email of a campaign, within the page's and the mailbox's
     * allowance for the day; what does not fit today is left for the next
     * run of the scheduler. Used by the launch button and by the scheduler.
     *
     * @return int how many were queued now
     */
    public static function start(Campaign $campaign, ?int $by = null): int
    {
        return \App\Services\CampaignRunner::start($campaign, $by);
    }

    public static function campaignRules(bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes';

        return [
            'name' => [$r, 'string', 'max:120'],
            'template' => ['nullable', 'string'],
            'sequence' => ['nullable', 'string'],
            'cohort' => ['nullable', 'string'],
            'list' => ['nullable', 'string'],
            'template_kind' => ['nullable', Rule::in(['invitation', 'catalogue'])],
            'filter_status' => ['nullable', Rule::in(['unknown', 'valid'])],
            'filter_country' => ['nullable', 'string', 'size:2'],
            'with_prices' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:600'],
            'scheduled_at' => ['nullable', 'date'],
        ];
    }

    private function campaignData(Page $page, array $data): array
    {
        $out = [];
        foreach (['name', 'template_kind', 'filter_status', 'note', 'with_prices'] as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }
        if (array_key_exists('filter_country', $data)) {
            $out['filter_country'] = $data['filter_country'] ? strtoupper($data['filter_country']) : null;
        }
        if (array_key_exists('scheduled_at', $data)) {
            $out['scheduled_at'] = $data['scheduled_at'] ? \Carbon\Carbon::parse($data['scheduled_at'])->setTimezone(config('app.timezone')) : null;
        }
        if (array_key_exists('template', $data)) {
            $t = $data['template'] ? Template::where('active', true)->where(fn ($q) => $q->whereNull('page_id')->orWhere('page_id', $page->id))->where('uuid', $data['template'])->first() : null;
            abort_if($data['template'] && ! $t, 422, 'That template is not yours to use.');
            $out['template_id'] = $t?->id;
            if ($t) {
                $out['template_kind'] = $t->kind === 'catalogue' ? 'catalogue' : 'invitation';
                $out['with_prices'] = $data['with_prices'] ?? $t->with_prices;
            }
        }
        // A sequence instead of one template: the first step is the first email, the rest follow.
        if (array_key_exists('sequence', $data)) {
            $sq = $data['sequence'] ? \App\Models\Outreach\Sequence::where('active', true)->where(fn ($q) => $q->whereNull('page_id')->orWhere('page_id', $page->id))->where('uuid', $data['sequence'])->first() : null;
            abort_if($data['sequence'] && ! $sq, 422, 'That sequence is not yours to use.');
            $out['sequence_id'] = $sq?->id;
            if ($sq) {
                $out['template_id'] = null;
            }
        }
        if (array_key_exists('cohort', $data)) {
            $co = $data['cohort'] ? \App\Models\Outreach\Cohort::where(fn ($q) => $q->whereNull('page_id')->orWhere('page_id', $page->id))->where('uuid', $data['cohort'])->first() : null;
            abort_if($data['cohort'] && ! $co, 422, 'That cohort is not yours to use.');
            $out['cohort_id'] = $co?->id;
        }
        if (array_key_exists('list', $data)) {
            $li = $data['list'] ? \App\Models\Outreach\ContactList::where('page_id', $page->id)->where('uuid', $data['list'])->first() : null;
            abort_if($data['list'] && ! $li, 422, 'That list is not yours.');
            $out['list_id'] = $li?->id;
        }

        return $out;
    }

    public static function campaignRow(Campaign $c): array
    {
        return [
            'uuid' => $c->uuid, 'name' => $c->name, 'status' => $c->status,
            'template' => $c->template ? ['uuid' => $c->template->uuid, 'name' => $c->template->name, 'kind' => $c->template->kind] : null,
            'sequence' => $c->sequence ? ['uuid' => $c->sequence->uuid, 'name' => $c->sequence->name, 'steps' => $c->sequence->steps()->count()] : null,
            'cohort' => $c->cohort ? ['uuid' => $c->cohort->uuid, 'name' => $c->cohort->name] : null,
            'list' => $c->list ? ['uuid' => $c->list->uuid, 'name' => $c->list->name] : null,
            'replied' => (int) $c->replied,
            'template_kind' => $c->template_kind, 'filter_status' => $c->filter_status, 'filter_country' => $c->filter_country,
            'with_prices' => $c->with_prices, 'note' => $c->note, 'scheduled_at' => $c->scheduled_at?->toDateTimeString(),
            'total' => (int) $c->total, 'sent' => (int) $c->sent, 'failed' => (int) $c->failed, 'opened' => (int) $c->opened, 'clicked' => (int) $c->clicked,
            'queued' => $c->status === 'sending' ? Send::where('campaign_id', $c->id)->where('status', 'queued')->count() : 0,
            'by' => $c->creator?->name, 'started_at' => $c->started_at?->toDateTimeString(), 'finished_at' => $c->finished_at?->toDateTimeString(), 'created_at' => $c->created_at?->toDateTimeString(),
            'page' => $c->relationLoaded('page') && $c->page ? ['name' => $c->page->name, 'slug' => $c->page->slug] : null,
        ];
    }

    // --- Figures --------------------------------------------------------------------

    public function stats(Request $request): JsonResponse
    {
        $page = $this->managed($request);

        return response()->json(['data' => self::statsFor($page->id)]);
    }

    /** The figures for one page, or for everything when no page is given. */
    public static function statsFor(?int $pageId): array
    {
        $sends = Send::query()->when($pageId, fn ($q) => $q->where('page_id', $pageId));
        $contacts = Contact::query()->when($pageId, fn ($q) => $q->where('page_id', $pageId));
        $sent = (clone $sends)->where('status', 'sent')->count();
        $days = collect(range(29, 0))->map(fn ($i) => now()->subDays($i)->toDateString());
        $byDay = (clone $sends)->where('status', 'sent')->where('sent_at', '>=', now()->subDays(30)->startOfDay())
            ->selectRaw('DATE(sent_at) as d, COUNT(*) as n, SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as o, SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as c')
            ->groupBy('d')->get()->keyBy('d');

        return [
            'contacts' => (clone $contacts)->count(),
            'writable' => (clone $contacts)->whereNotIn('email_status', ['unsubscribed', 'bounced'])->count(),
            'unsubscribed' => (clone $contacts)->where('email_status', 'unsubscribed')->count(),
            'bounced' => (clone $contacts)->where('email_status', 'bounced')->count(),
            'sent' => $sent,
            'failed' => (clone $sends)->where('status', 'failed')->count(),
            'queued' => (clone $sends)->where('status', 'queued')->count(),
            'opened' => (clone $sends)->whereNotNull('opened_at')->count(),
            'clicked' => (clone $sends)->whereNotNull('clicked_at')->count(),
            'replied' => (clone $sends)->whereNotNull('replied_at')->count(),
            'reply_rate' => $sent ? round((clone $sends)->whereNotNull('replied_at')->count() * 100 / $sent, 1) : 0,
            'inbox' => \App\Models\Outreach\InboxMessage::query()->when($pageId, fn ($q) => $q->where('page_id', $pageId))->count(),
            'inbox_unread' => \App\Models\Outreach\InboxMessage::query()->when($pageId, fn ($q) => $q->where('page_id', $pageId))->whereNull('read_at')->count(),
            'open_rate' => $sent ? round((clone $sends)->whereNotNull('opened_at')->count() * 100 / $sent, 1) : 0,
            'click_rate' => $sent ? round((clone $sends)->whereNotNull('clicked_at')->count() * 100 / $sent, 1) : 0,
            'campaigns' => Campaign::query()->when($pageId, fn ($q) => $q->where('page_id', $pageId))->count(),
            'days' => $days->map(fn ($d) => ['date' => $d, 'sent' => (int) ($byDay[$d]->n ?? 0), 'opened' => (int) ($byDay[$d]->o ?? 0), 'clicked' => (int) ($byDay[$d]->c ?? 0)])->values(),
        ];
    }
}
