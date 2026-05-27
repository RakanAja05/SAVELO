<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\TransportMode;

class ItineraryLeg extends Model
{
    protected $fillable = [
        'from_item_id',
        'to_item_id',
        'distance_km',
        'duration_min',
        'transport_mode_id',
    ];

    protected $casts = [
        'distance_km' => 'decimal:2',
        'duration_min' => 'integer',
        'transport_mode_id' => 'integer',
    ];

    public function fromItem(): BelongsTo
    {
        return $this->belongsTo(ItineraryItem::class, 'from_item_id');
    }

    public function toItem(): BelongsTo
    {
        return $this->belongsTo(ItineraryItem::class, 'to_item_id');
    }

    public function transportMode(): BelongsTo
    {
        return $this->belongsTo(TransportMode::class, 'transport_mode_id');
    }
}
