<?php

namespace App\Services\GrapUp;

/**
 * One person the pipeline found, and what it knows about reaching them.
 *
 * Mutable on purpose: the verification walk hands the same object between its
 * stages, filling in the address and status as it learns them. A value object
 * would mean threading replacements through every branch of a walk whose whole
 * job is changing its mind.
 */
class Contact
{
    public function __construct(
        public string $firstName,
        public string $lastName = '',
        /** Null until verification assigns a real one. Never a guess. */
        public ?string $email = null,
        public string $status = 'locked',
        public ?string $headline = null,
        public ?string $linkedinUrl = null,
        /**
         * Whether their current role actually names this company, and the
         * name that found them belongs to the domain we are about to spend
         * credits at. Both have to hold.
         */
        public bool $corroborated = false,
        /**
         * Which provider confirmed the address — 'inboxx' or 'clearout'. Null
         * when there is no address, or when it is a catch-all pattern guess
         * nobody checked.
         */
        public ?string $verifiedBy = null,
        /** True when that confirmation was reused from an earlier check. */
        public bool $fromCache = false,
    ) {}

    public function name(): PersonName
    {
        return new PersonName($this->firstName, $this->lastName);
    }

    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'status' => $this->status,
            'headline' => $this->headline,
            'linkedin_url' => $this->linkedinUrl,
            'corroborated' => $this->corroborated,
            'verified_by' => $this->verifiedBy,
            'from_cache' => $this->fromCache,
        ];
    }
}
