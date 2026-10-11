<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimetableValidationIssue extends Model
{
    protected $fillable = [
        'code', 'severity', 'category', 'message', 'section_id', 'teacher_id',
        'day', 'period', 'details',
    ];

    protected function casts(): array
    {
        return ['details' => 'array', 'day' => 'integer', 'period' => 'integer'];
    }

    public function timetableVersion(): BelongsTo
    {
        return $this->belongsTo(TimetableVersion::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
