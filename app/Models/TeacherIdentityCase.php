<?php

namespace App\Models;

use App\Enums\IdentityCaseReason;
use App\Enums\IdentityCaseStatus;
use App\Enums\IdentityDecision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question about who a written name refers to, kept until an Admin answers
 * it. The answer, its author, time and explanation stay on the record.
 */
class TeacherIdentityCase extends Model
{
    protected $fillable = [
        'reason', 'status', 'source_type', 'source_key', 'source_reference', 'school_year',
        'observed_name', 'normalized_name', 'observed_department_id', 'subject_teacher_id',
        'candidate_ids', 'chair_context',
    ];

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'reason' => IdentityCaseReason::class,
            'status' => IdentityCaseStatus::class,
            'decision' => IdentityDecision::class,
            'candidate_ids' => 'array',
            'chair_context' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', IdentityCaseStatus::Open->value);
    }

    public function isOpen(): bool
    {
        return $this->status === IdentityCaseStatus::Open;
    }

    /** @return Collection<int, Teacher> */
    public function candidates(): Collection
    {
        return Teacher::with('department')->whereIn('id', $this->candidate_ids ?? [])->orderBy('full_name')->get();
    }

    /** Every teacher this case questions: the candidates and the subject. */
    public function involvedTeacherIds(): array
    {
        return array_values(array_unique(array_filter([...($this->candidate_ids ?? []), $this->subject_teacher_id])));
    }

    public function observedDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'observed_department_id');
    }

    public function subjectTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'subject_teacher_id');
    }

    public function resolutionTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'resolution_teacher_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
