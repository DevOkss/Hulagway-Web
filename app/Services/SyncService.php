<?php

namespace App\Services;

use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\SyncLog;

class SyncService
{
    public function __construct(private readonly SurveyResponseService $responses) {}

    /**
     * Batch-sync offline responses. Idempotent per record via its client UUID.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    public function sync(array $records, int $userId, ?string $deviceInfo = null): array
    {
        $results = [
            'accepted' => [],
            'duplicates' => [],
            'failed' => [],
        ];

        foreach ($records as $record) {
            $uuid = $record['uuid'] ?? null;

            if (! $uuid) {
                $results['failed'][] = ['uuid' => null, 'error' => 'Missing uuid.'];

                continue;
            }

            try {
                // Idempotency: skip records already synchronized
                $existing = SurveyResponse::where('uuid', $uuid)->first();
                if ($existing) {
                    $results['duplicates'][] = $uuid;

                    continue;
                }

                $survey = Survey::where('status', Survey::STATUS_PUBLISHED)
                    ->find($record['survey_id']);

                if (! $survey) {
                    $results['failed'][] = ['uuid' => $uuid, 'error' => 'Survey not found or not published.'];

                    continue;
                }

                $this->responses->store($survey, [
                    'uuid' => $uuid,
                    'barangay_id' => $record['barangay_id'] ?? null,
                    'purok' => $record['purok'] ?? $record['household']['purok'] ?? null,
                    'household' => $record['household'] ?? null,
                    'household_members' => $record['household_members'] ?? $record['members'] ?? [],
                    'user_id' => $userId,
                    'respondent_data' => $record['respondent_data'] ?? null,
                    'latitude' => $record['latitude'] ?? null,
                    'longitude' => $record['longitude'] ?? null,
                    'source' => SurveyResponse::SOURCE_MOBILE,
                    'submitted_at' => $record['submitted_at'] ?? now(),
                    'answers' => $record['answers'] ?? [],
                    'member_answers' => $record['member_answers'] ?? [],
                ]);

                $results['accepted'][] = $uuid;
            } catch (\Throwable $e) {
                $results['failed'][] = ['uuid' => $uuid, 'error' => $e->getMessage()];
            }
        }

        SyncLog::create([
            'user_id' => $userId,
            'records_received' => count($records),
            'records_accepted' => count($results['accepted']),
            'duplicates' => count($results['duplicates']),
            'errors' => $results['failed'] ?: null,
            'device_info' => $deviceInfo,
            'synced_at' => now(),
        ]);

        return [
            'status' => $results['failed'] === [] ? 'success' : 'partial',
            'accepted' => $results['accepted'],
            'duplicates' => $results['duplicates'],
            'failed' => $results['failed'],
        ];
    }
}
