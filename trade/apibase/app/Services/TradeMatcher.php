<?php

namespace App\Services;

use App\Models\Business\CompanyMember;
use App\Models\Business\Page;
use App\Models\Business\Requirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Why two companies should talk.
 *
 * Not a percentage — a percentage promises a precision nobody has. A list
 * of reasons, strongest first: a shared HS heading, an open opportunity,
 * a word in common, a market that fits, a country that fits. And the
 * person to talk to: the one whose function faces the other side —
 * procurement answers a SELL, sales answers a BUY, management answers a
 * PARTNER call.
 */
class TradeMatcher
{
    /** Companies that fit an opportunity, each with its reasons and its person. */
    public function companiesForIntent(Requirement $r, int $limit = 40): Collection
    {
        $r->loadMissing('page');

        return $r->matchingPages($limit)
            ->map(fn (Page $p) => ['page' => $p, 'reasons' => $this->reasonsForIntent($r, $p), 'person' => $this->rightPerson($p, $this->facing($r->kind ?? 'buy'))])
            ->filter(fn ($m) => $m['reasons'] !== [])
            ->sortByDesc(fn ($m) => $this->score($m['reasons']))
            ->values();
    }

    /** Open opportunities a company should answer, with reasons. */
    public function intentsForPage(Page $page, int $limit = 40): Collection
    {
        $page->loadMissing('tradeLines');
        $sells = $page->tradeLines->where('direction', 'sell');
        $buys = $page->tradeLines->where('direction', 'buy');
        $sellHeadings = $sells->pluck('hs_code')->filter()->map(fn ($c) => substr($c, 0, 4))->unique()->values();
        $buyHeadings = $buys->pluck('hs_code')->filter()->map(fn ($c) => substr($c, 0, 4))->unique()->values();
        $productHeadings = $page->products()->where('status', 'active')->whereNotNull('hs_code')->pluck('hs_code')->map(fn ($c) => substr($c, 0, 4))->unique()->values();
        $words = $this->words($page);

        $query = Requirement::live()->with(['buyer.profile', 'page'])
            ->where('user_id', '!=', $page->user_id)
            ->where(fn ($q) => $q->whereNull('page_id')->orWhere('page_id', '!=', $page->id))
            ->where(function (Builder $outer) use ($sellHeadings, $buyHeadings, $productHeadings, $words, $page) {
                $outer->whereRaw('1 = 0');
                foreach ($sellHeadings->merge($productHeadings)->unique() as $h) {
                    $outer->orWhere(fn ($q) => $q->where('kind', 'buy')->where('hs_code', 'like', $h . '%'));
                    $outer->orWhere(fn ($q) => $q->where('kind', 'partner')->where('hs_code', 'like', $h . '%'));
                }
                foreach ($buyHeadings as $h) {
                    $outer->orWhere(fn ($q) => $q->where('kind', 'sell')->where('hs_code', 'like', $h . '%'));
                }
                foreach ($words as $w) {
                    $like = '%' . $w . '%';
                    $outer->orWhere(fn ($q) => $q->whereRaw('LOWER(keywords) LIKE ?', [$like])->orWhereRaw('LOWER(title) LIKE ?', [$like]));
                }
                if ($page->country) {
                    $outer->orWhere(fn ($q) => $q->where('kind', 'partner')->whereRaw('LOWER(target_markets) LIKE ?', ['%' . strtolower($page->country) . '%']));
                }
            })
            ->orderByDesc('created_at')
            ->limit($limit * 2)
            ->get();

        return $query
            ->map(fn (Requirement $r) => ['intent' => $r, 'reasons' => $this->reasonsForPage($r, $page), 'person' => $r->page ? $this->rightPerson($r->page, $this->facing($r->kind, fromPoster: true)) : null])
            ->filter(fn ($m) => $m['reasons'] !== [])
            ->sortByDesc(fn ($m) => $this->score($m['reasons']))
            ->take($limit)
            ->values();
    }

    /**
     * Companies on the other side of what a page trades: buyers of what it
     * sells, suppliers of what it buys — with reasons and the person.
     */
    public function companiesForPage(Page $page, int $limit = 30): array
    {
        $page->loadMissing('tradeLines');
        $sellHeadings = $page->tradeLines->where('direction', 'sell')->pluck('hs_code')->filter()->map(fn ($c) => substr($c, 0, 4))->unique()->values();
        $buyHeadings = $page->tradeLines->where('direction', 'buy')->pluck('hs_code')->filter()->map(fn ($c) => substr($c, 0, 4))->unique()->values();

        $find = function (Collection $headings, string $theirDirection, string $function) use ($page, $limit) {
            if ($headings->isEmpty()) {
                return collect();
            }

            return Page::live()->with(['owner', 'tradeLines'])
                ->where('business_pages.id', '!=', $page->id)
                ->whereHas('tradeLines', fn ($t) => $t->where('direction', $theirDirection)->where(function ($q) use ($headings) {
                    foreach ($headings as $h) {
                        $q->orWhere('hs_code', 'like', $h . '%');
                    }
                }))
                ->limit($limit)->get()
                ->map(fn (Page $other) => ['page' => $other, 'reasons' => $this->reasonsBetween($page, $other, $theirDirection), 'person' => $this->rightPerson($other, $function)])
                ->sortByDesc(fn ($m) => $this->score($m['reasons']))
                ->values();
        };

        return [
            'buyers' => $find($sellHeadings, 'buy', 'procurement'),
            'suppliers' => $find($buyHeadings, 'sell', 'sales'),
        ];
    }

