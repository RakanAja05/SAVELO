<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    use HasFactory;

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
            && $this->cached_at->greaterThan(now()->subDays(30));
    }
}
