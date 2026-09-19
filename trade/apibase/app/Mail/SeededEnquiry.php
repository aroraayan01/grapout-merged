<?php

namespace App\Mail;

use App\Models\Business\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An enquiry for a company that has not claimed its page yet.
 *
 * The page exists because GrapOut's research put it there; nobody from
 * the company is on the platform to see the enquiry. So it goes where
 * the research says the company reads: its email. The enquiry itself is
 * in the message, and under it the way in — claim the page, and this
 * enquiry and every next one are waiting inside.
 */
class SeededEnquiry extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Enquiry $enquiry)
    {
    }

    public function envelope(): Envelope
    {
        $this->enquiry->loadMissing(['page', 'product', 'requirement']);
        $about = $this->enquiry->product?->name ?? $this->enquiry->requirement?->title ?? 'your products';

        return new Envelope(subject: "Enquiry for {$this->enquiry->page?->name}: {$about}");
    }

    public function content(): Content
    {
        $e = $this->enquiry;
        $e->loadMissing(['page', 'product', 'requirement', 'sender.profile']);
        $front = rtrim((string) config('mypa.frontend_url'), '/');
        $claim = "{$front}/c/{$e->page?->slug}?claim=1";
        $company = e($e->page?->name ?? 'your company');
        $who = e($e->sender?->name ?? $e->guest_name ?? 'A buyer');
        $from = e(implode(', ', array_filter([$e->sender?->profile?->company_name ?? $e->guest_company, $e->sender?->profile?->country ?? $e->guest_country])));
        $about = e($e->product?->name ?? $e->requirement?->title ?? 'your products');
        $asked = nl2br(e($e->message));
        $facts = implode(' · ', array_filter([$e->quantity ? 'Quantity: ' . e($e->quantity) : null, $e->target_price ? 'Target: ' . e($e->currency) . ' ' . number_format((float) $e->target_price, 2) : null]));
        $fromLine = $from !== '' ? " ({$from})" : '';
        $factsLine = $facts !== '' ? "<br><span style=\"color:#64748b;font-size:12px\">{$facts}</span>" : '';

        $html = <<<HTML
        <p>Hello {$company},</p>
        <p><b>{$who}</b>{$fromLine} sent you an enquiry on GrapOut Trade about <b>{$about}</b>:</p>
        <blockquote style="margin:12px 0;padding:12px 16px;background:#f1f5f9;border-left:4px solid #d97706;border-radius:6px">{$asked}{$factsLine}</blockquote>
        <p style="margin-top:20px">
          <a href="{$claim}" style="display:inline-block;padding:10px 18px;background:#d97706;color:#fff;text-decoration:none;border-radius:8px;font-weight:600">Claim your page and reply</a>
        </p>
        <p style="color:#64748b;font-size:12px">GrapOut Trade lists {$company} from public trade records so buyers can find you. Claiming the page takes a minute: you answer this enquiry, add your products and prices, and every next enquiry reaches you directly.</p>
        HTML;

        return new Content(htmlString: $html);
    }
}
