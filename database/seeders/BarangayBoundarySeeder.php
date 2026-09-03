<?php

namespace Database\Seeders;

use App\Models\Barangay;
use Illuminate\Database\Seeder;

class BarangayBoundarySeeder extends Seeder
{
    /**
     * GeoJSON files generated from GADM / PSGC boundaries for Tangub City.
     * Stored under storage/app/.
     */
    private const BOUNDARY_FILE = 'tangub-boundaries.geojson';

    /**
     * Map GeoJSON feature name (ADM4_EN) -> exact Barangay name in the DB
     * for the few records whose naming differs.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'Barangay I - City Hall (Pob.)' => 'Barangay I - City Hall (Poblacion)',
        'Barangay II - Marilou Annex (Pob.)' => 'Barangay II - Marilou Annex (Poblacion)',
        'Barangay III- Market Kalubian (Pob.)' => 'Barangay III - Market Kalubian (Poblacion)',
        'Barangay IV - St. Michael (Pob.)' => 'Barangay IV - St. Michael (Poblacion)',
        'Barangay V - Malubog (Pob.)' => 'Barangay V - Malubog (Poblacion)',
        'Barangay VI - Lower Polao (Pob.)' => 'Barangay VI - Lower Polao (Poblacion)',
        'Barangay VII - Upper Polao (Pob.)' => 'Barangay VII - Upper Polao (Poblacion)',
        'Isidro D. Tan (Dimaloc-oc)' => 'Isidro D. Tan (Dimalooc)',
        'Baluk' => 'Baluc',
        'Huyohoy' => 'Hoyohoy',
        'Kauswagan' => 'Kausawagan',
        'Matugnaw' => 'Matugnao',
        'Aquino (Marcos)' => 'Aquino',
    ];

    public function run(): void
    {
        $path = storage_path('app/'.self::BOUNDARY_FILE);
        if (! is_file($path)) {
            $this->command?->warn('Boundary file "'.self::BOUNDARY_FILE.'" not found; skipping.');

            return;
        }

        $geojson = json_decode((string) file_get_contents($path), true);
        if (! $geojson || ! isset($geojson['features'])) {
            $this->command?->warn('Boundary file is not a valid FeatureCollection; skipping.');

            return;
        }

        $db = Barangay::orderBy('id')->get(['id', 'name']);
        $dbByName = [];
        foreach ($db as $barangay) {
            $dbByName[$this->normalize($barangay->name)] = $barangay->id;
        }

        $updated = 0;
        $skipped = [];
        foreach ($geojson['features'] as $feature) {
            $gjName = $feature['properties']['ADM4_EN'] ?? '';
            $targetName = self::ALIASES[$gjName] ?? null;
            $id = $targetName
                ? ($dbByName[$this->normalize($targetName)] ?? null)
                : ($dbByName[$this->normalize($gjName)] ?? null);

            if (! $id) {
                $skipped[] = $gjName;

                continue;
            }

            $geometry = $feature['geometry'] ?? null;
            if (! $geometry || ! isset($geometry['type'], $geometry['coordinates'])) {
                $skipped[] = $gjName.' (no geometry)';

                continue;
            }

            $center = $this->centroid($geometry);
            Barangay::query()->whereKey($id)->update([
                'boundary' => $geometry,
                'latitude' => $center[1] ?? null,
                'longitude' => $center[0] ?? null,
            ]);
            ++$updated;
        }

        $this->command?->info("Updated {$updated} barangay boundaries.");
        if ($skipped) {
            $this->command?->warn('Skipped (no name match): '.implode(', ', $skipped));
        }
    }

    private function normalize(string $name): string
    {
        $name = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9 ]/', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }

    /**
     * Rough centroid from the bounding box of a Polygon / MultiPolygon.
     *
     * @return array{0: float, 1: float}|null [lng, lat]
     */
    private function centroid(array $geometry): ?array
    {
        $points = [];
        $this->flattenCoords($geometry['coordinates'] ?? [], $points);
        if (! $points) {
            return null;
        }

        $lngs = array_map(fn ($p) => (float) $p[0], $points);
        $lats = array_map(fn ($p) => (float) $p[1], $points);

        return [
            (min($lngs) + max($lngs)) / 2,
            (min($lats) + max($lats)) / 2,
        ];
    }

    private function flattenCoords(array $coords, array &$points): void
    {
        foreach ($coords as $coord) {
            if (is_array($coord)) {
                if (count($coord) === 2 && is_numeric($coord[0] ?? null) && is_numeric($coord[1] ?? null)) {
                    $points[] = [$coord[0], $coord[1]];
                } else {
                    $this->flattenCoords($coord, $points);
                }
            }
        }
    }
}
