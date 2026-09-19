<?php

namespace App\Models\Grap;

use App\Models\User;
use App\Support\BooleanQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One company from GrapOut's research, met through up to two people.
 *
 * The research delivers 28 columns and nothing else: the company, where
 * it is, what it does, and how to reach the one or two people named at
 * it. A buyer and a supplier are the same 28 columns arriving in two
 * files, so `kind` is the whole of the difference between them.
 *
 * The contact columns are on the model but never leave it in the open —
 * see `forViewer()`. Search is free; the address and the phone number are
 * what the plan is actually for.
 */
class Lead extends Model
{
    use HasUuids;

    protected $table = 'grap_leads';

    public const KIND_BUYER = 'buyer';

    public const KIND_SUPPLIER = 'supplier';

    /**
     * Reserved, not in use. Grap Company is a later conversation about a
     * matching algorithm, not a third pile of the same rows.
     */
    public const KIND_COMPANY = 'company';

    public const KINDS = [self::KIND_BUYER, self::KIND_SUPPLIER];

    /**
     * The facets down the left of the results, in the order they appear.
     * Legacy had these five and no more; the key is the request parameter,
     * the value is the column it narrows.
     */
    public const FACETS = [
        'company' => 'company_name',
        'contact' => 'contact_person',
        'designation' => 'designation',
        'country' => 'country',
        'category' => 'business_category',
    ];

