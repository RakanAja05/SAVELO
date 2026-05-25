<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItineraryLeg extends Model
{
    protected $fillable = [
        'from_item_id',
        'to_item_id',
        'distance_km',
        'duration_min',
        'transport_mode',
    ];

    protected $casts = [
        'distance_km' => 'decimal:2',
        'duration_min' => 'integer',
    ];

    public function fromItem(): BelongsTo
    {
        return $this->belongsTo(ItineraryItem::class, 'from_item_id');
    }

    public function toItem(): BelongsTo
    {
        return $this->belongsTo(ItineraryItem::class, 'to_item_id');
    }
}
