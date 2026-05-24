<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Destination;
use App\Services\Gemini\GeminiService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PlaceCacheService
{
    const DETAIL_RADIUS = 3000;

    const CACHE_TTL_DAYS = 30;

    const GRID_SIZE = 0.02;

    const NEARBY_TYPES = [
        'tourist_attraction',
        'restaurant',
        'cafe',
        'lodging',
        'transit_station',
    ];

    const CATEGORY_MAP = [
        'tourist_attraction' => 'wisata',
        'restaurant' => 'restoran',
        'cafe' => 'cafe',
        'lodging' => 'hotel',
        'museum' => 'museum',
        'park' => 'taman',
        'shopping_mall' => 'belanja',
        'bus_station' => 'transit',
        'train_station' => 'transit',
        'transit_station' => 'transit',
        'subway_station' => 'transit',
        'airport' => 'transit',
    ];

    const FRANCHISE_BLACKLIST = [
        'mcdonald', 'starbucks', 'kfc', 'burger king', 'pizza hut',
        'domino', 'subway', 'dunkin', 'baskin robbins', 'haagen dazs',
        'uniqlo', 'zara', 'h&m', 'indomaret', 'alfamart', 'lawson',
        'circle k', 'family mart', 'chatime', 'koi', 'gong cha', 'mixue',
        'hypermart', 'transmart', 'carrefour', 'giant', 'superindo',
        'j.co', 'breadtalk',
    ];

    const UMKM_WHITELIST = [
        'warung', 'kedai', 'toko', 'oleh-oleh', 'oleh oleh',
        'kerajinan', 'angkringan', 'batik', 'koperasi',
        'tenun', 'anyaman', 'ukiran', 'gerabah', 'tembikar',
        'jamu', 'pecel', 'gudeg', 'bakpia', 'getuk', 'lumpia',
        'rumah makan', 'depot', 'lapak', 'lesehan', 'soto', 'sate',
    ];

    public function __construct(
        private GooglePlacesService $google,
        private GeminiService $gemini,
    ) {}

    public function getCityPlaces(string $cityName): array
    {
        $slug = Str::slug($cityName);
        $latestCachedAt = Destination::where('city', $cityName)
            ->whereNotNull('cached_at')
            ->orderByDesc('cached_at')
            ->value('cached_at');

        if ($latestCachedAt && now()->diffInDays($latestCachedAt) < self::CACHE_TTL_DAYS) {
            return [
                'from_cache' => true,
                'places' => Destination::where('city', $cityName)->orderByDesc('rating')->get(),
            ];
        }

        try {
            return Cache::lock("city_fetch_{$slug}", 30)->block(10, function () use ($cityName) {
                $latestCachedAt = Destination::where('city', $cityName)
                    ->whereNotNull('cached_at')
                    ->orderByDesc('cached_at')
                    ->value('cached_at');

                if ($latestCachedAt && now()->diffInDays($latestCachedAt) < self::CACHE_TTL_DAYS) {
                    return [
                        'from_cache' => true,
                        'places' => Destination::where('city', $cityName)->orderByDesc('rating')->get(),
                    ];
                }

                return $this->fetchCityTrending($cityName);
            });
        } catch (LockTimeoutException) {
            return [
                'from_cache' => true,
                'stale' => true,
                'places' => Destination::where('city', $cityName)->orderByDesc('rating')->get(),
            ];
        }
    }

    public function getPlaceDetail(string $placeId): array
    {
        // Gunakan first(), jangan firstOrFail()
        $destination = Destination::where('place_id', $placeId)->first();

        // Kalo di DB belum ada sama sekali, bikin blueprint barunya dulu dari Google Detail
        if (! $destination) {
            $googleDetail = $this->google->getDetail($placeId);

            if (empty($googleDetail)) {
                return ['error' => 'Tempat tidak ditemukan.', 'code' => 404];
            }

            $addressComponents = $googleDetail['addressComponents'] ?? [];

            // Simpan sebagai destinasi baru di DB lokal
            $destination = Destination::create([
                'place_id' => $googleDetail['id'],
                'area_id' => null, // Set null dulu jika di database boleh nullable, atau sesuaikan logika lu
                'name' => $googleDetail['displayName']['text'] ?? '',
                'slug' => Str::slug($googleDetail['displayName']['text'] ?? $googleDetail['id']),
                'country_code' => $this->extractCountryCode($addressComponents),
                'city' => $this->extractCity($addressComponents),
                'lat' => $googleDetail['location']['latitude'] ?? 0,
                'lng' => $googleDetail['location']['longitude'] ?? 0,
                'category' => $this->extractCategory(
                    $googleDetail['types'] ?? [],
                    $googleDetail['displayName']['text'] ?? '',
                    $googleDetail['formattedAddress'] ?? ''
                ),
                'price_tier' => $this->extractPriceTier($googleDetail['priceLevel'] ?? null),
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

    private function fetchCityTrending(string $cityName): array
    {
        try {
            $result = $this->google->searchByText("destinasi wisata populer {$cityName}", [
                'fields' => implode(',', [
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
                ]),
                'limit' => 20,
            ]);

            if (empty($result['places'])) {
                return ['places' => [], 'error' => 'Kota tidak ditemukan.'];
            }

            // Step 1: Save all destinations first with base values
            foreach ($result['places'] as $p) {
                $lat = $p['location']['latitude'];
                $lng = $p['location']['longitude'];
                $grid = $this->latLngToGrid($lat, $lng);

                $area = Area::updateOrCreate(
                    ['grid_lat' => $grid['grid_lat'], 'grid_lng' => $grid['grid_lng']],
                    ['radius' => self::DETAIL_RADIUS]
                );

                Destination::updateOrCreate(
                    ['place_id' => $p['id']],
                    [
                        'area_id' => $area->id,
                        'name' => $p['displayName']['text'] ?? '',
                        'slug' => Str::slug($p['displayName']['text'] ?? $p['id']),
                        'country_code' => $this->extractCountryCode($p['addressComponents'] ?? []),
                        'city' => $cityName,
                        'lat' => $lat,
                        'lng' => $lng,
                        'category' => $this->extractBaseCategory($p['types'] ?? []),
                        'map_category' => $this->resolveMapCategory(
                            $this->extractBaseCategory($p['types'] ?? []),
                            $p['displayName']['text'] ?? '',
                            (float) ($p['rating'] ?? 0),
                            (int) ($p['userRatingCount'] ?? 0)
                        ),
                        'rating' => $p['rating'] ?? 0,
                        'user_rating_count' => $p['userRatingCount'] ?? 0,
                        'price_tier' => $this->extractPriceTier($p['priceLevel'] ?? null),
                        'address' => $p['formattedAddress'] ?? null,
                        'opening_hours' => $p['regularOpeningHours'] ?? null,
                        'phone' => $p['internationalPhoneNumber'] ?? null,
                        'official_url' => $p['websiteUri'] ?? null,
                        'photos' => array_slice($p['photos'] ?? [], 0, 5),
                        'cached_at' => now(),
                    ]
                );
            }

            // Step 2: One Gemini call for UMKM batch classification
            $umkmResults = $this->batchClassifyUmkm($result['places']);

            // Step 3: Build resolved category map (place_id => category)
            $resolvedCategories = [];
            foreach ($result['places'] as $p) {
                $placeId = $p['id'];
                $name = $p['displayName']['text'] ?? '';
                $aiResult = $umkmResults[$placeId] ?? null;
                $isUmkm = $aiResult !== null ? $aiResult : $this->classifyUmkmByRules($name);
                $resolvedCategories[$placeId] = $isUmkm
                    ? 'umkm'
                    : $this->extractBaseCategory($p['types'] ?? []);
            }

            // Step 4: One Gemini call for price tier batch inference
            $priceResults = $this->batchInferPriceTier($result['places'], $resolvedCategories);

            // Step 5: Update DB with resolved category and price tier
            foreach ($result['places'] as $p) {
                $placeId = $p['id'];
                $category = $resolvedCategories[$placeId];

                $priceTier = $priceResults[$placeId]
                    ?? $this->extractPriceTier($p['priceLevel'] ?? null);

                if ($priceTier === 'unknown') {
                    $priceTier = $this->categoryFallbackTier($category);
                }

                Destination::where('place_id', $placeId)->update([
                    'category' => $category,
                    'price_tier' => $priceTier,
                    'map_category' => $this->resolveMapCategory(
                        $category,
                        $p['displayName']['text'] ?? '',
                        (float) ($p['rating'] ?? 0),
                        (int) ($p['userRatingCount'] ?? 0)
                    ),
                ]);
            }

            // Step 6: Soft delete destinations no longer returned by Google
            $freshPlaceIds = array_column($result['places'], 'id');

            Destination::where('city', $cityName)
                ->whereNotIn('place_id', $freshPlaceIds)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now()]);

            return [
                'from_cache' => false,
                'places' => Destination::where('city', $cityName)->orderByDesc('rating')->get(),
            ];

        } catch (\Exception $e) {
            Log::error('PlaceCacheService: fetchCityTrending failed', [
                'city' => $cityName,
                'error' => $e->getMessage(),
            ]);

            return ['places' => [], 'error' => 'Gagal mengambil data, coba lagi nanti.'];
        }
    }

    private function fetchPlaceDetail(Destination $destination): array
    {
        try {
            // PERBAIKAN: Loop dihapus, langsung kirim array tipe ke API untuk hemat billing 75%
            $result = $this->google->searchNearby(
                $destination->lat,
                $destination->lng,
                [
                    'types' => self::NEARBY_TYPES,
                    'radius' => self::DETAIL_RADIUS,
                    'limit' => 20, // Diperbanyak karena gabungan dari beberapa kategori
                    'fields' => implode(',', [
                        'places.id',
                        'places.displayName',
                        'places.formattedAddress',
                        'places.addressComponents',
                        'places.location',
                        'places.rating',
                        'places.userRatingCount',
                        'places.photos',
                        'places.types', // Ditambahkan karena wajib untuk ekstraksi kategori lokal
                        'places.regularOpeningHours',
                        'places.internationalPhoneNumber',
                        'places.websiteUri',
                        'places.priceLevel',
                    ]),
                ]
            );

            $area = $this->ensureDetailArea($destination);

            foreach ($result['places'] ?? [] as $p) {
                Destination::updateOrCreate(
                    ['place_id' => $p['id']],
                    [
                        'area_id' => $area->id,
                        'name' => $p['displayName']['text'] ?? '',
                        'slug' => Str::slug($p['displayName']['text'] ?? $p['id']),
                        'country_code' => $this->extractCountryCode($p['addressComponents'] ?? []),
                        'city' => $destination->city,
                        'lat' => $p['location']['latitude'],
                        'lng' => $p['location']['longitude'],
                        'category' => $this->extractBaseCategory($p['types'] ?? []),
                        'map_category' => $this->resolveMapCategory(
                            $this->extractBaseCategory($p['types'] ?? []),
                            $p['displayName']['text'] ?? '',
                            (float) ($p['rating'] ?? 0),
                            (int) ($p['userRatingCount'] ?? 0)
                        ),
                        'rating' => $p['rating'] ?? 0, // PERBAIKAN: Rating disimpan
                        'user_rating_count' => $p['userRatingCount'] ?? 0, // PERBAIKAN: Rating count disimpan
                        'price_tier' => $this->extractPriceTier($p['priceLevel'] ?? null),
                        'address' => $p['formattedAddress'] ?? null,
                        'opening_hours' => $p['regularOpeningHours'] ?? null,
                        'phone' => $p['internationalPhoneNumber'] ?? null,
                        'official_url' => $p['websiteUri'] ?? null,
                        'photos' => array_slice($p['photos'] ?? [], 0, 5),
                        'cached_at' => now(),
                    ]
                );
            }

            // Step 2: One Gemini call for UMKM batch classification
            $umkmResults = $this->batchClassifyUmkm($result['places'] ?? []);

            // Step 3: Build resolved category map (place_id => category)
            $resolvedCategories = [];
            foreach ($result['places'] ?? [] as $p) {
                $placeId = $p['id'];
                $name = $p['displayName']['text'] ?? '';
                $aiResult = $umkmResults[$placeId] ?? null;
                $isUmkm = $aiResult !== null ? $aiResult : $this->classifyUmkmByRules($name);
                $resolvedCategories[$placeId] = $isUmkm
                    ? 'umkm'
                    : $this->extractBaseCategory($p['types'] ?? []);
            }

            // Step 4: One Gemini call for price tier batch inference
            $priceResults = $this->batchInferPriceTier($result['places'] ?? [], $resolvedCategories);

            // Step 5: Update DB with resolved category and price tier
            foreach ($result['places'] ?? [] as $p) {
                $placeId = $p['id'];
                $category = $resolvedCategories[$placeId] ?? $this->extractBaseCategory($p['types'] ?? []);

                $priceTier = $priceResults[$placeId]
                    ?? $this->extractPriceTier($p['priceLevel'] ?? null);

                if ($priceTier === 'unknown') {
                    $priceTier = $this->categoryFallbackTier($category);
                }

                Destination::where('place_id', $placeId)->update([
                    'category' => $category,
                    'price_tier' => $priceTier,
                    'map_category' => $this->resolveMapCategory(
                        $category,
                        $p['displayName']['text'] ?? '',
                        (float) ($p['rating'] ?? 0),
                        (int) ($p['userRatingCount'] ?? 0)
                    ),
                ]);
            }

            $destination->update([
                'detail_fetched_at' => now(),
                'area_id' => $area->id,
            ]);

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

    private function extractBaseCategory(array $types): string
    {
        foreach ($types as $type) {
            if (isset(self::CATEGORY_MAP[$type])) {
                return self::CATEGORY_MAP[$type];
            }
        }

        return 'lainnya';
    }

    private function extractCategory(array $types, string $placeName = '', string $address = ''): string
    {
        $base = $this->extractBaseCategory($types);

        if ($placeName === '') {
            return $base;
        }

        $umkmCandidates = ['restoran', 'cafe', 'belanja', 'lainnya'];
        if (in_array($base, $umkmCandidates, true)) {
            $isUmkm = $this->classifyUmkmByRules($placeName);
            if ($isUmkm) {
                return 'umkm';
            }
        }

        return $base;
    }

    private function resolveMapCategory(
        string $category,
        string $placeName,
        float $rating,
        int $reviewCount
    ): string {
        if ($category === 'hotel') {
            return 'hotel';
        }

        if ($category === 'umkm') {
            return 'umkm';
        }

        if ($category === 'transit') {
            return 'transit';
        }

        $heritageTags = [
            'candi', 'keraton', 'kraton', 'pura', 'masjid agung',
            'benteng', 'makam', 'monumen', 'heritage', 'kolonial',
            'kota lama', 'peninggalan', 'situs',
        ];
        $nameLower = strtolower($placeName);

        foreach ($heritageTags as $tag) {
            if (str_contains($nameLower, $tag)) {
                return 'heritage';
            }
        }

        if ($category === 'museum') {
            return 'heritage';
        }

        if (in_array($category, ['wisata', 'taman'], true)) {
            if ($rating >= 4.5 && $reviewCount >= 1000) {
                return 'iconic';
            }

            if ($rating >= 4.6 && $reviewCount < 200) {
                return 'hidden_gem';
            }

            if ($reviewCount >= 5000) {
                return 'iconic';
            }
        }

        return $category;
    }

    private function classifyUmkmByRules(string $placeName): bool
    {
        $nameLower = strtolower($placeName);

        foreach (self::FRANCHISE_BLACKLIST as $franchise) {
            if (str_contains($nameLower, $franchise)) {
                return false;
            }
        }

        foreach (self::UMKM_WHITELIST as $keyword) {
            if (str_contains($nameLower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function categoryFallbackTier(string $category): string
    {
        return match ($category) {
            'umkm', 'taman' => 'budget',
            'wisata', 'museum' => 'mid',
            'restoran', 'cafe' => 'mid',
            'belanja' => 'mid',
            'hotel' => 'premium',
            default => 'unknown',
        };
    }

    private function parseBatchResponse(string $response, string $type): array
    {
        $results = [];
        $lines = array_filter(explode("\n", trim($response)));

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));
            if (count($parts) !== 3) {
                continue;
            }

            [$placeId, $value, $confidence] = $parts;
            $placeId = trim($placeId);
            $confidence = (int) trim($confidence);
            $value = trim($value);

            if ($confidence < 70) {
                $results[$placeId] = null;

                continue;
            }

            if ($type === 'umkm') {
                $results[$placeId] = strtoupper($value) === 'Y';
            } else {
                $validTiers = ['free', 'budget', 'mid', 'premium', 'luxury'];
                $results[$placeId] = in_array($value, $validTiers, true) ? $value : null;
            }
        }

        return $results;
    }

    private function batchClassifyUmkm(array $places): array
    {
        $lines = [];
        foreach ($places as $p) {
            $placeId = $p['id'];
            $name = $p['displayName']['text'] ?? '';
            $address = $p['formattedAddress'] ?? '';
            $category = $this->extractBaseCategory($p['types'] ?? []);
            $lines[] = "{$placeId} | {$name} | {$address} | {$category}";
        }

        $list = implode("\n", $lines);
        $prompt = <<<PROMPT
Kamu adalah sistem klasifikasi bisnis lokal Indonesia.

Tugasmu: tentukan apakah setiap tempat berikut adalah UMKM lokal (bukan franchise atau jaringan nasional/internasional besar).

UMKM lokal: warung, kedai, toko kelontong, usaha kerajinan, rumah makan lokal, toko oleh-oleh, dan sejenisnya yang dimiliki/dioperasikan secara independen.
Bukan UMKM: franchise internasional, jaringan minimarket nasional, brand F&B besar, mall, hotel bintang 3+.

Daftar tempat:
{$list}

Jawab HANYA dalam format berikut, satu baris per tempat, tanpa teks lain:
place_id|Y/N|confidence_score
PROMPT;

        $response = $this->gemini->generate($prompt);

        if (! $response) {
            return [];
        }

        return $this->parseBatchResponse($response, 'umkm');
    }

    private function batchInferPriceTier(array $places, array $resolvedCategories): array
    {
        // Only classify places where Google did not provide priceLevel
        $toClassify = array_filter($places, fn ($p) => empty($p['priceLevel']));

        if (empty($toClassify)) {
            return [];
        }

        $lines = [];
        foreach ($toClassify as $p) {
            $placeId = $p['id'];
            $name = $p['displayName']['text'] ?? '';
            $address = $p['formattedAddress'] ?? '';
            $category = $resolvedCategories[$placeId] ?? $this->extractBaseCategory($p['types'] ?? []);
            $lines[] = "{$placeId} | {$name} | {$address} | {$category}";
        }

        $list = implode("\n", $lines);
        $prompt = <<<PROMPT
Kamu adalah sistem klasifikasi harga tempat di Indonesia.

Tier yang tersedia:
- free: gratis, taman umum, ruang publik
- budget: Rp0-50.000
- mid: Rp50.000-150.000
- premium: Rp150.000-500.000
- luxury: Rp500.000+

Daftar tempat:
{$list}

Jawab HANYA dalam format berikut, satu baris per tempat, tanpa teks lain:
place_id|tier|confidence_score
PROMPT;

        $response = $this->gemini->generate($prompt);

        if (! $response) {
            return [];
        }

        return $this->parseBatchResponse($response, 'price');
    }

    private function extractPriceTier(?string $priceLevel): string
    {
        return match ($priceLevel) {
            'PRICE_LEVEL_FREE' => 'free',
            'PRICE_LEVEL_INEXPENSIVE' => 'budget',
            'PRICE_LEVEL_MODERATE' => 'mid',
            'PRICE_LEVEL_EXPENSIVE' => 'premium',
            'PRICE_LEVEL_VERY_EXPENSIVE' => 'luxury',
            default => 'unknown',
        };
    }

    private function extractCountryCode(array $components): string
    {
        foreach ($components as $component) {
            $types = $component['types'] ?? [];
            if (in_array('country', $types, true)) {
                return $component['shortText'] ?? 'ID';
            }
        }

        return 'ID';
    }

    private function extractCity(array $components): string
    {
        foreach ($components as $component) {
            $types = $component['types'] ?? [];
            if (in_array('locality', $types, true)) {
                return $component['longText'] ?? '';
            }
        }

        return '';
    }

    public static function priceTierRange(string $tier): array
    {
        return match ($tier) {
            'free' => ['min' => 0, 'max' => 0, 'label' => 'Gratis'],
            'budget' => ['min' => 10000, 'max' => 50000, 'label' => 'Rp10.000 - Rp50.000'],
            'mid' => ['min' => 50000, 'max' => 150000, 'label' => 'Rp50.000 - Rp150.000'],
            'premium' => ['min' => 150000, 'max' => 500000, 'label' => 'Rp150.000 - Rp500.000'],
            'luxury' => ['min' => 500000, 'max' => null, 'label' => 'Rp500.000+'],
            default => ['min' => null, 'max' => null, 'label' => 'Tidak diketahui'],
        };
    }

    private function ensureDetailArea(Destination $destination): Area
    {
        $grid = $this->latLngToGrid($destination->lat, $destination->lng);

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

    private function latLngToGrid(float $lat, float $lng): array
    {
        return [
            'grid_lat' => (int) floor($lat / self::GRID_SIZE),
            'grid_lng' => (int) floor($lng / self::GRID_SIZE),
        ];
    }
}
