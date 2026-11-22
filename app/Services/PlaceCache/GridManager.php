<?php

namespace App\Services\PlaceCache;

use App\Models\Area;

class GridManager
{
    const DETAIL_RADIUS = 3000;

    const GRID_SIZE = 0.02;

    /**
     * Convert lat/lng to grid coordinates for Area lookup/creation.
     */
    public function latLngToGrid(float $lat, float $lng): array
    {
        return [
            'grid_lat' => round($lat / self::GRID_SIZE) * self::GRID_SIZE,
            'grid_lng' => round($lng / self::GRID_SIZE) * self::GRID_SIZE,
        ];
    }

    /**
     * Ensure an Area exists for the given destination, creating it if necessary.
     */
    public function ensureDetailArea(float $lat, float $lng): Area
    {
        $grid = $this->latLngToGrid($lat, $lng);

        return Area::updateOrCreate(
            [
                'grid_lat' => $grid['grid_lat'],
                'grid_lng' => $grid['grid_lng'],
            ],
            ['radius' => self::DETAIL_RADIUS]
        );
    }
}
