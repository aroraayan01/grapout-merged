<?php

namespace App\Services\GrapUp\Http;

/** One vendor answer, or the absence of one. */
final class VendorResponse
{
    public function __construct(
        public readonly bool $ok,
        /** 0 when no response was received at all — timeout, DNS, open circuit. */
        public readonly int $status,
        public readonly mixed $data,
        public readonly ?string $error,
        /** True when the circuit was open: no call was made and nothing was spent. */
        public readonly bool $skipped,
    ) {}
}
