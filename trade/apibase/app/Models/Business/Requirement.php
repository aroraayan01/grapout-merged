<?php

namespace App\Models\Business;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a buyer needs.
 *
 * "5,000 pcs brass diyas, CIF Hamburg, target $2.10" — posted once, and
 * every supplier whose page or products carry a matching keyword is told.
 * They answer with quotes, which the buyer compares in one table.
 */
class Requirement extends Model
{
    use HasUuids;

    protected $table = 'business_requirements';

    public const STATUSES = ['open', 'closed', 'fulfilled'];

    /** BUY: I need. SELL: I offer. PARTNER: I am looking for a distributor, agent, JV… */
    public const KINDS = ['buy', 'sell', 'partner'];

    public const FREQUENCIES = ['one_time', 'monthly', 'quarterly', 'yearly'];

    public const PARTNER_TYPES = ['distributor', 'agent', 'dealer', 'joint_venture', 'sourcing_partner', 'freight_partner', 'representative', 'other'];

    protected $fillable = [
        'user_id', 'title', 'description', 'category', 'keywords', 'quantity', 'quantity_unit',
        'target_price', 'currency', 'terms', 'destination_country', 'destination_port', 'valid_until',
        'status', 'quotes_count', 'matched_count',
        'kind', 'page_id', 'hs_code', 'frequency', 'origin_countries', 'target_markets', 'payment_terms', 'partner_type',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array', 'terms' => 'array', 'origin_countries' => 'array', 'target_markets' => 'array',
            'quantity' => 'decimal:2', 'target_price' => 'decimal:2', 'valid_until' => 'date',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'requirement_id');
    }

    /** The company it was posted for, when the poster represents one. */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    /** Open, and not past its date. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'open')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', now()->toDateString()));
    }

    /** Every word a supplier could match on. */
    public function searchTerms(): array
    {
        $terms = array_merge($this->keywords ?? [], $this->category ? [$this->category] : []);

        return array_values(array_unique(array_filter(array_map(fn ($t) => mb_strtolower(trim((string) $t)), $terms), fn ($t) => mb_strlen($t) >= 3)));
    }

    /**
     * The companies this should reach, and why.
     *
     * A BUY goes to companies that SELL it: their sell lines or products
     * carry the HS heading, or a word matches. A SELL goes to companies
     * that BUY it. A PARTNER call goes to either side, in the markets it
     * names. Never the poster's own company. HS first: a shared 4-digit
     * heading is a stronger reason than a shared word.
     */
    public function matchingPages(int $limit = 60)
    {
        $terms = $this->searchTerms();
        $heading = $this->hs_code ? substr($this->hs_code, 0, 4) : null;
        if ($terms === [] && ! $heading) {
            return collect();
        }
        $direction = match ($this->kind ?? 'buy') { 'buy' => 'sell', 'sell' => 'buy', default => null };

        return Page::live()->with('owner')
            ->where('user_id', '!=', $this->user_id)
            ->when($this->page_id, fn ($q) => $q->where('business_pages.id', '!=', $this->page_id))
            ->where(function (Builder $outer) use ($terms, $heading, $direction) {
                if ($heading) {
                    $outer->orWhereHas('tradeLines', fn ($t) => $t->when($direction, fn ($q) => $q->where('direction', $direction))->where('hs_code', 'like', $heading . '%'));
                    if ($direction !== 'buy') {
                        $outer->orWhereHas('products', fn ($x) => $x->where('business_products.status', 'active')->where('hs_code', 'like', $heading . '%'));
                    }
                }
                foreach ($terms as $term) {
                    $like = '%' . $term . '%';
                    $outer->orWhereHas('tradeLines', fn ($t) => $t->when($direction, fn ($q) => $q->where('direction', $direction))->whereRaw('LOWER(description) LIKE ?', [$like]));
                    if ($direction !== 'buy') {
                        $outer->orWhereRaw('LOWER(business_pages.keywords) LIKE ?', [$like])
                            ->orWhereHas('products', fn ($x) => $x->where('business_products.status', 'active')->where(fn ($xx) => $xx
                                ->whereRaw('LOWER(business_products.keywords) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(business_products.category) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(business_products.name) LIKE ?', [$like])));
                    }
                }
            })
            ->when($this->kind === 'partner' && $this->target_markets, fn ($q) => $q->where(function (Builder $w) {
                foreach ($this->target_markets as $cc) {
                    $w->orWhere('business_pages.country', $cc)->orWhereRaw('LOWER(business_pages.markets) LIKE ?', ['%' . strtolower($cc) . '%']);
                }
            }))
            ->limit($limit)
            ->get();
    }
}
