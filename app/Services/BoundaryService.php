<?php

namespace App\Services;

use App\Models\Barangay;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BoundaryService
{
    /**
     * Known naming variants between official PSGC/GIS names and local records.
     */
    private const ALIASES = [
        'kauswagan' => 'kausawagan',
        'baluk' => 'baluc',
        'huyohoy' => 'hoyohoy',
        'matugnaw' => 'matugnao',
        'aquino marcos' => 'aquino',
        'santa maria baga' => 'santa maria',
    ];

    public function importFromUpload(UploadedFile $file): array
    {
        return $this->importFromString($file->getContent());
    }

    /**
     * Import barangay boundaries from a GeoJSON FeatureCollection string.
     *
     * @return array{matched: int, unmatched: array<string>, total: int}
     */
    public function importFromString(string $contents): array
    {
        $json = json_decode($contents, true);

        if (! is_array($json) || ($json['type'] ?? null) !== 'FeatureCollection') {
            throw ValidationException::withMessages([
                'file' => 'The file must be a GeoJSON FeatureCollection.',
            ]);
        }

        $barangays = Barangay::all()->keyBy(fn (Barangay $barangay) => $this->normalizeName($barangay->name));
        $matched = 0;
        $unmatched = [];

        DB::transaction(function () use ($json, &$barangays, &$matched, &$unmatched) {
            foreach ($json['features'] ?? [] as $feature) {
                $rawName = $feature['properties']['ADM4_EN']
                    ?? $feature['properties']['NAME_3']
                    ?? $feature['properties']['name']
                    ?? $feature['properties']['BgyName']
                    ?? null;

                $normalized = $this->normalizeName($rawName);

                /** @var Barangay|null $barangay */
                $barangay = $barangays[$normalized] ?? $this->fuzzyFind($barangays, $normalized);

                if ($normalized === null || ! $barangay) {
                    if ($normalized !== null && $normalized !== '') {
                        $unmatched[] = $normalized;
                    }

                    continue;
                }

                if (in_array($feature['geometry']['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
                    $barangay->update(['boundary' => $feature['geometry']]);
                    $matched++;
                }
            }
        });

        return [
            'total' => count($json['features'] ?? []),
            'matched' => $matched,
            'unmatched' => array_values(array_unique($unmatched)),
        ];
    }

    private function normalizeName(?string $name): ?string
    {
        if (! $name) {
            return null;
        }

        // Numeric PSGC codes carry no usable name — skip them.
        if (preg_match('/^\d+$/', trim($name))) {
            return null;
        }

        $clean = mb_strtolower(trim($name));
        $clean = str_replace(['ñ', 'á', 'é', 'í', 'ó', 'ú'], ['n', 'a', 'e', 'i', 'o', 'u'], $clean);
        $clean = preg_replace('/[^a-z0-9\s]/', ' ', $clean);
        // Drop generic administrative tokens and parenthetical qualifiers
        $clean = preg_replace('/\b(barangay|brgy|pob|poblacion|city hall|marilou annex|market kalubian|st michael|st saint michael|malubog|lower polao|upper polao|marcos|baga|dimalooc|dimaloc oc)\b/u', ' ', (string) $clean);
        $clean = trim((string) preg_replace('/\s+/', ' ', $clean));

        if ($clean === '') {
            // e.g. "Barangay I - City Hall (Poblacion)" collapses to its numeral
            preg_match('/[IVX]+/i', (string) $name, $matches);

            return isset($matches[0]) ? mb_strtolower($matches[0]) : null;
        }

        return self::ALIASES[$clean] ?? $clean;
    }

    /**
     * Fallback: longest-common-prefix matching for spelling variants.
     *
     * @param  Collection<string, Barangay>  $barangays
     */
    private function fuzzyFind($barangays, ?string $needle): ?Barangay
    {
        if (! $needle || mb_strlen($needle) < 5) {
            return null;
        }

        $best = null;
        $bestLength = 0;

        foreach ($barangays as $key => $barangay) {
            $common = $this->commonPrefixLength($needle, $key);
            // Require a meaningful shared prefix and that both names start alike
            if ($common >= max(6, (int) floor(min(mb_strlen($needle), mb_strlen($key)) * 0.7)) && $common > $bestLength) {
                $best = $barangay;
                $bestLength = $common;
            }
        }

        return $best;
    }

    private function commonPrefixLength(string $a, string $b): int
    {
        $length = min(mb_strlen($a), mb_strlen($b));
        $i = 0;

        while ($i < $length && $a[$i] === $b[$i]) {
            $i++;
        }

        return $i;
    }
}
