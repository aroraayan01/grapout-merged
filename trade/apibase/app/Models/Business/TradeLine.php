<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a company buys or sells, keyed by its HS code.
 *
 * "WE BUY — 8302 Door handles and hinges". The code is what matching
 * runs on; the description is what a person reads.
 */
class TradeLine extends Model
{
    protected $table = 'company_trade_lines';

    protected $fillable = ['page_id', 'direction', 'hs_code', 'description', 'sort'];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    /** Digits only, 2 to 10 of them, or null. */
    public static function normalizeCode(?string $code): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $code);

        return $digits === '' ? null : substr($digits, 0, 10);
    }
}
