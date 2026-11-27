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
     * Create a destination blueprint directly from Google Places detail payload.
     *
     * @param  array<string, mixed>  $googleDetail
     */
    public function createFromGoogleDetail(array $googleDetail): Destination
    {
        $addressComponents = $googleDetail['addressComponents'] ?? [];
        $displayName = $googleDetail['displayName']['text'] ?? '';
        $category = $this->categoryClassifier->extractCategory(
            $googleDetail['types'] ?? [],
            $displayName,
            $googleDetail['formattedAddress'] ?? ''
        );

        return Destination::create([
            'place_id' => $googleDetail['id'],
            'area_id' => null,
            'name' => $displayName,
            'slug' => Str::slug($displayName ?: $googleDetail['id']),
            'country_code' => $this->addressParser->extractCountryCode($addressComponents),
            'city' => $this->addressParser->extractCity($addressComponents),
            'lat' => $googleDetail['location']['latitude'] ?? 0,
            'lng' => $googleDetail['location']['longitude'] ?? 0,
            'category' => $category,
            'culture_points' => $this->categoryClassifier->culturePointsFor($category),
            'price_tier' => $this->priceTierResolver->extractPriceTier($googleDetail['priceLevel'] ?? null),
            'address' => $googleDetail['formattedAddress'] ?? null,
            'cached_at' => now(),
        ]);
    }

    /**
     * Upsert destination from Google Places city search data.
     *
     * @param  array<string, mixed>  $place
     */
    public function upsertCityPlace(array $place, string $cityName): Destination
    {
        $lat = (float) $place['location']['latitude'];
        $lng = (float) $place['location']['longitude'];
        $area = $this->gridManager->ensureCityArea($lat, $lng);

        return $this->upsertPlaceForArea($place, $area, $cityName);
    }

    /**
     * Upsert destination from Google Places nearby search data under an existing detail area.
     *
     * @param  array<string, mixed>  $place
     */
    public function upsertNearbyPlace(array $place, Area $area, string $cityName): Destination
    {
        return $this->upsertPlaceForArea($place, $area, $cityName);
    }

    /**
     * Get all destinations for a city ordered by descending rating.
     *
     * @return Collection<int, Destination>
     */
    public function getCityDestinations(string $cityName): Collection
    {
        return Destination::where('city', $cityName)
            ->orderByDesc('rating')
            ->get();
    }

    /**
     * Count destinations stored for a city.
     */
    public function countCityDestinations(string $cityName): int
    {
        return Destination::where('city', $cityName)->count();
    }

    /**
     * Fetch nearby destinations from the local database using haversine distance.
     *
     * @return Collection<int, Destination>
     */
    public function getNearbyFromDb(Destination $destination, int $radius = GridManager::DETAIL_RADIUS): Collection
    {
        $haversine = '(6371000 * acos(cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat))))';
        $bindings = [(float) $destination->lat, (float) $destination->lng, (float) $destination->lat];

        return Destination::selectRaw("*, {$haversine} AS distance", $bindings)
            ->where('id', '!=', $destination->id)
            ->whereRaw("{$haversine} <= ?", [...$bindings, $radius])
            ->orderBy('distance')
            ->limit(20)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function upsertPlaceForArea(array $place, Area $area, string $cityName): Destination
    {
        $baseCategory = $this->categoryClassifier->extractBaseCategory($place['types'] ?? []);
        $displayName = $place['displayName']['text'] ?? '';
        $rating = (float) ($place['rating'] ?? 0);
        $userRatingCount = (int) ($place['userRatingCount'] ?? 0);
        $mapCategory = $this->categoryClassifier->resolveMapCategory(
            $baseCategory,
            $displayName,
            $rating,
            $userRatingCount
        );

        return Destination::updateOrCreate(
            ['place_id' => $place['id']],
            [
                'area_id' => $area->id,
                'name' => $displayName,
                'slug' => Str::slug($displayName ?: $place['id']),
                'country_code' => $this->addressParser->extractCountryCode($place['addressComponents'] ?? []),
                'city' => $cityName,
                'lat' => $place['location']['latitude'],
                'lng' => $place['location']['longitude'],
                'category' => $baseCategory,
                'map_category' => $mapCategory,
                'culture_points' => $this->categoryClassifier->culturePointsFor($baseCategory, $mapCategory),
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
}
