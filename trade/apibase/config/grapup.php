<?php

/**
 * Grap Company's vendors and the dials that decide what it spends.
 *
 * Ported from GrapUp's `src/config`. Everything here is an environment
 * variable because these are credentials and cost controls: a key belongs in
 * .env, and a budget belongs somewhere it can be changed without a deploy.
 */
return [
    // Serper — Google results as JSON. The search tier; cheap, not free.
    'serper' => [
        'key' => env('SERPER_API_KEY', ''),
        'url' => env('SERPER_URL', 'https://google.serper.dev/search'),
        'max_rpm' => (int) env('SERPER_MAX_RPM', 60),
        'timeout_seconds' => (int) env('SERPER_TIMEOUT_SECONDS', 15),
    ],

    'verifier' => [
        // 'clearout' or 'hunter'. Nothing downstream knows which.
        'provider' => env('VERIFIER_PROVIDER', 'clearout'),
        'max_rpm' => (int) env('VERIFIER_MAX_RPM', 60),

        /*
         * A catch-all sweep is speculative — it hunts for one mailbox that
         * answers differently from all the others — so it gets a tighter
         * ceiling than a sweep that can prove something.
         */
        'catch_all_max_checks' => (int) env('GRAPUP_CATCHALL_MAX_CHECKS', 8),

        /*
         * The free tier that runs before the paid one. 'inboxx' or empty.
         *
         * Naming it without a key disables it rather than refusing to boot:
         * verification still works, it just costs what it cost before.
         */
        'prefilter' => env('VERIFIER_PREFILTER', 'inboxx'),

        /*
         * How sure a *guess* has to be to be kept without paying.
         *
         * Only the pattern tier produces a confidence at all — anything the
         * engine actually proved is settled whatever this says. So this is
         * the line between "a guess we trust" and "a guess worth a credit".
         */
        'prefilter_threshold' => (int) env('VERIFIER_PREFILTER_THRESHOLD', 80),

        /*
         * Whether a free catch-all verdict ends the question.
         *
         * Off by default, and that is the expensive half of the rule: a
         * server accepting every address has proved nothing about this one.
         * On for anyone who would rather have the finding than the certainty.
         */
        'prefilter_trust_catch_all' => (bool) env('VERIFIER_PREFILTER_TRUST_CATCHALL', false),

        // A run of "unknown" is the vendor, not the addresses. Stop asking.
        'unknown_streak_limit' => (int) env('GRAPUP_UNKNOWN_STREAK', 3),

        // How many confirmed addresses finish a company.
        'target_contacts' => (int) env('GRAPUP_TARGET_CONTACTS', 2),

        // How many people a working pattern is offered to before it is doubted.
        'pattern_probes' => (int) env('GRAPUP_PATTERN_PROBES', 5),

        /*
         * A run of people with no address at all is the domain saying it does
         * not issue personal mailboxes. Windsor spent fifty-two credits
         * establishing that one person at a time.
         */
        'dead_end_walks' => (int) env('GRAPUP_DEAD_END_WALKS', 5),
    ],

    'clearout' => [
        'token' => env('CLEAROUT_API_TOKEN', ''),
        'url' => env('CLEAROUT_URL', 'https://api.clearout.io/v2/email_verify/instant'),
        'timeout_seconds' => (int) env('CLEAROUT_TIMEOUT_SECONDS', 30),
        // What Clearout itself waits for the far mail server.
        'verify_timeout_ms' => (int) env('CLEAROUT_VERIFY_TIMEOUT_MS', 12000),
    ],

    'inboxx' => [
        // Our own verification engine, used for its free tiers.
        'key' => env('INBOXX_API_KEY', ''),
        'url' => env('INBOXX_BASE_URL', 'https://inboxx.work'),
        'max_rpm' => (int) env('INBOXX_MAX_RPM', 120),
        'timeout_seconds' => (int) env('INBOXX_TIMEOUT_SECONDS', 3),
    ],

    'hunter' => [
        'key' => env('HUNTER_API_KEY', ''),
        'url' => env('HUNTER_URL', 'https://api.hunter.io/v2/email-verifier'),
        'timeout_seconds' => (int) env('HUNTER_TIMEOUT_SECONDS', 30),
    ],

    /*
     * How long an answer stays good.
     *
     * A verdict is a fact about a mailbox at a moment. People leave; domains
     * change hands. Long enough that a month of prospecting the same market
     * does not re-pay for the same people, short enough that a year-old
     * "deliverable" is not presented as current.
     */
    'cache_ttl_days' => (int) env('GRAPUP_CACHE_TTL_DAYS', 90),

    // DNS is free and changes rarely, so it is kept much longer.
    'mx_ttl_days' => (int) env('GRAPUP_MX_TTL_DAYS', 30),

    /*
     * How deep into the pattern list one contact is worth taking.
     *
     * The head is cheap enough to try against several people; past it, each
     * check is a bet that this particular person works at this company. Five
     * is GrapUp's measured default — every pattern that cracked on a ten
     * company run sat at rank 1-3.
     */
    'common_depth' => (int) env('GRAPUP_COMMON_DEPTH', 6),

    // The ceiling on checks for one contact, whatever the patterns suggest.
    'max_checks_per_contact' => (int) env('GRAPUP_MAX_CHECKS_PER_CONTACT', 8),

    'budget' => [
        // The most one company may cost, across everybody at it.
        'max_verifications_per_search' => (int) env('GRAPUP_MAX_VERIFICATIONS', 60),
    ],

    'sourcing' => [
        // Stop widening the name once this many people have been found.
        'enough_contacts' => (int) env('GRAPUP_ENOUGH_CONTACTS', 6),
        // A search engine is cheap and a credit is not, but not unlimited.
        'max_searches' => (int) env('GRAPUP_MAX_SEARCHES', 5),
    ],

    'verifier_walk' => [
        // How many confirmed addresses finish a company.
        'target_contacts' => (int) env('GRAPUP_TARGET_CONTACTS', 2),
    ],

    'scrape' => [
        'timeout_seconds' => (int) env('GRAPUP_SCRAPE_TIMEOUT_SECONDS', 10),
    ],
];
