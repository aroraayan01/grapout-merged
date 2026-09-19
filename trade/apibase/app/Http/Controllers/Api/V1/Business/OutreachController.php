<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Jobs\SendOutreachMail;
use App\Models\Business\Page;
use App\Models\Outreach\Contact;
use App\Models\Outreach\Mailbox;
use App\Models\Outreach\Send;
use App\Support\ContactSheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A company's own email list, and what it sends to it.
 *
 * The list is the company's to keep: import a sheet, add one by one,
 * correct, remove. The sending is the GrapOut team's to allow: switched
 * on per page, so many a day, one at a time or in bulk, from a mailbox
 * the team keeps. Every email is a row the team can see.
 */
class OutreachController extends Controller
{
    public const MAX_PER_REQUEST = 500;

    private function managed(Request $request): Page
    {
        $page = $request->user()->businessPage()->first();
        abort_unless($page && $page->canManage($request->user()), 403, 'Only the owner or an admin keeps the hot leads list.');

        return $page;
    }

    /** Where this page stands: allowed or not, how much is left today, the mailbox it writes from. */
    public function status(Request $request): JsonResponse
    {
        $page = $this->managed($request);

        return response()->json(['data' => $this->statusBlock($page)]);
    }

    private function statusBlock(Page $page): array
    {
        $box = $page->outreach_mailbox_id ? Mailbox::find($page->outreach_mailbox_id) : Mailbox::where('is_default', true)->where('active', true)->first();
        $today = Send::where('page_id', $page->id)->whereDate('created_at', now()->toDateString())->where('status', '!=', 'failed')->count();

        return [
            'enabled' => (bool) $page->outreach_enabled,
            'bulk_allowed' => (bool) $page->outreach_bulk_allowed,
            'daily_limit' => (int) $page->outreach_daily_limit,
            'sent_today' => $today,
            'left_today' => max(0, (int) $page->outreach_daily_limit - $today),
            'mailbox' => $box && $box->active ? ['label' => $box->label, 'from' => "{$box->from_name} <{$box->from_address}>"] : null,
            'contacts' => Contact::where('page_id', $page->id)->count(),
            'writable' => Contact::where('page_id', $page->id)->whereNotIn('email_status', ['unsubscribed', 'bounced'])->count(),
            'sent_total' => Send::where('page_id', $page->id)->where('status', 'sent')->count(),
        ];
    }

