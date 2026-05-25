<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItineraryRequest extends Model
{
    protected $fillable = [
        'user_id',
        'origin',
        'destination_label',
        'duration_days',
        'num_people',
        'budget',
        'status',
        'gemini_raw_response',
    ];

    protected $casts = [
        'duration_days' => 'integer',
        'num_people' => 'integer',
        'budget' => 'decimal:2',
        'gemini_raw_response' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function itineraries(): HasMany
    {
        return $this->hasMany(Itinerary::class, 'request_id');
    }
}
