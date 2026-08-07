<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HonorsClassAssignment extends Model
{
    protected $fillable = ['teacher_id', 'section_id', 'school_year', 'hours'];

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
}