    // --- Reasons ------------------------------------------------------------------------------------

    /** Why $page fits opportunity $r. */
    public function reasonsForIntent(Requirement $r, Page $page): array
    {
        $page->loadMissing('tradeLines');
        $reasons = [];
        $direction = match ($r->kind ?? 'buy') { 'buy' => 'sell', 'sell' => 'buy', default => null };
        $heading = $r->hs_code ? substr($r->hs_code, 0, 4) : null;

        if ($heading) {
            $line = $page->tradeLines->first(fn ($l) => (! $direction || $l->direction === $direction) && $l->hs_code && str_starts_with($l->hs_code, $heading));
            if ($line) {
                $reasons[] = ['kind' => 'hs', 'weight' => 100, 'text' => ($line->direction === 'sell' ? 'Sells' : 'Buys') . " HS {$line->hs_code} — {$line->description}"];
            }
            if ($direction !== 'buy') {
                $product = $page->products()->where('status', 'active')->where('hs_code', 'like', $heading . '%')->first();
                if ($product) {
                    $reasons[] = ['kind' => 'product', 'weight' => 90, 'text' => "Lists {$product->name} (HS {$product->hs_code})"];
                }
            }
        }
        foreach ($r->searchTerms() as $term) {
            $line = $page->tradeLines->first(fn ($l) => (! $direction || $l->direction === $direction) && str_contains(mb_strtolower($l->description), $term));
            if ($line) {
                $reasons[] = ['kind' => 'keyword', 'weight' => 60, 'text' => "“{$term}” in what they " . ($line->direction === 'sell' ? 'sell' : 'buy')];
                break;
            }
            if ($direction !== 'buy' && collect($page->keywords ?? [])->contains(fn ($k) => str_contains(mb_strtolower($k), $term))) {
                $reasons[] = ['kind' => 'keyword', 'weight' => 50, 'text' => "“{$term}” among their keywords"];
                break;
            }
        }
        if ($r->origin_countries && $page->country && in_array($page->country, $r->origin_countries, true)) {
            $reasons[] = ['kind' => 'origin', 'weight' => 40, 'text' => "Based in {$page->country}, a preferred origin"];
        }
        $markets = collect($page->markets ?? []);
        $wanted = collect($r->target_markets ?? [])->merge($r->destination_country ? [$r->destination_country] : []);
        if ($wanted->isNotEmpty() && $markets->intersect($wanted)->isNotEmpty()) {
            $reasons[] = ['kind' => 'market', 'weight' => 40, 'text' => 'Already trades with ' . $markets->intersect($wanted)->implode(', ')];
        }
        if ($r->kind === 'partner' && $page->country && $wanted->contains($page->country)) {
            $reasons[] = ['kind' => 'market', 'weight' => 45, 'text' => "In {$page->country}, where you want a partner"];
        }
        if ($page->verification_status === 'verified') {
            $reasons[] = ['kind' => 'trust', 'weight' => 20, 'text' => 'Verified company'];
        }

        return $reasons;
    }

    /** Why opportunity $r is for $page. */
    public function reasonsForPage(Requirement $r, Page $page): array
    {
        $page->loadMissing('tradeLines');
        $reasons = [];
        $heading = $r->hs_code ? substr($r->hs_code, 0, 4) : null;
        $myDirection = match ($r->kind ?? 'buy') { 'buy' => 'sell', 'sell' => 'buy', default => null };
        $what = match ($r->kind ?? 'buy') { 'buy' => 'They need what you sell', 'sell' => 'They offer what you buy', default => 'They want a partner in your trade' };

        if ($heading) {
            $line = $page->tradeLines->first(fn ($l) => (! $myDirection || $l->direction === $myDirection) && $l->hs_code && str_starts_with($l->hs_code, $heading));
            if ($line) {
                $reasons[] = ['kind' => 'hs', 'weight' => 100, 'text' => "{$what}: HS {$line->hs_code} — {$line->description}"];
            } elseif ($myDirection !== 'buy') {
                $product = $page->products()->where('status', 'active')->where('hs_code', 'like', $heading . '%')->first();
                if ($product) {
                    $reasons[] = ['kind' => 'product', 'weight' => 90, 'text' => "{$what}: your {$product->name} (HS {$product->hs_code})"];
                }
            }
        }
        foreach ($r->searchTerms() as $term) {
            $line = $page->tradeLines->first(fn ($l) => (! $myDirection || $l->direction === $myDirection) && str_contains(mb_strtolower($l->description), $term));
            if ($line || collect($page->keywords ?? [])->contains(fn ($k) => str_contains(mb_strtolower($k), $term))) {
                $reasons[] = ['kind' => 'keyword', 'weight' => 60, 'text' => "“{$term}” matches what you " . ($line?->direction === 'buy' ? 'buy' : 'sell')];
                break;
            }
        }
        if ($r->kind === 'partner' && $page->country && in_array($page->country, $r->target_markets ?? [], true)) {
            $reasons[] = ['kind' => 'market', 'weight' => 45, 'text' => "They want a partner in {$page->country}"];
        }
        if ($r->origin_countries && $page->country && in_array($page->country, $r->origin_countries, true)) {
            $reasons[] = ['kind' => 'origin', 'weight' => 40, 'text' => "They prefer to source from {$page->country}"];
        }

        return $reasons;
    }

