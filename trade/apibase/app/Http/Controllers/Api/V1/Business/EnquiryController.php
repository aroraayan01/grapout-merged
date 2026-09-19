<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\SerializesBusiness;
use App\Http\Controllers\Controller;
use App\Models\Business\Enquiry;
use App\Models\Business\Page;
use App\Models\Business\Product;
use App\Mail\EnquiryReply;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Asking a page about a product, and what either side can do next.
 *
 * The enquiry lands in the owner's Enquiries with the product on it. From
 * there, either person can connect (an ordinary connection request), open
 * a chat, or — if it is rubbish — report the other, which reaches the
 * platform's moderators through the report every part of GrapOut uses.
 */
class EnquiryController extends Controller
{
    use SerializesBusiness;

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate([
            'product' => ['nullable', 'string'],
            'page' => ['nullable', 'string'],
            'requirement' => ['nullable', 'string'],
            'message' => ['required', 'string', 'max:3000'],
            'quantity' => ['nullable', 'string', 'max:80'],
            'target_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $product = null;
        $intent = null;
        if (! empty($data['requirement'])) {
            $intent = \App\Models\Business\Requirement::with('page')->where('uuid', $data['requirement'])->firstOrFail();
            abort_unless($intent->page, 422, 'That opportunity was posted without a company page — connect with the poster instead.');
            $page = $intent->page;
        } elseif (! empty($data['product'])) {
            $product = Product::live()->where('uuid', $data['product'])->with('page.owner')->firstOrFail();
            $page = $product->page;
        } else {
            abort_if(empty($data['page']), 422, 'Say which product, opportunity or page this is about.');
            $page = Page::live()->where('slug', $data['page'])->with('owner')->firstOrFail();
        }
        abort_if($page->isMember($me), 422, 'That is your own company.');

        $enquiry = Enquiry::create([
            'confirmed_at' => now(), // a member's enquiry needs no code
            'page_id' => $page->id,
            'product_id' => $product?->id,
            'requirement_id' => $intent?->id,
            'from_user_id' => $me->id,
            'message' => $data['message'],
            'quantity' => $data['quantity'] ?? null,
            'target_price' => $data['target_price'] ?? null,
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : $product?->currency,
        ]);
        if ($product) {
            Product::whereKey($product->id)->increment('enquiry_count');
        }

        $page->notifyTeam(new \App\Notifications\SocialNotification(
            'business_enquiry',
            "{$me->name} asked about " . ($product?->name ?? $intent?->title ?? $page->name) . '.',
            ['enquiry_uuid' => $enquiry->uuid, 'product_uuid' => $product?->uuid],
            '/business/enquiries',
            'business-enquiry-' . $enquiry->uuid,
        ));

        // Nobody from the company is here yet: the enquiry goes where the research says they read.
        if ($page->isUnclaimed() && $page->email) {
            \Illuminate\Support\Facades\Mail::to($page->email)->send(new \App\Mail\SeededEnquiry($enquiry));
        }

        return response()->json(['message' => $page->isUnclaimed()
            ? "Sent to {$page->name} by email — they have not joined GrapOut yet. You will hear here once they do."
            : 'Sent to ' . $page->name . '. You will hear back here and in your notifications.', 'data' => $this->enquiryRow($enquiry, $me)], 201);
    }

    /** What my page has been asked. */
    public function received(Request $request): JsonResponse
    {
        $me = $request->user();
        $page = $me->businessPage;
        // The GrapOut team with no page picked reads every page's inbox.
        $everything = ! $page && $me->isStaff();
        abort_unless($page || $everything, 404, 'You do not have a business page yet.');

        $rows = Enquiry::confirmed()->with('page')->when($page, fn ($q) => $q->where('page_id', $page->id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')->paginate(25);
        $rows->getCollection()->transform(fn ($e) => $this->enquiryRow($e, $request->user()));

        return response()->json($rows);
    }

    /** What I have asked others. */
    public function sent(Request $request): JsonResponse
    {
        // Asked by email before joining? It is theirs now.
        Enquiry::adoptFor($request->user());

        $me = $request->user();
        $everything = $me->isStaff() && ! $me->businessPage()->exists();
        $rows = Enquiry::with('page')->when(! $everything, fn ($q) => $q->where('from_user_id', $me->id))->orderByDesc('id')->paginate(25);
        $rows->getCollection()->transform(fn ($e) => $this->enquiryRow($e, $request->user()));

        return response()->json($rows);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $page = $me->businessPage;
        $everything = ! $page && $me->isStaff();
        $enquiry = Enquiry::where('uuid', $uuid)->when(! $everything, fn ($q) => $q->where('page_id', $page?->id ?? 0))->firstOrFail();
        $data = $request->validate([
            'status' => ['nullable', Rule::in(Enquiry::STATUSES)],
            'stage' => ['nullable', Rule::in(Enquiry::STAGES)],
        ]);
        abort_if(empty($data['status']) && empty($data['stage']), 422, 'Say what to mark it.');
        if (! empty($data['stage'])) {
            $enquiry->update(['stage' => $data['stage'], 'handled_by' => $enquiry->handled_by ?? $request->user()->id]);
            if (in_array($data['stage'], ['declined', 'lost', 'won'], true)) {
                $enquiry->update(['status' => 'closed']);
            } elseif ($data['stage'] === 'accepted' && $enquiry->status === 'new') {
                $enquiry->update(['status' => 'replied', 'replied_at' => $enquiry->replied_at ?? now()]);
                $enquiry->page->refreshResponseStats();
            }
            if (in_array($data['stage'], ['accepted', 'declined'], true)) {
                $enquiry->sender?->notify(new \App\Notifications\SocialNotification(
                    'business_enquiry',
                    "{$enquiry->page->name} " . ($data['stage'] === 'accepted' ? 'accepted your enquiry. Say hello in the chat.' : 'declined your enquiry.'),
                    ['enquiry_uuid' => $enquiry->uuid],
                    '/business/enquiries?box=sent',
                    'business-enquiry-' . $enquiry->uuid,
                ));
            }
        }
        if (! empty($data['status'])) {
            $enquiry->update(['status' => $data['status']]);
        }

        return response()->json(['message' => 'Marked ' . ($data['stage'] ?? $data['status']) . '.', 'data' => $this->enquiryRow($enquiry->fresh(), $request->user())]);
    }

    /**
     * Open the chat between the two people on an enquiry.
     *
     * Either side may. The message privacy setting is stepped past on
     * purpose: one of them asked the other a question in writing, and a
     * reply that could not be sent would make the enquiry a dead letter.
     * Blocks still hold — Conversation::blockBetween is checked on send.
     */
    public function chat(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $enquiry = Enquiry::where('uuid', $uuid)->with('page.owner')->firstOrFail();
        abort_unless($enquiry->involves($me), 403);

        abort_if($enquiry->isGuest(), 422, 'This came from a visitor without an account. Reply by email from the enquiry, and the chat opens once they join.');
        abort_if($enquiry->page->isUnclaimed(), 422, 'This company has not claimed its page yet. Your enquiry went to them by email; the chat opens once they join.');

        // Once anybody on either side has opened the chat, that is the chat.
        // Before that: a team member talks to the buyer; the buyer talks to
        // whoever runs the page.
        if ($enquiry->conversation_id) {
            $conversation = Conversation::findOrFail($enquiry->conversation_id);
        } else {
            $other = $enquiry->from_user_id === $me->id ? $enquiry->page->owner : $enquiry->sender;
            abort_unless($other, 422, 'The other person is no longer on GrapOut.');
            $conversation = Conversation::directBetween($me, $other);
        }
        if (! $enquiry->conversation_id) {
            $enquiry->update(['conversation_id' => $conversation->id]);
            // The exchange so far opens the chat, so nobody repeats themselves.
            if ($enquiry->viaEmail() && $enquiry->sender && $enquiry->page->owner) {
                $about = $enquiry->product?->name ?? $enquiry->page->name;
                Message::create(['conversation_id' => $conversation->id, 'user_id' => $enquiry->sender->id, 'type' => 'text', 'body' => "Enquiry about {$about}:\n" . $enquiry->message]);
                if ($enquiry->owner_reply) {
                    Message::create(['conversation_id' => $conversation->id, 'user_id' => $enquiry->page->owner->id, 'type' => 'text', 'body' => $enquiry->owner_reply]);
                }
            }
        }
        if ($enquiry->page->isMember($me)) {
            if ($enquiry->status === 'new') {
                $enquiry->update(['status' => 'replied', 'replied_at' => $enquiry->replied_at ?? now()]);
                $enquiry->page->refreshResponseStats();
            }
            $enquiry->update(['handled_by' => $enquiry->handled_by ?? $me->id]);
            $enquiry->advanceTo('accepted');
        }

        return response()->json(['data' => ['conversation_uuid' => $conversation->uuid]]);
    }

    /**
     * Answer a visitor's enquiry.
     *
     * They have no account, so the reply goes to the email they gave — with
     * the reply in it, and a link that carries them into GrapOut if they want
     * to keep talking. A member's enquiry is answered in chat instead.
     */
    public function reply(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $enquiry = Enquiry::where('uuid', $uuid)->with(['page', 'product'])->firstOrFail();
        abort_unless($enquiry->page->isMember($me), 403);
        abort_unless($enquiry->viaEmail(), 422, 'This person asked from inside GrapOut — open the chat instead.');
        $data = $request->validate(['message' => ['required', 'string', 'min:2', 'max:3000']]);

        $enquiry->update(['owner_reply' => $data['message'], 'replied_at' => $enquiry->replied_at ?? now(), 'status' => 'replied']);
        $enquiry->page->refreshResponseStats();
        \Illuminate\Support\Facades\Mail::to($enquiry->guest_email, $enquiry->guest_name)->send(new EnquiryReply($enquiry));

        // Answered in the app as well, when the sender has an account.
        if ($enquiry->from_user_id) {
            $enquiry->sender?->notify(new \App\Notifications\SocialNotification('business_enquiry', "{$enquiry->page->name} replied to your enquiry.", ['enquiry_uuid' => $enquiry->uuid], '/business/enquiries?box=sent', 'business-reply-' . $enquiry->uuid));
        }

        return response()->json(['message' => "Sent to {$enquiry->guest_email}" . ($enquiry->from_user_id ? ' and to their GrapOut enquiries.' : '.'), 'data' => $this->enquiryRow($enquiry->fresh(), $me)]);
    }

    /**
     * The visitor arrives.
     *
     * The link in the reply email carried the enquiry's token; whoever signed
     * in holding it becomes the enquiry's sender, and the exchange so far is
     * laid into a direct chat with the page's owner — their question, then
     * the answer — so both pick up mid-sentence rather than starting over.
     */
    public function claim(Request $request): JsonResponse
    {
        $me = $request->user();
        $token = (string) $request->validate(['token' => ['required', 'string', 'size:64']])['token'];
        $enquiry = Enquiry::where('guest_token', $token)->with(['page.owner', 'product'])->firstOrFail();
        abort_if($enquiry->page->isMember($me), 422, 'That is an enquiry to your own company.');
        abort_if($enquiry->claimed_at && $enquiry->from_user_id !== $me->id, 409, 'This enquiry already belongs to another account.');

        $owner = $enquiry->page->owner;
        abort_unless($owner, 422, 'The page owner is no longer on GrapOut.');

        $conversation = Conversation::directBetween($me, $owner);
        if (! $enquiry->claimed_at) {
            $about = $enquiry->product?->name ?? $enquiry->page->name;
            Message::create(['conversation_id' => $conversation->id, 'user_id' => $me->id, 'type' => 'text', 'body' => "Enquiry about {$about}:\n" . $enquiry->message]);
            if ($enquiry->owner_reply) {
                Message::create(['conversation_id' => $conversation->id, 'user_id' => $owner->id, 'type' => 'text', 'body' => $enquiry->owner_reply]);
            }
            $enquiry->update([
                'from_user_id' => $me->id,
                'claimed_at' => now(),
                'conversation_id' => $conversation->id,
            ]);
            \App\Models\Business\Quote::where('enquiry_id', $enquiry->id)->whereNull('buyer_user_id')->update(['buyer_user_id' => $me->id]);
            $owner->notify(new \App\Notifications\SocialNotification(
                'business_enquiry',
                "{$me->name} joined GrapOut to continue the enquiry about {$about}.",
                ['enquiry_uuid' => $enquiry->uuid, 'conversation_uuid' => $conversation->uuid],
                '/messages?conversation=' . $conversation->uuid,
                'business-enquiry-' . $enquiry->uuid,
            ));
        }

        return response()->json(['message' => 'Welcome. Your conversation with ' . $enquiry->page->name . ' is open.', 'data' => ['conversation_uuid' => $conversation->uuid, 'enquiry_uuid' => $enquiry->uuid]]);
    }

    // --- Meeting, outcome, pipeline ------------------------------------------------------------------

    /**
     * Propose a meeting on an enquiry.
     *
     * A GrapOut video room, hosted by whoever proposes it, on the day and
     * hour they pick. The other side gets the link. The enquiry moves to
     * "meeting", and the outcome recorded afterwards moves it on.
     */
    public function meeting(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $enquiry = Enquiry::where('uuid', $uuid)->with(['page.owner', 'sender', 'product', 'requirement'])->firstOrFail();
        abort_unless($enquiry->involves($me), 403);
        abort_if($enquiry->isGuest(), 422, 'The visitor has to join GrapOut first — reply by email.');
        abort_if($me->featureBlocked('meetings'), 403, 'Meetings are switched off for this account by the GrapOut team.');
        // The company on the other side may have switched meeting requests off.
        abort_if($enquiry->page && ! $enquiry->page->isMember($me) && ($enquiry->page->meetings_disabled || ! ($enquiry->page->accept_meetings ?? true)), 403, 'This company does not take meeting requests at the moment. Reply in the chat instead.');
        $data = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:now'],
            'title' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(['video', 'audio'])],
        ]);

        // The browser sends an ISO instant with a Z; the column wants a plain datetime in the app's zone.
        $at = \Carbon\Carbon::parse($data['scheduled_at'])->setTimezone(config('app.timezone'));
        $about = $enquiry->product?->name ?? $enquiry->requirement?->title ?? $enquiry->page->name;
        $meeting = \App\Models\Meeting::create([
            'host_id' => $me->id,
            'code' => \App\Models\Meeting::generateCode(),
            'title' => $data['title'] ?? "{$enquiry->page->name} × " . ($enquiry->sender?->name ?? 'buyer') . " — {$about}",
            'type' => $data['type'] ?? 'video',
            'is_screen' => false,
            'requires_approval' => false,
            'scheduled_at' => $at,
        ]);
        $enquiry->update(['meeting_id' => $meeting->id, 'meeting_at' => $at, 'handled_by' => $enquiry->handled_by ?? ($enquiry->page->isMember($me) ? $me->id : null)]);
        $enquiry->advanceTo('meeting');

        $when = $at->format('D j M, H:i');
        $others = $enquiry->page->isMember($me) ? collect([$enquiry->sender]) : $enquiry->page->team()->with('user')->get()->map(fn ($m) => $m->user);
        foreach ($others->filter() as $other) {
            $other->notify(new \App\Notifications\SocialNotification(
                'business_meeting',
                "{$me->name} proposed a meeting about {$about}: {$when}.",
                ['enquiry_uuid' => $enquiry->uuid, 'meeting_code' => $meeting->code],
                '/meetings/room/' . $meeting->code,
                'business-meeting-' . $meeting->code,
            ));
        }

        return response()->json(['message' => "Meeting set for {$when}. The link is on the enquiry.", 'data' => $this->enquiryRow($enquiry->fresh(), $me)], 201);
    }

