<?php

use App\Models\Barangay;
use App\Models\Role;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function fieldUser(): User
{
    $role = Role::create(['name' => Role::FIELD_PERSONNEL, 'label' => 'Field Extension Personnel']);

    return User::factory()->create(['role_id' => $role->id]);
}

function publishedSurvey(): Survey
{
    $barangay = Barangay::create(['name' => 'Test Barangay']);

    $survey = Survey::create([
        'title' => 'Community Needs Survey',
        'status' => Survey::STATUS_PUBLISHED,
        'public_token' => bin2hex(random_bytes(16)),
        'barangay_id' => $barangay->id,
        'created_by' => User::factory()->create()->id,
        'published_at' => now(),
    ]);

    $survey->questions()->createMany([
        [
            'question_text' => 'Full name',
            'type' => SurveyQuestion::TYPE_TEXT,
            'is_required' => true,
            'order' => 0,
        ],
        [
            'question_text' => 'Top concern',
            'type' => SurveyQuestion::TYPE_SINGLE_CHOICE,
            'is_required' => false,
            'order' => 1,
        ],
    ]);

    return $survey;
}

test('mobile sync accepts a batch and is idempotent on retry', function () {
    $user = fieldUser();
    $survey = publishedSurvey();
    $barangay = Barangay::first();
    $questionIds = $survey->questions->pluck('id');
    $uuid = (string) Str::uuid();

    $payload = [
        'device_info' => 'test-device',
        'responses' => [
            [
                'uuid' => $uuid,
                'survey_id' => $survey->id,
                'barangay_id' => $barangay->id,
                'purok' => 'Purok 1',
                'household' => ['head_name' => 'Juan Dela Cruz', 'purok' => 'Purok 1'],
                'household_members' => [['name' => 'Juan Dela Cruz', 'age' => 30, 'sex' => 'Male', 'is_head' => true]],
                'latitude' => 8.0639,
                'longitude' => 123.7458,
                'submitted_at' => now()->toIso8601String(),
                'answers' => [
                    $questionIds[0] => 'Juan Dela Cruz',
                    $questionIds[1] => 'Livelihood',
                ],
            ],
        ],
    ];

    // First sync: accepted
    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/mobile/sync', $payload);

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonCount(1, 'accepted');

    expect(SurveyResponse::where('uuid', $uuid)->count())->toBe(1)
        ->and($response->json('duplicates'))->toBe([]);

    // Second sync with the same uuid: duplicate detected, no new row
    $retry = $this->actingAs($user, 'sanctum')
        ->postJson('/api/mobile/sync', $payload);

    $retry->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonCount(1, 'duplicates')
        ->assertJsonCount(0, 'accepted');

    expect(SurveyResponse::count())->toBe(1);
});

test('mobile sync rejects unauthenticated requests', function () {
    $this->postJson('/api/mobile/sync', ['responses' => []])->assertUnauthorized();
});

test('mobile sync reports failures for unknown surveys without aborting the batch', function () {
    $user = fieldUser();
    $uuid = (string) Str::uuid();

    $barangay = Barangay::create(['name' => 'Test Barangay 2']);
    $response = $this->actingAs($user, 'sanctum')->postJson('/api/mobile/sync', [
        'responses' => [[
            'uuid' => $uuid,
            'survey_id' => 999999,
            'barangay_id' => $barangay->id,
            'purok' => 'Purok 1',
            'household' => ['head_name' => 'Test', 'purok' => 'Purok 1'],
            'household_members' => [['name' => 'Test', 'is_head' => true]],
            'answers' => [],
        ]],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'partial')
        ->assertJsonCount(1, 'failed');
});
