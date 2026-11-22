<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    public const STAGES = ['lodged', 'exhibition', 'approved', 'construction', 'complete'];

    protected $guarded = ['id', 'lot_keys', 'geom'];

    protected function casts(): array
    {
        return [
            'stage_changed_at' => 'datetime',
            'first_event_at' => 'date',
            'last_event_at' => 'date',
            'cost' => 'decimal:2',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProjectEvent::class)->orderBy('occurred_at');
    }
}