    public function contacts(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $q = mb_strtolower(trim((string) $request->query('q')));
        $rows = Contact::with('lists')->where('page_id', $page->id)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->whereRaw('LOWER(email) LIKE ?', ["%{$q}%"])->orWhereRaw('LOWER(company_name) LIKE ?', ["%{$q}%"])->orWhereRaw('LOWER(contact_name) LIKE ?', ["%{$q}%"])))
            ->when($request->query('status'), fn ($b, $s) => $b->where('email_status', $s))
            ->when($request->query('list'), fn ($b, $l) => $b->whereHas('lists', fn ($x) => $x->where('outreach_lists.uuid', $l)))
            ->when($request->query('replied') === '1', fn ($b) => $b->whereNotNull('replied_at'))
            ->orderByDesc('id')
            ->paginate(50);
        $rows->getCollection()->transform(fn (Contact $c) => $this->contactRow($c));

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $data = $request->validate($this->rules());
        $data['email'] = mb_strtolower($data['email']);
        abort_if(Contact::where('page_id', $page->id)->where('email', $data['email'])->exists(), 422, 'That address is already on your list.');
        $contact = Contact::create($data + ['page_id' => $page->id, 'source' => 'manual', 'created_by' => $request->user()->id,
            'contact_name' => ($data['contact_name'] ?? null) ?: ContactSheet::nameFromEmail($data['email'])]);

        return response()->json(['message' => 'Added.', 'data' => $this->contactRow($contact)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        $contact = Contact::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate($this->rules(false) + ['email_status' => ['nullable', Rule::in(['unknown', 'valid'])]]);
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower($data['email']);
            abort_if(Contact::where('page_id', $page->id)->where('email', $data['email'])->whereKeyNot($contact->id)->exists(), 422, 'That address is already on your list.');
        }
        // Somebody who unsubscribed stays unsubscribed; the list does not overrule them.
        if ($contact->email_status === 'unsubscribed') {
            unset($data['email_status']);
        }
        $contact->update($data);

        return response()->json(['message' => 'Saved.', 'data' => $this->contactRow($contact->fresh())]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $page = $this->managed($request);
        Contact::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Removed.']);
    }

    /**
     * A sheet, as a file or pasted text. The columns are worked out here;
     * the result says what was read and what was left out.
     */
    public function import(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $request->validate([
            'file' => ['nullable', 'file', 'mimes:csv,txt,tsv', 'max:4096'],
            'text' => ['nullable', 'string', 'max:2000000'],
            'list' => ['nullable', 'string'],
        ]);
        $list = $request->input('list') ? \App\Models\Outreach\ContactList::where('page_id', $page->id)->where('uuid', $request->input('list'))->first() : null;
        abort_if($request->input('list') && ! $list, 422, 'That list is not yours.');
        $text = $request->file('file') ? (string) file_get_contents($request->file('file')->getRealPath()) : (string) $request->input('text');
        abort_if(trim($text) === '', 422, 'Choose a file or paste the rows.');

        $sheet = ContactSheet::parse($text);
        abort_if(count($sheet['rows']) > 5000, 422, 'Up to 5,000 rows at a time.');

        $created = 0;
        $updated = 0;
        $onList = [];
        foreach ($sheet['rows'] as $row) {
            $existing = Contact::where('page_id', $page->id)->where('email', $row['email'])->first();
            if ($existing) {
                // A sheet fills blanks; it does not overwrite what was corrected by hand.
                $existing->update(array_filter([
                    'company_name' => $existing->company_name ?: $row['company_name'],
                    'contact_name' => $existing->contact_name ?: $row['contact_name'],
                    'mobile' => $existing->mobile ?: $row['mobile'],
                    'phone' => $existing->phone ?: $row['phone'],
                    'country' => $existing->country ?: $row['country'],
                    'notes' => $existing->notes ?: $row['notes'],
                ], fn ($v) => $v !== null));
                $updated++;
                $onList[] = $existing->id;
            } else {
                $c = Contact::create($row + ['page_id' => $page->id, 'source' => 'import', 'created_by' => $request->user()->id]);
                $created++;
                $onList[] = $c->id;
            }
        }
        if ($list && $onList) {
            $list->contacts()->syncWithoutDetaching($onList);
        }

        return response()->json([
            'message' => "{$created} added, {$updated} already on the list" . ($sheet['skipped'] ? ', ' . count($sheet['skipped']) . ' rows skipped.' : '.'),
            'data' => ['created' => $created, 'updated' => $updated, 'skipped' => array_slice($sheet['skipped'], 0, 50), 'header' => $sheet['header'], 'mapping' => $sheet['mapping']],
        ]);
    }

    /**
     * Write to some or all of the list. One is one; more than one is bulk,
     * which the team allows per page. Nothing goes out here — rows are
     * queued and the queue does the sending.
     */
    public function send(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $data = $request->validate([
            'template' => ['required', Rule::in(Send::TEMPLATES)],
            'contacts' => ['nullable', 'array', 'max:' . self::MAX_PER_REQUEST],
            'contacts.*' => ['string'],
            'all' => ['nullable', 'boolean'],
            'with_prices' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:600'],
        ]);
        abort_unless($page->outreach_enabled, 403, 'Emailing your list is not switched on for this page yet. Ask the GrapOut team.');
        $status = $this->statusBlock($page);
        abort_unless($status['mailbox'], 422, 'No mailbox is set up for sending yet. Ask the GrapOut team.');

        $query = Contact::where('page_id', $page->id)->whereNotIn('email_status', ['unsubscribed', 'bounced']);
        if (! empty($data['all'])) {
            $query->limit(self::MAX_PER_REQUEST);
        } else {
            abort_if(empty($data['contacts']), 422, 'Pick who to write to.');
            $query->whereIn('uuid', $data['contacts']);
        }
        $targets = $query->get();
        abort_if($targets->isEmpty(), 422, 'Nobody to write to: the chosen contacts are unsubscribed, bounced, or not on your list.');
        abort_if($targets->count() > 1 && ! $page->outreach_bulk_allowed, 403, 'Bulk sending is not switched on for this page. One at a time, or ask the GrapOut team.');
        abort_if($targets->count() > $status['left_today'], 422, "That is more than your allowance for today: {$status['left_today']} left of {$status['daily_limit']}.");

        $box = $page->outreach_mailbox_id ? Mailbox::find($page->outreach_mailbox_id) : Mailbox::where('is_default', true)->where('active', true)->first();
        abort_if($box && $box->sentToday() + $targets->count() > $box->daily_limit, 422, 'The sending mailbox has reached its limit for today. Try tomorrow, or ask the GrapOut team.');

        $queued = 0;
        foreach ($targets as $c) {
            $send = Send::create([
                'page_id' => $page->id, 'contact_id' => $c->id, 'mailbox_id' => $box?->id, 'template' => $data['template'],
                'to_email' => $c->email, 'subject' => '', 'status' => 'queued',
                'with_prices' => $data['with_prices'] ?? true, 'note' => $data['note'] ?? null, 'sent_by' => $request->user()->id,
            ]);
            SendOutreachMail::dispatch($send->id);
            $queued++;
        }

        return response()->json(['message' => $queued === 1 ? "Sending to {$targets->first()->email}." : "Sending to {$queued} contacts. They go out one by one over the next minutes.", 'data' => ['queued' => $queued] + $this->statusBlock($page->fresh())]);
    }

    public function sends(Request $request): JsonResponse
    {
        $page = $this->managed($request);
        $rows = Send::with(['contact', 'sender', 'campaign'])->where('page_id', $page->id)->orderByDesc('id')->paginate(50);
        $rows->getCollection()->transform(fn (Send $s) => [
            'uuid' => $s->uuid, 'to' => $s->to_email, 'contact' => $s->contact ? ['company_name' => $s->contact->company_name, 'contact_name' => $s->contact->contact_name] : null,
            'template' => $s->template, 'subject' => $s->subject, 'status' => $s->status, 'error' => $s->error, 'with_prices' => $s->with_prices,
            'campaign' => $s->campaign?->name, 'opened_at' => $s->opened_at?->toDateTimeString(), 'clicked_at' => $s->clicked_at?->toDateTimeString(),
            'by' => $s->sender?->name, 'created_at' => $s->created_at?->toDateTimeString(), 'sent_at' => $s->sent_at?->toDateTimeString(),
        ]);

        return response()->json($rows);
    }

    /** The one-pixel picture at the foot of every email: fetched, the email was opened. */
    public function opened(string $uuid)
    {
        $send = Send::where('uuid', $uuid)->first();
        if ($send && $send->status === 'sent') {
            $first = $send->opened_at === null;
            $send->update(['opened_at' => $send->opened_at ?? now(), 'open_count' => ($send->open_count ?? 0) + 1]);
            if ($first && $send->campaign_id) {
                \App\Models\Outreach\Campaign::whereKey($send->campaign_id)->increment('opened');
            }
        }
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($gif, 200)->header('Content-Type', 'image/gif')->header('Cache-Control', 'no-store, no-cache, must-revalidate')->header('Pragma', 'no-cache');
    }

    /** Every button in the email passes through here on its way: the click is counted, then the reader goes on. */
    public function clicked(Request $request, string $uuid)
    {
        $send = Send::where('uuid', $uuid)->first();
        $to = (string) $request->query('u', '');
        $front = rtrim((string) config('mypa.frontend_url'), '/');
        $api = rtrim((string) config('app.url'), '/');
        // Only our own links are followed; anything else lands on the front door.
        if ($to === '' || ! (str_starts_with($to, $front) || str_starts_with($to, $api))) {
            $to = $front;
        }
        if ($send && $send->status === 'sent') {
            $first = $send->clicked_at === null;
            $send->update(['clicked_at' => $send->clicked_at ?? now(), 'opened_at' => $send->opened_at ?? now()]);
            if ($first && $send->campaign_id) {
                \App\Models\Outreach\Campaign::whereKey($send->campaign_id)->increment('clicked');
            }
        }

        return redirect()->away($to);
    }

    /** The link at the foot of every email. No sign-in, one click, done. */
    public function unsubscribe(string $uuid)
    {
        $send = Send::with(['contact', 'page'])->where('uuid', $uuid)->firstOrFail();
        $send->contact?->update(['email_status' => 'unsubscribed']);
        // The same address on other pages' lists is left alone: the no was to this company.
        $name = e($send->page?->name ?? 'this company');

        return response("<!doctype html><meta charset=\"utf-8\"><body style=\"font-family:Helvetica,Arial,sans-serif;max-width:520px;margin:60px auto;color:#0f172a\"><h2>Unsubscribed</h2><p>You will not receive further emails from {$name} through GrapOut Trade.</p></body>", 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function rules(bool $emailRequired = true): array
    {
        return [
            'company_name' => ['nullable', 'string', 'max:160'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => [$emailRequired ? 'required' : 'sometimes', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:40'],
            'phone' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function contactRow(Contact $c): array
    {
        return [
            'uuid' => $c->uuid, 'company_name' => $c->company_name, 'contact_name' => $c->contact_name, 'email' => $c->email,
            'email_status' => $c->email_status, 'mobile' => $c->mobile, 'phone' => $c->phone, 'country' => $c->country ? strtoupper($c->country) : null,
            'notes' => $c->notes, 'source' => $c->source, 'sent_count' => (int) $c->sent_count, 'last_sent_at' => $c->last_sent_at?->toDateTimeString(),
            'last_template' => $c->last_template, 'created_at' => $c->created_at?->toDateTimeString(),
            'replied_at' => $c->replied_at?->toDateTimeString(), 'reply_count' => (int) ($c->reply_count ?? 0),
            'lists' => $c->relationLoaded('lists') ? $c->lists->map(fn ($l) => ['uuid' => $l->uuid, 'name' => $l->name])->values() : [],
        ];
    }
}
