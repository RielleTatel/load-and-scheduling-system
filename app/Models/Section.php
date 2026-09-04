<?php

namespace App\Models;

use App\Enums\GradeLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    use HasFactory;

    protected $fillable = ['school_year', 'grade_level', 'name', 'full_name', 'room', 'is_magis', 'moderator_name', 'teacher_partner_name'];

    public function teacherAssignments()
    {
        return $this->hasMany(TeacherSectionAssignment::class);
    }

    public function moderatorAssignment()
    {
        return $this->hasOne(ClassModeratorAssignment::class);
    }

    public function honorsAssignments()
    {
        return $this->hasMany(HonorsClassAssignment::class);
    }

    protected function casts(): array
    {
        return [
            'grade_level' => GradeLevel::class,
            'is_magis' => 'boolean',
        ];
    }
}
