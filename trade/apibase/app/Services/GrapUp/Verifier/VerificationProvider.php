<?php

namespace App\Services\GrapUp\Verifier;

/**
 * The contract every verification provider implements.
 *
 * A provider's only job is to turn its own vocabulary into one of the five
 * canonical statuses. Nothing downstream knows or cares which vendor is
 * configured, which is what makes swapping one a single config value.
 */
interface VerificationProvider
{
    public function name(): string;

    /**
     * Verify one address. Never throws: a failure is reported as `unknown`,
     * because a failed check is not evidence that an address is bad.
     */
    public function verify(string $email): string;

    /** Whether the credential is present. Checked at boot, not per call. */
    public function isConfigured(): bool;

    /** @return array{state: string, consecutive_failures: int, retry_in_seconds: int|null} */
    public function circuit(): array;
}
