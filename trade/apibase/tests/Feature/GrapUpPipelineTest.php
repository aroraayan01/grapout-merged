<?php

namespace Tests\Feature;

use App\Services\GrapUp\Contact;
use App\Services\GrapUp\Domain\CompanyMatch;
use App\Services\GrapUp\Domain\Parse;
use App\Services\GrapUp\Domain\Seniority;
use App\Services\GrapUp\Domain\Text;
use App\Services\GrapUp\DomainFacts;
use App\Services\GrapUp\Pipeline\CompanySearch;
use App\Services\GrapUp\Pipeline\ContactWaterfall;
use App\Services\GrapUp\Pipeline\DomainDiscovery;
use App\Services\GrapUp\Pipeline\EmployeeSearch;
use App\Services\GrapUp\Pipeline\PropagationResult;
use App\Services\GrapUp\Pipeline\VerificationWalk;
use Illuminate\Support\Facades\DB;
use App\Services\GrapUp\Verifier\Status;
use App\Services\GrapUp\Verifier\VerificationProvider;
use App\Services\GrapUp\Verifier\Verifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pipeline, and the expensive mistakes it exists to avoid.
 *
 * No network: the verifier is a stub that answers from a script and counts
 * what it was asked. Each case here is a company GrapUp actually got wrong
 * before the rule that fixes it existed, and the names are kept because
 * "Pardaen shipped nine addresses that do not exist" is a better reason to
 * keep a line of code than "propagation is unsafe".
 */
class GrapUpPipelineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A verifier that answers from a map of address => status, defaulting to
     * undeliverable, and remembers every address it was asked about.
     */
    private function verifier(array $answers, ?array &$asked = null): Verifier
    {
        $asked = [];

        return new Verifier(new class($answers, $asked) implements VerificationProvider
        {
            public function __construct(private array $answers, private array &$asked) {}

            public function name(): string
            {
                return 'stub';
            }

            public function verify(string $email): string
            {
                $this->asked[] = $email;

                return $this->answers[$email] ?? Status::UNDELIVERABLE;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function circuit(): array
            {
                return ['state' => 'closed', 'consecutive_failures' => 0, 'retry_in_seconds' => null];
            }
        });
    }

    /** MX is a real DNS call; the tests pre-record the answer instead. */
    private function domainCanReceiveMail(string $domain): void
    {
        DomainFacts::recordMx($domain, true);
    }

    // --- Nobody is handed an unchecked address ------------------------------

    /**
     * The Pardaen regression. One lucky hit was read as the company's
     * convention and applied to everyone unchecked, and the run shipped nine
     * addresses that do not exist — every one of them wearing a verified badge.
     */
    public function test_a_pattern_that_worked_for_one_person_is_never_applied_to_another_unchecked(): void
    {
        $this->domainCanReceiveMail('pardaen.be');

        $contacts = [
            new Contact('Jan', 'Dierckx', corroborated: true),
            new Contact('Marie', 'Claes', corroborated: true),
        ];

        // Only Jan has a mailbox. Marie has none, and no permutation of hers
        // will answer.
        $walk = new VerificationWalk($this->verifier(['jan.dierckx@pardaen.be' => Status::DELIVERABLE]));
        $result = $walk->verifyAndPropagate($contacts, 'pardaen.be', Verifier::CONTEXT_SEARCH);

        $this->assertSame('jan.dierckx@pardaen.be', $contacts[0]->email);
        $this->assertNull($contacts[1]->email, 'Marie was never confirmed, so she gets no address at all');
        $this->assertSame('locked', $contacts[1]->status);
        $this->assertSame('{f}.{l}@pardaen.be', $result->corporateFormat);
    }

    /**
     * A search has to be able to say who answered each person and what each
     * provider was asked, so the page can show Inboxx and Clearout spend
     * separately. The walk reports both.
     */
    public function test_a_walk_reports_who_confirmed_each_person_and_the_calls_it_made(): void
    {
        $this->domainCanReceiveMail('pardaen.be');

        $contacts = [
            new Contact('Jan', 'Dierckx', corroborated: true),
            new Contact('Marie', 'Claes', corroborated: true),
        ];

        $walk = new VerificationWalk($this->verifier(['jan.dierckx@pardaen.be' => Status::DELIVERABLE], $asked));
        $result = $walk->verifyAndPropagate($contacts, 'pardaen.be', Verifier::CONTEXT_SEARCH);

        $this->assertSame('stub', $contacts[0]->verifiedBy);
        $this->assertFalse($contacts[0]->fromCache);
        $this->assertNull($contacts[1]->verifiedBy, 'nobody confirmed Marie, so nobody is credited');
        $this->assertSame(['stub' => count($asked)], $result->calls);
        $this->assertSame('stub', $contacts[0]->toArray()['verified_by']);
    }

    /**
     * The Windsor regression. Sourcing fills every contact with a guess built from
     * their first name so the row is not blank while locked; anyone the walk
     * never reaches would otherwise keep that guess and ship it.
     */
    public function test_the_sourcing_placeholder_never_survives_the_walk(): void
    {
        $this->domainCanReceiveMail('windsor.com');

        $contacts = [new Contact('Alice', 'Smith', email: 'alice@windsor.com')];

        $walk = new VerificationWalk($this->verifier([]));  // nothing verifies
        $walk->verifyAndPropagate($contacts, 'windsor.com', Verifier::CONTEXT_SEARCH);

        $this->assertNull($contacts[0]->email, 'an invented address must not be reported as found');
    }

    // --- Not spending at all -------------------------------------------------

    public function test_a_domain_that_cannot_receive_mail_is_never_verified(): void
    {
        // One free DNS lookup replaces up to twenty paid checks.
        DomainFacts::recordMx('dead-domain.test', false);

        $contacts = [new Contact('Alice', 'Smith')];
        $walk = new VerificationWalk($this->verifier([], $asked));

        $result = $walk->verifyAndPropagate($contacts, 'dead-domain.test', Verifier::CONTEXT_SEARCH);

        $this->assertSame(0, $result->creditsSpent);
        $this->assertSame([], $asked, 'the vendor was never called');
        $this->assertSame('undeliverable', $contacts[0]->status);
    }

    /**
     * The zohomail regression. A permutation on a shared host verifies as
     * valid because a real stranger owns that mailbox — a confident wrong
     * answer indistinguishable from a right one, and a "pattern" learned from
     * somebody unconnected to the company.
     */
    public function test_a_shared_mailbox_host_is_never_permuted_on(): void
    {
        $contacts = [new Contact('Alice', 'Smith')];
        $walk = new VerificationWalk($this->verifier([], $asked));

        $result = $walk->verifyAndPropagate($contacts, 'gmail.com', Verifier::CONTEXT_SEARCH);

        $this->assertSame(0, $result->creditsSpent);
        $this->assertSame([], $asked);
        $this->assertNull($result->corporateFormat, 'nothing here could be a company convention');
    }

    /**
     * The JAGO regression. Two full walks on people who genuinely have no
     * mailbox wrote the domain off, while the third person in the list held
     * the commonest pattern there is, one check away.
     */
    public function test_a_known_pattern_answers_the_next_person_in_one_check(): void
    {
        $this->domainCanReceiveMail('jago.com');
        DomainFacts::recordVerifiedPattern('jago.com', '{f}.{l}');

        $contacts = [new Contact('Simon', 'Jago', corroborated: true)];
        $walk = new VerificationWalk($this->verifier(['simon.jago@jago.com' => Status::DELIVERABLE], $asked));

        $result = $walk->verifyAndPropagate($contacts, 'jago.com', Verifier::CONTEXT_SEARCH);

        $this->assertSame('simon.jago@jago.com', $contacts[0]->email);
        $this->assertSame(1, $result->creditsSpent, 'the remembered pattern led, so one check settled it');
        $this->assertSame(['simon.jago@jago.com'], $asked);
    }

    public function test_the_walk_stops_when_the_vendor_stops_answering(): void
    {
        $this->domainCanReceiveMail('acme.com');

        $contacts = [new Contact('Alice', 'Smith')];

        // Everything comes back unknown: the vendor is down, not the addresses.
        $always = new Verifier(new class implements VerificationProvider
        {
            public function name(): string
            {
                return 'down';
            }

            public function verify(string $email): string
            {
                return Status::UNKNOWN;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function circuit(): array
            {
                return ['state' => 'open', 'consecutive_failures' => 5, 'retry_in_seconds' => 30];
            }
        });

        $result = (new VerificationWalk($always))->verifyAndPropagate($contacts, 'acme.com', Verifier::CONTEXT_SEARCH);

        $this->assertTrue($result->vendorUnavailable);
        $this->assertSame('unknown', $contacts[0]->status, 'an outage is not a finding about a person');
        $this->assertLessThanOrEqual(3, $result->creditsSpent, 'a run of unknowns aborts rather than draining the budget');
    }

    // --- Catch-all -----------------------------------------------------------

    /**
     * A catch-all server accepts every mailbox, so a naive reading marks all
     * twenty candidates valid and ships twenty fakes. Everyone gets the honest
     * badge instead: plausible, not proven.
     */
    public function test_a_catch_all_domain_yields_plausible_addresses_not_proven_ones(): void
    {
        $this->domainCanReceiveMail('catchall.test');
        DomainFacts::recordCatchAll('catchall.test', true);

        $contacts = [new Contact('Alice', 'Smith'), new Contact('Bob', 'Jones')];
        $walk = new VerificationWalk($this->verifier(['alice.smith@catchall.test' => Status::DELIVERABLE]));

        $walk->verifyAndPropagate($contacts, 'catchall.test', Verifier::CONTEXT_SEARCH);

        $this->assertSame('catch_all', $contacts[1]->status);
        $this->assertNotNull($contacts[1]->email, 'on a catch-all a plausible address is the best answer there is');
    }

    /**
     * With nothing proven on a catch-all, the guess is the commonest
     * convention — first.last — not the bare first name. Havells came back
     * ranjit@ for everyone's first name, the weakest guess there is.
     */
    public function test_an_unproven_catch_all_guesses_first_dot_last(): void
    {
        $this->domainCanReceiveMail('guess.test');
        DomainFacts::recordCatchAll('guess.test', true);

        $contacts = [new Contact('Ranjit', 'Kumar'), new Contact('Priya', 'Sharma'), new Contact('Madonna')];
        $walk = new VerificationWalk($this->verifier([]));  // nothing stands out

        $walk->verifyAndPropagate($contacts, 'guess.test', Verifier::CONTEXT_SEARCH);

        $this->assertSame('ranjit.kumar@guess.test', $contacts[0]->email);
        $this->assertSame('priya.sharma@guess.test', $contacts[1]->email);
        $this->assertSame('madonna@guess.test', $contacts[2]->email, 'no last name falls back to the first');
        $this->assertSame('catch_all', $contacts[1]->status);
        $this->assertNull($contacts[1]->verifiedBy, 'still labelled a guess nobody checked');
    }

    /**
     * The Havells regression. Free catch-all answers cost no credits, and the
     * catch-all ceiling used to count only credits — so one person's whole
     * twenty-guess list was walked, a minute for nothing. The ceiling counts
     * checks now, paid or free.
     */
    public function test_a_catch_all_hunt_stops_at_the_ceiling_even_when_every_answer_is_free(): void
    {
        $this->domainCanReceiveMail('havells.com');
        config()->set('grapup.verifier.prefilter_trust_catch_all', true);
        config()->set('grapup.verifier.catch_all_max_checks', 8);

        $freeCalls = 0;
        $free = new class($freeCalls) implements \App\Services\GrapUp\Prefilter\PrefilterProvider
        {
            public function __construct(private int &$calls) {}

            public function name(): string
            {
                return 'free';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function check(string $email): ?\App\Services\GrapUp\Prefilter\PrefilterVerdict
            {
                $this->calls++;

                return new \App\Services\GrapUp\Prefilter\PrefilterVerdict(Status::CATCH_ALL, 95, 'vendor');
            }
        };

        $paid = $this->verifier([], $asked);
        $paid->usePrefilter($free);

        $contacts = [new Contact('Ranjit', 'Kumar', corroborated: true)];
        (new VerificationWalk($paid))->verifyAndPropagate($contacts, 'havells.com', Verifier::CONTEXT_SEARCH);

        $this->assertSame(8, $freeCalls, 'the hunt stopped at the ceiling, not at the end of the list');
        $this->assertSame([], $asked, 'trusted free catch-alls never reached the paid tier');
        $this->assertSame('catch_all', $contacts[0]->status);
    }

    // --- Collisions ----------------------------------------------------------

    /**
     * At Pardaen, Jan Dierckx and Jorne De Smet both render jd@ under
     * {fi}{li}. Jan's was bought and is real; showing the same address for
     * Jorne presents a known-wrong answer with the same confidence as a right
     * one, and somebody would have written to it.
     */
    public function test_two_people_are_never_shown_the_same_address(): void
    {
        $this->domainCanReceiveMail('pardaen.be');
        DomainFacts::recordVerifiedPattern('pardaen.be', '{fi}{li}');

        $contacts = [
            new Contact('Jan', 'Dierckx', corroborated: true),
            new Contact('Jorne', 'De Smet', corroborated: true),
        ];

        $walk = new VerificationWalk($this->verifier(['jd@pardaen.be' => Status::DELIVERABLE]));
        $walk->verifyAndPropagate($contacts, 'pardaen.be', Verifier::CONTEXT_SEARCH);

        $emails = array_values(array_filter(array_map(fn ($c) => $c->email, $contacts)));
        $this->assertSame(array_unique($emails), $emails, 'a mailbox belongs to one person');
    }

    // --- Who gets asked first ------------------------------------------------

    public function test_a_managing_director_outranks_a_buyer_for_the_expensive_walk(): void
    {
        // Pardaen again: the engine ranked the buyer first, the walk proved he
        // has no mailbox, and never reached the director's initials.
        $this->assertLessThan(
            Seniority::rank('Buyer'),
            Seniority::rank('Managing Director'),
            'lower is more senior',
        );
        $this->assertSame(Seniority::UNKNOWN, Seniority::rank(null));
        // Word boundaries: "vp" must not fire on "development".
        $this->assertSame(Seniority::UNKNOWN, Seniority::rank('Business Development'));
    }

    // --- Not adopting a stranger's domain ------------------------------------

    /**
     * "THILLAI SUPER MARKET PTE. LTD" resolved correctly to
     * thillaisupermarket.com, then adopted cybex.in from a stray result — and
     * the whole verification budget went to a different company.
     */
    public function test_a_domain_unrelated_to_the_company_name_is_not_adopted(): void
    {
        $this->assertTrue(CompanyMatch::domainMatches('thillaisupermarket.com', 'THILLAI SUPER MARKET PTE. LTD'));
        $this->assertFalse(CompanyMatch::domainMatches('cybex.in', 'THILLAI SUPER MARKET PTE. LTD'));
    }

    /**
     * "B&Q Limited" reduces to no testable word at all — "b" and "q" are below
     * the length floor and "limited" is a legal form. The permissive answer
     * waved a paint manufacturer through and twenty credits went to it, which
     * is why the caller has to ask whether the name can vouch before trusting
     * a permissive yes.
     */
    public function test_a_name_with_nothing_testable_in_it_says_so(): void
    {
        $this->assertFalse(CompanyMatch::hasTestableIdentity('B&Q Limited'));
        $this->assertTrue(CompanyMatch::hasTestableIdentity('Mylands Paints Ltd'));
        // Permissive on purpose — and wrong to trust here, which is the point.
        $this->assertTrue(CompanyMatch::domainMatches('mylands.co.uk', 'B&Q Limited'));
    }

    public function test_related_domains_are_recognised_as_the_same_firm(): void
    {
        // A firm may send mail from a domain whose relationship to its website
        // is obvious to a human and invisible to a name match.
        $this->assertTrue(CompanyMatch::sharesIdentity('acme.com', 'acme-group.com'));
        $this->assertFalse(CompanyMatch::sharesIdentity('acme.com', 'globex.com'));
    }

    // --- Who counts as an employee -------------------------------------------

    /**
     * Melanie Walton's snippet read "Commercial Services Specialist -
     * Promethean · Experience: Traditional Hardware Direct Ltd". She left.
     * Twenty credits went on her because the whole snippet was tested.
     */
    public function test_only_the_current_role_corroborates_an_employer(): void
    {
        $title = 'Melanie Walton - Commercial Services Specialist - Promethean | LinkedIn';

        $this->assertFalse(
            CompanyMatch::mentionsCompany(Text::currentRoleText($title), 'Traditional Hardware Direct Ltd'),
            'a former employer in the history is not a current employer',
        );
        $this->assertTrue(CompanyMatch::mentionsCompany(Text::currentRoleText($title), 'Promethean'));
    }

    /**
     * Companies get named after people. Searching "Andries de Jong" returns
     * everyone *called* Andries de Jong, and each corroborates the company
     * perfectly if you test the whole title — so the name segment is dropped.
     */
    public function test_a_persons_own_name_does_not_corroborate_a_company_named_after_them(): void
    {
        $title = 'Andries de Jong - Accountmanager - Bouwmaat | LinkedIn';

        $this->assertFalse(CompanyMatch::mentionsCompany(Text::currentRoleText($title), 'Andries de Jong B.V.'));
    }

    // --- What gets scraped ---------------------------------------------------

    public function test_things_shaped_like_phone_numbers_that_are_not(): void
    {
        // Every one of these reached production wearing the right shape.
        $this->assertNull(Parse::phone('46.17.172.215'), 'an IP address');
        $this->assertNull(Parse::phone('1239-1240'), 'a page range off a homepage');
        $this->assertNull(Parse::phone('2009-10-23'), 'a date, as J. D. Beardmore’s switchboard');
        $this->assertNull(Parse::phone('2025 99.999'), 'mixed separators, off Stripe');
        $this->assertNull(Parse::phone('+44 333 011'), 'a truncated capture, off Screwfix');
        $this->assertNull(Parse::phone('78727 (512'), 'a snippet cut mid-area-code');
        $this->assertNull(Parse::phone('0000000000'), 'placeholder markup');

        // And the ones that are.
        $this->assertSame('+65 6293 4567', Parse::phone('+65 6293 4567'));
        $this->assertSame('01254 773945', Parse::phone('(01254 773945'), 'a stray opening bracket is framing');
    }

    public function test_cloudflare_obfuscated_addresses_are_decoded(): void
    {
        // The single highest-yield trick in the homepage tier — plenty of
        // sites expose no other address at all.
        $email = 'info@acme.com';
        $key = 0x2a;
        $hash = sprintf('%02x', $key);
        foreach (str_split($email) as $char) {
            $hash .= sprintf('%02x', ord($char) ^ $key);
        }

        $this->assertSame([$email], Text::cloudflareEmails('<a class="__cf_email__" data-cfemail="' . $hash . '">'));
    }

    public function test_a_role_mailbox_is_preferred_over_a_persons_address(): void
    {
        // info@ is the company's; a named person's address on a contact page
        // may belong to whoever built the site.
        $this->assertSame(
            'info@acme.com',
            Text::pickBestEmail(['dave@acme.com', 'info@acme.com']),
        );
        // And a corporate address beats a consumer one whatever the prefix.
        $this->assertSame(
            'dave@acme.com',
            Text::pickBestEmail(['info@gmail.com', 'dave@acme.com']),
        );
    }

    // --- The searcher's cap -------------------------------------------------

    /**
     * A CompanySearch whose four collaborators are scripted, so the cap can be
     * tested without a network: the domain is fixed, the team is a fixed five
     * ranked people, and the walk hands whatever it is given straight back as
     * deliverable — so the only thing that decides how many come out is the
     * cap applied before the walk ever sees them.
     */
    private function scriptedSearch(int $sourced, ?int &$findCalls = null): CompanySearch
    {
        $findCalls = 0;

        $discovery = new class extends DomainDiscovery
        {
            public function __construct() {}

            public function find(string $companyName, string $country, ?string $gl): array
            {
                return ['domain' => 'acme.com', 'rejected' => []];
            }
        };

        $waterfall = new class extends ContactWaterfall
        {
            public function __construct() {}

            public function run(string $companyName, ?string $domain, ?string $gl): array
            {
                return ['domain' => 'acme.com', 'email' => null, 'phone' => null];
            }
        };

        $employees = new class($sourced, $findCalls) extends EmployeeSearch
        {
            public function __construct(private int $sourced, private int &$findCalls) {}

            public function find(string $companyName, string $country, string $domain, string $targetRole, ?string $gl): array
            {
                $this->findCalls++;
                $contacts = [];
                for ($i = 1; $i <= $this->sourced; $i++) {
                    $contacts[] = new Contact(
                        firstName: "Person{$i}", lastName: 'Smith',
                        email: "person{$i}@acme.com", status: 'deliverable',
                        headline: 'Director', linkedinUrl: "https://x/{$i}", corroborated: true,
                    );
                }

                return ['contacts' => $contacts, 'matchedAs' => 'acme', 'searches' => 1];
            }
        };

        $walk = new class extends VerificationWalk
        {
            public function __construct() {}

            public function verifyAndPropagate(array $contacts, string $domain, string $context, ?\App\Models\User $user = null, ?string $reference = null, ?int $budget = null): PropagationResult
            {
                // Hand them straight back, verified — the cap already chose who.
                return new PropagationResult($contacts, '{f}.{l}@acme.com', count($contacts), false);
            }
        };

        return new CompanySearch($discovery, $waterfall, $employees, $walk);
    }

    public function test_a_cap_returns_only_the_top_people_and_never_verifies_the_rest(): void
    {
        $search = $this->scriptedSearch(sourced: 5);

        $all = $search->run('Acme Ltd');
        $this->assertCount(5, $all['contacts'], 'no cap returns the whole sourced team');

        $capped = $search->run('Acme Ltd', maxContacts: 2);
        $this->assertCount(2, $capped['contacts'], 'the cap slices the ranked team before the walk');
        $this->assertSame('Person1', $capped['contacts'][0]['first_name'], 'the top of the ranking survives');
    }

    public function test_a_capped_search_is_a_different_cache_row_from_an_uncapped_one(): void
    {
        $search = $this->scriptedSearch(sourced: 5, findCalls: $calls);

        $search->run('Acme Ltd');                    // caches under max_contacts = 0
        $search->run('Acme Ltd', maxContacts: 2);     // caches under max_contacts = 2

        $this->assertSame(2, $calls, 'neither read the other from cache — both actually ran');
        $this->assertDatabaseHas('grap_search_cache', ['company_name' => 'Acme Ltd', 'max_contacts' => 0]);
        $this->assertDatabaseHas('grap_search_cache', ['company_name' => 'Acme Ltd', 'max_contacts' => 2]);

        // And the capped answer is now cached: a repeat does not source again.
        $again = $search->run('Acme Ltd', maxContacts: 2);
        $this->assertTrue($again['cached']);
        $this->assertSame(2, $calls, 'the repeat came from the cache');
    }

}
