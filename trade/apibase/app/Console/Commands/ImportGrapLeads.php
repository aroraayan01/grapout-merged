<?php

namespace App\Console\Commands;

use App\Models\Grap\Lead;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Load GrapOut's research into Hot Leads.
 *
 * The research arrives as 28 columns describing a company and up to two
 * people at it, in two files: one of buyers, one of suppliers. Which file
 * a row came from is the only thing that separates them, so `--kind` says
 * which and nothing in the file needs to.
 *
 *   php artisan grap:import buyers.csv --kind=buyer --source=research-2026-09
 *   php artisan grap:import suppliers.csv --kind=supplier --source=research-2026-09
 *
 * A row is matched on (data_source, source_ref), so running the same file
 * twice corrects rows rather than doubling them. `--split` takes one file
 * and halves it, which is only useful for a sample.
 *
 * Headers are matched in both the spellings seen so far — "Company Name"
 * as the export writes it, and `company_name_direct_buyer` as the old
 * database did. Anything unrecognised is ignored rather than refused.
 */
class ImportGrapLeads extends Command
{
    protected $signature = 'grap:import
        {file : CSV or tab-separated export, with a header row}
        {--kind=buyer : buyer or supplier}
        {--source= : what to record as data_source; defaults to the file name}
        {--split= : take only half a file — "first" or "second". For samples.}
        {--truncate : empty this kind first}
        {--dry-run : read and report, write nothing}';

    protected $description = "Load GrapOut's buyer/supplier research into Hot Leads";

    /**
     * Header => column, in every spelling seen.
     *
     * Lower-cased, and the legacy `_direct_buyer` / `_direct_supplier`
     * suffix is stripped before the lookup, so one table serves the
     * export's human headings and the old database's column names without
     * either having to know about the other.
     */
    private const MAP = [
        // The export's headings.
        'id' => 'source_ref',
        'code' => 'dial_code',
        'code 2' => 'dial_code_2',
        'input name' => 'input_name',
        'company name' => 'company_name',
        'company id' => 'company_ref',
        'contact person name' => 'contact_person',
        'designation' => 'designation',
        'mobile number' => 'mobile',
        'phone number' => 'phone',
        'email id' => 'email',
        'email catch all' => 'email_catch_all',
        'website address' => 'website',
        'contact person name-2' => 'contact_person_2',
        'designation-2' => 'designation_2',
        'mobile number-2' => 'mobile_2',
        'phone number-2' => 'phone_2',
        'email id-2' => 'email_2',
        'email 2 catch all' => 'email_2_catch_all',
        'company address' => 'company_address',
        'country name' => 'country',
        'business category' => 'business_category',
        'brief intro of company' => 'brief_intro',
        'company linkedin url' => 'linkedin_url',
        'created by' => 'source_created_by',
        'created on' => 'source_created_at',
        'updated by' => 'source_updated_by',
        'updated on' => 'source_updated_at',

        // The same fields as the old database spelled them.
        'company_name' => 'company_name',
        'company_address' => 'company_address',
        'country_name' => 'country',
        'website_address' => 'website',
        'company_linkedin_url' => 'linkedin_url',
        'brief_intro_of_company' => 'brief_intro',
        'business_category' => 'business_category',
        'contact_person_name' => 'contact_person',
        'email_id' => 'email',
        'email_catch_all' => 'email_catch_all',
        'phone_number' => 'phone',
        'mobile_number' => 'mobile',
        'contact_person_name2' => 'contact_person_2',
        'designation2' => 'designation_2',
        'email_id2' => 'email_2',
        'email2_catch_all' => 'email_2_catch_all',
        'code2' => 'dial_code_2',
        'phone_number2' => 'phone_2',
        'mobile_number2' => 'mobile_2',
        'data_source' => 'data_source',
    ];

    /** Every column this command will write. */
    private const COLUMNS = [
        'source_ref', 'input_name', 'company_name', 'company_ref', 'company_address',
        'country', 'website', 'linkedin_url', 'brief_intro', 'business_category',
        'contact_person', 'designation', 'email', 'email_catch_all', 'dial_code', 'phone', 'mobile',
        'contact_person_2', 'designation_2', 'email_2', 'email_2_catch_all', 'dial_code_2', 'phone_2', 'mobile_2',
        'source_created_by', 'source_created_at', 'source_updated_by', 'source_updated_at',
        'data_source',
    ];

