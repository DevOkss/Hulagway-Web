<?php

namespace App\Services;

use App\Models\ExtensionActivity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExtensionActivityService
{
    public const STATUSES = [
        ExtensionActivity::STATUS_PLANNED,
        ExtensionActivity::STATUS_ONGOING,
        ExtensionActivity::STATUS_COMPLETED,
        ExtensionActivity::STATUS_CANCELLED,
    ];

    /**
     * Determine status automatically from the fragmented activity days.
     * - today < first day => planned
     * - first day <= today <= last day => ongoing
     * - today > last day => completed
     * - cancelled stays as manual override unless explicitly changed
     *
     * @param  iterable<int, mixed>  $dates  Y-m-d strings or Carbon instances
     */
    private function determineStatus($dates, ?string $requestedStatus = null, ?string $currentStatus = null): string
    {
        if ($requestedStatus === ExtensionActivity::STATUS_CANCELLED) {
            return ExtensionActivity::STATUS_CANCELLED;
        }
        if ($currentStatus === ExtensionActivity::STATUS_CANCELLED && $requestedStatus === null) {
            return ExtensionActivity::STATUS_CANCELLED;
        }
        if ($requestedStatus !== null && in_array($requestedStatus, self::STATUSES, true)) {
            return $requestedStatus;
        }

        $parsed = collect($dates)
            ->filter()
            ->map(fn ($d) => Carbon::parse($d))
            ->sort()
            ->values();

        if ($parsed->isEmpty()) {
            return ExtensionActivity::STATUS_PLANNED;
        }

        $today = Carbon::today();

        if ($today->lt($parsed->first())) {
            return ExtensionActivity::STATUS_PLANNED;
        }
        if ($today->gt($parsed->last())) {
            return ExtensionActivity::STATUS_COMPLETED;
        }

        return ExtensionActivity::STATUS_ONGOING;
    }

    /**
     * Determine progress automatically from fragmented days + uploads.
     * progress = (distinct activity days that have at least one document) / total_days * 100
     * - Progress only advances when there is an upload/update for that day's event date
     * - cancelled => keep existing progress
     */
    private function determineProgress(ExtensionActivity $activity, ?string $status = null, ?int $currentProgress = null): int
    {
        if ($status === ExtensionActivity::STATUS_CANCELLED && $currentProgress !== null) {
            return $currentProgress;
        }

        $days = $activity->days()->pluck('activity_date')
            ->filter()
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->sort()
            ->values();

        if ($days->isEmpty()) {
            return 0;
        }

        $total = $days->count();

        $uploadedDays = $activity->documents()->pluck('activity_date')
            ->filter()
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->filter(fn (string $d) => $days->contains($d))
            ->count();

        return (int) round($uploadedDays / $total * 100);
    }

    /**
     * Refresh stored status/progress if they are stale relative to days/uploads.
     * Called on read to keep progress automatic without requiring an update.
     * Status is date-based; progress is upload-based (uploaded days / total).
     */
    public function refreshIfStale(ExtensionActivity $activity): void
    {
        $dates = $activity->days()->pluck('activity_date');
        if ($dates->isEmpty()) {
            return;
        }
        $expectedStatus = $this->determineStatus($dates, null, $activity->status);
        $expectedProgress = $this->determineProgress($activity, $expectedStatus, $activity->progress);

        if ($expectedStatus !== $activity->status || $expectedProgress !== (int) $activity->progress) {
            $activity->update([
                'status' => $expectedStatus,
                'progress' => $expectedProgress,
            ]);
        }
    }

    /**
     * Sync the fragmented activity days (Day 1..N each with its own date)
     * and keep start_date / end_date derived as min/max for reports & timelines.
     *
     * @param  array<int, string>|null  $days  null = derive from existing rows only
     */
    public function syncDays(ExtensionActivity $activity, ?array $days): void
    {
        if ($days === null) {
            $existing = $activity->days()->pluck('activity_date');
            if ($existing->isNotEmpty()) {
                $activity->update([
                    'start_date' => $existing->min(),
                    'end_date' => $existing->max(),
                ]);
            }

            return;
        }

        $clean = collect($days)
            ->filter(fn ($d) => $d !== null && $d !== '')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->sort()
            ->unique()
            ->values();

        abort_if($clean->isEmpty(), 422, 'Select at least one activity date.');

        $activity->days()->delete();
        foreach ($clean as $index => $date) {
            $activity->days()->create([
                'day_number' => $index + 1,
                'activity_date' => $date,
            ]);
        }

        $activity->update([
            'start_date' => $clean->first(),
            'end_date' => $clean->last(),
        ]);
    }

    public function syncSdgs(ExtensionActivity $activity, ?array $sdgIds): void
    {
        if ($sdgIds === null) {
            return;
        }
        $clean = collect($sdgIds)->map(fn ($v) => (int) $v)->unique()->values()->all();
        $activity->sdgs()->sync($clean);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $documents
     * @param  array<string, mixed>  $documentMeta  ['activity_date' => ?, 'progress' => ?, 'caption' => ?]
     */
    public function create(array $data, int $userId, array $documents = [], array $documentMeta = [], ?array $days = null): ExtensionActivity
    {
        // status/progress are automatic from days — do not force planned here
        unset($data['status'], $data['progress']);

        // Normalize empty-string FKs (Inertia sends "" for "City-wide" / unselected)
        if (($data['barangay_id'] ?? null) === '') {
            $data['barangay_id'] = null;
        }
        if (($data['program_id'] ?? null) === '') {
            $data['program_id'] = null;
        }

        $sdgIds = $data['sdg_ids'] ?? null;
        unset($data['sdg_ids'], $data['sdgs']);

        // Derive start/end from fragmented days BEFORE first insert
        // (`extension_activities.start_date` is NOT NULL in schema)
        if ($days !== null) {
            $clean = collect($days)
                ->filter(fn ($d) => $d !== null && $d !== '')
                ->map(fn ($d) => Carbon::parse($d)->toDateString())
                ->sort()
                ->unique()
                ->values();
            abort_if($clean->isEmpty(), 422, 'Select at least one activity date.');
            $data['start_date'] = $clean->first();
            $data['end_date'] = $clean->last();
        }

        return DB::transaction(function () use ($data, $userId, $documents, $documentMeta, $days, $sdgIds) {
            $activity = new ExtensionActivity($data + ['created_by' => $userId]);
            $activity->save();

            // Fragmented days first so start/end + status derive from them
            $this->syncDays($activity, $days);

            $dates = $activity->days()->pluck('activity_date');
            $status = $this->determineStatus($dates);
            $activity->update([
                'status' => $status,
                'progress' => $this->determineProgress($activity, $status),
            ]);

            foreach ($documents as $file) {
                $this->storeDocument($activity, $file, $documentMeta);
            }

            // Progress only advances with uploads — recalc after documents are stored
            if (! empty($documents)) {
                $activity->update([
                    'progress' => $this->determineProgress($activity->fresh(), $activity->status, $activity->progress),
                ]);
            }

            if ($sdgIds !== null) {
                $this->syncSdgs($activity, $sdgIds);
            }

            return $activity->load(['documents', 'sdgs']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $documents
     * @param  array<string, mixed>  $documentMeta
     */
    public function update(ExtensionActivity $activity, array $data, array $documents = [], array $documentMeta = [], ?array $days = null): ExtensionActivity
    {
        DB::transaction(function () use ($activity, $data, $documents, $documentMeta, $days) {
            if (($data['barangay_id'] ?? null) === '') {
                $data['barangay_id'] = null;
            }
            if (($data['program_id'] ?? null) === '') {
                $data['program_id'] = null;
            }
            unset($data['start_date'], $data['end_date']);

            $requestedStatus = $data['status'] ?? null;

            // Replace fragmented days when supplied (also syncs start/end)
            if ($days !== null) {
                $this->syncDays($activity, $days);
            }

            // Status is date-based; progress is upload-based (uploaded days / total)
            $dates = $activity->days()->pluck('activity_date');
            $data['status'] = $this->determineStatus($dates, $requestedStatus, $activity->status);
            $data['progress'] = $this->determineProgress($activity, $data['status'], $activity->progress);

            $sdgIds = $data['sdg_ids'] ?? null;
            unset($data['document_activity_date'], $data['document_progress'], $data['document_caption'], $data['sdg_ids'], $data['sdgs']);

            $activity->update($data);

            if ($sdgIds !== null) {
                $this->syncSdgs($activity, $sdgIds);
            }

            foreach ($documents as $file) {
                $this->storeDocument($activity, $file, $documentMeta);
            }

            if (! empty($documents)) {
                $activity->update([
                    'progress' => $this->determineProgress($activity->fresh(), $activity->status, $activity->progress),
                ]);
            }
        });

        return $activity->load(['documents', 'sdgs']);
    }

    public function deleteDocument(ExtensionActivity $activity, int $documentId): void
    {
        $document = $activity->documents()->findOrFail($documentId);

        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        // Progress only counts days with uploads — recalc after delete
        $activity->update([
            'progress' => $this->determineProgress($activity->fresh(), $activity->status, $activity->progress),
        ]);
    }

    private function storeDocument(ExtensionActivity $activity, $file, array $meta = []): void
    {
        $path = $file->store("activities/{$activity->id}", 'public');

        // Initial documents (uploaded during creation) have no activity_date and should NOT count toward progress.
        // Daily uploads always provide document_activity_date (today's event date) and will be counted.
        $activityDate = $meta['activity_date'] ?? $meta['document_activity_date'] ?? null;
        // Treat empty string as null (no daily date)
        if ($activityDate === '') {
            $activityDate = null;
        }

        $activity->documents()->create([
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'activity_date' => $activityDate,
            'progress' => isset($meta['progress']) && $meta['progress'] !== '' ? (int) $meta['progress'] : (isset($meta['document_progress']) && $meta['document_progress'] !== '' ? (int) $meta['document_progress'] : null),
            'caption' => $meta['caption'] ?? $meta['document_caption'] ?? null,
        ]);
    }
}
