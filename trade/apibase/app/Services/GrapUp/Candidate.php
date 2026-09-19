<?php

namespace App\Services\GrapUp;

/** A candidate address, and the template that produced it. */
final class Candidate
{
    public function __construct(
        public readonly string $email,
        public readonly string $template,
        /** True when this was built from a formal name rather than the one given. */
        public readonly bool $fromFormalName,
        /**
         * The template's position in `EmailPatterns::TEMPLATES`.
         *
         * Carried on the candidate rather than recomputed, because the walk
         * splits the list on it: the common head is tried against every
         * contact before any contact is taken into the rare tail. A candidate
         * built from a formal-name alternate keeps the rank of the template
         * that produced it, so "charles.waters@" is treated as the common
         * pattern it is.
         */
        public readonly int $rank,
    ) {}
}
