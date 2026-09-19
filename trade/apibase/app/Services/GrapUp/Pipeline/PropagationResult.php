<?php

namespace App\Services\GrapUp\Pipeline;

use App\Services\GrapUp\Contact;

/** A whole team's worth of answers. */
final class PropagationResult
{
    /** @param list<Contact> $contacts */
    public function __construct(
        public readonly array $contacts,
        /** "{f}.{l}@acme.com", or null when nothing was learned. */
        public readonly ?string $corporateFormat,
        public readonly int $creditsSpent,
        public readonly bool $vendorUnavailable,
        /**
         * Calls actually made to each provider during this pass, e.g.
         * ['inboxx' => 7, 'clearout' => 2]. Cache and memo hits are not calls.
         *
         * @var array<string, int>
         */
        public readonly array $calls = [],
    ) {}

    /** @param array<string, int> $calls */
    public function withCalls(array $calls): self
    {
        return new self($this->contacts, $this->corporateFormat, $this->creditsSpent, $this->vendorUnavailable, $calls);
    }

    /**
     * True when the pass produced no usable address at all.
     *
     * Keyed on status rather than on the email field. Testing the email is
     * what broke GrapUp's second-domain retry: every contact after the primary
     * still held its placeholder address, so a pass that had verified nothing
     * still looked like it had produced something.
     */
    public function noneVerified(): bool
    {
        foreach ($this->contacts as $contact) {
            if ($contact->email !== null && in_array($contact->status, ['deliverable', 'accept_all', 'catch_all', 'predicted'], true)) {
                return false;
            }
        }

        return true;
    }
}
