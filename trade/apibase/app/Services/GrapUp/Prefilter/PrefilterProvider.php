<?php

namespace App\Services\GrapUp\Prefilter;

/**
 * A free tier that runs before the paid one.
 *
 * Deliberately not the same contract as a VerificationProvider. Clearout
 * answers every question for a credit; a prefilter answers some questions for
 * nothing and is explicit about the rest. Those are different jobs, so a
 * verification provider returns a verdict and a prefilter returns a verdict
 * *or admits it could not settle one*.
 *
 * `check()` returning null is the whole point of the distinction: `unknown` is
 * a finding, which would be cached and would stop the address ever reaching
 * the paid tier. Null means the prefilter said nothing.
 */
interface PrefilterProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /** A verdict, or null when the service could not be reached at all. */
    public function check(string $email): ?PrefilterVerdict;
}
