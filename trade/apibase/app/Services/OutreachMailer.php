<?php

namespace App\Services;

use App\Mail\OutreachMail;
use App\Models\Outreach\Mailbox;
use App\Models\Outreach\Send;
use App\Models\Outreach\Template;
use App\Support\Catalogue;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * Writes to a company's buyers and suppliers from a mailbox the GrapOut
 * team keeps: the invitation to the company's page, its catalogue, or a
 * template written in words. Every email carries the page link, the
 * GrapOut Trade mark, a way to unsubscribe, and the two small things
 * that say whether it was opened and clicked.
 */
class OutreachMailer
{
    /** The transport behind a mailbox: the platform's own, or the box's SMTP / SES. */
    public function mailerFor(?Mailbox $box): Mailer
    {
        if (! $box || $box->mailer === 'platform') {
            return Mail::mailer();
        }
        try {
            if ($box->mailer === 'smtp' && $box->smtp_host) {
                $encryption = $box->smtp_encryption ?: 'tls';

                return Mail::build(array_filter([
                    'transport' => 'smtp',
                    'host' => $box->smtp_host,
                    'port' => (int) ($box->smtp_port ?: 587),
                    'scheme' => $encryption === 'ssl' ? 'smtps' : null,
                    'encryption' => $encryption === 'none' ? null : 'tls',
                    'username' => $box->smtp_username ?: $box->from_address,
                    'password' => self::secret($box->smtp_password),
                ], fn ($v) => $v !== null));
            }
            if ($box->mailer === 'ses' && $box->ses_key) {
                return Mail::build([
                    'transport' => 'ses',
                    'key' => self::secret($box->ses_key),
                    'secret' => self::secret($box->ses_secret),
                    'region' => $box->ses_region ?: 'ap-south-1',
                ]);
            }
        } catch (\Throwable) {
            // A broken mailbox must not swallow the email: it goes the platform's way.
        }

        return Mail::mailer();
    }

    /** Send one row, and record how it went. */
    public function deliver(Send $send): void
    {
        $send->loadMissing(['page.owner', 'page.tradeLines', 'page.products.images', 'contact', 'mailbox', 'tpl', 'campaign']);
        $box = $send->mailbox;
        [$subject, $html] = $this->render($send);
        $send->update(['subject' => $subject]);

        try {
            $mailer = $this->mailerFor($box);
            $from = $box ? [$box->from_address, $box->from_name] : [config('mail.from.address'), config('mail.from.name')];
            // The Message-ID is ours, so a reply's In-Reply-To finds its way back to this row.
            $domain = substr(strrchr((string) $from[0], '@') ?: '@grapout.com', 1) ?: 'grapout.com';
            $messageId = str_replace('-', '', (string) \Illuminate\Support\Str::uuid()) . '.' . $send->id . '@' . $domain;
            $send->update(['message_id' => $messageId]);
            $mailer->to($send->to_email, $send->contact?->contact_name)
                ->send(new OutreachMail($subject, $html, (string) $from[0], (string) $from[1], $box?->reply_to, $messageId));
            $send->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
            $send->contact?->update(['sent_count' => ($send->contact->sent_count ?? 0) + 1, 'last_sent_at' => now(), 'last_template' => $send->template]);
            if ($send->campaign) {
                $send->campaign->increment('sent');
                $send->campaign->fresh()->settle();
            }
        } catch (\Throwable $e) {
            $send->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            if ($send->campaign) {
                $send->campaign->increment('failed');
                $send->campaign->fresh()->settle();
            }
        }
    }

