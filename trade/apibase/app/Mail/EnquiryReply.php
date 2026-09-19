<?php

namespace App\Mail;

use App\Models\Business\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The supplier's answer, to somebody who asked without an account.
 *
 * The reply itself is in the email, so the buyer gets what they asked for
 * without doing anything. The link under it is the invitation: open it,
 * sign up in a step, and the exchange carries on as a chat on GrapOut
 * with the supplier — calls, files and everything else the app has.
 *
 * The link carries the enquiry's secret token. Whoever holds the email
 * holds the enquiry, which is the same trust a reply-to address gives.
 */
class EnquiryReply extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Enquiry $enquiry)
    {
    }

    public function envelope(): Envelope
    {
        $this->enquiry->loadMissing(['page', 'product']);
        $about = $this->enquiry->product?->name ?? $this->enquiry->page?->name ?? 'your enquiry';

        return new Envelope(subject: "Reply from {$this->enquiry->page?->name}: {$about}");
    }

    public function content(): Content
    {
        $e = $this->enquiry;
        $e->loadMissing(['page', 'product']);
        $front = rtrim((string) config('mypa.frontend_url'), '/');
        // With an account (every confirmed enquiry has one) the door is the sign-in;
        // an older enquiry with none still carries its continue link.
        $hasAccount = (bool) $e->from_user_id;
        $continue = $hasAccount ? "{$front}/business/enquiries?box=sent" : "{$front}/continue/{$e->guest_token}";
        $cta = $hasAccount ? 'See the reply on GrapOut' : 'Continue this conversation on GrapOut';
        $note = $hasAccount
            ? 'Sign in with your email and the temporary password we sent when you asked — or reset it from the sign-in page. Your enquiries, the quotes and the chat with ' . e($e->page?->name ?? 'the supplier') . ' are all there.'
            : 'One step to join — your name and email are already filled in. From there you can message and call ' . e($e->page?->name ?? 'the supplier') . ' directly, and keep every enquiry in one place.';
        $name = e($e->guest_name ?: 'there');
        $page = e($e->page?->name ?? 'the supplier');
        $about = e($e->product?->name ?? $e->page?->name ?? '');
        $asked = nl2br(e($e->message));
        $reply = nl2br(e((string) $e->owner_reply));
        $pageLink = "{$front}/c/{$e->page?->slug}";

        $html = <<<HTML
        <p>Hello {$name},</p>
        <p><b>{$page}</b> has replied to your enquiry about <b>{$about}</b>:</p>
        <blockquote style="margin:12px 0;padding:12px 16px;background:#f1f5f9;border-left:4px solid #059669;border-radius:6px">{$reply}</blockquote>
        <p style="margin-top:20px">
          <a href="{$continue}" style="display:inline-block;padding:10px 18px;background:#d97706;color:#fff;text-decoration:none;border-radius:8px;font-weight:600">{$cta}</a>
        </p>
        <p style="color:#64748b;font-size:12px">{$note}</p>
        <p style="color:#94a3b8;font-size:12px;margin-top:24px">You asked: <i>{$asked}</i><br>
        Their page: <a href="{$pageLink}">{$pageLink}</a></p>
        HTML;

        return new Content(htmlString: $html);
    }
}