    public function handle(): int
    {
        $kind = $this->option('kind');
        if (! in_array($kind, Lead::KINDS, true)) {
            $this->error('--kind must be one of: ' . implode(', ', Lead::KINDS) . '.');

            return self::FAILURE;
        }

        $split = $this->option('split');
        if ($split !== null && ! in_array($split, ['first', 'second'], true)) {
            $this->error('--split must be "first" or "second".');

            return self::FAILURE;
        }

        $file = $this->argument('file');
        if (! is_readable($file)) {
            $this->error("Cannot read {$file}");

            return self::FAILURE;
        }

        $source = $this->option('source') ?: Str::before(basename($file), '.');
        $dry = (bool) $this->option('dry-run');

        if ($this->option('truncate') && ! $dry) {
            $this->warn("Emptying every {$kind} row first.");
            Lead::where('kind', $kind)->delete();
        }

        $delimiter = $this->sniff($file);
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle, 0, $delimiter, '"', '');
        if (! $header) {
            $this->error('The file has no header row.');

            return self::FAILURE;
        }

        $columns = $this->columnsFrom($header);
        if (! isset($columns['company_name'])) {
            $this->error('No company-name column found. Is this the right file?');
            $this->line('Saw: ' . implode(', ', array_map('trim', $header)));

            return self::FAILURE;
        }

        // Halving needs to know how many there are, which means one pass to
        // count. Only ever used on a sample, so the second read is cheap.
        [$from, $to] = $split === null
            ? [0, PHP_INT_MAX]
            : $this->half($file, $delimiter, $split);

        $read = $written = $skipped = 0;
        $batch = [];

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $index = $read++;
            if ($index < $from || $index >= $to) {
                continue;
            }

            $attributes = $this->rowToAttributes($row, $columns, $kind, $source);
            if (trim((string) $attributes['company_name']) === '') {
                $skipped++;
                continue;
            }
            $batch[] = $attributes;

