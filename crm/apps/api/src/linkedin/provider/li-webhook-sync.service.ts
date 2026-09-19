import { Injectable, Logger, OnApplicationBootstrap } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

/**
 * Keeps Unipile's messaging webhook pointed at this deployment.
 *
 * The webhook is how a LinkedIn reply reaches us the moment it arrives. It was
 * registered by hand once, at a temporary ngrok tunnel on somebody's laptop,
 * and then the laptop went away: Unipile kept posting replies into the void
 * while the 3-hourly sync sweep quietly picked them up hours later. Nothing was
 * lost, which is exactly why nobody noticed for months.
 *
 * Two things follow from that, and this service is the answer to both:
 *
 *  - A URL typed into a dashboard has no idea the app has moved. Ours is
 *    derived from APP_PUBLIC_URL, the same value the account-connection
 *    callback already uses, so moving the app to another address re-points the
 *    webhook by itself instead of stranding it.
 *  - A stale registration is not merely useless. Free tunnel hostnames get
 *    handed to somebody else, and the next owner starts receiving clients'
 *    LinkedIn messages, with the secret that authorises them sitting in the
 *    query string.
 *
 * It is deliberately timid. It only ever touches a webhook that is ours — by
 * name, or by pointing at our own endpoint path — and it creates the
 * replacement before removing the old one, so there is no window with no
 * webhook at all. Every failure is a warning and nothing more: the sweep is
 * still there, and a LinkedIn integration must never be the reason the API
 * fails to boot.
 */
@Injectable()
export class LiWebhookSyncService implements OnApplicationBootstrap {
  private readonly logger = new Logger(LiWebhookSyncService.name);

  /** Our webhook's name at Unipile. Also how we recognise it again. */
  private static readonly NAME = 'aeo-linkedin-replies';
  /** The endpoint it must deliver to, relative to APP_PUBLIC_URL. */
  private static readonly PATH = '/api/v1/linkedin/webhooks/unipile/messaging';

  /**
   * The payload fields we ask for, copied from the registration that has been
   * working. `sender` matters most: with it, a reply on a chat we have not seen
   * before is matched to a lead from the payload alone. Without it the inbox
   * has to ask Unipile who was in the chat — a call we would rather not spend.
   */
  private static readonly FIELDS = [
    'account_id', 'account_type', 'account_info', 'webhook_name',
    'chat_id', 'attendees', 'sender', 'subject', 'message', 'mentions',
    'message_id', 'timestamp', 'attachments', 'reaction', 'reaction_sender',
    'read_by', 'is_sender', 'provider_chat_id', 'provider_message_id',
    'is_event', 'chat_pinned', 'quoted', 'reply_to', 'is_forwarded',
    'chat_content_type', 'message_type', 'is_group', 'folder', 'event_type',
  ];

  constructor(private readonly config: ConfigService) {}

  onApplicationBootstrap(): void {
    // Off the boot path entirely. Unipile being slow, or down, is not a reason
    // for this API to be slow to accept its first request.
    setTimeout(() => {
      void this.sync().catch((e) =>
        this.logger.warn(`Webhook sync failed: ${(e as Error).message}`),
      );
    }, 5_000).unref();
  }

