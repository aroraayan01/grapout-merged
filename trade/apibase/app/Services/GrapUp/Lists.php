<?php

namespace App\Services\GrapUp;

/**
 * The curated lists the pipeline was tuned against, carried over from GrapUp
 * unchanged.
 *
 * Every entry here was added because a real company run produced a wrong
 * answer without it, so these are data rather than opinion: add to them, do
 * not rewrite them. They are lifted from the source rather than retyped,
 * because a blocklist that quietly loses six entries is six domains the
 * pipeline will believe are company websites.
 */
final class Lists
{
    /**
     * Sites that rank for company names but never host the company's own
     * contact details — data brokers, directories, filings aggregators,
     * social networks. Domain discovery skips these unless the company name
     * is in the URL itself.
     */
    public const DOMAIN_BLACKLIST = [
        'seair.co.in', 'volza.com', 'trademo.com', 'tradedata.pro', 'panjiva.com',
        'importgenius.com', 'indiamart.com', 'tradeindia.com', 'exportersindia.com',
        'kompass.com', 'connect2india.com', 'zaubacorp.com', 'tofler.in',
        'thecompanycheck.com', 'cleartax.in', '99corporates.com', 'quickcompany.in',
        'instafinancials.com', 'credhive.in', 'economictimes.indiatimes.com', 'bloomberg.com',
        'zoominfo.com', 'apollo.io', 'lusha.com', 'rocketreach.co', 'dnb.com',
        'crunchbase.com', 'pitchbook.com', 'linkedin.com', 'facebook.com', 'instagram.com',
        'twitter.com', 'x.com', 'justdial.com', 'yellowpages.com', 'yelp.com', 'glassdoor.com',
        'ambitionbox.com', 'g2.com', 'capterra.com', 'trustpilot.com', 'scribd.com',
        'issuu.com', 'slideshare.net', 'recordowl.com', 'ltddir.com', 'sgpbusiness.com',
        'tracxn.com', 'opencorporates.com', 'bizfile.gov.sg', 'sgcompanies.org',
        'singaporecompany.info', 'entitysearch.io', 'companieshouse.gov.uk', 'endole.co.uk',
        'companycheck.co.uk', 'bizdb.co.uk',
        'find-and-update.company-information.service.gov.uk', 'corporationwiki.com',
        'owler.com', 'buzzfile.com', 'manta.com', 'bizapedia.com', 'hoovers.com', 'leadiq.com',
        'signalhire.com', 'contactout.com', 'clearbit.com'
    ];

    /**
     * Domain labels that carry no identity — the public-suffix parts of a
     * hostname. Used to reduce "thillai.com.sg" to "thillai" before comparing
     * it to a name.
     */
    public const TLD_LABELS = [
        'com', 'net', 'org', 'edu', 'gov', 'mil', 'int', 'co', 'biz', 'info', 'name', 'pro',
        'io', 'ai', 'app', 'dev', 'xyz', 'online', 'site', 'store', 'tech', 'shop', 'me', 'tv',
        'in', 'sg', 'uk', 'us', 'au', 'ca', 'nz', 'my', 'ph', 'id', 'th', 'vn', 'hk', 'tw',
        'kr', 'jp', 'cn', 'ae', 'sa', 'za', 'br', 'mx', 'de', 'fr', 'es', 'it', 'nl', 'se',
        'no', 'dk', 'fi', 'pl', 'ru', 'tr', 'gr', 'pt', 'ie', 'be', 'ch', 'at', 'eu', 'asia'
    ];

