<?php

namespace App\Services\GrapUp\Domain;

/**
 * Country name -> ISO 3166-1 alpha-2, for Serper's `gl` geo parameter.
 *
 * Passing `gl` changes result ranking, and for this pipeline that matters:
 * with it set, a company's own website tends to outrank the directory
 * listings that would otherwise be picked as its domain.
 *
 * Aliases are included because the input is typed by hand, or arrives in a
 * spreadsheet column filled in by somebody else. An unrecognised country sends
 * no `gl` at all rather than a wrong one — no geo-targeting beats the wrong
 * country's results.
 *
 * Ported from GrapUp's `domain/countries.ts`.
 */
final class Countries
{
    private const CODES = [
        'india' => 'in',
        'bharat' => 'in',
        'singapore' => 'sg',
        'united states' => 'us',
        'usa' => 'us',
        'us' => 'us',
        'america' => 'us',
        'united states of america' => 'us',
        'united kingdom' => 'gb',
        'uk' => 'gb',
        'britain' => 'gb',
        'great britain' => 'gb',
        'england' => 'gb',
        'scotland' => 'gb',
        'wales' => 'gb',
        'northern ireland' => 'gb',
        'united arab emirates' => 'ae',
        'uae' => 'ae',
        'dubai' => 'ae',
        'abu dhabi' => 'ae',
        'sharjah' => 'ae',
        'australia' => 'au',
        'canada' => 'ca',
        'new zealand' => 'nz',
        'ireland' => 'ie',
        'germany' => 'de',
        'deutschland' => 'de',
        'france' => 'fr',
        'spain' => 'es',
        'italy' => 'it',
        'netherlands' => 'nl',
        'holland' => 'nl',
        'belgium' => 'be',
        'switzerland' => 'ch',
        'austria' => 'at',
        'portugal' => 'pt',
        'greece' => 'gr',
        'luxembourg' => 'lu',
        'sweden' => 'se',
        'norway' => 'no',
        'denmark' => 'dk',
        'finland' => 'fi',
        'iceland' => 'is',
        'poland' => 'pl',
        'romania' => 'ro',
        'hungary' => 'hu',
        'bulgaria' => 'bg',
        'croatia' => 'hr',
        'turkey' => 'tr',
        'turkiye' => 'tr',
        'russia' => 'ru',
        'ukraine' => 'ua',
        'czechia' => 'cz',
        'czech republic' => 'cz',
        'slovakia' => 'sk',
        'slovenia' => 'si',
        'china' => 'cn',
        'hong kong' => 'hk',
        'hongkong' => 'hk',
        'macau' => 'mo',
        'taiwan' => 'tw',
        'japan' => 'jp',
        'south korea' => 'kr',
        'korea' => 'kr',
        'malaysia' => 'my',
        'indonesia' => 'id',
        'thailand' => 'th',
        'vietnam' => 'vn',
        'viet nam' => 'vn',
        'philippines' => 'ph',
        'cambodia' => 'kh',
        'laos' => 'la',
        'brunei' => 'bn',
        'bangladesh' => 'bd',
        'sri lanka' => 'lk',
        'pakistan' => 'pk',
        'nepal' => 'np',
        'myanmar' => 'mm',
        'bhutan' => 'bt',
        'maldives' => 'mv',
        'afghanistan' => 'af',
        'saudi arabia' => 'sa',
        'ksa' => 'sa',
        'qatar' => 'qa',
        'kuwait' => 'kw',
        'oman' => 'om',
        'bahrain' => 'bh',
        'israel' => 'il',
        'jordan' => 'jo',
        'lebanon' => 'lb',
        'iraq' => 'iq',
        'iran' => 'ir',
        'egypt' => 'eg',
        'morocco' => 'ma',
        'tunisia' => 'tn',
        'algeria' => 'dz',
        'south africa' => 'za',
        'nigeria' => 'ng',
        'kenya' => 'ke',
        'ghana' => 'gh',
        'tanzania' => 'tz',
        'uganda' => 'ug',
        'ethiopia' => 'et',
        'zimbabwe' => 'zw',
        'zambia' => 'zm',
        'mauritius' => 'mu',
        'brazil' => 'br',
        'mexico' => 'mx',
        'argentina' => 'ar',
        'chile' => 'cl',
        'colombia' => 'co',
        'peru' => 'pe',
        'ecuador' => 'ec',
        'uruguay' => 'uy',
        'venezuela' => 've',
        'panama' => 'pa',
        'costa rica' => 'cr',
        'dominican republic' => 'do',
    ];

    /** The ISO code for a country as somebody wrote it, or null. */
    public static function code(?string $country): ?string
    {
        $value = mb_strtolower(trim($country ?? ''));
        if ($value === '') {
            return null;
        }

        // Already a code.
        if (preg_match('/^[a-z]{2}$/', $value) === 1) {
            return $value;
        }

        return self::CODES[$value] ?? null;
    }
}
