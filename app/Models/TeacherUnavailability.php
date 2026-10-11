<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherUnavailability extends Model
{
    protected $fillable = ['teacher_id', 'school_year', 'quarter', 'day', 'period', 'reason', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['quarter' => 'integer', 'day' => 'integer', 'period' => 'integer'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
