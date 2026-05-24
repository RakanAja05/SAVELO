<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    protected $fillable = ['grid_lat', 'grid_lng', 'radius', 'cached_at'];

    protected $casts = [
        'grid_lat' => 'integer',
        'grid_lng' => 'integer',
        'radius' => 'integer',
        'cached_at' => 'datetime',
    ];

    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class);
    }

    public function isFresh(): bool
    {
        return $this->cached_at !== null
            && now()->diffInDays($this->cached_at) < 30;
    }
}
