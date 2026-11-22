<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Application extends Model
{
    protected $guarded = ['id', 'lot_keys', 'geom'];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'development_types' => 'array',
            'building_classes' => 'array',
            'lodged_at' => 'date',
            'determined_at' => 'date',
            'exhibition_starts_at' => 'date',
            'exhibition_ends_at' => 'date',
            'source_updated_at' => 'datetime',
            'fetched_at' => 'datetime',
            'cost' => 'decimal:2',
            'gross_floor_area' => 'decimal:2',
        ];
    }

    public function builder(): BelongsTo
    {
        return $this->belongsTo(Builder::class);
    }

    public function event(): HasOne
    {
        return $this->hasOne(ProjectEvent::class);
    }
}
