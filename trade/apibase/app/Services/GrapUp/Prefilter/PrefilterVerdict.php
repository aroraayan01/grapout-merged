<?php

namespace App\Services\GrapUp\Prefilter;

/** What a free tier concluded, and how sure it is. */
final class PrefilterVerdict
{
    public function __construct(
        /** One of the five canonical statuses. */
        public readonly string $status,
        /**
         * 0..100 from the pattern tier. Null when the address was actually
         * proven — something proven does not carry a probability.
         */
        public readonly ?int $confidence,
        /** Which tier answered: local, microsoft, smtp, pattern. */
        public readonly string $tier,
    ) {}
}
