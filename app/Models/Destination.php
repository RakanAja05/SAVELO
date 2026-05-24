<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// app/Models/Destination.php
class Destination extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'area_id', 'place_id', 'name', 'slug', 'country_code', 'city',
        'address', 'lat', 'lng', 'rating', 'user_rating_count', 'description', 'category', 'map_category', 'price_tier',
        'opening_hours', 'phone', 'whatsapp', 'official_url',
        'photos', 'ai_microstory', 'cached_at', 'detail_fetched_at',
    ];

    protected $casts = [
        'opening_hours' => 'array',
        'photos' => 'array',
        'cached_at' => 'datetime',
        'detail_fetched_at' => 'datetime',
        'rating' => 'decimal:2',
        'user_rating_count' => 'integer',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function isDetailFresh(): bool
    {
        return $this->detail_fetched_at !== null
            && now()->diffInDays($this->detail_fetched_at) < 30;
    }
}
