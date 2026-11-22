<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectEvent extends Model
{
    public const CONFIDENCE = [
        'seed' => 1.0,
        'explicit' => 1.0,
        'lot_plan' => 0.9,
        'address' => 0.7,
        'proximity' => 0.5,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'date', 'confidence' => 'decimal:2'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
