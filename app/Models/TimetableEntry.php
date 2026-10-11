<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimetableEntry extends Model
{
    protected $fillable = ['section_id', 'department_id', 'teacher_id', 'day', 'period'];

    protected function casts(): array
    {
        return ['day' => 'integer', 'period' => 'integer'];
    }

    public function timetableVersion(): BelongsTo
    {
        return $this->belongsTo(TimetableVersion::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
