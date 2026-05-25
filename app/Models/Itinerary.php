<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Itinerary extends Model
{
    protected $fillable = [
        'request_id',
        'variant',
        'title',
        'total_budget',
        'status',
    ];

    protected $casts = [
        'total_budget' => 'decimal:2',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ItineraryRequest::class, 'request_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(ItineraryDay::class);
    }
}
