<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportMode extends Model
{
    protected $fillable = ['mode', 'co2_per_km', 'eco_points_rate', 'label'];

    public function checkins(): HasMany
    {
        return $this->hasMany(Checkin::class);
    }
}
