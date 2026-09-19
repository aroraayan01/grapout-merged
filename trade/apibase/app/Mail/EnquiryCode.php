<?php

namespace App\Mail;

use App\Models\Business\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The code that confirms a visitor's enquiry — and, typed back, becomes the
 * temporary password of the account that opens for them.
 */
class EnquiryCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Enquiry $enquiry, public string $code)
    {
    }

    public function envelope(): Envelope
    {
        $this->enquiry->loadMissing(['page', 'product']);

        return new Envelope(subject: "{$this->code} is your code — enquiry to {$this->enquiry->page?->name}");
    }

    public function content(): Content
    {
        $e = $this->enquiry;
        $e->loadMissing(['page', 'product']);
        $name = e($e->guest_name ?: 'there');
        $page = e($e->page?->name ?? 'the supplier');
        $about = e($e->product?->name ?? $e->page?->name ?? '');
        $code = e($this->code);
        $minutes = (int) config('trade.enquiry_code_minutes', 30);

        $html = <<<HTML
        <p>Hello {$name},</p>
        <p>Type this code on the page you asked from, and your enquiry about <b>{$about}</b> goes to <b>{$page}</b>:</p>
        <p style="font-size:28px;letter-spacing:6px;font-weight:700;font-family:monospace;margin:16px 0">{$code}</p>
        <p style="color:#64748b;font-size:13px">It works for {$minutes} minutes. If you did not ask anything, ignore this email — nothing is sent without the code.</p>
        <p style="color:#64748b;font-size:13px">The code is also the temporary password of your new GrapOut account, where the reply and every enquiry you send are kept. Change it in Settings once you are in.</p>
        HTML;

        return new Content(htmlString: $html);
    }
}
