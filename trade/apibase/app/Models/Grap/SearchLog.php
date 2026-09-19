<?php

namespace App\Models\Grap;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every search, kept.
 *
 * Two jobs: it is what the daily and monthly allowances count, and it is
 * what "your recent searches" reads. Storing the filters as JSON means a
 * search can be re-run exactly, which the old site could not do — it kept
 * only the text.
 */
class SearchLog extends Model
{
    protected $table = 'grap_searches';

    protected $fillable = ['user_id', 'kind', 'q', 'filters', 'signature', 'hits'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'hits' => 'integer'];
    }

    /**
     * What makes two requests the same search.
     *
     * Not the page: paging is reading further into an answer, not asking
     * again. Facet values are sorted so that ticking India then Turkey and
     * ticking Turkey then India are one search, which is what the person
     * doing it believes.
     *
     * @param  array<string, list<string>>  $filters
     */
    public static function signature(string $kind, ?string $q, array $filters): string
    {
        ksort($filters);
        foreach ($filters as &$values) {
            sort($values);
        }
        unset($values);

        return sha1(json_encode([$kind, mb_strtolower(trim((string) $q)), $filters]));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
