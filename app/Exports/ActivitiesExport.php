<?php

namespace App\Exports;

use App\Models\ExtensionActivity;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;

class ActivitiesExport implements FromArray
{
    /**
     * @param  Collection<int, ExtensionActivity>  $activities
     */
    public function __construct(private $activities) {}

    public function array(): array
    {
        $rows = [[
            'Title',
            'Description',
            'Program',
            'Institute',
            'Barangay',
            'Location',
            'Start Date',
            'End Date',
            'Status',
            'Progress (%)',
            'Faculty Participants',
            'Student Participants',
            'Beneficiaries',
            'SDGs',
            'Collaborating Programs',
            'Activity Days',
            'Daily Tasks',
            'Documents',
        ]];

        foreach ($this->activities as $activity) {
            $sdgs = $activity->relationLoaded('sdgs') ? $activity->sdgs->map(fn ($s) => 'SDG '.$s->number.': '.$s->title)->implode('; ') : '';
            $collabs = $activity->relationLoaded('collaborators') ? $activity->collaborators->pluck('name')->implode('; ') : '';
            $days = $activity->relationLoaded('days') ? $activity->days->sortBy('day_number')->map(fn ($d) => 'Day '.$d->day_number.' ('.$d->activity_date->format('Y-m-d').')')->implode('; ') : ($activity->start_date?->format('Y-m-d').' to '.$activity->end_date?->format('Y-m-d'));
            $tasks = $activity->relationLoaded('dailyTasks') ? $activity->dailyTasks->map(fn ($t) => $t->scheduled_date->format('Y-m-d').': '.$t->title)->implode('; ') : '';
            $docs = $activity->relationLoaded('documents') ? (string) $activity->documents->count() : '';

            $rows[] = [
                $activity->title,
                $activity->description,
                $activity->program?->name,
                $activity->program?->institute?->name,
                $activity->barangay?->name ?? 'City-wide',
                $activity->location,
                $activity->start_date?->format('Y-m-d'),
                $activity->end_date?->format('Y-m-d'),
                $activity->status,
                $activity->progress,
                $activity->faculty_participants,
                $activity->student_participants,
                $activity->beneficiaries,
                $sdgs,
                $collabs,
                $days,
                $tasks,
                $docs,
            ];
        }

        return $rows;
    }
}
