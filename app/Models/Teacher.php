<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    use HasFactory;

    protected $fillable = ['full_name', 'employment_status', 'department_id'];

    protected function casts(): array
    {
        return [
            'employment_status' => EmploymentStatus::class,
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function sectionAssignments(): HasMany
    {
        return $this->hasMany(TeacherSectionAssignment::class);
    }

    public function moderatorAssignments(): HasMany
    {
        return $this->hasMany(ClassModeratorAssignment::class);
    }

    public function honorsAssignments(): HasMany
    {
        return $this->hasMany(HonorsClassAssignment::class);
    }

    public function serviceLoads(): HasMany
    {
        return $this->hasMany(ServiceLoad::class);
    }

    public function otherAssignments(): HasMany
    {
        return $this->hasMany(TeacherOtherAssignment::class);
    }
}
