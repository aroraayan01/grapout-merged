<?php

namespace App\Support;

/**
 * The little search grammar people already know from job boards.
 *
 *   import handicraft       → both words, anywhere
 *   "brass lamp"            → that phrase
 *   importer NOT supplier   → the first, and not the second (-supplier too)
 *   rice OR wheat           → either
 *
 * Parsed into alternatives, each a list of terms that must all match and a
 * list that must not. Whatever is being searched — people, pages, products
 * — applies the same shape against its own columns.
 */
class BooleanQuery
{
    /**
     * @return list<array{must: list<string>, not: list<string>}>
     */
    public static function parse(string $q): array
    {
        preg_match_all('/"([^"]+)"|(\S+)/u', $q, $m, PREG_SET_ORDER);
        $tokens = array_map(fn ($t) => $t[1] !== '' ? ['phrase', $t[1]] : ['word', $t[2]], $m);

        $groups = [['must' => [], 'not' => []]];
        $negateNext = false;
        foreach ($tokens as [$kind, $text]) {
            if ($kind === 'word' && strtoupper($text) === 'OR') {
                $groups[] = ['must' => [], 'not' => []];
                continue;
            }
            if ($kind === 'word' && strtoupper($text) === 'AND') {
                continue;
            }
            if ($kind === 'word' && strtoupper($text) === 'NOT') {
                $negateNext = true;
                continue;
            }
            $negative = $negateNext || ($kind === 'word' && str_starts_with($text, '-') && mb_strlen($text) > 1);
            $negateNext = false;
            $clean = $kind === 'word' ? ltrim($text, '-') : $text;
            if ($clean === '') {
                continue;
            }
            $groups[count($groups) - 1][$negative ? 'not' : 'must'][] = $clean;
        }

        return array_values(array_filter($groups, fn ($g) => $g['must'] !== [] || $g['not'] !== []))
            ?: [['must' => [$q], 'not' => []]];
    }

    /**
     * Apply the parsed alternatives to a query, given a callback that
     * constrains one builder to one term.
     *
     * @param  list<array{must: list<string>, not: list<string>}>  $alternatives
     * @param  callable(\Illuminate\Database\Eloquent\Builder, string): void  $matches
     */
    public static function apply(\Illuminate\Database\Eloquent\Builder $query, array $alternatives, callable $matches): void
    {
        $query->where(function ($outer) use ($alternatives, $matches) {
            foreach ($alternatives as $group) {
                $outer->orWhere(function ($alt) use ($group, $matches) {
                    foreach ($group['must'] as $term) {
                        $alt->where(fn ($w) => $matches($w, $term));
                    }
                    foreach ($group['not'] as $term) {
                        $alt->whereNot(fn ($w) => $matches($w, $term));
                    }
                });
            }
        });
    }
}
