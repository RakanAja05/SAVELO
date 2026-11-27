<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Destination;
use App\Services\PlaceCache\GridManager;
use App\Services\PlaceCache\PlaceBatchProcessor;
use App\Services\PlaceCache\PlaceDestinationPersister;
use App\Services\PlaceCache\PlaceEnricher;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PlaceCacheService
{
    public const DETAIL_RADIUS = 3000;

    public const CACHE_TTL_DAYS = 30;

    public const NEARBY_TYPES = [
        'tourist_attraction',
        'restaurant',
        'cafe',
        'lodging',
        'transit_station',
    ];

    private const PLACE_FIELDS = [
        'places.id',
        'places.displayName',
        'places.formattedAddress',
        'places.addressComponents',
        'places.location',
        'places.rating',
        'places.userRatingCount',
        'places.photos',
        'places.types',
        'places.regularOpeningHours',
        'places.internationalPhoneNumber',
        'places.websiteUri',
        'places.priceLevel',
    ];

    public function __construct(
        private GooglePlacesService $google,
        private GridManager $gridManager,
        private PlaceEnricher $placeEnricher,
        private PlaceBatchProcessor $batchProcessor,
        private PlaceDestinationPersister $persister,
    ) {}

    /**
     * Get cached destinations for a city, fetching from Google Places when stale or insufficient.
     *
     * @return array<string, mixed>
     */
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
            $blockSeconds = (int) config('services.places.lock_block_seconds', 15);

            return Cache::lock("city_fetch_{$slug}", 60)->block($blockSeconds, function () use ($cityName, $cacheKey) {
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

    /**
     * Get full detail for a destination along with nearby places within 3km.
     *
     * @return array<string, mixed>
     */
    public function getPlaceDetail(string $placeId): array
    {
        $destination = Destination::where('place_id', $placeId)->first();

        if (! $destination) {
            $googleDetail = $this->google->getDetail($placeId);

            if (empty($googleDetail)) {
                return ['error' => 'Tempat tidak ditemukan.', 'code' => 404];
            }

            $destination = $this->persister->createFromGoogleDetail($googleDetail);
        }

        $area = $destination->area;

        if (! $area || ! $area->isFresh()) {
            return $this->fetchPlaceDetail($destination);
        }

        return [
            'from_cache' => true,
            'destination' => $destination,
            'nearby' => $this->persister->getNearbyFromDb($destination),
        ];
    }

    /**
     * @return array<string, mixed>
     */
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
                    'fields' => implode(',', self::PLACE_FIELDS),
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

            foreach ($uniquePlaces as $p) {
                $this->persister->upsertCityPlace($p, $cityName);
            }

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

            return ['places' => [], 'error' => 'Gagal mengambil data, coba lagi nanti.'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPlaceDetail(Destination $destination): array
    {
        try {
            $result = $this->google->searchNearby(
                (float) $destination->lat,
                (float) $destination->lng,
                [
                    'types' => self::NEARBY_TYPES,
                    'radius' => self::DETAIL_RADIUS,
                    'limit' => 20,
                    'fields' => implode(',', self::PLACE_FIELDS),
                ]
            );

            $area = $this->gridManager->ensureDetailArea((float) $destination->lat, (float) $destination->lng);

            $nearbyPlaces = $result['places'] ?? [];

            foreach ($nearbyPlaces as $p) {
                $this->persister->upsertNearbyPlace($p, $area, (string) $destination->city);
            }

            $this->batchProcessor->processBatchClassification($nearbyPlaces);

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
                'nearby' => $this->persister->getNearbyFromDb($destination),
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
                'nearby' => $this->persister->getNearbyFromDb($destination),
            ];
        }
    }
}
