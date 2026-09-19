<?php

namespace Tests\Unit;

use App\Services\GrapUp\EmailPatterns;
use App\Services\GrapUp\Lists;
use App\Services\GrapUp\PersonName;
use App\Services\GrapUp\Roles;
use PHPUnit\Framework\TestCase;

/**
 * The part of GrapUp that decides where the money goes.
 *
 * Verification is the only step that costs anything, the budget cuts the
 * candidate list off partway, and everything asserted here happens before a
 * single credit is spent. Every case is one GrapUp learned on a real company
 * run — a wrong surname or a missed nickname does not rank the candidates
 * badly, it keeps the address that exists off the list entirely and spends
 * the whole budget proving addresses nobody has do not exist.
 */
class GrapUpDomainTest extends TestCase
{
    private const ACME = 'acme.com';

    /** @return list<string> */
    private function permutations(PersonName $name, string $domain = self::ACME): array
    {
        return array_map(
            fn ($candidate) => $candidate->email,
            EmailPatterns::candidates($name, $domain),
        );
    }

    // --- Surnames ----------------------------------------------------------

    public function test_a_middle_name_is_dropped_rather_than_glued_onto_the_surname(): void
    {
        /*
         * The regression the whole name module exists for. "Kumar Sharma" as
         * the surname put rajesh.kumarsharma@ at the head of the walk and kept
         * rajesh.sharma@ — the address that exists — off the list entirely.
         */
        $name = PersonName::fromTitle('Rajesh Kumar Sharma - CTO | LinkedIn');
        $this->assertSame('Rajesh', $name?->first);
        $this->assertSame('Sharma', $name?->last);

        $second = PersonName::fromTitle('Jane Marie Doe - Head of Sales | LinkedIn');
        $this->assertSame('Jane', $second?->first);
        $this->assertSame('Doe', $second?->last);
    }

    public function test_the_corrected_surname_is_what_permutations_are_built_from(): void
    {
        $name = PersonName::fromTitle('Rajesh Kumar Sharma - CTO | LinkedIn');
        $this->assertNotNull($name);

        $candidates = $this->permutations($name);

        $this->assertSame('rajesh.sharma@acme.com', $candidates[0]);
        $this->assertContains('rsharma@acme.com', $candidates);

        foreach ($candidates as $candidate) {
            $this->assertStringNotContainsString(
                'kumarsharma',
                $candidate,
                'the glued form must not appear at all — every slot it takes is a wasted credit',
            );
        }
    }

    public function test_surname_particles_are_kept_because_they_are_part_of_the_surname(): void
    {
        $name = PersonName::fromTitle('Jan van der Berg - Director | LinkedIn');

        $this->assertSame('Jan', $name?->first);
        $this->assertSame('van der Berg', $name?->last);
        $this->assertContains('jan.vanderberg@acme.com', $this->permutations($name));
    }

    public function test_a_mononym_yields_a_first_name_and_no_surname(): void
    {
        $name = PersonName::split(['Prakash']);

        $this->assertSame('Prakash', $name?->first);
        $this->assertSame('', $name?->last);
        // "{f}.{l}" would render "prakash." — a malformed address and a
        // wasted check.
        $this->assertSame(['prakash@acme.com'], $this->permutations($name));
    }

    // --- Titles that are not names -----------------------------------------

    public function test_an_activity_page_is_not_a_person(): void
    {
        /*
         * These rank alongside profiles and are not evidence that anybody
         * works anywhere — they may only have commented. Credits spent on
         * them buy addresses for a person who is not there.
         */
        $this->assertNull(PersonName::fromTitle('Rahul Verma on LinkedIn: We are hiring across five teams'));
        $this->assertNull(PersonName::fromTitle('Priya Nair posted on LinkedIn: our Q3 results'));
    }

    public function test_a_headline_that_leaked_into_the_name_slot_is_refused(): void
    {
        $this->assertNull(PersonName::fromTitle('We are hiring three senior engineers this quarter'));
        $this->assertNull(PersonName::fromTitle(''));
        $this->assertNull(PersonName::fromTitle(null));
    }