    /** What the free-text box looks in. */
    protected const SEARCHABLE = [
        'company_name', 'contact_person', 'business_category',
        'brief_intro', 'country', 'company_address', 'website', 'input_name',
    ];

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'email_catch_all' => 'boolean',
            'email_2_catch_all' => 'boolean',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function reveals(): HasMany
    {
        return $this->hasMany(Reveal::class, 'grap_lead_id');
    }

    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(LeadList::class, 'grap_list_lead', 'grap_lead_id', 'grap_list_id')
            ->withPivot('note')->withTimestamps();
    }

    // --- Reading -----------------------------------------------------------

    /**
     * Rows worth showing at all — anything with a way to reach it.
     *
     * The old site appended `email_id_direct_buyer_status not in
     * ('invalid','unknown')` to all six of its ORed LIKE clauses and got it
     * wrong: a row with no email has a null status and so failed the test,
     * hiding companies whose phone number was perfectly good. There is no
     * status column in the research any more, so the question is simply
     * whether there is an address or a number — either person's.
     */
    public function scopeReachable(Builder $q): Builder
    {
        return $q->where(function ($w) {
            $w->whereNotNull('email')
                ->orWhereNotNull('email_2')
                ->orWhereNotNull('phone')
                ->orWhereNotNull('mobile');
        });
    }

    /** The free-text box, with the grammar the rest of the app already uses. */
    public function scopeMatching(Builder $q, ?string $text): Builder
    {
        $text = trim((string) $text);
        if (mb_strlen($text) < 2) {
            return $q;
        }

        $groups = BooleanQuery::parse($text);
        $driver = $q->getModel()->getConnection()->getDriverName();

        /*
         * The fast path: the full-text index. `LIKE '%term%'` has a leading
         * wildcard no B-tree can start from, so at a quarter of a million rows
         * every search scanned the whole table. MATCH ... AGAINST answers the
         * same question from the index built for it — but only when the engine
         * has one and the words are long enough for it to have indexed them.
         */
        if (in_array($driver, ['mysql', 'mariadb'], true) && self::fulltextCanServe($groups)) {
            return $q->where(function (Builder $outer) use ($groups) {
                foreach ($groups as $group) {
                    $against = self::booleanMode($group);
                    if ($against !== '') {
                        $outer->orWhereRaw(
                            'MATCH(company_name, contact_person, business_category, brief_intro, country) AGAINST (? IN BOOLEAN MODE)',
                            [$against]
                        );
                    }
                }
            });
        }

        /*
         * The fallback: contains-anywhere across every searchable column. What
         * a two-letter query, a pure-negation query, or a driver without full
         * text needs.
         *
         * COALESCE is not for tidiness. `NULL LIKE '%steel%'` is NULL, not
         * false. A positive term survives that, but a negative one does not:
         * `NOT (false OR NULL)` is NULL, so "lamp NOT steel" would quietly drop
         * every row with nothing in one of these columns, which is most of
         * them. Coalescing to '' makes the negation mean what it says.
         */
        BooleanQuery::apply($q, $groups, function (Builder $w, string $term) {
            $like = '%' . mb_strtolower($term) . '%';
            foreach (self::SEARCHABLE as $i => $column) {
                $expr = "LOWER(COALESCE({$column}, '')) LIKE ?";
                $i === 0 ? $w->whereRaw($expr, [$like]) : $w->orWhereRaw($expr, [$like]);
            }
        });

        return $q;
    }

    /**
     * Full text can serve a query only when every alternative has something
     * positive to match and every must-word is at least the engine's minimum
     * token — three characters. A shorter word is invisible to the index, and
     * a boolean search whose only positive term is invisible matches nothing;
     * a pure-negation alternative ("NOT steel") has no base set to subtract
     * from. Both fall back to LIKE.
     *
     * @param  list<array{must: list<string>, not: list<string>}>  $groups
     */
    private static function fulltextCanServe(array $groups): bool
    {
        foreach ($groups as $group) {
            if ($group['must'] === []) {
                return false;
            }
            foreach ($group['must'] as $term) {
                foreach (preg_split('/\s+/u', trim($term)) ?: [] as $word) {
                    if ($word !== '' && mb_strlen($word) < 3) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * One boolean-mode string for one alternative: every must-term required,
     * every not-term forbidden. A single word gets a trailing `*` so "steel"
     * still finds "steelworks"; a quoted phrase is matched whole. Characters
     * that mean something to boolean mode are stripped from inside a term, so
     * a stray `+` or `(` in the query cannot break the parse.
     *
     * @param  array{must: list<string>, not: list<string>}  $group
     */
    private static function booleanMode(array $group): string
    {
        $piece = function (string $term, string $sign): string {
            $phrase = str_contains(trim($term), ' ');
            $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[+\-<>~*()"@]+/u', ' ', $term)));
            if ($clean === '') {
                return '';
            }

            return $phrase ? "{$sign}\"{$clean}\"" : "{$sign}{$clean}*";
        };

        $parts = [];
        foreach ($group['must'] as $t) {
            if (($p = $piece($t, '+')) !== '') {
                $parts[] = $p;
            }
        }
        foreach ($group['not'] as $t) {
            if (($p = $piece($t, '-')) !== '') {
                $parts[] = $p;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * The left-panel facets, applied together.
     *
     * @param  array<string, list<string>>  $chosen  facet key => values
     */
    public function scopeFaceted(Builder $q, array $chosen): Builder
    {
        foreach (self::FACETS as $key => $column) {
            $values = array_values(array_filter((array) ($chosen[$key] ?? [])));
            if ($values !== []) {
                // Several values within one facet widen it; separate facets
                // narrow each other. That is what a person means by ticking
                // two countries and one designation.
                $q->whereIn($column, $values);
            }
        }

        return $q;
    }

    /**
     * How the old site ordered results: a number you can ring beats an
     * address, and an address beats neither. A row naming a person beats
     * one addressed to the switchboard.
     */
    public function scopeBestFirst(Builder $q): Builder
    {
        // sort_rank packs those three tiers into one indexed number (see the
        // 2026_10_10 migration): reach*4 + has-email*2 + has-name, 0 best.
        // Ordering by it alone reproduces the old three-key sort, but as an
        // index range read instead of a filesort over every row of the kind.
        return $q->orderBy('sort_rank')->orderByDesc('id');
    }

    // --- Showing -----------------------------------------------------------

    /**
     * The row as this person may see it.
     *
     * Everything about the company is public; the contact details are the
     * product. Unrevealed, they come back masked but *present*, because
     * "j***@acme.com" tells a buyer the address exists and is worth
     * spending on, and a bare "hidden" does not.
     */
    public function forViewer(?User $viewer, bool $revealed = false): array
    {
        $base = [
            'uuid' => $this->uuid,
            'kind' => $this->kind,
            'company_name' => $this->company_name,
            'company_ref' => $this->company_ref,
            'company_address' => $this->company_address,
            'country' => $this->country,
            'website' => $this->website,
            'linkedin_url' => $this->linkedin_url,
            'brief_intro' => $this->brief_intro,
            'business_category' => $this->business_category,
            'input_name' => $this->input_name,
            'contact_person' => $this->contact_person,
            'designation' => $this->designation,
            'contact_person_2' => $this->contact_person_2,
            'designation_2' => $this->designation_2,
            'data_source' => $this->data_source,
            'source_updated_at' => $this->source_updated_at?->toDateString(),
            'revealed' => $revealed,
            'has_email' => filled($this->email) || filled($this->email_2),
            'has_phone' => filled($this->phone) || filled($this->mobile)
                || filled($this->phone_2) || filled($this->mobile_2),
        ];

        $contacts = [
            'email' => $this->email,
            'email_catch_all' => $this->email_catch_all,
            'phone' => $this->joinDial($this->dial_code, $this->phone),
            'mobile' => $this->joinDial($this->dial_code, $this->mobile),
            'email_2' => $this->email_2,
            'email_2_catch_all' => $this->email_2_catch_all,
            'phone_2' => $this->joinDial($this->dial_code_2, $this->phone_2),
            'mobile_2' => $this->joinDial($this->dial_code_2, $this->mobile_2),
        ];

        if (! $revealed) {
            foreach (['email', 'email_2'] as $k) {
                $contacts[$k] = self::maskEmail($contacts[$k]);
            }
            foreach (['phone', 'mobile', 'phone_2', 'mobile_2'] as $k) {
                $contacts[$k] = self::maskPhone($contacts[$k]);
            }
        }

        return $base + $contacts;
    }

    protected function joinDial(?string $code, ?string $number): ?string
    {
        $number = trim((string) $number);
        if ($number === '') {
            return null;
        }
        $code = trim((string) $code);

        return $code !== '' && ! str_starts_with($number, '+') && ! str_starts_with($number, $code)
            ? "+{$code} {$number}"
            : $number;
    }

    /** j***@acme.com — the domain survives, because the domain is the clue. */
    public static function maskEmail(?string $email): ?string
    {
        $email = trim((string) $email);
        if ($email === '' || ! str_contains($email, '@')) {
            return $email ?: null;
        }
        [$user, $domain] = explode('@', $email, 2);

        return mb_substr($user, 0, 1) . str_repeat('*', max(3, mb_strlen($user) - 1)) . '@' . $domain;
    }

    /** +91 98****3210 — enough to see it is a mobile, not enough to dial. */
    public static function maskPhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }
        $keep = mb_strlen($phone) > 8 ? 4 : 2;

        return mb_substr($phone, 0, $keep) . str_repeat('*', max(4, mb_strlen($phone) - $keep - 2)) . mb_substr($phone, -2);
    }
}
