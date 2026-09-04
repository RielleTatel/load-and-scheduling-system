<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RosterImport extends Model
{
    protected $fillable = [
        'school_year', 'file_path', 'original_filename',
        'extraction_status', 'extracted_at', 'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return ['extracted_at' => 'datetime'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(RosterExtractionRow::class);
    }
}
