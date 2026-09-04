<?php

namespace App\Models;

use App\Enums\ExtractionRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RosterExtractionRow extends Model
{
    protected $fillable = ['roster_import_id', 'row_json', 'row_status'];

    protected function casts(): array
    {
        return [
            'row_json' => 'array',
            'row_status' => ExtractionRowStatus::class,
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(RosterImport::class, 'roster_import_id');
    }
}
