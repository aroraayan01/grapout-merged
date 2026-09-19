<?php

namespace App\Mail;

use App\Models\Business\CompanyMember;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Join our company page on GrapOut Trade."
 *
 * Sent to somebody who is not on the platform yet. The link carries the
 * invitation's token: open it, create the account, and they are on the
 * team the moment the account exists.
 */
class TeamInvite extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CompanyMember $member)
    {
    }

    public function envelope(): Envelope
    {
        $this->member->loadMissing(['page', 'inviter']);

        return new Envelope(subject: "{$this->member->inviter?->name} invited you to {$this->member->page?->name} on GrapOut Trade");
    }

    public function content(): Content
    {
        $m = $this->member;
        $m->loadMissing(['page', 'inviter']);
        $front = rtrim((string) config('mypa.frontend_url'), '/');
        $link = "{$front}/join-company/{$m->invite_token}";
        $who = e($m->inviter?->name ?? 'A colleague');
        $company = e($m->page?->name ?? 'a company');
        $role = e(CompanyMember::FUNCTION_LABELS[$m->function] ?? ucfirst((string) $m->function));

        $html = <<<HTML
        <p>Hello,</p>
        <p><b>{$who}</b> has added you to the team of <b>{$company}</b> on GrapOut Trade{$role}.</p>
        <p style="margin-top:20px"><a href="{$link}" style="display:inline-block;padding:10px 18px;background:#059669;color:#fff;text-decoration:none;border-radius:8px;font-weight:600">Join the team</a></p>
        <p style="color:#64748b;font-size:12px">One step: your email is already filled in. You will be able to answer enquiries, send quotes and represent {$company} to buyers and suppliers.</p>
        HTML;

        return new Content(htmlString: $html);
    }
}
