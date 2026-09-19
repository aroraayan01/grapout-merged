<?php

namespace App\Support;

/**
 * A sheet of contacts, whatever shape it came in.
 *
 * People keep their buyer lists in every layout there is: with a header
 * row or without, "Company" or "Firm", "Contact Person" or just "Name",
 * the email in the third column or the first. This reads the headers it
 * can recognise, and where there are none it reads the cells themselves —
 * an address is an address, a run of digits is a phone, the rest is the
 * company and the person. A missing name is taken from the address.
 */
final class ContactSheet
{
    private const HEADERS = [
        'company_name' => ['company', 'company name', 'companyname', 'organisation', 'organization', 'firm', 'business', 'business name', 'supplier', 'buyer', 'exporter', 'importer', 'vendor', 'client'],
        'contact_name' => ['contact', 'contact person', 'contact name', 'contactperson', 'person', 'name', 'full name', 'owner', 'representative', 'attn'],
        'email' => ['email', 'e-mail', 'mail', 'email id', 'emailid', 'email address', 'e mail'],
        'mobile' => ['mobile', 'mobile no', 'mobile number', 'cell', 'cellphone', 'whatsapp', 'whats app', 'cell phone'],
        'phone' => ['phone', 'phone no', 'phone number', 'telephone', 'tel', 'landline', 'office phone', 'contact no', 'contact number'],
        'country' => ['country', 'nation', 'country code'],
        'notes' => ['notes', 'note', 'remarks', 'remark', 'comment', 'comments', 'products', 'product', 'category'],
    ];