            if (count($batch) >= 500) {
                $written += $dry ? count($batch) : $this->flush($batch);
                $batch = [];
                $this->output->write('.');
            }
        }
        if ($batch !== []) {
            $written += $dry ? count($batch) : $this->flush($batch);
        }
        fclose($handle);

        $this->newLine();
        $this->info(($dry ? 'Would import ' : 'Imported ') . "{$written} {$kind} rows from {$read} read"
            . ($skipped ? ", {$skipped} skipped with no company name." : '.'));

        return self::SUCCESS;
    }

    /** Which slice of the data rows `--split` asks for. */
    private function half(string $file, string $delimiter, string $which): array
    {
        $handle = fopen($file, 'r');
        fgetcsv($handle, 0, $delimiter, '"', '');
        $rows = 0;
        while (fgetcsv($handle, 0, $delimiter, '"', '') !== false) {
            $rows++;
        }
        fclose($handle);

        $mid = intdiv($rows, 2);

        return $which === 'first' ? [0, $mid] : [$mid, PHP_INT_MAX];
    }

    /** upsert, so re-running a file corrects rows rather than doubling them. */
    private function flush(array $batch): int
    {
        Lead::upsert($batch, ['data_source', 'source_ref']);

        return count($batch);
    }

    /** @return array<string, int> our column => index in the file */
    private function columnsFrom(array $header): array
    {
        $found = [];

        foreach ($header as $i => $raw) {
            $name = strtolower(trim((string) $raw));
            $stripped = str_replace(['_direct_buyer', '_direct_supplier'], '', $name);
            $ours = self::MAP[$name] ?? self::MAP[$stripped] ?? null;

            // A file already written in our own column names works too.
            if (! $ours && in_array($name, self::COLUMNS, true)) {
                $ours = $name;
            }
            if ($ours && ! isset($found[$ours])) {
                $found[$ours] = $i;
            }
        }

        return $found;
    }

    private function rowToAttributes(array $row, array $columns, string $kind, string $source): array
    {
        $get = fn (string $key) => isset($columns[$key])
            ? $this->demojibake(trim((string) ($row[$columns[$key]] ?? '')))
            : null;

        $attributes = ['uuid' => (string) Str::uuid(), 'kind' => $kind, 'created_at' => now(), 'updated_at' => now()];

        foreach (self::COLUMNS as $column) {
            $attributes[$column] = $this->clean($column, $get($column));
        }

        $attributes['data_source'] = $attributes['data_source'] ?: $source;
        $this->splitDialCodes($attributes);

        return $attributes;
    }

    /**
     * Text that has been through UTF-8 twice, put back.
     *
     * The export writes the bytes of real UTF-8 read once as Windows-1252
     * and encoded again, so every accented character arrives as two or
     * three Latin ones. Turkish and Vietnamese company names — a good half
     * of this data — come through unreadable and, worse, unsearchable:
     * nobody types the mangled form.
     *
     * Windows-1252 rather than Latin-1 on purpose. The 0x80-0x9F range is
     * unassigned in Latin-1 and this data uses it: C-cedilla (C3 87) comes
     * through with U+2021 as its second character, which exists only
     * because something read 0x87 as CP1252.
     *
     * Up to three passes: some rows went through the encoding twice, and
     * one pass leaves those still wrong and still unsearchable.
     */
    private function demojibake(string $value): string
    {
        for ($pass = 0; $pass < 3; $pass++) {
            $once = $this->demojibakeOnce($value);
            if ($once === $value) {
                break;
            }
            $value = $once;
        }

        return $value;
    }

    private function demojibakeOnce(string $value): string
    {
        /*
         * The tell: a character that could have been a UTF-8 lead byte
         * (U+00C2-U+00FF) immediately followed by one that could have been
         * a continuation byte (U+0080-U+00BF) or one of the printable
         * characters CP1252 puts in 0x80-0x9F. Real prose does not do this
         * — "Müller" is u-umlaut then an ordinary l — but every mangled
         * character does it by construction.
         */
        $lead = '\x{00C2}-\x{00FF}';
        $tail = '\x{0080}-\x{00BF}\x{0152}\x{0153}\x{0160}\x{0161}\x{0178}\x{017D}\x{017E}'
            . '\x{0192}\x{02C6}\x{02DC}\x{2013}\x{2014}\x{2018}-\x{201E}\x{2020}-\x{2022}'
            . '\x{2026}\x{2030}\x{2039}\x{203A}\x{20AC}\x{2122}';

        if ($value === '' || ! preg_match('/[' . $lead . '][' . $tail . ']/u', $value)) {
            return $value;
        }

        $candidate = @mb_convert_encoding($value, 'Windows-1252', 'UTF-8');

        // A replacement character means the conversion guessed; keep the
        // original rather than trade one kind of unreadable for another.
        return is_string($candidate) && $candidate !== ''
            && mb_check_encoding($candidate, 'UTF-8')
            && ! str_contains($candidate, "\u{FFFD}")
            ? $candidate
            : $value;
    }

    /**
     * `(+90)2324792737` — the dial code travelling inside the number.
     *
     * The export writes it that way and leaves its own Code column empty,
     * so without this the country prefix ends up as part of the digits: a
     * number that cannot be dialled and cannot be matched to a country
     * either. Taken out once per contact, and only used when the Code
     * column has not already said something.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function splitDialCodes(array &$attributes): void
    {
        $pairs = [
            'dial_code' => ['phone', 'mobile'],
            'dial_code_2' => ['phone_2', 'mobile_2'],
        ];

        foreach ($pairs as $codeKey => $numberKeys) {
            foreach ($numberKeys as $key) {
                $value = (string) ($attributes[$key] ?? '');
                if ($value === '' || ! preg_match('/^\(\+(\d{1,4})\)\s*(.+)$/', $value, $m)) {
                    continue;
                }
                $attributes[$key] = trim($m[2]) ?: null;
                $attributes[$codeKey] = $attributes[$codeKey] ?: $m[1];
            }
        }
    }

    /** The few columns the export keeps as text but the table does not. */
    private function clean(string $column, ?string $value): mixed
    {
        $value = $value === null ? null : trim($value);
        if ($value === '' || $value === null || strcasecmp($value, 'NULL') === 0) {
            return in_array($column, ['email_catch_all', 'email_2_catch_all'], true) ? false : null;
        }

        return match (true) {
            in_array($column, ['email_catch_all', 'email_2_catch_all'], true)
                => in_array(strtolower($value), ['1', 'yes', 'true', 'catch_all', 'catchall'], true),

            in_array($column, ['source_created_at', 'source_updated_at'], true)
                // "01/01/1970 01:00" is how the export writes "never", and
                // strtotime reads it happily as a real moment.
                => str_starts_with($value, '01/01/1970') ? null : $this->dateTime($value),

            // A company id of 0 is the export saying it has not got one.
            $column === 'company_ref' => $value === '0' ? null : $value,

            default => $value,
        };
    }

    /** Day first: the export writes 01/02/2026 meaning the 1st of February. */
    private function dateTime(string $value): ?string
    {
        $parsed = date_create_from_format('d/m/Y H:i', $value) ?: date_create($value);

        return $parsed ? $parsed->format('Y-m-d H:i:s') : null;
    }

    /** Tab or comma, decided by whichever the header has more of. */
    private function sniff(string $file): string
    {
        $line = (string) fgets(fopen($file, 'r'));

        return substr_count($line, "\t") > substr_count($line, ',') ? "\t" : ',';
    }
}
