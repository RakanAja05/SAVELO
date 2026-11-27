<?php

declare(strict_types=1);

namespace App\Services\Itinerary;

use App\Models\Destination;
use App\Models\ItineraryItem;
use App\Models\ItineraryLeg;
use App\Models\TransportMode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CheckinManager
{
    public const CHECKIN_RADIUS_METERS = 100;

    /**
     * Check whether user coordinates fall within the 100m check-in radius.
     *
     * @return array{is_within_radius: bool, distance_meters: int}
     */
    public function checkLocation(
        float $userLat,
        float $userLng,
        Destination $destination
    ): array {
        $distance = $this->haversineDistance(
            $userLat,
            $userLng,
            (float) $destination->lat,
            (float) $destination->lng
        );

        return [
            'is_within_radius' => $distance <= self::CHECKIN_RADIUS_METERS,
            'distance_meters' => (int) round($distance),
        ];
    }

    /**
     * Get check-in preview including destination points, incoming leg distance, and transport modes.
     *
     * @return array<string, mixed>
     */
    public function getCheckinPreview(ItineraryItem $item, Destination $destination): array
    {
        $leg = ItineraryLeg::where('to_item_id', $item->id)->first();
        $distanceKm = (float) ($leg?->distance_km ?? 0);

        $transportModes = TransportMode::query()
            ->orderByDesc('eco_points_rate')
            ->get();

        return [
            'destination' => [
                'name' => $destination->name,
                'address' => $destination->address,
                'culture_points' => (int) ($destination->culture_points ?? 0),
            ],
            'leg_distance_km' => $distanceKm,
            'transport_modes' => $transportModes->map(fn (TransportMode $mode) => [
                'id' => $mode->id,
                'mode' => $mode->mode,
                'label' => $mode->label,
                'eco_points_rate' => (int) $mode->eco_points_rate,
                'co2_per_km' => (float) $mode->co2_per_km,
            ])->all(),
        ];
    }

    /**
     * Process check-in and award EcoPoints, CulturePoints, and PathPoints.
     *
     * @return array{eco_points: int, culture_points: int, path_points: int}
     */
    public function processCheckin(
        ItineraryItem $item,
        TransportMode $transportMode,
        User $user
    ): array {
        $destination = $item->destination;

        if (! $destination) {
            throw new \RuntimeException('Destination not found for item');
        }

        $leg = ItineraryLeg::where('to_item_id', $item->id)->first();
        $distanceKm = (float) ($leg?->distance_km ?? 0);
        $ecoPoints = (int) round($distanceKm * (int) $transportMode->eco_points_rate);
        $culturePoints = (int) ($destination->culture_points ?? 0);
        $pathPoints = $ecoPoints + $culturePoints;

        $itinerary = $item->day?->itinerary;
        $previousCost = (float) $item->cost_estimate;

        DB::transaction(function () use (
            $item,
            $leg,
            $transportMode,
            $user,
            $ecoPoints,
            $culturePoints,
            $pathPoints,
            $itinerary,
            $previousCost
        ) {
            $item->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            if ($leg) {
                $leg->update(['transport_mode_id' => $transportMode->id]);
            }

            if ($itinerary) {
                $itinerary->update([
                    'total_cost' => max(0, (float) $itinerary->total_cost - $previousCost),
                ]);
            }

            $user->increment('eco_points', $ecoPoints);
            $user->increment('culture_points', $culturePoints);
            $user->increment('path_points', $pathPoints);
        });

        return [
            'eco_points' => $ecoPoints,
            'culture_points' => $culturePoints,
            'path_points' => $pathPoints,
        ];
    }

    /**
     * Calculate haversine distance in meters between two coordinates.
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);

        $a = sin($dLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
