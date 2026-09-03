<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlantillaSubmission extends Model
{
    protected $fillable = [
        'department_id', 'school_year', 'status',
        'submitted_by_user_id', 'submitted_at', 'returned_comment', 'returned_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'submitted_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by_user_id');
    }

    /**
     * The submission row for a department in the active school year,
     * created in Draft state if it doesn't exist yet.
     */
    public static function currentFor(int $departmentId): self
    {
        return static::firstOrCreate(
            [
                'department_id' => $departmentId,
                'school_year' => SystemConstant::get('current_school_year'),
            ],
            ['status' => SubmissionStatus::Draft],
        );
    }
}
