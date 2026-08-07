<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherOtherAssignment extends Model
{
    protected $fillable = ['teacher_id', 'other_assignment_role_id', 'school_year'];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(OtherAssignmentRole::class, 'other_assignment_role_id');
    }
}
