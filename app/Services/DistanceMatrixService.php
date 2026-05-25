<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DistanceMatrixService
{
    public function get(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $default = [
            'distance_km' => 0.0,
            'duration_min' => 0,
            'mode' => 'unknown',
        ];

        try {
            $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
                'origins' => $fromLat.','.$fromLng,
                'destinations' => $toLat.','.$toLng,
                'mode' => 'driving',
                'language' => 'id',
                'key' => (string) config('services.google.api_key'),
            ]);
        } catch (\Throwable $e) {
            return $default;
        }

        if (! $response->ok()) {
            return $default;
        }

        $data = $response->json();

        if (! is_array($data) || ($data['status'] ?? null) !== 'OK') {
            return $default;
        }

        $element = $data['rows'][0]['elements'][0] ?? null;

        if (! is_array($element) || ($element['status'] ?? null) !== 'OK') {
            return $default;
        }

        $distanceValue = (float) ($element['distance']['value'] ?? 0);
        $durationValue = (float) ($element['duration']['value'] ?? 0);

        return [
            'distance_km' => round($distanceValue / 1000, 2),
            'duration_min' => (int) ceil($durationValue / 60),
            'mode' => 'driving',
        ];
    }
}
