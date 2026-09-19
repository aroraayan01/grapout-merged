<?php

namespace App\Models\Business;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A product or service on a business page.
 *
 * Pictures, a name, a line, what it costs and on what terms, how many you
 * have to take. The spec sheet is label/value pairs, because a spice
 * exporter and a machine-tool maker do not share forty fields.
 */
class Product extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'business_products';

    public const PRICE_TYPES = ['fixed', 'range', 'on_request'];

    public const KINDS = ['product', 'service'];

    public const TERMS = ['EXW', 'FCA', 'FOB', 'CFR', 'CIF', 'DAP', 'DDP'];

    public const MAX_IMAGES = 8;

    protected $fillable = [
        'page_id', 'slug', 'kind', 'name', 'summary', 'description', 'specifications', 'moq', 'moq_unit',
        'price_type', 'price_min', 'price_max', 'currency', 'price_unit', 'terms', 'category', 'keywords',
        'status', 'sort', 'interested_count', 'enquiry_count',
        'hs_code', 'capacity', 'certifications', 'export_markets',
    ];

    protected function casts(): array
    {
        return [
            'specifications' => 'array',
            'terms' => 'array',
            'keywords' => 'array',
            'certifications' => 'array',
            'export_markets' => 'array',
            'moq' => 'decimal:2',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_id')->orderBy('sort')->orderBy('id');
    }

    public function interestedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_interests', 'product_id', 'user_id')->withTimestamps();
    }

    public function enquiries(): HasMany
    {
        return $this->hasMany(Enquiry::class, 'product_id');
    }

    /** What anybody but the owner may see: shown, on a live page. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('business_products.status', 'active')
            ->whereHas('page', fn ($p) => $p->live());
    }

    public function cover(): ?ProductImage
    {
        return $this->relationLoaded('images') ? $this->images->first() : $this->images()->first();
    }

    public static function slugFor(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'item', 140, '');

        do {
            $slug = $base . '-' . Str::lower(Str::random(5));
        } while (self::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