    /** What happened. Recorded by the company's side; moves the stage on. */
    public function outcome(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $enquiry = Enquiry::where('uuid', $uuid)->with('page')->firstOrFail();
        abort_unless($enquiry->page->isMember($me), 403, 'The company records the outcome.');
        $data = $request->validate([
            'outcome' => ['required', Rule::in(array_keys(Enquiry::OUTCOMES))],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $stage = Enquiry::OUTCOMES[$data['outcome']];
        $enquiry->update(['outcome' => $data['outcome'], 'outcome_note' => $data['note'] ?? null, 'outcome_at' => now(), 'handled_by' => $enquiry->handled_by ?? $me->id]);
        // Outcomes may move forward or settle; "not interested" and "won" end it.
        if (in_array($stage, ['won', 'lost'], true)) {
            $enquiry->update(['stage' => $stage, 'status' => 'closed']);
        } else {
            $enquiry->advanceTo($stage);
        }

        return response()->json(['message' => 'Recorded.', 'data' => $this->enquiryRow($enquiry->fresh(), $me)]);
    }

    /**
     * Every conversation of mine, by stage.
     *
     * Received: what my company is being asked, from new to won. Sent:
     * what I asked others. The columns a sales desk wants on a Monday.
     */
    public function pipeline(Request $request): JsonResponse
    {
        $me = $request->user();
        $page = $me->businessPage;
        $group = fn ($rows) => collect(Enquiry::STAGES)->mapWithKeys(fn ($s) => [$s => $rows->where('stage', $s)->map(fn ($e) => $this->enquiryRow($e, $me))->values()]);

        // The GrapOut team with no page picked sees every page's board.
        $everything = ! $page && $me->isStaff();
        $received = $page || $everything ? Enquiry::confirmed()->when($page, fn ($q) => $q->where('page_id', $page->id))->with(['product', 'requirement', 'sender.profile', 'page'])->orderByDesc('updated_at')->limit(300)->get() : collect();
        $sent = Enquiry::when(! $everything, fn ($q) => $q->where('from_user_id', $me->id))->with(['product', 'requirement', 'page.owner'])->orderByDesc('updated_at')->limit(300)->get();

        return response()->json(['data' => [
            'stages' => Enquiry::STAGES,
            'received' => $group($received),
            'sent' => $group($sent),
            'counts' => ['received' => $received->countBy('stage'), 'sent' => $sent->countBy('stage')],
        ]]);
    }
}