    /**
     * @return array{rows: list<array<string, ?string>>, mapping: array<string, ?int>, skipped: list<array{row: int, reason: string}>, header: bool}
     */
    public static function parse(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
        if ($lines === []) {
            return ['rows' => [], 'mapping' => [], 'skipped' => [], 'header' => false];
        }
        $delimiter = self::delimiter($lines[0]);
        $cells = array_map(fn ($l) => array_map('trim', str_getcsv($l, $delimiter)), $lines);

        $mapping = self::mapHeader($cells[0]);
        $hasHeader = isset($mapping['email']) || count(array_filter($mapping)) >= 2;
        $body = $hasHeader ? array_slice($cells, 1) : $cells;
        $offset = $hasHeader ? 2 : 1;

        $rows = [];
        $skipped = [];
        $seen = [];
        foreach ($body as $i => $row) {
            $rec = $hasHeader ? self::fromMapped($row, $mapping) : self::fromCells($row);
            $email = mb_strtolower(trim((string) ($rec['email'] ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = ['row' => $i + $offset, 'reason' => $email === '' ? 'no email address' : "not an email address: {$email}"];
                continue;
            }
            if (isset($seen[$email])) {
                $skipped[] = ['row' => $i + $offset, 'reason' => "{$email} appears twice; the first is kept"];
                continue;
            }
            $seen[$email] = true;
            $rec['email'] = $email;
            $rec['contact_name'] = self::clean($rec['contact_name'] ?? null, 120) ?: self::nameFromEmail($email);
            $rec['company_name'] = self::clean($rec['company_name'] ?? null, 160);
            $rec['mobile'] = self::clean($rec['mobile'] ?? null, 40);
            $rec['phone'] = self::clean($rec['phone'] ?? null, 40);
            $rec['country'] = self::country($rec['country'] ?? null);
            $rec['notes'] = self::clean($rec['notes'] ?? null, 500);
            $rows[] = $rec;
        }

        return ['rows' => $rows, 'mapping' => $mapping, 'skipped' => $skipped, 'header' => $hasHeader];
    }

    private static function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t"), '|' => substr_count($line, '|')];
        arsort($counts);

        return array_key_first($counts) ?: ',';
    }

    /** Which column holds what, read from the header row. Null where nothing matched. */
    private static function mapHeader(array $header): array
    {
        $map = array_fill_keys(array_keys(self::HEADERS), null);
        foreach ($header as $i => $cell) {
            $key = mb_strtolower(trim(preg_replace('/[^a-zA-Z ]/', ' ', (string) $cell)));
            $key = preg_replace('/\s+/', ' ', $key);
            if ($key === '') {
                continue;
            }
            foreach (self::HEADERS as $field => $names) {
                if ($map[$field] === null && in_array($key, $names, true)) {
                    $map[$field] = $i;
                    continue 2;
                }
            }
        }

        return $map;
    }

    private static function fromMapped(array $row, array $mapping): array
    {
        $rec = [];
        foreach ($mapping as $field => $i) {
            $rec[$field] = $i !== null ? ($row[$i] ?? null) : null;
        }
        // No email column named? The address may still be somewhere on the row.
        if (! $rec['email']) {
            $rec['email'] = self::firstEmail($row);
        }

        return $rec;
    }

    /** No header: the cells say what they are. */
    private static function fromCells(array $row): array
    {
        $rec = ['email' => null, 'company_name' => null, 'contact_name' => null, 'mobile' => null, 'phone' => null, 'country' => null, 'notes' => null];
        $texts = [];
        $numbers = [];
        foreach ($row as $cell) {
            $cell = trim((string) $cell);
            if ($cell === '') {
                continue;
            }
            if (! $rec['email'] && filter_var($cell, FILTER_VALIDATE_EMAIL)) {
                $rec['email'] = $cell;
            } elseif (preg_match('/^[+\d][\d\s().-]{6,}$/', $cell)) {
                $numbers[] = $cell;
            } elseif (! $rec['country'] && preg_match('/^[A-Za-z]{2}$/', $cell)) {
                $rec['country'] = $cell;
            } else {
                $texts[] = $cell;
            }
        }
        // Two names: the longer, or the one with a company word, is the company.
        if (count($texts) >= 2) {
            $companyish = fn ($t) => (bool) preg_match('/\b(ltd|llc|inc|gmbh|pvt|co|corp|company|industries|exports|imports|trading|enterprises|international|limited|group|sons|bros)\b/i', $t);
            [$a, $b] = [$texts[0], $texts[1]];
            if ($companyish($b) && ! $companyish($a)) {
                [$a, $b] = [$b, $a];
            }
            $rec['company_name'] = $a;
            $rec['contact_name'] = $b;
            if (count($texts) > 2) {
                $rec['notes'] = implode(' · ', array_slice($texts, 2));
            }
        } elseif (count($texts) === 1) {
            $rec['company_name'] = $texts[0];
        }
        $rec['mobile'] = $numbers[0] ?? null;
        $rec['phone'] = $numbers[1] ?? null;

        return $rec;
    }

    private static function firstEmail(array $row): ?string
    {
        foreach ($row as $cell) {
            $cell = trim((string) $cell);
            if (filter_var($cell, FILTER_VALIDATE_EMAIL)) {
                return $cell;
            }
        }

        return null;
    }

    /** "rahul.sharma@x.com" reads as "Rahul Sharma"; "info@" reads as nothing. */
    public static function nameFromEmail(string $email): ?string
    {
        $local = explode('@', $email)[0];
        if (preg_match('/^(info|sales|export|exports|import|imports|contact|admin|office|hello|support|enquiry|inquiry|mail|marketing|purchase|purchasing|accounts|hr)\d*$/i', $local)) {
            return null;
        }
        $words = preg_split('/[._\-]+/', preg_replace('/\d+/', '', $local)) ?: [];
        $words = array_values(array_filter(array_map(fn ($w) => ucfirst(mb_strtolower($w)), $words), fn ($w) => mb_strlen($w) >= 2));

        return $words ? mb_substr(implode(' ', array_slice($words, 0, 3)), 0, 120) : null;
    }

    private static function clean(?string $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function country(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z]{2}$/', $v)) {
            return strtoupper($v);
        }
        $names = ['india' => 'IN', 'united states' => 'US', 'usa' => 'US', 'united kingdom' => 'GB', 'uk' => 'GB', 'germany' => 'DE', 'france' => 'FR', 'italy' => 'IT', 'spain' => 'ES', 'netherlands' => 'NL', 'uae' => 'AE', 'united arab emirates' => 'AE', 'saudi arabia' => 'SA', 'china' => 'CN', 'japan' => 'JP', 'australia' => 'AU', 'canada' => 'CA', 'singapore' => 'SG', 'malaysia' => 'MY', 'indonesia' => 'ID', 'vietnam' => 'VN', 'thailand' => 'TH', 'bangladesh' => 'BD', 'sri lanka' => 'LK', 'nepal' => 'NP', 'pakistan' => 'PK', 'south africa' => 'ZA', 'brazil' => 'BR', 'mexico' => 'MX', 'turkey' => 'TR', 'egypt' => 'EG', 'kenya' => 'KE', 'nigeria' => 'NG', 'russia' => 'RU', 'korea' => 'KR', 'south korea' => 'KR', 'qatar' => 'QA', 'oman' => 'OM', 'kuwait' => 'KW', 'bahrain' => 'BH'];

        return $names[mb_strtolower($v)] ?? null;
    }
}
