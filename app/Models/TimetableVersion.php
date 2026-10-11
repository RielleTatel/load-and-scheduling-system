<?php

namespace App\Models;

use App\Enums\TimetableKind;
use App\Enums\TimetableStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TimetableVersion extends Model
{
    protected $attributes = ['kind' => 'normal'];

    protected $fillable = [
        'school_year', 'quarter', 'version', 'status', 'created_by_user_id',
        'approved_by_user_id', 'generation_seed', 'edit_sequence', 'started_at',
        'completed_at', 'approved_at', 'failure_message', 'generation_checkpoint', 'kind',
    ];

    protected function casts(): array
    {
        return [
            'kind' => TimetableKind::class,
            'status' => TimetableStatus::class,
            'quarter' => 'integer',
            'version' => 'integer',
            'generation_seed' => 'integer',
            'edit_sequence' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'approved_at' => 'datetime',
            'generation_checkpoint' => 'array',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(TimetableValidationIssue::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(TimetableChange::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
