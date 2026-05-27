<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ItineraryItem extends Model
{
    protected $fillable = [
        'itinerary_day_id',
        'destination_id',
        'order_index',
        'visit_time',
        'duration_minutes',
        'cost_estimate',
        'notes',
        'status',
        'completed_at',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'order_index' => 'integer',
        'duration_minutes' => 'integer',
        'cost_estimate' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    public function day(): BelongsTo
    {
        return $this->belongsTo(ItineraryDay::class, 'itinerary_day_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function legsFrom(): HasMany
    {
        return $this->hasMany(ItineraryLeg::class, 'from_item_id');
    }

    public function legsTo(): HasMany
    {
        return $this->hasMany(ItineraryLeg::class, 'to_item_id');
    }

    public function legDeparting(): HasOne
    {
        return $this->hasOne(ItineraryLeg::class, 'from_item_id');
    }
}
