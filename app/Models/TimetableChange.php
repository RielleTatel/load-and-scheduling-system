<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimetableChange extends Model
{
    protected $fillable = ['timetable_version_id', 'user_id', 'sequence', 'action', 'before_state', 'after_state'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'before_state' => 'array', 'after_state' => 'array'];
    }

    public function timetableVersion(): BelongsTo
    {
        return $this->belongsTo(TimetableVersion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
