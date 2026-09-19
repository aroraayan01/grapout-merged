<?php

namespace App\Services\GrapUp\Verifier;

/** What one verification returned, and whether it cost anything. */
final class VerifyOutcome
{
    public function __construct(
        public readonly string $status,
        /** False when the answer came from the cache and cost nothing. */
        public readonly bool $paid,
        /** Which tier answered: 'memo', 'cache', 'prefilter' or 'vendor'. */
        public readonly string $source,
        /**
         * The provider whose verdict this is — 'inboxx', 'clearout' — even
         * when it was read back from the cache or the memo rather than asked
         * just now. Null only for a cache row written before providers were
         * recorded.
         */
        public readonly ?string $by = null,
        /** True when the verdict was reused from an earlier check, not asked now. */
        public readonly bool $cached = false,
    ) {}

    public function isAcceptable(): bool
    {
        return Status::isAcceptable($this->status);
    }
}