    /**
     * Consumer mailbox providers.
     *
     * A hit here is worse than useless — it is actively dangerous. There is no
     * company naming pattern on a shared host, and a permutation verifies as
     * valid because a real stranger owns that mailbox: a confident wrong
     * answer that looks exactly like a right one.
     */
    public const FREE_MAIL_PROVIDERS = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.in', 'yahoo.co.uk',
        'yahoo.co.jp', 'ymail.com', 'rocketmail.com', 'hotmail.com', 'hotmail.co.uk',
        'outlook.com', 'live.com', 'msn.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com',
        'protonmail.com', 'proton.me', 'pm.me', 'gmx.com', 'gmx.net', 'web.de', 'zoho.com',
        'zohomail.com', 'zohomail.in', 'yandex.com', 'yandex.ru', 'mail.ru', 'bk.ru',
        'inbox.ru', 'mail.com', 'email.com', 'usa.com', 'rediffmail.com', 'sify.com',
        'indiatimes.com', 'qq.com', '163.com', '126.com', 'sina.com', 'naver.com', 'daum.net',
        'fastmail.com', 'hushmail.com', 'tutanota.com', 'tuta.io', 'zoho.eu'
    ];

    /** Placeholder domains that scrapers pick up from template markup. */
    public const PLACEHOLDER_DOMAINS = [
        'unknown.com', 'domain.com', 'example.com', 'example.org', 'example.net',
        'yourdomain.com', 'yoursite.com', 'email.com', 'company.com', 'website.com',
        'sentry.io', 'wixpress.com', 'localhost'
    ];

    /**
     * Mailbox names that belong to a function rather than a person.
     *
     * A local part like "sales.uk" is shaped exactly like first.last, and a
     * company whose first confirmed address happens to be a functional inbox
     * would teach the pattern cache a naming convention nobody uses.
     */
    public const ROLE_MAILBOX_WORDS = [
        'info', 'sales', 'contact', 'contacts', 'enquiries', 'enquiry', 'inquiry', 'inquiries',
        'admin', 'office', 'hello', 'mail', 'email', 'export', 'exports', 'import', 'imports',
        'marketing', 'support', 'help', 'orders', 'order', 'purchase', 'purchasing',
        'accounts', 'accounting', 'finance', 'hr', 'careers', 'jobs', 'service', 'services',
        'customerservice', 'general', 'reception', 'team', 'shop', 'trade', 'webmaster',
        'postmaster', 'noreply', 'no', 'reply', 'news', 'press', 'media', 'invoice',
        'invoices', 'billing', 'quote', 'quotes', 'rfq', 'web', 'website', 'technical', 'tech',
        'training', 'legal', 'compliance', 'operations', 'ops', 'logistics', 'uk', 'usa', 'us',
        'eu', 'asia', 'apac', 'emea', 'india', 'in', 'global', 'group', 'head', 'main', 'all',
        'everyone', 'corp', 'hq'
    ];

    /**
     * Hosts where a subdomain legitimately belongs to the company that named
     * it. On a site builder, siomex.weebly.com really is Siomex's website. On
     * a marketplace, a listing subdomain is about the company on somebody
     * else's site — anyone can get their name into a directory subdomain.
     */
    public const SITE_BUILDER_HOSTS = [
        'weebly.com', 'wixsite.com', 'wix.com', 'blogspot.com', 'wordpress.com',
        'squarespace.com', 'godaddysites.com', 'business.site', 'webnode.com', 'jimdosite.com',
        'strikingly.com', 'myshopify.com', 'sitey.com', 'webs.com'
    ];

    /**
     * Words in a company name that identify nobody in particular.
     *
     * The legal forms matter as much as the filler: leaving "pty" in means the
     * matcher will accept pty.com as the website of any Australian company.
     * Kept in the languages the book is actually written in, because an
     * English-only list made every German company's name look distinctive when
     * half of it was "logistics and services".
     */
    public const GENERIC_NAME_WORDS = [
        'pte', 'pty', 'ltd', 'limited', 'llc', 'llp', 'plc', 'inc', 'incorporated', 'corp',
        'corporation', 'co', 'company', 'group', 'holdings', 'pvt', 'private', 'gmbh', 'bv',
        'nv', 'sa', 'ag', 'srl', 'sdn', 'bhd', 'fzco', 'fzc', 'fze', 'dmcc', 'aps', 'the',
        'and', 'of', 'for', 'international', 'global', 'enterprises', 'enterprise', 'services',
        'solutions', 'systems', 'technologies', 'industries', 'trading',
        's name look
     * distinctive when half of it was "logistics and services". Measured:
     * syscon.biz claimed to be "B+S GmbH Logistik und Dienstleistungen",
     * because its page carries both those words — as would most German
     * logistics sites.
     */
    ',
        ', ', ', ', ', ', ', ', ',
    ', ', ', ', ', ', ', ', ', ',
    ', ', ', ', ', ', ',
        ',
    ', ', ', ', ', ', ', ', ', ',
    ', ', ', ', ', ', '
    ];

    /**
     * Particles that belong to the surname that follows them. "Jan van der
     * Berg" is a Berg, but his address is built from "vandenberg" — dropping
     * the particles would put an address nobody has at the top of the walk.
     */
    public const SURNAME_PARTICLES = [
        'van', 'von', 'de', 'del', 'della', 'der', 'den', 'di', 'da', 'das', 'dos', 'du', 'la',
        'le', 'lo', 'bin', 'ibn', 'al', 'el', 'ter', 'ten', 'op', 'mac', 'mc', 'st', 'san',
        'santa', 'abu'
    ];

    /**
     * The name somebody introduces themselves by, and the name IT gave their
     * mailbox. LinkedIn shows "Chuck Waters"; the mail server has
     * charles.waters@. Every permutation built from "chuck" fails against a
     * perfectly healthy domain, and the walk then reports the address as
     * undeliverable — true of every address it tested, false about the person.
     *
     * @var array<string, list<string>>
     */
    public const NICKNAMES = [
        'abby' => ['abigail'],
        'al' => ['albert', 'alan'],
        'alex' => ['alexander', 'alexandra'],
        'andy' => ['andrew'],
        'art' => ['arthur'],
        'barb' => ['barbara'],
        'becky' => ['rebecca'],
        'ben' => ['benjamin'],
        'bert' => ['albert'],
        'beth' => ['elizabeth'],
        'bill' => ['william'],
        'billy' => ['william'],
        'bob' => ['robert'],
        'bobby' => ['robert'],
        'cathy' => ['catherine'],
        'charlie' => ['charles'],
        'chris' => ['christopher', 'christine'],
        'chuck' => ['charles'],
        'cindy' => ['cynthia'],
        'dan' => ['daniel'],
        'danny' => ['daniel'],
        'dave' => ['david'],
        'deb' => ['deborah'],
        'debbie' => ['deborah'],
        'dick' => ['richard'],
        'don' => ['donald'],
        'doug' => ['douglas'],
        'ed' => ['edward'],
        'eddie' => ['edward'],
        'fran' => ['frances', 'francis'],
        'fred' => ['frederick'],
        'gerry' => ['gerald'],
        'greg' => ['gregory'],
        'hank' => ['henry'],
        'harry' => ['harold'],
        'jack' => ['john'],
        'jake' => ['jacob'],
        'jeff' => ['jeffrey'],
        'jen' => ['jennifer'],
        'jenny' => ['jennifer'],
        'jim' => ['james'],
        'jimmy' => ['james'],
        'joe' => ['joseph'],
        'joey' => ['joseph'],
        'jon' => ['jonathan'],
        'kate' => ['katherine'],
        'kathy' => ['katherine'],
        'ken' => ['kenneth'],
        'kim' => ['kimberly'],
        'larry' => ['lawrence'],
        'len' => ['leonard'],
        'liz' => ['elizabeth'],
        'lou' => ['louis'],
        'maggie' => ['margaret'],
        'matt' => ['matthew'],
        'meg' => ['margaret'],
        'mike' => ['michael'],
        'nate' => ['nathan'],
        'nick' => ['nicholas'],
        'pat' => ['patrick', 'patricia'],
        'patty' => ['patricia'],
        'peggy' => ['margaret'],
        'pete' => ['peter'],
        'phil' => ['philip'],
        'ray' => ['raymond'],
        'rich' => ['richard'],
        'rick' => ['richard'],
        'rob' => ['robert'],
        'ron' => ['ronald'],
        'russ' => ['russell'],
        'sam' => ['samuel'],
        'sandy' => ['sandra'],
        'stan' => ['stanley'],
        'steve' => ['steven', 'stephen'],
        'sue' => ['susan'],
        'ted' => ['edward', 'theodore'],
        'terry' => ['terence'],
        'tim' => ['timothy'],
        'tina' => ['christina'],
        'tom' => ['thomas'],
        'tony' => ['anthony'],
        'trish' => ['patricia'],
        'vic' => ['victor'],
        'vicky' => ['victoria'],
        'walt' => ['walter'],
        'will' => ['william'],
        'raj' => ['rajesh', 'rajeev', 'rajendra'],
        'sanju' => ['sanjay'],
        'vijay' => ['vijayakumar'],
        'ash' => ['ashish', 'ashok'],
        'sid' => ['siddharth'],
        'abhi' => ['abhishek', 'abhijit'],
        'manu' => ['manoj', 'manish'],
        'bala' => ['balakrishnan', 'balasubramanian'],
    ];

    /** Honorifics that would otherwise occupy the first-name slot. */
    public const HONORIFIC_RE = '/^(dr|mr|mrs|ms|miss|prof|professor|er|ca|sri|shri|smt)\.?\s+/i';

    /** Post-nominals that are not part of anybody's email address. */
    public const POST_NOMINAL_RE =
        '/[,\s]+(phd|ph\.d|mba|cfa|cpa|ca|pmp|msc|bsc|ba|ma|md|jd|acca|cima|fcca|mcips|itil|aws|csm)\.?$/i';

    /** Legal forms and registry suffixes that never appear in a LinkedIn headline. */
    public const LEGAL_SUFFIX_RE =
        '/\b(pte|pvt|private|ltd|limited|llc|llp|plc|inc|incorporated|corp|corporation|co|company|gmbh|bv|nv|sa|ag|srl|fzco|fzc|fze|dmcc|sdn|bhd|pty|aps|oy|ab|as)\b\.?/i';

    /** Mailbox prefixes worth preferring when a homepage exposes several addresses. */
    public const ROLE_MAILBOX_PREFIXES =
        '/^(info|contact|sales|support|hello|help|admin|enquiry|enquiries|office|mail)@/';

    /** Membership tests want a hash, not a scan of a 60-entry list. */
    public static function has(array $list, string $needle): bool
    {
        static $sets = [];
        $key = md5(serialize($list));
        $sets[$key] ??= array_flip($list);

        return isset($sets[$key][$needle]);
    }
}
