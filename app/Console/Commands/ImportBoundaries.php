<?php

namespace App\Console\Commands;

use App\Services\BoundaryService;
use Illuminate\Console\Command;

class ImportBoundaries extends Command
{
    protected $signature = 'hulagway:import-boundaries {file : Path to a GeoJSON FeatureCollection of barangay boundaries}';

    protected $description = 'Import barangay boundary polygons from a GeoJSON file';

    public function handle(BoundaryService $boundaries): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $this->info("Importing boundaries from {$path}…");

        $result = $boundaries->importFromString((string) file_get_contents($path));

        $this->info("Matched {$result['matched']} of {$result['total']} features.");

        if ($result['unmatched'] !== []) {
            $this->warn('Unmatched features:');
            foreach ($result['unmatched'] as $name) {
                $this->line("  - {$name}");
            }
        }

        return self::SUCCESS;
    }
}