    /** The words a template may use, filled in for this send. */
    public function placeholders(Send $send): array
    {
        $page = $send->page;
        $contact = $send->contact;
        $front = rtrim((string) config('mypa.frontend_url'), '/');
        $api = rtrim((string) config('app.url'), '/');

        return [
            'contact_name' => $contact?->contact_name ?: ($contact?->company_name ?: 'there'),
            'company_name' => $contact?->company_name ?: '',
            'page_name' => $page->name,
            'page_link' => "{$front}/c/{$page->slug}",
            'catalogue_link' => "{$api}/api/v1/trade/pages/{$page->slug}/catalogue.pdf" . ($send->with_prices ? '' : '?prices=hide'),
            // What the page sells: its trade lines, or failing those, the names of its products.
            'sells' => $page->tradeLines->where('direction', 'sell')->pluck('description')->filter()->take(6)->implode(', ')
                ?: $page->products->where('status', 'active')->sortBy('sort')->pluck('name')->filter()->take(6)->implode(', '),
            'buys' => $page->tradeLines->where('direction', 'buy')->pluck('description')->filter()->take(6)->implode(', '),
            'sender_name' => $page->owner?->name ?? $page->name,
            'note' => (string) $send->note,
        ];
    }

    /** @return array{0: string, 1: string} subject and HTML */
    public function render(Send $send): array
    {
        $page = $send->page;
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $ph = $this->placeholders($send);
        $front = rtrim((string) config('mypa.frontend_url'), '/');
        $api = rtrim((string) config('app.url'), '/');
        $pageLink = $ph['page_link'];
        $catalogueLink = $ph['catalogue_link'];
        $unsubscribe = "{$api}/api/v1/trade/outreach/unsubscribe/{$send->uuid}";
        $track = fn (string $url) => "{$api}/api/v1/trade/outreach/c/{$send->uuid}?u=" . rawurlencode($url);
        $name = $e($page->name);
        $who = $e($ph['contact_name']);
        $kind = ucfirst(str_replace('_', ' ', (string) $page->kind));
        $where = implode(', ', array_filter([$page->city, $page->country]));
        $verified = $page->verification_status === 'verified' ? ' <span style="font-size:11px;color:#0369a1;background:#e0f2fe;border-radius:10px;padding:2px 8px">&#10004; Verified</span>' : '';
        $note = $send->note ? '<blockquote style="margin:14px 0;padding:10px 14px;background:#fffbeb;border-left:4px solid #f59e0b;border-radius:6px;color:#334155">' . nl2br($e($send->note)) . '</blockquote>' : '';
        $signature = $e($ph['sender_name']) . ($page->owner ? ', ' . $name : '');
        $button = fn (string $href, string $label, string $bg = '#d97706') => '<a href="' . $e($track($href)) . '" style="display:inline-block;margin:6px 8px 6px 0;padding:11px 18px;background:' . $bg . ';color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px">' . $label . '</a>';

        $template = $send->tpl;
        if ($template) {
            // A template written in words: placeholders in, the page's links tracked.
            $subject = $this->fill($template->subject, $ph, false);
            $bodyText = $this->fill($template->body, $ph, true);
            $bodyText = nl2br($bodyText);
            $body = '<div style="font-size:15px;line-height:1.5">' . $bodyText . '</div>'
                . '<p style="margin-top:18px">' . $button($pageLink, 'See ' . $name . "'s page") . ($template->kind === 'catalogue' ? $button($catalogueLink, 'Open the catalogue (PDF)', '#0f172a') : $button($pageLink, 'Send an enquiry', '#0f172a')) . '</p>';
        } elseif ($send->template === 'catalogue') {
            $products = $page->products->where('status', 'active')->sortBy('sort')->take(6);
            $rows = '';
            foreach ($products as $p) {
                $price = $send->with_prices ? Catalogue::priceLabel($p) : 'Price on enquiry';
                $rows .= '<tr><td style="padding:8px 0;border-top:1px solid #f1f5f9;font-size:14px"><b>' . $e($p->name) . '</b>' . ($p->summary ? '<br><span style="color:#64748b;font-size:12px">' . $e($p->summary) . '</span>' : '') . '</td><td style="padding:8px 0;border-top:1px solid #f1f5f9;text-align:right;white-space:nowrap;font-size:13px;color:#b45309">' . $e($price) . '</td></tr>';
            }
            $more = $page->products->where('status', 'active')->count() > 6 ? '<p style="color:#64748b;font-size:12px">And more in the full catalogue.</p>' : '';
            $subject = "{$page->name} — product catalogue" . ($send->with_prices ? ' with prices' : '');
            $body = <<<HTML
            <p style="font-size:15px">Hello {$who},</p>
            <p style="font-size:15px">Please find the product catalogue of <b>{$name}</b>{$verified} — a {$e(strtolower($kind))}{$e($where ? ' in ' . $where : '')}.</p>
            {$note}
            <table style="border-collapse:collapse;width:100%">{$rows}</table>
            {$more}
            <p style="margin-top:18px">{$button($catalogueLink, 'Open the catalogue (PDF)')}{$button($pageLink, 'See the page', '#0f172a')}</p>
            <p style="font-size:14px;color:#334155">To ask for a price, a sample or a quotation, open the page and press <b>Send enquiry</b>. The reply comes to your email, and to your GrapOut Trade account where every enquiry you send is kept.</p>
            HTML;
        } else {
            $facts = '';
            foreach (array_filter(['What we are' => trim($kind . ($where ? ' · ' . $where : '')), 'We sell' => $ph['sells'], 'We buy' => $ph['buys']]) as $k => $v) {
                $facts .= '<tr><td style="padding:4px 10px 4px 0;color:#64748b;font-size:13px;white-space:nowrap;vertical-align:top">' . $e($k) . '</td><td style="padding:4px 0;font-size:13px;color:#0f172a">' . $e($v) . '</td></tr>';
            }
            $subject = "{$page->name} invites you to GrapOut Trade";
            $body = <<<HTML
            <p style="font-size:15px">Hello {$who},</p>
            <p style="font-size:15px"><b>{$name}</b>{$verified} would like to work with you, and has opened its page on <b>GrapOut Trade</b> — the network where buyers and suppliers meet directly: enquiries, quotes, chat and video meetings in one place.</p>
            {$note}
            <table style="border-collapse:collapse">{$facts}</table>
            <p style="margin-top:18px">{$button($pageLink, 'See ' . $name . "'s page")}{$button($pageLink, 'Send an enquiry', '#0f172a')}</p>
            <p style="font-size:14px;color:#334155">Following the page brings its products and requirements to you; an enquiry opens the conversation. No account is needed to ask — one opens for you when you do.</p>
            HTML;
        }

        $pixel = "{$api}/api/v1/trade/outreach/o/{$send->uuid}.gif";
        $html = <<<HTML
        <div style="font-family:Helvetica,Arial,sans-serif;max-width:640px;margin:0 auto;color:#0f172a">
          <div style="padding:14px 0;border-bottom:2px solid #f59e0b;margin-bottom:18px"><span style="font-weight:800;letter-spacing:1px;color:#b45309">GRAPOUT TRADE</span> <span style="color:#64748b;font-size:12px">&nbsp;buyers and suppliers, one network</span></div>
          {$body}
          <p style="font-size:14px;margin-top:22px">Regards,<br><b>{$signature}</b><br><a href="{$e($track($pageLink))}" style="color:#b45309">{$e($pageLink)}</a></p>
          <div style="margin-top:28px;padding-top:12px;border-top:1px solid #e2e8f0;color:#94a3b8;font-size:11px">
            Sent through GrapOut Trade on behalf of {$name}. <a href="{$e($front)}" style="color:#94a3b8">Find more suppliers and buyers</a> &middot; <a href="{$e($unsubscribe)}" style="color:#94a3b8">Unsubscribe</a> from emails by {$name}.
          </div>
          <img src="{$e($pixel)}" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0">
        </div>
        HTML;

        return [$subject, $html];
    }

    /** {{placeholder}} → value; escaped for HTML when the text goes into the body. */
    private function fill(string $text, array $ph, bool $html): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($ph, $html) {
            $v = (string) ($ph[$m[1]] ?? '');

            return $html ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v;
        }, $html ? htmlspecialchars($text, ENT_QUOTES, 'UTF-8') : $text);
    }

    public static function secret(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return $stored;
        }
    }
}
