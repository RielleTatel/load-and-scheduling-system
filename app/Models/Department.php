<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Department extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'hours_per_section', 'has_honors_class'];

    protected function casts(): array
    {
        return [
            'hours_per_section' => 'integer',
            'has_honors_class' => 'boolean',
        ];
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }

    public function chair(): HasOne
    {
        return $this->hasOne(User::class)->where('role', UserRole::DepartmentChair);
    }
}
