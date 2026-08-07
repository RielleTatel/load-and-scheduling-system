<?php

namespace App\Models;

use App\Enums\ExtractionRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlantillaExtractionRow extends Model
{
    protected $fillable = ['plantilla_upload_id', 'row_json', 'row_status'];

    protected function casts(): array
    {
        return [
            'row_json' => 'array',
            'row_status' => ExtractionRowStatus::class,
        ];
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(PlantillaUpload::class, 'plantilla_upload_id');
    }
}
