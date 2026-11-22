<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    public const INCREMENTAL = 'incremental';

    public const SCOPED = 'scoped';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
