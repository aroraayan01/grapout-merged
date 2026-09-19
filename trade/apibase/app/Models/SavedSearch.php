<?php

namespace App\Models;

use App\Models\Business\Product;
use App\Models\Business\Requirement;
use App\Support\BooleanQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing question.
 *
 * "brass lamp, from India" saved once; every day the app looks at what
 * arrived since it last looked, and if anything matches, says so — in
 * the bell, and by email when the address is verified.
 */
class SavedSearch extends Model
{
    use HasUuids;

    public const KINDS = ['products', 'requirements'];

    protected $fillable = ['user_id', 'page_id', 'kind', 'q', 'country', 'category', 'active', 'last_run_at', 'last_hits'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_run_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** What this search reads as, for a person. */
    public function label(): string
    {
        return implode(' · ', array_filter([$this->q ? "“{$this->q}”" : null, $this->category, $this->country]))
            ?: ($this->kind === 'products' ? 'All new products' : 'All new requirements');
    }

    /** The in-app link that shows the same thing. */
    public function path(): string
    {
        $qs = http_build_query(array_filter(['q' => $this->q, 'country' => $this->country, 'category' => $this->category]));

        return ($this->kind === 'products' ? '/search' : '/requirements') . ($qs ? "?{$qs}" : '');
    }

    /** New matches since the search last ran. */
    public function newMatches(): Builder
    {
        $since = $this->last_run_at ?? $this->created_at ?? now()->subDay();
        $terms = $this->q && mb_strlen($this->q) >= 2 ? BooleanQuery::parse($this->q) : [];

        if ($this->kind === 'requirements') {
            return Requirement::live()->where('created_at', '>', $since)
                ->when($terms, fn ($b) => BooleanQuery::apply($b, $terms, function (Builder $w, string $t) {
                    $like = '%' . mb_strtolower($t) . '%';
                    $w->whereRaw('LOWER(title) LIKE ?', [$like])->orWhereRaw('LOWER(description) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(keywords) LIKE ?', [$like])->orWhereRaw('LOWER(category) LIKE ?', [$like]);
                }))
                ->when($this->country, fn ($b, $cc) => $b->where('destination_country', $cc))
                ->when($this->category, fn ($b, $c) => $b->whereRaw('LOWER(category) = ?', [mb_strtolower($c)]))
                ->where('user_id', '!=', $this->user_id);
        }

        return Product::live()->where('business_products.created_at', '>', $since)
            ->when($terms, fn ($b) => BooleanQuery::apply($b, $terms, function (Builder $w, string $t) {
                $like = '%' . mb_strtolower($t) . '%';
                $w->whereRaw('LOWER(business_products.name) LIKE ?', [$like])->orWhereRaw('LOWER(business_products.summary) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(business_products.keywords) LIKE ?', [$like])->orWhereRaw('LOWER(business_products.category) LIKE ?', [$like]);
            }))
            ->when($this->country, fn ($b, $cc) => $b->whereHas('page', fn ($p) => $p->where('country', $cc)))
            ->when($this->category, fn ($b, $c) => $b->whereRaw('LOWER(business_products.category) = ?', [mb_strtolower($c)]))
            ->whereHas('page', fn ($p) => $p->where('user_id', '!=', $this->user_id));
    }
}
