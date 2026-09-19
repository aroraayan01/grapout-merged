<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OutreachMail;
use App\Models\Business\Page;
use App\Models\Outreach\Mailbox;
use App\Models\Outreach\Send;
use App\Services\OutreachMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

/**
 * Outreach from the platform's chair: the mailboxes it writes from, and
 * what each company page is allowed to send — on or off, how many a day,
 * one at a time or in bulk, from which mailbox. The page-level switches
 * for calls and meetings live on the same row.
 */
class OutreachController extends Controller
{
    // --- Mailboxes --------------------------------------------------------------------

    public function mailboxes(): JsonResponse
    {
        return response()->json(['data' => Mailbox::orderByDesc('is_default')->orderBy('label')->get()->map(fn (Mailbox $m) => $this->mailboxRow($m))->values()]);
    }

    public function storeMailbox(Request $request): JsonResponse
    {
        $data = $this->mailboxData($request);
        $box = Mailbox::create($data);
        $this->keepOneDefault($box);

        return response()->json(['message' => 'Mailbox added.', 'data' => $this->mailboxRow($box->fresh())], 201);
    }

    public function updateMailbox(Request $request, string $uuid): JsonResponse
    {
        $box = Mailbox::where('uuid', $uuid)->firstOrFail();
        $data = $this->mailboxData($request, $box);
        $box->update($data);
        $this->keepOneDefault($box);

        return response()->json(['message' => 'Saved.', 'data' => $this->mailboxRow($box->fresh())]);
    }

    public function destroyMailbox(string $uuid): JsonResponse
    {
        $box = Mailbox::where('uuid', $uuid)->firstOrFail();
        $box->delete();

        return response()->json(['message' => 'Mailbox removed. Pages that used it fall back to the default.']);
    }

