<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherSectionAssignment extends Model
{
    protected $fillable = ['teacher_id', 'section_id', 'department_id', 'school_year', 'hours'];

    protected function casts(): array
    {
        return ['hours' => 'decimal:1'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
