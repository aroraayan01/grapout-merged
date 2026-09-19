<?php

namespace App\Console\Commands;

use App\Models\HsCode;
use Illuminate\Console\Command;

/**
 * Load the HS nomenclature from a CSV.
 *
 * Two columns are enough: code, description. A header row is skipped
 * when its first cell is not numeric. Codes keep their digits only;
 * level and parent are worked out from the length, so a 6-digit line
 * knows its 4-digit heading and 2-digit chapter.
 *
 *   php artisan trade:import-hs storage/app/hs.csv
 */
class ImportHsCodes extends Command
{
    protected $signature = 'trade:import-hs {file : CSV with code,description} {--truncate : empty the table first}';

    protected $description = 'Load HS codes and descriptions from a CSV file';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_readable($file)) {
            $this->error("Cannot read {$file}");

            return self::FAILURE;
        }
        if ($this->option('truncate')) {
            HsCode::query()->delete();
        }

        $handle = fopen($file, 'r');
        $n = 0;
        $batch = [];
        while (($row = fgetcsv($handle)) !== false) {
            $code = preg_replace('/\D+/', '', (string) ($row[0] ?? ''));
            $description = trim((string) ($row[1] ?? ''));
            if ($code === '' || $description === '') {
                continue;
            }
            $len = strlen($code);
            $batch[] = [
                'code' => $code,
                'description' => $description,
                'level' => $len <= 2 ? 2 : ($len <= 4 ? 4 : ($len <= 6 ? 6 : 8)),
                'parent' => $len > 2 ? substr($code, 0, $len <= 4 ? 2 : ($len <= 6 ? 4 : 6)) : null,
                'created_at' => now(), 'updated_at' => now(),
            ];
            if (count($batch) >= 500) {
                HsCode::upsert($batch, ['code'], ['description', 'level', 'parent', 'updated_at']);
                $n += count($batch);
                $batch = [];
            }
        }
        if ($batch) {
            HsCode::upsert($batch, ['code'], ['description', 'level', 'parent', 'updated_at']);
            $n += count($batch);
        }
        fclose($handle);
        $this->info("Loaded {$n} codes.");

        return self::SUCCESS;
    }
}
