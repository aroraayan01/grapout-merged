<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\SerializesBusiness;
use App\Http\Controllers\Controller;
use App\Mail\EnquiryReply;
use App\Models\Business\Enquiry;
use App\Models\Business\Product;
use App\Models\Business\Quote;
use App\Models\Business\Requirement;
use App\Notifications\SocialNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Structured offers.
 *
 * A page quotes a requirement, or answers an enquiry with a quote instead
 * of a paragraph. The buyer accepts or declines; either way the supplier
 * hears. A quote to a visitor's enquiry travels in the reply email.
 */
class QuoteController extends Controller
{
    use SerializesBusiness;

    private function rules(): array
    {
        return [
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'price_unit' => ['nullable', 'string', 'max:40'],
            'moq' => ['nullable', 'numeric', 'min:0'],
            'moq_unit' => ['nullable', 'string', 'max:24'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:730'],
            'valid_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'terms' => ['nullable', Rule::in(Product::TERMS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function myPage(Request $request)
    {
        $page = $request->user()->businessPage()->live()->first();
        abort_unless($page, 422, 'Open a business page first — quotes are sent from a page.');

        return $page;
    }

    /** Quote a requirement. */
    public function forRequirement(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $page = $this->myPage($request);
        $r = Requirement::with('buyer')->where('uuid', $uuid)->firstOrFail();
        abort_if($r->user_id === $me->id, 422, 'That is your own requirement.');
        abort_unless($r->status === 'open', 422, 'This requirement is closed.');
        $data = $request->validate($this->rules());

        $quote = Quote::create($data + [
            'page_id' => $page->id, 'user_id' => $me->id, 'requirement_id' => $r->id, 'buyer_user_id' => $r->user_id,
            'currency' => strtoupper($data['currency']), 'valid_days' => $data['valid_days'] ?? 30,
        ]);
        Requirement::whereKey($r->id)->increment('quotes_count');

        $r->buyer?->notify(new SocialNotification(
            'business_quote',
            "{$page->name} quoted {$quote->currency} " . number_format((float) $quote->price, 2) . ($quote->price_unit ? " / {$quote->price_unit}" : '') . " for: {$r->title}",
            ['quote_uuid' => $quote->uuid, 'requirement_uuid' => $r->uuid],
            '/requirements/' . $r->uuid,
            'business-quote-' . $quote->uuid,
        ));

        return response()->json(['message' => "Quote sent to {$r->buyer?->name}.", 'data' => $this->quoteRow($quote->fresh(['page', 'supplier']), $me)], 201);
    }

    /** Answer an enquiry with a quote. */
    public function forEnquiry(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $page = $this->myPage($request);
        $e = Enquiry::with(['sender', 'product', 'page'])->where('uuid', $uuid)->firstOrFail();
        abort_unless($e->page_id === $page->id, 403);
        $data = $request->validate($this->rules());

        $quote = Quote::create($data + [
            'page_id' => $page->id, 'user_id' => $me->id, 'enquiry_id' => $e->id, 'buyer_user_id' => $e->from_user_id,
            'currency' => strtoupper($data['currency']), 'valid_days' => $data['valid_days'] ?? 30,
        ]);
        $e->update(['status' => 'replied', 'replied_at' => $e->replied_at ?? now(), 'handled_by' => $e->handled_by ?? $me->id]);
        $e->advanceTo('quoted');
        $page->refreshResponseStats();

        if ($e->viaEmail()) {
            // Whoever asked from the public page gets the quote by email as well.
            $summary = $this->quoteText($quote);
            $e->update(['owner_reply' => trim(($e->owner_reply ? $e->owner_reply . "\n\n" : '') . $summary)]);
            Mail::to($e->guest_email, $e->guest_name)->send(new EnquiryReply($e->fresh()));
        }
        if (! $e->isGuest()) {
            $e->sender?->notify(new SocialNotification(
                'business_quote',
                "{$page->name} sent you a quote: {$quote->currency} " . number_format((float) $quote->price, 2) . ($quote->price_unit ? " / {$quote->price_unit}" : '') . ' for ' . ($e->product?->name ?? $page->name) . '.',
                ['quote_uuid' => $quote->uuid, 'enquiry_uuid' => $e->uuid],
                '/business/enquiries?box=sent',
                'business-quote-' . $quote->uuid,
            ));
        }

        return response()->json(['message' => $e->viaEmail() ? "Quote emailed to {$e->guest_email}" . ($e->isGuest() ? '.' : ' and sent to their GrapOut enquiries.') : "Quote sent to {$e->sender?->name}.", 'data' => $this->quoteRow($quote->fresh(['page', 'supplier']), $me)], 201);
    }

    /** The buyer accepts or declines; the supplier withdraws. */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $q = Quote::with(['page.owner', 'buyer', 'requirement', 'enquiry'])->where('uuid', $uuid)->firstOrFail();
        $status = $request->validate(['status' => ['required', Rule::in(['accepted', 'declined', 'withdrawn'])]])['status'];

        if ($status === 'withdrawn') {
            abort_unless($q->page->isMember($me), 403);
        } else {
            abort_unless($q->buyer_user_id === $me->id || $q->enquiry?->from_user_id === $me->id, 403);
            abort_unless($q->status === 'sent', 422, 'This quote was already answered.');
        }
        $q->update(['status' => $status, 'responded_at' => now()]);

        $about = $q->requirement?->title ?? $q->enquiry?->product?->name ?? $q->page->name;
        if ($status !== 'withdrawn') {
            $q->page->notifyTeam(new SocialNotification(
                'business_quote',
                "{$me->name} {$status} your quote for {$about}." . ($status === 'accepted' ? ' Open the chat to close the deal.' : ''),
                ['quote_uuid' => $q->uuid],
                $q->requirement ? '/requirements/' . $q->requirement->uuid : '/business/enquiries',
                'business-quote-' . $q->uuid,
            ));
        }

        return response()->json(['message' => ucfirst($status) . '.', 'data' => $this->quoteRow($q->fresh(['page', 'supplier']), $me)]);
    }

    /** Quotes I sent (as a page) or received (as a buyer). */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $box = $request->query('box', 'received');
        $myPage = $me->businessPage()->value('business_pages.id');
        $everything = $box === 'sent' && ! $myPage && $me->isStaff();
        $rows = Quote::with(['page', 'supplier', 'requirement', 'enquiry.product'])
            // Sent: by me, or by my page; the GrapOut team with no page picked sees every page's.
            ->when($box === 'sent' && ! $everything, fn ($b) => $b->where(fn ($w) => $w->where('user_id', $me->id)->when($myPage, fn ($x) => $x->orWhere('page_id', $myPage))))
            // Received: addressed to me, or answering an enquiry that became mine after it was sent.
            ->when($box !== 'sent', fn ($b) => $b->where(fn ($w) => $w->where('buyer_user_id', $me->id)->orWhereHas('enquiry', fn ($e) => $e->where('from_user_id', $me->id))))
            ->orderByDesc('created_at')
            ->paginate(20);
        $rows->getCollection()->transform(fn (Quote $q) => $this->quoteRow($q, $me) + [
            'about' => $q->requirement ? ['kind' => 'requirement', 'uuid' => $q->requirement->uuid, 'title' => $q->requirement->title]
                : ($q->enquiry ? ['kind' => 'enquiry', 'uuid' => $q->enquiry->uuid, 'title' => $q->enquiry->product?->name ?? 'Enquiry'] : null),
        ]);

        return response()->json($rows);
    }

    private function quoteText(Quote $q): string
    {
        $lines = ["Quote: {$q->currency} " . number_format((float) $q->price, 2) . ($q->price_unit ? " / {$q->price_unit}" : '') . ($q->terms ? " {$q->terms}" : '')];
        if ($q->moq !== null) {
            $lines[] = 'MOQ: ' . number_format((float) $q->moq, 2) . ($q->moq_unit ? " {$q->moq_unit}" : '');
        }
        if ($q->lead_time_days !== null) {
            $lines[] = "Lead time: {$q->lead_time_days} days";
        }
        $lines[] = "Valid for {$q->valid_days} days";
        if ($q->notes) {
            $lines[] = $q->notes;
        }

        return implode("\n", $lines);
    }
}
