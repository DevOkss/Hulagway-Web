<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExtensionActivity extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_ONGOING = 'ongoing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'title',
        'description',
        'program_id',
        'barangay_id',
        'created_by',
        'location',
        'start_date',
        'end_date',
        'status',
        'progress',
        'faculty_participants',
        'student_participants',
        'beneficiaries',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'progress' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ExtensionActivityDocument::class, 'extension_activity_id');
    }

    public function dailyTasks(): HasMany
    {
        return $this->hasMany(ExtensionActivityDailyTask::class, 'extension_activity_id')->orderBy('scheduled_date')->orderBy('order');
    }

    public function days(): HasMany
    {
        return $this->hasMany(ExtensionActivityDay::class, 'extension_activity_id')->orderBy('day_number');
    }

    /**
     * Collaborating programs (lead program is `program_id`).
      */
    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(Program::class, 'extension_activity_collaborators', 'extension_activity_id', 'program_id')
            ->withTimestamps();
    }

    public function sdgs(): BelongsToMany
    {
        return $this->belongsToMany(Sdg::class, 'extension_activity_sdg')
            ->withTimestamps()
            ->orderBy('number');
    }

    /**
     * Can this user view the activity? Officer: always. Coordinator: lead or collaborator program.
     */
    public function isViewableBy(User $user): bool
    {
        if ($user->isOfficer()) {
            return true;
        }

        if (! $user->isCoordinator() || ! $user->program_id) {
            return false;
        }

        return $user->program_id === $this->program_id
            || $this->collaborators()->where('programs.id', $user->program_id)->exists();
    }

    /**
     * Can this user manage the activity? Lead program coordinator or main creator.
     */
    public function isManagedBy(User $user): bool
    {
        if (! $user->isCoordinator()) {
            return false;
        }

        // Lead program coordinator can manage
        if ($user->program_id !== null && $user->program_id === $this->program_id) {
            return true;
        }

        // Main creator (created_by) can manage even if program differs (e.g. creator is the lead at creation time)
        if ($this->created_by !== null && $user->id === $this->created_by) {
            return true;
        }

        return false;
    }
}
