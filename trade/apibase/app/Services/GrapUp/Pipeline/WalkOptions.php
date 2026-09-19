<?php

namespace App\Services\GrapUp\Pipeline;

use App\Models\User;

/** What one sweep is allowed to spend, and on whose behalf. */
final class WalkOptions
{
    public function __construct(
        public readonly int $budget,
        public readonly string $context,
        public readonly ?User $user = null,
        /** The company name, so a ledger row means something later. */
        public readonly ?string $reference = null,
        /** Skip the domain-fact lookup when the caller already knows. */
        public readonly ?bool $knownCatchAll = null,
    ) {}
}
