<?php

declare(strict_types=1);

namespace App\Services\PlaceCache;

use App\Models\Area;
use App\Models\Destination;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class PlaceDestinationPersister
{
    public function __construct(
        private AddressParser $addressParser,
        private PlaceCategoryClassifier $categoryClassifier,
        private PriceTierResolver $priceTierResolver,
        private GridManager $gridManager,
    ) {}

    /**
     * Upsert destination from Google Places API data.
     * Used during initial city search to create/update destination records.
     */
    public function upsertFromGooglePlace(array $place, string $cityName, int $detailRadius): Destination
    {
        $lat = $place['location']['latitude'];
        $lng = $place['location']['longitude'];
        $grid = $this->gridManager->latLngToGrid($lat, $lng);

        $area = Area::updateOrCreate(
            ['grid_lat' => $grid['grid_lat'], 'grid_lng' => $grid['grid_lng']],
            ['radius' => $detailRadius]
        );

        $baseCategory = $this->categoryClassifier->extractBaseCategory($place['types'] ?? []);
        $displayName = $place['displayName']['text'] ?? '';
        $rating = (float) ($place['rating'] ?? 0);
        $userRatingCount = (int) ($place['userRatingCount'] ?? 0);

        return Destination::updateOrCreate(
            ['place_id' => $place['id']],
            [
                'area_id' => $area->id,
                'name' => $displayName,
                'slug' => Str::slug($displayName ?: $place['id']),
                'country_code' => $this->addressParser->extractCountryCode($place['addressComponents'] ?? []),
                'city' => $cityName,
                'lat' => $lat,
                'lng' => $lng,
                'category' => $baseCategory,
                'map_category' => $this->categoryClassifier->resolveMapCategory(
                    $baseCategory,
                    $displayName,
                    $rating,
                    $userRatingCount
                ),
                'culture_points' => $this->categoryClassifier->culturePointsFor(
                    $baseCategory,
                    $this->categoryClassifier->resolveMapCategory(
                        $baseCategory,
                        $displayName,
                        $rating,
                        $userRatingCount
                    )
                ),
                'rating' => $rating,
                'user_rating_count' => $userRatingCount,
                'price_tier' => $this->priceTierResolver->extractPriceTier($place['priceLevel'] ?? null),
                'address' => $place['formattedAddress'] ?? null,
                'opening_hours' => $place['regularOpeningHours'] ?? null,
                'phone' => $place['internationalPhoneNumber'] ?? null,
                'official_url' => $place['websiteUri'] ?? null,
                'photos' => array_slice($place['photos'] ?? [], 0, 5),
                'cached_at' => now(),
            ]
        );
    }

    /**
     * Get all destinations for a city, ordered by rating (descending).
     * Consolidates repeated query pattern throughout PlaceCacheService.
     */
    public function getCityDestinations(string $cityName): Collection
    {
        return Destination::where('city', $cityName)
            ->orderByDesc('rating')
            ->get();
    }

    /**
     * Count destinations in a city.
     */
    public function countCityDestinations(string $cityName): int
    {
        return Destination::where('city', $cityName)->count();
    }
}
