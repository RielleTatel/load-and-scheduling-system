<?php

namespace App\Models;

use App\Enums\GradeLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    use HasFactory;

    protected $fillable = ['grade_level', 'name'];

    protected function casts(): array
    {
        return [
            'grade_level' => GradeLevel::class,
        ];
    }
}
