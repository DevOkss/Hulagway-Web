<?php

use App\Models\Barangay;
use App\Models\Role;
use App\Models\User;
use App\Services\BoundaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function officer(): User
{
    $role = Role::create(['name' => Role::OFFICER, 'label' => 'CAES Officer']);

    return User::factory()->create(['role_id' => $role->id]);
}

test('geojson boundary import matches barangays case-insensitively', function () {
    Barangay::create(['name' => 'Barangay I - City Hall (Poblacion)']);
    Barangay::create(['name' => 'Aquino']);
    Barangay::create(['name' => 'Villaba']);

    $geojson = json_encode([
        'type' => 'FeatureCollection',
        'features' => [
            [
                'type' => 'Feature',
                'properties' => ['NAME_3' => 'BARANGAY I'],
                'geometry' => ['type' => 'Polygon', 'coordinates' => [[[123.7, 8.06], [123.71, 8.06], [123.71, 8.07], [123.7, 8.06]]]],
            ],
            [
                'type' => 'Feature',
                'properties' => ['name' => 'aquino'],
                'geometry' => ['type' => 'Polygon', 'coordinates' => [[[123.72, 8.08], [123.73, 8.08], [123.73, 8.09], [123.72, 8.08]]]],
            ],
            [
                'type' => 'Feature',
                'properties' => ['ADM4_EN' => 'Unknown Place'],
                'geometry' => ['type' => 'Polygon', 'coordinates' => [[[123.74, 8.1], [123.75, 8.1], [123.75, 8.11], [123.74, 8.1]]]],
            ],
        ],
    ]);

    $file = UploadedFile::fake()->createWithContent('boundaries.geojson', $geojson);
    $result = app(BoundaryService::class)->importFromUpload($file);

    expect($result['matched'])->toBe(2)
        ->and($result['total'])->toBe(3)
        ->and($result['unmatched'])->toBe(['unknown place']);

    expect(Barangay::where('name', 'Aquino')->first()->boundary)->not->toBeNull()
        ->and(Barangay::where('name', 'like', '%City Hall%')->first()->boundary)->not->toBeNull();
});

test('boundaries page route has been removed', function () {
    // The /boundaries UI was removed; boundaries are now managed via artisan import
    $this->get('/boundaries')->assertNotFound();
    $this->post('/boundaries', [])->assertNotFound();
});
