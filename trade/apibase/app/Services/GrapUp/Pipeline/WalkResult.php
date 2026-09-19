<?php

namespace App\Services\GrapUp\Pipeline;

/** What one sweep found, and what it cost to find out. */
final class WalkResult
{
    public function __construct(
        public readonly ?string $email,
        /** The template that produced the hit, when there was one. */
        public readonly ?string $template,
        public readonly string $status,
        /** True when the domain accepts every address, learned or remembered. */
        public readonly bool $catchAll,
        /** Calls that actually reached the vendor. Cache hits are not spend. */
        public readonly int $creditsSpent,
        /** Candidates examined, paid or not. */
        public readonly int $checked,
        /**
         * True when the vendor stopped answering. Distinct from "nothing
         * verified": one means the domain is wrong, the other means we never
         * got to ask.
         */
        public readonly bool $vendorUnavailable,
        /** The provider that confirmed the hit, when there was one. */
        public readonly ?string $verifiedBy = null,
        /** True when that confirmation was reused rather than asked now. */
        public readonly bool $fromCache = false,
    ) {}
}
