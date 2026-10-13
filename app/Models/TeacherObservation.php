<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One source naming one person: a plantilla row, a roster row's moderator or
 * teacher-partner, a directory entry, or a manual entry. `source_key` is the
 * stable reference to that row and field, so retrying a confirmation updates
 * the observation instead of multiplying it.
 */
class TeacherObservation extends Model
{
    protected $fillable = [
        'teacher_id', 'source_type', 'source_key', 'source_reference', 'school_year',
        'completeness', 'coverage_department_id', 'raw_name', 'observed_name', 'normalized_name',
        'observed_department_id', 'identity_hash', 'recorded_by_user_id',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function observedDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'observed_department_id');
    }
}
