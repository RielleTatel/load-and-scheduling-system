<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlantillaUpload extends Model
{
    protected $fillable = [
        'plantilla_submission_id', 'file_path', 'original_filename',
        'extraction_status', 'extracted_at',
    ];

    protected function casts(): array
    {
        return ['extracted_at' => 'datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(PlantillaSubmission::class, 'plantilla_submission_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PlantillaExtractionRow::class);
    }
}
