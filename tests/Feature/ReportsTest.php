<?php

use App\Models\Barangay;
use App\Models\ExtensionActivity;
use App\Models\Role;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function officerUser(): User
{
    $role = Role::create(['name' => Role::OFFICER, 'label' => 'CAES Officer']);

    return User::factory()->create(['role_id' => $role->id]);
}

test('activities report exports as xlsx and pdf', function (string $format) {
    Barangay::create(['name' => 'Test Brgy']);
    $officer = officerUser();

    ExtensionActivity::create([
        'title' => 'Livelihood Training',
        'barangay_id' => 1,
        'created_by' => $officer->id,
        'location' => 'Barangay Hall',
        'start_date' => now()->toDateString(),
        'status' => ExtensionActivity::STATUS_ONGOING,
        'progress' => 40,
        'beneficiaries' => 50,
    ]);

    $response = $this->actingAs($officer)->get("/reports/activities/{$format}");

    expect($response->getStatusCode())->toBe(200);
})->with(['xlsx', 'pdf']);

test('survey responses report exports as xlsx and pdf', function (string $format) {
    Barangay::create(['name' => 'Test Brgy']);
    $creator = User::factory()->create();
    $officer = officerUser();

    $survey = Survey::create([
        'title' => 'Needs Assessment',
        'status' => Survey::STATUS_PUBLISHED,
        'public_token' => bin2hex(random_bytes(16)),
        'created_by' => $creator->id,
        'published_at' => now(),
    ]);

    $response = $this->actingAs($officer)->get("/reports/surveys/{$survey->id}/{$format}");

    expect($response->getStatusCode())->toBe(200);
})->with(['xlsx', 'pdf']);
