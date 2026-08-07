<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OtherAssignmentRole extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'equivalent_hours', 'is_honorarium'];

    protected function casts(): array
    {
        return [
            'equivalent_hours' => 'decimal:1',
            'is_honorarium' => 'boolean',
        ];
    }
}
