<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationOTP extends Model
{
    protected $table = 'registration_otps';

    protected $fillable = ['email', 'otp', 'payload', 'expires_at'];

    protected $casts = [
        'payload' => 'array',
        'expires_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }
}