    public function test_a_real_profile_may_still_say_linkedin_in_its_headline(): void
    {
        // "Top Voice on LinkedIn" is a job-title segment, not an activity
        // marker — which is why the marker is tested before the delimiter.
        $name = PersonName::fromTitle('Asha Menon - Top Voice on LinkedIn | LinkedIn');

        $this->assertSame('Asha', $name?->first);
        $this->assertSame('Menon', $name?->last);
    }

    public function test_a_hyphenated_name_survives_the_delimiter(): void
    {
        // Splitting on a bare "-" yields "Jean" as the name and "Luc Picard"
        // as the job title.
        $name = PersonName::fromTitle('Jean-Luc Picard - CTO | LinkedIn');

        $this->assertSame('Jean-Luc', $name?->first);
        $this->assertSame('Picard', $name?->last);
    }

    public function test_decoration_is_stripped_from_a_name(): void
    {
        $this->assertSame('Jane Doe', PersonName::clean('Dr. Jane Doe (She/Her) 🚀'));
        $this->assertSame('Ravi Menon', PersonName::clean('Ravi Menon, PhD'));
        $this->assertSame('Amit Shah', PersonName::clean('Mr Amit Shah [Acme]'));
    }

    // --- Candidate generation ----------------------------------------------

    public function test_a_short_first_name_also_tries_the_formal_one_near_the_front(): void
    {
        /*
         * Every permutation of "chuck" can fail against a perfectly healthy
         * domain and report the person as undeliverable when only the guesses
         * were. Interleaved rather than appended: a budget that stops after
         * eight checks never reaches position twenty-one.
         */
        $candidates = EmailPatterns::candidates(new PersonName('Chuck', 'Waters'), self::ACME);
        $emails = array_map(fn ($c) => $c->email, $candidates);

        $position = array_search('charles.waters@acme.com', $emails, true);
        $this->assertNotFalse($position, 'the formal alternate must be generated');
        $this->assertLessThan(
            12,
            $position,
            'and must sit inside a plausible budget, or it may as well not exist',
        );
    }

    public function test_an_alternate_keeps_the_rank_of_the_template_that_made_it(): void
    {
        $candidates = EmailPatterns::candidates(new PersonName('Chuck', 'Waters'), self::ACME);

        foreach ($candidates as $candidate) {
            if ($candidate->email === 'charles.waters@acme.com') {
                $this->assertTrue($candidate->fromFormalName);
                $this->assertSame(0, $candidate->rank, '{f}.{l} is rank 0 whoever it was built for');

                return;
            }
        }

        $this->fail('charles.waters@acme.com was never generated');
    }

    public function test_candidates_are_unique_and_the_most_likely_comes_first(): void
    {
        $emails = $this->permutations(new PersonName('Jane', 'Doe'));

        $this->assertSame('jane.doe@acme.com', $emails[0]);
        $this->assertSame(array_values(array_unique($emails)), $emails, 'a duplicate is a credit spent twice');
    }

    public function test_a_single_character_mailbox_is_never_generated(): void
    {
        // "Ananthkumar S" produced s@ — a credit spent on an address that, if
        // it exists at all, belongs to somebody else.
        foreach ($this->permutations(new PersonName('Ananthkumar', 'S')) as $email) {
            $this->assertNotSame('s@acme.com', $email);
        }
    }

    public function test_a_template_that_cannot_render_produces_nothing(): void
    {
        $this->assertNull(EmailPatterns::render('{f}.{l}', new PersonName('Jane', '')));
        $this->assertSame('jane', EmailPatterns::render('{f}', new PersonName('Jane', '')));
    }

    // --- Pattern detection --------------------------------------------------

    public function test_a_verified_address_teaches_the_template_that_made_it(): void
    {
        $name = new PersonName('Jane', 'Doe');

        $this->assertSame('{f}.{l}', EmailPatterns::detect('jane.doe', $name));
        $this->assertSame('{fi}{l}', EmailPatterns::detect('jdoe', $name));
        $this->assertSame('{f}_{l}', EmailPatterns::detect('jane_doe', $name));
    }

    public function test_an_alias_teaches_nothing_rather_than_the_wrong_thing(): void
    {
        // Better to report no pattern than to propagate one that was never
        // really there and derive the rest of the team from it.
        $this->assertNull(EmailPatterns::detect('jd2', new PersonName('Jane', 'Doe')));
        $this->assertNull(EmailPatterns::detect('jane.doe.ext', new PersonName('Jane', 'Doe')));
    }

