<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportMode extends Model
{
    use HasFactory;

    protected $fillable = ['mode', 'co2_per_km', 'eco_points_rate', 'label'];

    protected $casts = [
        'co2_per_km' => 'decimal:3',
        'eco_points_rate' => 'integer',
    ];

    public function checkins(): HasMany
    {
        return $this->hasMany(Checkin::class);
    }
}
