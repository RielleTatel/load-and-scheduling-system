<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A spelling an Admin approved as naming an existing teacher. Several
 * teachers may legitimately share an alias; it is a lookup key, not an
 * identity.
 */
class TeacherAlias extends Model
{
    protected $fillable = ['teacher_id', 'alias_name', 'normalized_name', 'identity_case_id', 'approved_by_user_id', 'note'];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