    /** Why $me and $other fit, given $other's lines in $theirDirection. */
    private function reasonsBetween(Page $me, Page $other, string $theirDirection): array
    {
        $myDirection = $theirDirection === 'buy' ? 'sell' : 'buy';
        $reasons = [];
        foreach ($me->tradeLines->where('direction', $myDirection) as $mine) {
            if (! $mine->hs_code) {
                continue;
            }
            $h = substr($mine->hs_code, 0, 4);
            $theirs = $other->tradeLines->first(fn ($l) => $l->direction === $theirDirection && $l->hs_code && str_starts_with($l->hs_code, $h));
            if ($theirs) {
                $reasons[] = ['kind' => 'hs', 'weight' => 100, 'text' => ($theirDirection === 'buy' ? "They buy HS {$theirs->hs_code} — {$theirs->description}; you sell {$mine->description}" : "They sell HS {$theirs->hs_code} — {$theirs->description}; you buy {$mine->description}")];
            }
        }
        $open = Requirement::live()->where('page_id', $other->id)->where('kind', $theirDirection)->first();
        if ($open) {
            $reasons[] = ['kind' => 'intent', 'weight' => 80, 'text' => 'Open ' . ($theirDirection === 'buy' ? 'buy requirement' : 'sell offer') . ": {$open->title}"];
        }
        if ($me->country && collect($other->markets ?? [])->contains($me->country)) {
            $reasons[] = ['kind' => 'market', 'weight' => 40, 'text' => "Already trades with {$me->country}"];
        }
        if ($other->verification_status === 'verified') {
            $reasons[] = ['kind' => 'trust', 'weight' => 20, 'text' => 'Verified company'];
        }

        return array_values(array_unique($reasons, SORT_REGULAR));
    }

    // --- The person ------------------------------------------------------------------------------

    /** Which function on the other side faces a kind of opportunity. */
    private function facing(string $kind, bool $fromPoster = false): string
    {
        // Answering a BUY: their sales talks to me. Answering a SELL: their procurement.
        // Looking at the poster of a BUY: their procurement posted it; of a SELL: their sales.
        return match ($kind) {
            'buy' => $fromPoster ? 'procurement' : 'sales',
            'sell' => $fromPoster ? 'sales' : 'procurement',
            default => 'management',
        };
    }

    /** The team member whose function fits, else an owner or admin, else anybody active. */
    public function rightPerson(Page $page, string $function): ?CompanyMember
    {
        $team = $page->team()->with('user.profile')->get()->filter(fn ($m) => $m->user);
        if ($team->isEmpty()) {
            return null;
        }

        return $team->first(fn ($m) => $m->function === $function)
            ?? $team->first(fn ($m) => $m->user?->profile?->role_function === $function)
            ?? $team->first(fn ($m) => $m->role === 'owner')
            ?? $team->first();
    }

    private function score(array $reasons): int
    {
        return array_sum(array_column($reasons, 'weight'));
    }

    /** The words a company trades by, for the loose match. */
    private function words(Page $page): array
    {
        $lines = $page->tradeLines->pluck('description')->flatMap(fn ($d) => preg_split('/[\s,\/]+/', mb_strtolower($d)));
        $keys = collect($page->keywords ?? [])->map(fn ($k) => mb_strtolower($k));

        return $lines->merge($keys)->filter(fn ($w) => mb_strlen($w) >= 5)->unique()->take(12)->values()->all();
    }

    /** A serialized match, for the API. */
    public function serializeCompany(array $m, ?User $viewer, callable $pageCard, callable $personCard): array
    {
        return [
            'page' => $pageCard($m['page'], $viewer),
            'reasons' => $m['reasons'],
            'person' => $m['person'] && $m['person']->user ? [
                'member_id' => $m['person']->id,
                'function' => $m['person']->function,
                'function_label' => $m['person']->function ? (CompanyMember::FUNCTION_LABELS[$m['person']->function] ?? ucfirst($m['person']->function)) : null,
                'title' => $m['person']->title,
                'user' => $personCard($m['person']->user, $viewer, false),
            ] : null,
        ];
    }
}