  /**
   * Make Unipile's messaging webhook match this deployment. Safe to run as
   * often as you like: when it already matches, it does nothing.
   */
  async sync(): Promise<{ status: 'skipped' | 'unchanged' | 'created' | 'repointed'; reason?: string }> {
    if (this.config.get<string>('UNIPILE_WEBHOOK_SYNC') === 'false') {
      return { status: 'skipped', reason: 'UNIPILE_WEBHOOK_SYNC=false' };
    }

    const dsn = (this.config.get<string>('UNIPILE_DSN') ?? '').replace(/^https?:\/\//i, '').replace(/\/+$/, '');
    const apiKey = this.config.get<string>('UNIPILE_API_KEY') ?? '';
    const publicUrl = (this.config.get<string>('APP_PUBLIC_URL') ?? '').replace(/\/+$/, '');
    const secret = this.config.get<string>('UNIPILE_WEBHOOK_SECRET') ?? '';

    // Each of these is a different kind of "not set up", so say which.
    if (!dsn || !apiKey) return this.skip('Unipile is not configured (UNIPILE_DSN / UNIPILE_API_KEY)');
    if (!publicUrl) return this.skip('APP_PUBLIC_URL is blank — there is no address to send replies to');
    if (!secret) {
      return this.skip(
        'UNIPILE_WEBHOOK_SECRET is blank. The endpoint would be open to anyone who guessed the URL, ' +
          'so the webhook is left alone; replies keep arriving on the 3-hourly sweep.',
      );
    }

    /*
     * The one that matters most.
     *
     * A developer copy carries the same .env as production, so without this a
     * laptop booting the app would hand Unipile its own localhost and every
     * client's replies would stop arriving live — from a machine nobody thinks
     * of as production. Unipile cannot reach a private address anyway, so
     * registering one is never right, only harmful.
     */
    const unreachable = this.whyUnreachable(publicUrl);
    if (unreachable) return this.skip(`APP_PUBLIC_URL is ${unreachable} — Unipile could never reach it, so the webhook is left as it is`);

    if (publicUrl.startsWith('http://')) {
      this.logger.warn(
        'APP_PUBLIC_URL is plain http — the webhook secret will travel in the clear on every reply. Use https.',
      );
    }

    const desired = `${publicUrl}${LiWebhookSyncService.PATH}?secret=${encodeURIComponent(secret)}`;
    const existing = (await this.list(dsn, apiKey)).filter((w) => this.isOurs(w));

    const matching = existing.find((w) => w.request_url === desired);
    if (matching) {
      const strays = existing.filter((w) => w.id !== matching.id);
      for (const w of strays) await this.remove(dsn, apiKey, w.id, w.request_url);

      return { status: 'unchanged' };
    }

    // Create first, delete after: a gap with no webhook loses replies until the
    // next sweep, and there is no reason to accept one.
    await this.create(dsn, apiKey, desired);
    this.logger.log(`Messaging webhook now points at ${this.redact(desired)}`);

    for (const w of existing) await this.remove(dsn, apiKey, w.id, w.request_url);

    return { status: existing.length ? 'repointed' : 'created' };
  }

  /**
   * Ours by name, or by delivering to our own endpoint under a different name.
   *
   * The second test is what lets this adopt the hand-made registration instead
   * of leaving it behind as a duplicate — that path exists nowhere but in this
   * codebase, so anything posting to it was meant for us.
   */
  private isOurs(w: { name?: string; request_url?: string; source?: string }): boolean {
    if (w.source && w.source !== 'messaging') return false;

    return w.name === LiWebhookSyncService.NAME || (w.request_url ?? '').includes(LiWebhookSyncService.PATH);
  }

  private skip(reason: string): { status: 'skipped'; reason: string } {
    this.logger.log(`Messaging webhook not synced: ${reason}`);

    return { status: 'skipped', reason };
  }

  private async list(dsn: string, apiKey: string): Promise<Array<{ id: string; name?: string; request_url?: string; source?: string }>> {
    const res = await fetch(`https://${dsn}/api/v1/webhooks`, {
      headers: { 'X-API-KEY': apiKey, accept: 'application/json' },
    });
    if (!res.ok) throw new Error(`list webhooks failed (${res.status}): ${(await res.text()).slice(0, 200)}`);
    const body = (await res.json()) as { items?: Array<any> };

    return body.items ?? [];
  }

  private async create(dsn: string, apiKey: string, url: string): Promise<void> {
    const res = await fetch(`https://${dsn}/api/v1/webhooks`, {
      method: 'POST',
      headers: { 'X-API-KEY': apiKey, accept: 'application/json', 'content-type': 'application/json' },
      body: JSON.stringify({
        source: 'messaging',
        name: LiWebhookSyncService.NAME,
        request_url: url,
        format: 'json',
        enabled: true,
        events: ['message_received'],
        headers: [],
        data: LiWebhookSyncService.FIELDS.map((key) => ({ key, name: key })),
      }),
    });
    if (!res.ok) throw new Error(`create webhook failed (${res.status}): ${(await res.text()).slice(0, 200)}`);
  }

  /** Removing the old one is housekeeping — never worth failing the sync over. */
  private async remove(dsn: string, apiKey: string, id: string, was?: string): Promise<void> {
    try {
      const res = await fetch(`https://${dsn}/api/v1/webhooks/${encodeURIComponent(id)}`, {
        method: 'DELETE',
        headers: { 'X-API-KEY': apiKey, accept: 'application/json' },
      });
      if (!res.ok) throw new Error(`${res.status}`);
      this.logger.log(`Removed stale messaging webhook ${id} (${this.redact(was ?? '')})`);
    } catch (e) {
      this.logger.warn(
        `Could not remove stale messaging webhook ${id} (${this.redact(was ?? '')}): ${(e as Error).message}. ` +
          'Replies still arrive on the new one; delete it by hand when convenient.',
      );
    }
  }

  /**
   * Why the public internet could not reach this address, or null if it could.
   *
   * Names rather than a boolean, because "it did nothing" is a bad log line
   * and "APP_PUBLIC_URL is a loopback address" tells you what to change.
   */
  private whyUnreachable(url: string): string | null {
    let host: string;
    try {
      host = new URL(url).hostname.toLowerCase();
    } catch {
      return 'not a valid URL';
    }

    if (host === 'localhost' || host.endsWith('.localhost')) return 'localhost';
    if (host === '::1' || host === '[::1]' || /^127\./.test(host)) return 'a loopback address';
    if (host === '0.0.0.0') return 'a wildcard address';
    if (/^10\./.test(host) || /^192\.168\./.test(host) || /^172\.(1[6-9]|2\d|3[01])\./.test(host)) {
      return 'a private network address';
    }
    if (/^169\.254\./.test(host)) return 'a link-local address';
    if (/\.(local|internal|invalid|test|example)$/.test(host)) return `a non-routable name (.${host.split('.').pop()})`;

    return null;
  }

  /** Never put the shared secret in a log line. */
  private redact(url: string): string {
    return url.replace(/([?&]secret=)[^&]*/i, '$1***');
  }
}
