<?php

namespace App\Services;

use App\Models\Outreach\Mailbox;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

/**
 * Checks on a mailbox: does its domain say who may send for it (SPF,
 * DKIM, DMARC), does the SMTP login work, does the IMAP login work.
 * Nothing here sends an email; the test email is the controller's.
 */
class MailboxDoctor
{
    /** Selectors tried for DKIM when the mailbox names none. */
    public const DKIM_SELECTORS = ['default', 'google', 'selector1', 'selector2', 'k1', 'k2', 'mail', 'dkim', 's1', 's2', 'zoho', 'zmail', 'mandrill', 'amazonses', 'mailo', 'smtp', 'protonmail', 'mx', 'em'];

    /** @var null|callable(string $host, string $type): array  — swapped in tests so no DNS is asked */
    public static $resolver = null;

    private function txt(string $host): array
    {
        $records = self::$resolver ? (self::$resolver)($host, 'TXT') : (@dns_get_record($host, DNS_TXT) ?: []);
        $out = [];
        foreach ($records as $r) {
            $v = is_array($r) ? ($r['txt'] ?? (isset($r['entries']) ? implode('', $r['entries']) : '')) : (string) $r;
            if ($v !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }

    /** SPF, DKIM and DMARC for the mailbox's domain, and a score out of 100. */
    public function checkDns(Mailbox $box): array
    {
        $domain = strtolower(substr(strrchr($box->from_address, '@') ?: '', 1));
        $spf = collect($this->txt($domain))->contains(fn ($v) => stripos($v, 'v=spf1') !== false);
        $dmarc = collect($this->txt('_dmarc.' . $domain))->contains(fn ($v) => stripos($v, 'v=DMARC1') !== false);
        $selectors = array_values(array_unique(array_filter(array_merge([$box->dkim_selector], self::DKIM_SELECTORS))));
        $dkim = false;
        $found = null;
        foreach ($selectors as $sel) {
            $recs = $this->txt("{$sel}._domainkey.{$domain}");
            if (collect($recs)->contains(fn ($v) => stripos($v, 'v=DKIM1') !== false || stripos($v, 'p=') !== false)) {
                $dkim = true;
                $found = $sel;
                break;
            }
        }
        $score = ($spf ? 34 : 0) + ($dkim ? 33 : 0) + ($dmarc ? 33 : 0);
        $box->forceFill(['dns_spf' => $spf, 'dns_dkim' => $dkim, 'dns_dmarc' => $dmarc, 'dns_score' => $score, 'dns_checked_at' => now(), 'dkim_selector' => $found ?? $box->dkim_selector])->save();

        $notes = [];
        if (! $spf) {
            $notes[] = "No SPF record on {$domain}: add a TXT record starting v=spf1 that names your mail server.";
        }
        if (! $dkim) {
            $notes[] = 'No DKIM key found' . ($box->dkim_selector ? " under selector {$box->dkim_selector}" : ' under the usual selectors') . ': ask your mail host for the selector and set it on the mailbox.';
        }
        if (! $dmarc) {
            $notes[] = "No DMARC policy on _dmarc.{$domain}: a TXT record such as v=DMARC1; p=none; tells receivers what to do with mail that fails.";
        }

        return ['domain' => $domain, 'spf' => $spf, 'dkim' => $dkim, 'dkim_selector' => $found, 'dmarc' => $dmarc, 'score' => $score, 'notes' => $notes];
    }

    /** Log in to the SMTP server and out again. No email is sent. */
    public function testSmtp(Mailbox $box): array
    {
        if ($box->mailer === 'platform') {
            return $this->noteSmtp($box, true, "The server's own mail setup is used; nothing to log in to.");
        }
        if ($box->mailer === 'ses') {
            $ok = (bool) OutreachMailer::secret($box->ses_key) && (bool) OutreachMailer::secret($box->ses_secret);

            return $this->noteSmtp($box, $ok, $ok ? 'SES keys are saved. Send a test email to be sure they work.' : 'SES key or secret is missing.');
        }
        try {
            $this->smtpLogin($box);

            return $this->noteSmtp($box, true, "Logged in to {$box->smtp_host}:{$box->smtp_port} as " . ($box->smtp_username ?: $box->from_address) . '.');
        } catch (\Throwable $e) {
            return $this->noteSmtp($box, false, 'Could not log in: ' . $e->getMessage());
        }
    }

    private function noteSmtp(Mailbox $box, bool $ok, string $message): array
    {
        $box->forceFill(['smtp_ok' => $ok, 'smtp_tested_at' => now(), 'last_error' => $ok ? null : $message])->save();

        return ['ok' => $ok, 'message' => $message];
    }

    /** The SMTP conversation up to a successful AUTH, then QUIT. */
    private function smtpLogin(Mailbox $box): void
    {
        $host = (string) $box->smtp_host;
        $port = (int) ($box->smtp_port ?: 587);
        $enc = $box->smtp_encryption ?: 'tls';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $s = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
        if (! $s) {
            throw new \RuntimeException("cannot reach {$host}:{$port} ({$errstr})");
        }
        stream_set_timeout($s, 12);
        $expect = function (array $codes) use ($s): string {
            $line = '';
            do {
                $l = fgets($s, 2048);
                if ($l === false) {
                    throw new \RuntimeException('the server closed the connection');
                }
                $line .= $l;
            } while (isset($l[3]) && $l[3] === '-');
            $code = (int) substr($line, 0, 3);
            if (! in_array($code, $codes, true)) {
                throw new \RuntimeException(trim($line));
            }

            return $line;
        };
        $say = function (string $cmd) use ($s): void {
            fwrite($s, $cmd . "\r\n");
        };
        try {
            $expect([220]);
            $say('EHLO grapout.local');
            $expect([250]);
            if ($enc === 'tls') {
                $say('STARTTLS');
                $expect([220]);
                if (! @stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS failed');
                }
                $say('EHLO grapout.local');
                $expect([250]);
            }
            $user = $box->smtp_username ?: $box->from_address;
            $pass = (string) OutreachMailer::secret($box->smtp_password);
            $say('AUTH LOGIN');
            $expect([334]);
            $say(base64_encode($user));
            $expect([334]);
            $say(base64_encode($pass));
            $expect([235]);
            $say('QUIT');
        } finally {
            fclose($s);
        }
    }

    /** An IMAP client for the mailbox, not yet connected. */
    public function imapClient(Mailbox $box): Client
    {
        $enc = $box->imap_encryption ?: 'ssl';

        return (new ClientManager)->make([
            'host' => $box->imap_host,
            'port' => (int) ($box->imap_port ?: 993),
            'encryption' => $enc === 'none' ? false : $enc,
            'validate_cert' => ! $box->imap_self_signed,
            'username' => $box->imap_username ?: $box->from_address,
            'password' => (string) (OutreachMailer::secret($box->imap_password) ?: OutreachMailer::secret($box->smtp_password)),
            'protocol' => 'imap',
            'timeout' => 15,
        ]);
    }

    /** Log in over IMAP and count the inbox. */
    public function testImap(Mailbox $box): array
    {
        if (! $box->imap_host) {
            return $this->noteImap($box, false, 'No IMAP host set; replies cannot be read for this mailbox.');
        }
        try {
            $client = $this->imapClient($box);
            $client->connect();
            $n = $client->getFolder('INBOX')?->messages()->all()->count() ?? 0;
            $client->disconnect();

            return $this->noteImap($box, true, "Logged in to {$box->imap_host}; {$n} messages in the inbox.");
        } catch (\Throwable $e) {
            return $this->noteImap($box, false, 'Could not log in over IMAP: ' . $e->getMessage());
        }
    }

    private function noteImap(Mailbox $box, bool $ok, string $message): array
    {
        $box->forceFill(['imap_ok' => $ok, 'imap_tested_at' => now(), 'last_error' => $ok ? $box->last_error : $message])->save();

        return ['ok' => $ok, 'message' => $message];
    }
}
