<?php

namespace App\Models\Outreach;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mailbox the GrapOut team keeps for outreach. Secrets are stored
 * encrypted and never leave the server; the admin screen only learns
 * whether one is saved.
 */
class Mailbox extends Model
{
    use HasUuids;

    protected $table = 'outreach_mailboxes';

    public const MAILERS = ['platform', 'smtp', 'ses'];

    public const SECRETS = ['smtp_password', 'ses_key', 'ses_secret', 'imap_password'];

    protected $fillable = [
        'label', 'from_name', 'from_address', 'reply_to', 'mailer',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
        'ses_key', 'ses_secret', 'ses_region', 'is_default', 'active', 'daily_limit', 'group_id',
        'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password', 'imap_self_signed', 'dkim_selector',
    ];

    protected $hidden = ['smtp_password', 'ses_key', 'ses_secret', 'imap_password'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean', 'active' => 'boolean', 'imap_self_signed' => 'boolean',
            'dns_spf' => 'boolean', 'dns_dkim' => 'boolean', 'dns_dmarc' => 'boolean', 'smtp_ok' => 'boolean', 'imap_ok' => 'boolean',
            'dns_checked_at' => 'datetime', 'smtp_tested_at' => 'datetime', 'imap_tested_at' => 'datetime', 'inbox_synced_at' => 'datetime',
        ];
    }

    public function group(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MailboxGroup::class, 'group_id');
    }

    /** Can replies be read from it? */
    public function receives(): bool
    {
        return (bool) $this->imap_host;
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function sends(): HasMany
    {
        return $this->hasMany(Send::class, 'mailbox_id');
    }

    /** How many went out of this mailbox today. */
    public function sentToday(): int
    {
        return $this->sends()->whereDate('created_at', now()->toDateString())->where('status', '!=', 'failed')->count();
    }
}
