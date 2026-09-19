<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The Harmonized System nomenclature, as loaded by the platform.
 *
 * Nothing is typed in by hand: the table is filled from a file with
 * `php artisan trade:import-hs`, and the app only ever looks codes up.
 * Empty table, free entry — the code field still validates its shape.
 */
class HsCode extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['code', 'description', 'level', 'parent'];

    /** A code prefix or a word from the description. */
    public function scopeLookup(Builder $query, string $q): Builder
    {
        $q = trim($q);
        if (preg_match('/^\d+$/', $q)) {
            return $query->where('code', 'like', $q . '%')->orderBy('code');
        }

        return $query->whereRaw('LOWER(description) LIKE ?', ['%' . mb_strtolower($q) . '%'])->orderBy('level')->orderBy('code');
    }

    /** The chapter (2 digits) and heading (4 digits) a code sits under. */
    public static function ancestors(string $code): array
    {
        $out = [];
        foreach ([2, 4, 6] as $n) {
            if (strlen($code) > $n) {
                $out[] = substr($code, 0, $n);
            }
        }

        return $out;
    }
}
