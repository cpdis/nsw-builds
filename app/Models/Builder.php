<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder as Query;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Builder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_company' => 'boolean', 'suppressed' => 'boolean'];
    }

    /** Only builders that may appear anywhere on the site. */
    public function scopePublishable(Query $q): Query
    {
        return $q->where('is_company', true)->where('suppressed', false);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
