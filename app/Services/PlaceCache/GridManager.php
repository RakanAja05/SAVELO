<?php

declare(strict_types=1);

namespace App\Services\PlaceCache;

use App\Models\Area;

class GridManager
{
    public const DETAIL_RADIUS = 3000;

    public const GRID_SIZE = 0.02;

    /**
     * Convert lat/lng to integer spatial grid coordinates for Area lookup/creation.
     *
     * @return array{grid_lat: int, grid_lng: int}
     */
    public function latLngToGrid(float $lat, float $lng): array
    {
        return [
            'grid_lat' => (int) floor($lat / self::GRID_SIZE),
            'grid_lng' => (int) floor($lng / self::GRID_SIZE),
        ];
    }

    /**
     * Ensure an Area exists for city search results without marking detail cache fresh.
     */
    public function ensureCityArea(float $lat, float $lng): Area
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

    /**
     * Ensure an Area exists for detail lookup and mark it fresh.
     */
    public function ensureDetailArea(float $lat, float $lng): Area
    {
        $grid = $this->latLngToGrid($lat, $lng);

        return Area::updateOrCreate(
            [
                'grid_lat' => $grid['grid_lat'],
                'grid_lng' => $grid['grid_lng'],
            ],
            [
                'radius' => self::DETAIL_RADIUS,
                'cached_at' => now(),
            ]
        );
    }
}