    /** A test email out of the mailbox, to the admin's own address by default. */
    public function testMailbox(Request $request, string $uuid, OutreachMailer $mailer): JsonResponse
    {
        $box = Mailbox::where('uuid', $uuid)->firstOrFail();
        $to = $request->validate(['to' => ['nullable', 'email']])['to'] ?? $request->user()->email;
        try {
            $mailer->mailerFor($box)->to($to)->send(new OutreachMail(
                "GrapOut Trade — mailbox test: {$box->label}",
                '<p>This is a test from GrapOut Trade. If you are reading it, the mailbox <b>' . e($box->label) . '</b> can send from <b>' . e($box->from_address) . '</b>.</p>',
                $box->from_address,
                $box->from_name,
                $box->reply_to,
            ));

            return response()->json(['message' => "Sent to {$to}. If it does not arrive, check the spam folder and the mailbox's DNS records."]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not send: ' . $e->getMessage()], 422);
        }
    }

    /** SPF, DKIM, DMARC for the mailbox's domain. */
    public function dnsMailbox(string $uuid, \App\Services\MailboxDoctor $doctor): JsonResponse
    {
        $box = Mailbox::where('uuid', $uuid)->firstOrFail();
        $r = $doctor->checkDns($box);
        $message = "SPF " . ($r['spf'] ? 'ok' : 'missing') . ', DKIM ' . ($r['dkim'] ? 'ok' : 'missing') . ', DMARC ' . ($r['dmarc'] ? 'ok' : 'missing') . " — {$r['score']}/100.";

        return response()->json(['message' => $message, 'data' => $r + ['mailbox' => $this->mailboxRow($box->fresh())]]);
    }

    /** Log in to the SMTP server; no email goes out. */
    public function smtpTestMailbox(string $uuid, \App\Services\MailboxDoctor $doctor): JsonResponse
    {
        $box = Mailbox::where('uuid', $uuid)->firstOrFail();
        $r = $doctor->testSmtp($box);

        return response()->json(['message' => $r['message'], 'data' => $r + ['mailbox' => $this->mailboxRow($box->fresh())]], $r['ok'] ? 200 : 422);
    }

    /** Log in over IMAP and count the inbox. */
    public function imapTestMailbox(string $uuid, \App\Services\MailboxDoctor $doctor): JsonResponse
    {
        $box = Mailbox::where('uuid', $uuid)->firstOrFail();
        $r = $doctor->testImap($box);

        return response()->json(['message' => $r['message'], 'data' => $r + ['mailbox' => $this->mailboxRow($box->fresh())]], $r['ok'] ? 200 : 422);
    }

    /** Read what arrived, now. */
    public function syncMailbox(string $uuid, \App\Services\InboxSync $sync): JsonResponse
    {
        $box = Mailbox::where('uuid', $uuid)->firstOrFail();
        abort_unless($box->receives(), 422, 'No IMAP host on this mailbox.');
        $n = $sync->sync($box);
        $box = $box->fresh();

        return response()->json(['message' => $box->last_error ?: "{$n} new message" . ($n === 1 ? '' : 's') . ' filed.', 'data' => $this->mailboxRow($box)], $box->last_error ? 422 : 200);
    }

    /** A copy of the mailbox, secrets included, to change the address on. */
    public function replicateMailbox(string $uuid): JsonResponse
    {
        $box = Mailbox::where('uuid', $uuid)->firstOrFail();
        $copy = $box->replicate(['uuid', 'is_default', 'dns_spf', 'dns_dkim', 'dns_dmarc', 'dns_score', 'dns_checked_at', 'smtp_ok', 'smtp_tested_at', 'imap_ok', 'imap_tested_at', 'last_error', 'inbox_synced_at']);
        $copy->label = $box->label . ' (copy)';
        $copy->is_default = false;
        $copy->save();

        return response()->json(['message' => 'Copied. Change the label and the address, then save.', 'data' => $this->mailboxRow($copy->fresh())], 201);
    }

    // --- Mailbox groups: several mailboxes that send as one -------------------------------

    public function groups(): JsonResponse
    {
        $rows = \App\Models\Outreach\MailboxGroup::with('mailboxes')->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn ($g) => $this->groupRow($g))->values()]);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:255'], 'active' => ['nullable', 'boolean'], 'mailboxes' => ['nullable', 'array'], 'mailboxes.*' => ['string']]);
        $g = \App\Models\Outreach\MailboxGroup::create(['name' => $data['name'], 'description' => $data['description'] ?? null, 'active' => $data['active'] ?? true, 'created_by' => $request->user()->id]);
        if (isset($data['mailboxes'])) {
            Mailbox::whereIn('uuid', $data['mailboxes'])->update(['group_id' => $g->id]);
        }

        return response()->json(['message' => 'Group added.', 'data' => $this->groupRow($g->fresh('mailboxes'))], 201);
    }

    public function updateGroup(Request $request, string $uuid): JsonResponse
    {
        $g = \App\Models\Outreach\MailboxGroup::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:255'], 'active' => ['nullable', 'boolean'], 'mailboxes' => ['nullable', 'array'], 'mailboxes.*' => ['string']]);
        $g->update(array_intersect_key($data, array_flip(['name', 'description', 'active'])));
        if (array_key_exists('mailboxes', $data)) {
            Mailbox::where('group_id', $g->id)->update(['group_id' => null]);
            Mailbox::whereIn('uuid', $data['mailboxes'] ?? [])->update(['group_id' => $g->id]);
        }

        return response()->json(['message' => 'Saved.', 'data' => $this->groupRow($g->fresh('mailboxes'))]);
    }

    public function destroyGroup(string $uuid): JsonResponse
    {
        \App\Models\Outreach\MailboxGroup::where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Group removed. Its mailboxes stay, on their own.']);
    }

    private function groupRow(\App\Models\Outreach\MailboxGroup $g): array
    {
        return [
            'uuid' => $g->uuid, 'name' => $g->name, 'description' => $g->description, 'active' => $g->active,
            'mailboxes' => $g->mailboxes->map(fn ($m) => ['uuid' => $m->uuid, 'label' => $m->label, 'from_address' => $m->from_address, 'active' => $m->active, 'daily_limit' => (int) $m->daily_limit, 'sent_today' => $m->sentToday()])->values(),
            'daily_limit' => (int) $g->mailboxes->where('active', true)->sum('daily_limit'),
            'pages' => Page::where('outreach_mailbox_group_id', $g->id)->count(),
            'campaigns' => \App\Models\Outreach\Campaign::where('mailbox_group_id', $g->id)->count(),
        ];
    }

    // --- Sequences and cohorts the platform offers to every page ---------------------------

    public function sequences(): JsonResponse
    {
        $rows = \App\Models\Outreach\Sequence::with(['steps.template', 'page'])->withCount('campaigns')->orderByRaw('page_id IS NULL DESC')->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn ($s) => \App\Http\Controllers\Api\V1\Business\OutreachListController::sequenceRow($s) + ['page_name' => $s->page?->name])->values()]);
    }

    public function storeSequence(Request $request): JsonResponse
    {
        $data = $request->validate(\App\Http\Controllers\Api\V1\Business\OutreachListController::sequenceRules());
        $s = \App\Models\Outreach\Sequence::create(['page_id' => null, 'name' => $data['name'], 'description' => $data['description'] ?? null, 'stop_on_reply' => $data['stop_on_reply'] ?? true, 'stop_on_click' => $data['stop_on_click'] ?? false, 'active' => $data['active'] ?? true, 'created_by' => $request->user()->id]);
        \App\Http\Controllers\Api\V1\Business\OutreachListController::putSteps($s, $data['steps'], null);

        return response()->json(['message' => 'Sequence saved for every page.', 'data' => \App\Http\Controllers\Api\V1\Business\OutreachListController::sequenceRow($s->fresh(['steps.template'])->loadCount('campaigns'))], 201);
    }

    public function updateSequence(Request $request, string $uuid): JsonResponse
    {
        $s = \App\Models\Outreach\Sequence::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(\App\Http\Controllers\Api\V1\Business\OutreachListController::sequenceRules(false));
        $s->update(array_intersect_key($data, array_flip(['name', 'description', 'stop_on_reply', 'stop_on_click', 'active'])));
        if (isset($data['steps'])) {
            \App\Http\Controllers\Api\V1\Business\OutreachListController::putSteps($s, $data['steps'], $s->page);
        }

        return response()->json(['message' => 'Saved.', 'data' => \App\Http\Controllers\Api\V1\Business\OutreachListController::sequenceRow($s->fresh(['steps.template'])->loadCount('campaigns'))]);
    }

    public function destroySequence(string $uuid): JsonResponse
    {
        \App\Models\Outreach\Sequence::where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Sequence removed.']);
    }

    public function cohorts(): JsonResponse
    {
        $rows = \App\Models\Outreach\Cohort::with('page')->orderByRaw('page_id IS NULL DESC')->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn ($c) => \App\Http\Controllers\Api\V1\Business\OutreachListController::cohortRow($c, $c->page) + ['page_name' => $c->page?->name])->values(), 'filters' => \App\Models\Outreach\Cohort::FILTERS]);
    }

    public function storeCohort(Request $request): JsonResponse
    {
        $data = $request->validate(\App\Http\Controllers\Api\V1\Business\OutreachListController::cohortRules());
        $c = \App\Models\Outreach\Cohort::create(['page_id' => null, 'name' => $data['name'], 'description' => $data['description'] ?? null, 'filters' => \App\Models\Outreach\Cohort::cleanFilters($data['filters'] ?? []), 'created_by' => $request->user()->id]);

        return response()->json(['message' => 'Cohort saved for every page.', 'data' => \App\Http\Controllers\Api\V1\Business\OutreachListController::cohortRow($c)], 201);
    }

    public function updateCohort(Request $request, string $uuid): JsonResponse
    {
        $c = \App\Models\Outreach\Cohort::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(\App\Http\Controllers\Api\V1\Business\OutreachListController::cohortRules(false));
        $c->update(array_filter(['name' => $data['name'] ?? null, 'description' => array_key_exists('description', $data) ? $data['description'] : $c->description, 'filters' => array_key_exists('filters', $data) ? \App\Models\Outreach\Cohort::cleanFilters($data['filters'] ?? []) : null], fn ($v) => $v !== null));

        return response()->json(['message' => 'Saved.', 'data' => \App\Http\Controllers\Api\V1\Business\OutreachListController::cohortRow($c->fresh(), $c->page)]);
    }

    public function destroyCohort(string $uuid): JsonResponse
    {
        \App\Models\Outreach\Cohort::where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Cohort removed.']);
    }

    /** Every page's lists. */
    public function lists(Request $request): JsonResponse
    {
        $rows = \App\Models\Outreach\ContactList::with('page')->withCount('contacts')->orderByDesc('id')
            ->when($request->query('page_uuid'), fn ($b, $u) => $b->whereHas('page', fn ($p) => $p->where('uuid', $u)))
            ->paginate(60);
        $rows->getCollection()->transform(fn ($l) => \App\Http\Controllers\Api\V1\Business\OutreachListController::listRow($l) + ['page' => $l->page ? ['name' => $l->page->name, 'slug' => $l->page->slug, 'uuid' => $l->page->uuid] : null]);

        return response()->json($rows);
    }

    /** Everything that came back, across every mailbox. */
    public function inbox(Request $request): JsonResponse
    {
        $rows = \App\Models\Outreach\InboxMessage::with(['contact', 'send', 'campaign', 'mailbox', 'page'])
            ->when($request->query('unread'), fn ($q) => $q->whereNull('read_at'))
            ->when($request->query('mailbox'), fn ($q, $u) => $q->whereHas('mailbox', fn ($m) => $m->where('uuid', $u)))
            ->when($request->query('page_uuid'), fn ($q, $u) => $q->whereHas('page', fn ($p) => $p->where('uuid', $u)))
            ->orderByDesc('received_at')->orderByDesc('id')->paginate(30);
        $rows->getCollection()->transform(fn ($m) => \App\Http\Controllers\Api\V1\Business\OutreachListController::inboxRow($m));

        return response()->json($rows);
    }

    public function readInbox(string $uuid): JsonResponse
    {
        $m = \App\Models\Outreach\InboxMessage::where('uuid', $uuid)->firstOrFail();
        $m->update(['read_at' => $m->read_at ?? now()]);

        return response()->json(['message' => 'Read.']);
    }

    private function mailboxData(Request $request, ?Mailbox $existing = null): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'from_name' => ['required', 'string', 'max:120'],
            'from_address' => ['required', 'email', 'max:255'],
            'reply_to' => ['nullable', 'email', 'max:255'],
            'mailer' => ['required', Rule::in(Mailbox::MAILERS)],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:512'],
            'ses_key' => ['nullable', 'string', 'max:512'],
            'ses_secret' => ['nullable', 'string', 'max:512'],
            'ses_region' => ['nullable', 'string', 'max:32'],
            'is_default' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'daily_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'group' => ['nullable', 'string'],
            'imap_host' => ['nullable', 'string', 'max:255'],
            'imap_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'imap_encryption' => ['nullable', Rule::in(['ssl', 'tls', 'none'])],
            'imap_username' => ['nullable', 'string', 'max:255'],
            'imap_password' => ['nullable', 'string', 'max:512'],
            'imap_self_signed' => ['nullable', 'boolean'],
            'dkim_selector' => ['nullable', 'string', 'max:64'],
        ]);
        if (array_key_exists('group', $data)) {
            $data['group_id'] = $data['group'] ? \App\Models\Outreach\MailboxGroup::where('uuid', $data['group'])->value('id') : null;
            unset($data['group']);
        }
        // A secret typed in is encrypted; a blank keeps the one saved; a masked value too.
        foreach (Mailbox::SECRETS as $key) {
            $v = $data[$key] ?? null;
            if ($v === null || $v === '' || $v === '********') {
                unset($data[$key]);
                continue;
            }
            $data[$key] = Crypt::encryptString($v);
        }

        return $data;
    }

    private function keepOneDefault(Mailbox $box): void
    {
        if ($box->is_default) {
            Mailbox::whereKeyNot($box->id)->update(['is_default' => false]);
        } elseif (! Mailbox::where('is_default', true)->exists()) {
            $box->update(['is_default' => true]);
        }
    }

    private function mailboxRow(Mailbox $m): array
    {
        return [
            'uuid' => $m->uuid, 'label' => $m->label, 'from_name' => $m->from_name, 'from_address' => $m->from_address, 'reply_to' => $m->reply_to,
            'mailer' => $m->mailer, 'smtp_host' => $m->smtp_host, 'smtp_port' => $m->smtp_port, 'smtp_encryption' => $m->smtp_encryption, 'smtp_username' => $m->smtp_username,
            'smtp_password_saved' => (bool) $m->smtp_password, 'ses_key_saved' => (bool) $m->ses_key, 'ses_secret_saved' => (bool) $m->ses_secret, 'ses_region' => $m->ses_region,
            'is_default' => $m->is_default, 'active' => $m->active, 'daily_limit' => (int) $m->daily_limit, 'sent_today' => $m->sentToday(),
            'pages' => Page::where('outreach_mailbox_id', $m->id)->count(),
            'group' => $m->group_id ? ['uuid' => $m->group?->uuid, 'name' => $m->group?->name] : null,
            'imap_host' => $m->imap_host, 'imap_port' => $m->imap_port, 'imap_encryption' => $m->imap_encryption, 'imap_username' => $m->imap_username, 'imap_password_saved' => (bool) $m->imap_password, 'imap_self_signed' => (bool) $m->imap_self_signed,
            'dkim_selector' => $m->dkim_selector, 'dns' => $m->dns_checked_at ? ['spf' => (bool) $m->dns_spf, 'dkim' => (bool) $m->dns_dkim, 'dmarc' => (bool) $m->dns_dmarc, 'score' => (int) $m->dns_score, 'checked_at' => $m->dns_checked_at->toDateTimeString()] : null,
            'smtp_ok' => $m->smtp_ok, 'smtp_tested_at' => $m->smtp_tested_at?->toDateTimeString(), 'imap_ok' => $m->imap_ok, 'imap_tested_at' => $m->imap_tested_at?->toDateTimeString(),
            'last_error' => $m->last_error, 'inbox_synced_at' => $m->inbox_synced_at?->toDateTimeString(), 'receives' => $m->receives(),
            'replies' => \App\Models\Outreach\InboxMessage::where('mailbox_id', $m->id)->count(),
        ];
    }

    // --- Pages ------------------------------------------------------------------------

    /** Every company page with what it may send, and what it has sent. */
    public function pages(Request $request): JsonResponse
    {
        $q = mb_strtolower(trim((string) $request->query('q')));
        $rows = Page::with(['owner'])->withCount(['outreachContacts', 'outreachSends as sent_count' => fn ($b) => $b->where('status', 'sent')])
            ->when($q !== '', fn ($b) => $b->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"]))
            ->when($request->query('only') === 'enabled', fn ($b) => $b->where('outreach_enabled', true))
            ->when($request->query('sort') === 'rank', fn ($b) => $b->orderByDesc('rank_score'))
            ->orderByDesc('outreach_enabled')->orderBy('name')
            ->paginate(40);
        $rows->getCollection()->transform(fn (Page $p) => $this->pageRow($p));

        return response()->json($rows);
    }

    public function updatePage(Request $request, string $uuid): JsonResponse
    {
        $page = Page::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'outreach_enabled' => ['sometimes', 'boolean'],
            'outreach_daily_limit' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'outreach_bulk_allowed' => ['sometimes', 'boolean'],
            'outreach_mailbox' => ['sometimes', 'nullable', 'string'],
            'outreach_mailbox_group' => ['sometimes', 'nullable', 'string'],
            'calls_disabled' => ['sometimes', 'boolean'],
            'meetings_disabled' => ['sometimes', 'boolean'],
            'contacts_allowed' => ['sometimes', 'boolean'],
            'whatsapp_allowed' => ['sometimes', 'boolean'],
            'rank_boost' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ]);
        if (array_key_exists('outreach_mailbox', $data)) {
            $data['outreach_mailbox_id'] = $data['outreach_mailbox'] ? Mailbox::where('uuid', $data['outreach_mailbox'])->value('id') : null;
            unset($data['outreach_mailbox']);
        }
        if (array_key_exists('outreach_mailbox_group', $data)) {
            $data['outreach_mailbox_group_id'] = $data['outreach_mailbox_group'] ? \App\Models\Outreach\MailboxGroup::where('uuid', $data['outreach_mailbox_group'])->value('id') : null;
            unset($data['outreach_mailbox_group']);
        }
        $page->update($data);
        if (array_key_exists('rank_boost', $data)) {
            \App\Services\PageRanker::recompute($page->fresh());
        }
        \App\Models\AuditLog::record($request->user(), 'business.outreach.settings', $page->owner, ['page' => $page->slug] + $data);

        return response()->json(['message' => 'Saved.', 'data' => $this->pageRow($page->fresh()->loadCount(['outreachContacts', 'outreachSends as sent_count' => fn ($b) => $b->where('status', 'sent')]))]);
    }

    /** One switch for many pages: the ticked ones, or every page the filter shows. */
    public function bulkPages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuids' => ['sometimes', 'array', 'max:2000'],
            'uuids.*' => ['string'],
            'all' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'only' => ['sometimes', 'nullable', 'string'],
            'calls_disabled' => ['sometimes', 'boolean'],
            'meetings_disabled' => ['sometimes', 'boolean'],
            'outreach_enabled' => ['sometimes', 'boolean'],
            'outreach_bulk_allowed' => ['sometimes', 'boolean'],
            'contacts_allowed' => ['sometimes', 'boolean'],
            'whatsapp_allowed' => ['sometimes', 'boolean'],
        ]);
        $changes = array_intersect_key($data, array_flip(['calls_disabled', 'meetings_disabled', 'outreach_enabled', 'outreach_bulk_allowed', 'contacts_allowed', 'whatsapp_allowed']));
        abort_if($changes === [], 422, 'Say what to switch.');
        $q = mb_strtolower(trim((string) ($data['q'] ?? '')));
        $query = ! empty($data['all'])
            ? Page::query()->when($q !== '', fn ($b) => $b->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"]))->when(($data['only'] ?? '') === 'enabled', fn ($b) => $b->where('outreach_enabled', true))
            : Page::whereIn('uuid', $data['uuids'] ?? []);
        $n = $query->update($changes);
        \App\Models\AuditLog::record($request->user(), 'business.outreach.bulk', null, ['pages' => $n] + $changes);

        return response()->json(['message' => "Done for {$n} page" . ($n === 1 ? '' : 's') . '.', 'data' => ['pages' => $n]]);
    }

    /** Everything that went out, newest first, across every page. */
    public function sends(Request $request): JsonResponse
    {
        $rows = Send::with(['page', 'contact', 'sender', 'mailbox'])->orderByDesc('id')
            ->when($request->query('status'), fn ($b, $s) => $b->where('status', $s))
            ->paginate(60);
        $rows->getCollection()->transform(fn (Send $s) => [
            'uuid' => $s->uuid, 'page' => $s->page ? ['name' => $s->page->name, 'slug' => $s->page->slug] : null, 'to' => $s->to_email,
            'contact' => $s->contact?->company_name, 'template' => $s->template, 'subject' => $s->subject, 'status' => $s->status, 'error' => $s->error,
            'mailbox' => $s->mailbox?->label, 'by' => $s->sender?->name, 'created_at' => $s->created_at?->toDateTimeString(), 'sent_at' => $s->sent_at?->toDateTimeString(),
        ]);

        return response()->json($rows);
    }

    private function pageRow(Page $p): array
    {
        return [
            'uuid' => $p->uuid, 'slug' => $p->slug, 'name' => $p->name, 'kind' => $p->kind, 'country' => $p->country, 'owner' => $p->owner?->name, 'owner_uuid' => $p->owner?->uuid,
            'outreach_enabled' => (bool) $p->outreach_enabled, 'outreach_daily_limit' => (int) $p->outreach_daily_limit, 'outreach_bulk_allowed' => (bool) $p->outreach_bulk_allowed,
            'outreach_mailbox' => $p->outreach_mailbox_id ? Mailbox::where('id', $p->outreach_mailbox_id)->value('uuid') : null,
            'outreach_mailbox_group' => $p->outreach_mailbox_group_id ? \App\Models\Outreach\MailboxGroup::where('id', $p->outreach_mailbox_group_id)->value('uuid') : null,
            'calls_disabled' => (bool) $p->calls_disabled, 'meetings_disabled' => (bool) $p->meetings_disabled,
            'contacts_allowed' => (bool) $p->contacts_allowed, 'whatsapp_allowed' => (bool) $p->whatsapp_allowed,
            'rank_tier' => \App\Services\PageRanker::tierOf($p), 'rank_tier_label' => \App\Services\PageRanker::TIER_LABEL[\App\Services\PageRanker::tierOf($p)] ?? 'Free',
            'rank_boost' => (int) $p->rank_boost, 'activity_score' => (int) $p->activity_score, 'rank_score' => (int) $p->rank_score, 'last_activity_at' => $p->last_activity_at ? (string) $p->last_activity_at : null,
            'contacts' => (int) ($p->outreach_contacts_count ?? 0), 'sent' => (int) ($p->sent_count ?? 0),
            'sent_today' => Send::where('page_id', $p->id)->whereDate('created_at', now()->toDateString())->where('status', '!=', 'failed')->count(),
        ];
    }

    // --- Templates (the platform's, offered to every page) -------------------------------

    public function templates(): JsonResponse
    {
        $rows = \App\Models\Outreach\Template::with('page')->orderByRaw('page_id IS NULL DESC')->orderBy('name')->get();

        return response()->json(['data' => [
            'templates' => $rows->map(fn ($t) => \App\Http\Controllers\Api\V1\Business\OutreachCampaignController::templateRow($t) + ['page_name' => $t->page?->name])->values(),
            'placeholders' => \App\Models\Outreach\Template::PLACEHOLDERS,
        ]]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate(\App\Http\Controllers\Api\V1\Business\OutreachCampaignController::templateRules());
        $t = \App\Models\Outreach\Template::create($data + ['page_id' => null, 'created_by' => $request->user()->id]);

        return response()->json(['message' => 'Template saved for every page.', 'data' => \App\Http\Controllers\Api\V1\Business\OutreachCampaignController::templateRow($t)], 201);
    }

    public function updateTemplate(Request $request, string $uuid): JsonResponse
    {
        $t = \App\Models\Outreach\Template::where('uuid', $uuid)->firstOrFail();
        $t->update($request->validate(\App\Http\Controllers\Api\V1\Business\OutreachCampaignController::templateRules(false)));

        return response()->json(['message' => 'Saved.', 'data' => \App\Http\Controllers\Api\V1\Business\OutreachCampaignController::templateRow($t->fresh())]);
    }

    public function destroyTemplate(string $uuid): JsonResponse
    {
        \App\Models\Outreach\Template::where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Removed. Campaigns that used it fall back to the built-in invitation.']);
    }

    // --- Campaigns, contacts and figures across every page -------------------------------

    public function campaigns(Request $request): JsonResponse
    {
        $rows = \App\Models\Outreach\Campaign::with(['template', 'creator', 'page', 'sequence', 'cohort', 'list'])->orderByDesc('id')
            ->when($request->query('status'), fn ($b, $s) => $b->where('status', $s))
            ->paginate(40);
        $rows->getCollection()->transform(fn ($c) => \App\Http\Controllers\Api\V1\Business\OutreachCampaignController::campaignRow($c));

        return response()->json($rows);
    }

    public function pauseCampaign(string $uuid): JsonResponse
    {
        $campaign = \App\Models\Outreach\Campaign::where('uuid', $uuid)->firstOrFail();
        $dropped = Send::where('campaign_id', $campaign->id)->where('status', 'queued')->update(['status' => 'failed', 'error' => 'Paused by the GrapOut team.']);
        $campaign->update(['status' => 'paused', 'failed' => $campaign->failed + $dropped]);

        return response()->json(['message' => 'Paused.']);
    }

    public function contacts(Request $request): JsonResponse
    {
        $q = mb_strtolower(trim((string) $request->query('q')));
        $rows = \App\Models\Outreach\Contact::with('page')->orderByDesc('id')
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->whereRaw('LOWER(email) LIKE ?', ["%{$q}%"])->orWhereRaw('LOWER(company_name) LIKE ?', ["%{$q}%"])->orWhereRaw('LOWER(contact_name) LIKE ?', ["%{$q}%"])))
            ->when($request->query('page_uuid'), fn ($b, $u) => $b->whereHas('page', fn ($p) => $p->where('uuid', $u)))
            ->when($request->query('status'), fn ($b, $s) => $b->where('email_status', $s))
            ->paginate(60);
        $rows->getCollection()->transform(fn ($c) => [
            'uuid' => $c->uuid, 'page' => $c->page ? ['name' => $c->page->name, 'slug' => $c->page->slug, 'uuid' => $c->page->uuid] : null,
            'company_name' => $c->company_name, 'contact_name' => $c->contact_name, 'email' => $c->email, 'email_status' => $c->email_status,
            'mobile' => $c->mobile, 'phone' => $c->phone, 'country' => $c->country, 'sent_count' => (int) $c->sent_count, 'last_sent_at' => $c->last_sent_at?->toDateTimeString(),
        ]);

        return response()->json($rows);
    }

    /** A contact's status, set by the team: bounced, valid, or back to unknown. Unsubscribed stays. */
    public function updateContact(Request $request, string $uuid): JsonResponse
    {
        $c = \App\Models\Outreach\Contact::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(['email_status' => ['required', Rule::in(['unknown', 'valid', 'bounced'])]]);
        abort_if($c->email_status === 'unsubscribed', 422, 'They unsubscribed; that stands.');
        $c->update($data);

        return response()->json(['message' => 'Saved.']);
    }

    public function stats(Request $request): JsonResponse
    {
        $overall = \App\Http\Controllers\Api\V1\Business\OutreachCampaignController::statsFor(null);
        $top = Send::selectRaw('page_id, COUNT(*) as n, SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as o, SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as c')
            ->where('status', 'sent')->groupBy('page_id')->orderByDesc('n')->limit(10)->get();
        $pages = Page::whereIn('id', $top->pluck('page_id'))->get()->keyBy('id');

        return response()->json(['data' => $overall + [
            'mailboxes' => Mailbox::count(),
            'pages_enabled' => Page::where('outreach_enabled', true)->count(),
            'top_pages' => $top->map(fn ($r) => ['name' => $pages[$r->page_id]->name ?? '—', 'slug' => $pages[$r->page_id]->slug ?? null, 'sent' => (int) $r->n, 'opened' => (int) $r->o, 'clicked' => (int) $r->c])->values(),
        ]]);
    }
}
