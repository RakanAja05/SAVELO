<?php

namespace App\Services;

use App\Models\Destination;
use App\Services\Gemini\GeminiService;
use App\Services\PlaceCache\AddressParser;
use App\Services\PlaceCache\GridManager;
use App\Services\PlaceCache\PlaceBatchProcessor;
use App\Services\PlaceCache\PlaceCategoryClassifier;
use App\Services\PlaceCache\PlaceDestinationPersister;
use App\Services\PlaceCache\PlaceEnricher;
use App\Services\PlaceCache\PriceTierResolver;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PlaceCacheService
{
    const DETAIL_RADIUS = 3000;

    const CACHE_TTL_DAYS = 30;

    const NEARBY_TYPES = [
        'tourist_attraction',
        'restaurant',
        'cafe',
        'lodging',
        'transit_station',
    ];

    public function __construct(
        private GooglePlacesService $google,
        private GeminiService $gemini,
        private GridManager $gridManager,
        private AddressParser $addressParser,
        private PlaceCategoryClassifier $categoryClassifier,
        private PriceTierResolver $priceTierResolver,
        private PlaceEnricher $placeEnricher,
        private PlaceBatchProcessor $batchProcessor,
        private PlaceDestinationPersister $persister,
    ) {}

    public function getCityPlaces(string $cityName): array
    {
        $slug = Str::slug($cityName);
        $cacheKey = "city_data_synced_{$slug}";

        $placeCount = $this->persister->countCityDestinations($cityName);

        if (Cache::has($cacheKey) && $placeCount >= 20) {
            return [
                'from_cache' => true,
                'places' => $this->persister->getCityDestinations($cityName),
            ];
        }

        try {
            return Cache::lock("city_fetch_{$slug}", 60)->block(15, function () use ($cityName, $cacheKey) {

                $placeCount = $this->persister->countCityDestinations($cityName);
                if (Cache::has($cacheKey) && $placeCount >= 20) {
                    return [
                        'from_cache' => true,
                        'places' => $this->persister->getCityDestinations($cityName),
                    ];
                }

                $result = $this->fetchCityDiverseDestinations($cityName);

                if (empty($result['error'])) {
                    Cache::put($cacheKey, true, now()->addDays(self::CACHE_TTL_DAYS));
                }

                return $result;
            });
        } catch (LockTimeoutException) {
            return [
                'from_cache' => true,
                'stale' => true,
                'places' => $this->persister->getCityDestinations($cityName),
            ];
        }
    }

    public function getPlaceDetail(string $placeId): array
    {
        $destination = Destination::where('place_id', $placeId)->first();

        if (! $destination) {
            $googleDetail = $this->google->getDetail($placeId);

            if (empty($googleDetail)) {
                return ['error' => 'Tempat tidak ditemukan.', 'code' => 404];
            }

            $addressComponents = $googleDetail['addressComponents'] ?? [];

            $destination = Destination::create([
                'place_id' => $googleDetail['id'],
                'area_id' => null,
                'name' => $googleDetail['displayName']['text'] ?? '',
                'slug' => Str::slug($googleDetail['displayName']['text'] ?? $googleDetail['id']),
                'country_code' => $this->addressParser->extractCountryCode($addressComponents),
                'city' => $this->addressParser->extractCity($addressComponents),
                'lat' => $googleDetail['location']['latitude'] ?? 0,
                'lng' => $googleDetail['location']['longitude'] ?? 0,
                'category' => $this->categoryClassifier->extractCategory(
                    $googleDetail['types'] ?? [],
                    $googleDetail['displayName']['text'] ?? '',
                    $googleDetail['formattedAddress'] ?? ''
                ),
                'culture_points' => $this->categoryClassifier->culturePointsFor(
                    $this->categoryClassifier->extractCategory(
                        $googleDetail['types'] ?? [],
                        $googleDetail['displayName']['text'] ?? '',
                        $googleDetail['formattedAddress'] ?? ''
                    )
                ),
                'price_tier' => $this->priceTierResolver->extractPriceTier($googleDetail['priceLevel'] ?? null),
                'address' => $googleDetail['formattedAddress'] ?? null,
                'cached_at' => now(),
            ]);
        }

        $area = $destination->area;

        if (! $area || ! $area->isFresh()) {
            return $this->fetchPlaceDetail($destination);
        }

        return [
            'from_cache' => true,
            'destination' => $destination,
            'nearby' => $this->getNearbyFromDb($destination),
        ];
    }

    private function fetchCityDiverseDestinations(string $cityName): array
    {
        try {
            $searchQueries = [
                "top tourist attractions in {$cityName}",
                "best restaurants in {$cityName}",
                "best parks in {$cityName}",
                "best hotels in {$cityName}",
            ];

            $allFetchedPlaces = [];

            foreach ($searchQueries as $query) {
                $result = $this->google->searchByText($query, [
                    'fields' => implode(',', [
                        'places.id', 'places.displayName', 'places.formattedAddress',
                        'places.addressComponents', 'places.location', 'places.rating',
                        'places.userRatingCount', 'places.photos', 'places.types',
                        'places.regularOpeningHours', 'places.internationalPhoneNumber',
                        'places.websiteUri', 'places.priceLevel',
                    ]),
                    'limit' => 15,
                ]);

                if (! empty($result['places'])) {
                    $allFetchedPlaces = array_merge($allFetchedPlaces, $result['places']);
                }
            }

            if (empty($allFetchedPlaces)) {
                return ['places' => [], 'error' => 'Kota tidak ditemukan atau data terlalu sedikit.'];
            }

            $uniquePlaces = collect($allFetchedPlaces)->unique('id')->values()->all();

            // Initial upsert of all places
            foreach ($uniquePlaces as $p) {
                $this->persister->upsertFromGooglePlace($p, $cityName, self::DETAIL_RADIUS);
            }

            // Batch process UMKM classification and price tier enrichment
            $this->batchProcessor->processBatchClassification($uniquePlaces);

            return [
                'from_cache' => false,
                'places' => $this->persister->getCityDestinations($cityName),
            ];
        } catch (\Exception $e) {
            Log::error('PlaceCacheService: fetchCityDiverseDestinations failed', [
                'city' => $cityName,
                'error' => $e->getMessage(),
            ]);

            return [
                'places' => $this->persister->getCityDestinations($cityName),
                'error' => 'Gagal mengambil data dari Google Places API',
            ];
        }
    }

    private function fetchPlaceDetail(Destination $destination): array
    {
        try {
            $result = $this->google->getNearby(
                $destination->lat,
                $destination->lng,
                self::DETAIL_RADIUS,
                self::NEARBY_TYPES
            );

            $area = $this->gridManager->ensureDetailArea($destination->lat, $destination->lng);

            // Batch process UMKM classification and price tier enrichment
            $this->batchProcessor->processBatchClassification($result['places'] ?? []);

            $microstory = $destination->ai_microstory;

            if ($this->placeEnricher->shouldRefreshMicrostory($destination)) {
                $generated = $this->placeEnricher->generateMicrostory($destination);

                if ($generated !== null) {
                    $microstory = $generated;
                }
            }

            $updatePayload = [
                'detail_fetched_at' => now(),
                'area_id' => $area->id,
            ];

            if ($microstory !== null) {
                $updatePayload['ai_microstory'] = $microstory;
            }

            $destination->update($updatePayload);

            return [
                'from_cache' => false,
                'destination' => $destination->fresh(),
                'nearby' => $this->getNearbyFromDb($destination),
            ];

        } catch (\Exception $e) {
            Log::error('PlaceCacheService: fetchPlaceDetail failed', [
                'place_id' => $destination->place_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'from_cache' => true,
                'stale' => true,
                'destination' => $destination,
                'nearby' => $this->getNearbyFromDb($destination),
            ];
        }
    }

    private function getNearbyFromDb(Destination $destination): Collection
    {
        return Destination::selectRaw('*, (
                6371000 * acos(
                    cos(radians(?)) * cos(radians(lat)) *
                    cos(radians(lng) - radians(?)) +
                    sin(radians(?)) * sin(radians(lat))
                )
            ) AS distance',
            [$destination->lat, $destination->lng, $destination->lat]
        )
            ->where('id', '!=', $destination->id)
            ->having('distance', '<=', self::DETAIL_RADIUS)
            ->orderBy('distance')
            ->limit(20)
            ->get();
    }
}
