<?php

namespace App\Models\Outreach;

use App\Models\Business\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved slice of a list: "valid addresses in Germany never written
 * to", "everyone who was written to a month ago and did not answer".
 * Kept as filters, so it is always up to date. A cohort with no page
 * is the GrapOut team's: offered to every page, applied to its own list.
 */
class Cohort extends Model
{
    use HasUuids;

    protected $table = 'outreach_cohorts';

    /** The filters a cohort may carry, and what each means. */
    public const FILTERS = [
        'status' => 'Address status: unknown, valid',
        'country' => 'Country (ISO code)',
        'lists' => 'On any of these lists (uuids)',
        'never_sent' => 'Never written to',
        'replied' => 'Replied (true) or never replied (false)',
        'sent_days_ago' => 'Last written to at least N days ago',
        'no_reply_days' => 'Written to at least N days ago and no reply since',
    ];

    protected $fillable = ['page_id', 'name', 'description', 'filters', 'created_by'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Narrow a contact query by these filters. Works on any page's contacts. */
    public function apply(Builder $q): Builder
    {
        $f = $this->filters ?? [];
        if (! empty($f['status'])) {
            $q->where('email_status', $f['status']);
        }
        if (! empty($f['country'])) {
            $q->where('country', strtoupper($f['country']));
        }
        if (! empty($f['lists']) && is_array($f['lists'])) {
            $q->whereHas('lists', fn ($l) => $l->whereIn('outreach_lists.uuid', $f['lists']));
        }
        if (! empty($f['never_sent'])) {
            $q->where('sent_count', 0);
        }
        if (array_key_exists('replied', $f) && $f['replied'] !== null && $f['replied'] !== '') {
            filter_var($f['replied'], FILTER_VALIDATE_BOOLEAN) ? $q->whereNotNull('replied_at') : $q->whereNull('replied_at');
        }
        if (! empty($f['sent_days_ago'])) {
            $q->whereNotNull('last_sent_at')->where('last_sent_at', '<=', now()->subDays((int) $f['sent_days_ago']));
        }
        if (! empty($f['no_reply_days'])) {
            $q->whereNotNull('last_sent_at')->where('last_sent_at', '<=', now()->subDays((int) $f['no_reply_days']))->whereNull('replied_at');
        }

        return $q;
    }

    /** Only the filters we know, with the values tidied. */
    public static function cleanFilters(array $in): array
    {
        $out = [];
        if (! empty($in['status']) && in_array($in['status'], ['unknown', 'valid'], true)) {
            $out['status'] = $in['status'];
        }
        if (! empty($in['country']) && preg_match('/^[A-Za-z]{2}$/', $in['country'])) {
            $out['country'] = strtoupper($in['country']);
        }
        if (! empty($in['lists']) && is_array($in['lists'])) {
            $out['lists'] = array_values(array_filter(array_map('strval', $in['lists'])));
        }
        if (! empty($in['never_sent'])) {
            $out['never_sent'] = true;
        }
        if (array_key_exists('replied', $in) && $in['replied'] !== null && $in['replied'] !== '') {
            $out['replied'] = filter_var($in['replied'], FILTER_VALIDATE_BOOLEAN);
        }
        foreach (['sent_days_ago', 'no_reply_days'] as $k) {
            if (! empty($in[$k]) && (int) $in[$k] > 0) {
                $out[$k] = min(365, (int) $in[$k]);
            }
        }

        return $out;
    }

    /** The slice in words, for a list row. */
    public function describe(): string
    {
        $f = $this->filters ?? [];
        $bits = [];
        if (! empty($f['status'])) {
            $bits[] = $f['status'] === 'valid' ? 'checked-valid addresses' : 'unchecked addresses';
        }
        if (! empty($f['country'])) {
            $bits[] = 'in ' . $f['country'];
        }
        if (! empty($f['lists'])) {
            $bits[] = 'on ' . count($f['lists']) . ' list' . (count($f['lists']) === 1 ? '' : 's');
        }
        if (! empty($f['never_sent'])) {
            $bits[] = 'never written to';
        }
        if (array_key_exists('replied', $f)) {
            $bits[] = $f['replied'] ? 'who replied' : 'who never replied';
        }
        if (! empty($f['sent_days_ago'])) {
            $bits[] = "written to {$f['sent_days_ago']}+ days ago";
        }
        if (! empty($f['no_reply_days'])) {
            $bits[] = "no reply in {$f['no_reply_days']} days";
        }

        return $bits ? ucfirst(implode(', ', $bits)) : 'Everyone who can be written to';
    }
}