    public function test_a_role_mailbox_never_becomes_a_naming_convention(): void
    {
        /*
         * "sales.uk@" is shaped exactly like first.last. A company whose first
         * confirmed address happens to be a functional inbox would otherwise
         * teach the cache a convention nobody uses, then spend the rest of the
         * quarter deriving addresses from it.
         */
        $this->assertTrue(EmailPatterns::isRoleMailbox('info'));
        $this->assertTrue(EmailPatterns::isRoleMailbox('sales.uk'));
        $this->assertTrue(EmailPatterns::isRoleMailbox('hr.in'));
        $this->assertNull(EmailPatterns::detect('sales.uk', new PersonName('Sales', 'Uk')));

        // A real surname survives.
        $this->assertFalse(EmailPatterns::isRoleMailbox('sales.patel'));
    }

    public function test_a_learned_pattern_is_applied_to_the_rest_of_the_team(): void
    {
        $this->assertSame(
            'ravi.menon@acme.com',
            EmailPatterns::apply('{f}.{l}', new PersonName('Ravi', 'Menon'), self::ACME),
        );

        // A "{f}.{l}" company still has mononym employees.
        $this->assertSame(
            'prakash@acme.com',
            EmailPatterns::apply('{f}.{l}', new PersonName('Prakash', ''), self::ACME),
        );
    }

    // --- The budget split ---------------------------------------------------

    public function test_the_common_head_is_separated_from_the_rare_tail(): void
    {
        $candidates = EmailPatterns::candidates(new PersonName('Jane', 'Doe'), self::ACME);
        $split = EmailPatterns::splitByDepth($candidates, 5);

        $this->assertNotEmpty($split['common']);
        $this->assertNotEmpty($split['tail']);
        foreach ($split['common'] as $candidate) {
            $this->assertLessThan(5, $candidate->rank);
        }
    }

    public function test_a_pattern_the_domain_already_taught_us_leads_the_head(): void
    {
        // One check settles a company that genuinely uses a rare form, rather
        // than re-discovering it from scratch every time.
        $candidates = EmailPatterns::candidates(new PersonName('Jane', 'Doe'), self::ACME);
        $split = EmailPatterns::splitByDepth($candidates, 5, '{l}.{fi}');

        $this->assertSame('{l}.{fi}', $split['common'][0]->template);
        $this->assertSame('doe.j@acme.com', $split['common'][0]->email);
    }

    // --- Departments --------------------------------------------------------

    public function test_a_known_department_expands_into_a_boolean_fragment(): void
    {
        $this->assertStringContainsString('Procurement', Roles::query('procurement'));
        $this->assertStringContainsString('Procurement', Roles::query('purchasing'), 'aliases resolve');
        $this->assertStringContainsString('CTO', Roles::query('IT'));
    }

    public function test_an_unknown_department_narrows_the_search_rather_than_being_dropped(): void
    {
        $this->assertSame('"kiln operator"', Roles::query('kiln operator'));
        $this->assertSame('', Roles::query(null));
        // Quotes inside the term would break out of the phrase.
        $this->assertSame('"weird role"', Roles::query('weird" role'));
    }

    // --- The curated lists --------------------------------------------------

    public function test_the_lists_came_across_whole(): void
    {
        /*
         * Every entry was added because a real run produced a wrong answer
         * without it. A list that quietly loses entries is a pipeline that
         * quietly starts believing data brokers are company websites, so the
         * counts are asserted rather than trusted.
         */
        $this->assertCount(68, Lists::DOMAIN_BLACKLIST);
        $this->assertCount(48, Lists::FREE_MAIL_PROVIDERS);
        $this->assertCount(79, Lists::ROLE_MAILBOX_WORDS);
        $this->assertCount(28, Lists::SURNAME_PARTICLES);
        $this->assertCount(94, Lists::NICKNAMES);

        // The one that was missing in the field: a vendor running two hosts.
        $this->assertTrue(Lists::has(Lists::FREE_MAIL_PROVIDERS, 'zohomail.com'));
        $this->assertTrue(Lists::has(Lists::FREE_MAIL_PROVIDERS, 'zoho.com'));
        $this->assertTrue(Lists::has(Lists::DOMAIN_BLACKLIST, 'indiamart.com'));
    }
}
