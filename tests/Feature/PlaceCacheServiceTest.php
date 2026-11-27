<?php

declare(strict_types=1);

use App\Models\Area;
use App\Models\Destination;
use App\Services\PlaceCache\PriceTierResolver;
use App\Services\PlaceCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('getCityPlaces returns cached data when available and place count is sufficient', function () {
    $city = 'Yogyakarta';

    for ($i = 0; $i < 25; $i++) {
        Destination::factory()->create([
            'city' => $city,
            'place_id' => "place_$i",
            'rating' => 4.5 - ($i * 0.01),
        ]);
    }

    Cache::put('city_data_synced_yogyakarta', true, now()->addDays(30));

    $service = app(PlaceCacheService::class);
    $result = $service->getCityPlaces($city);

    expect($result)
        ->toHaveKey('from_cache', true)
        ->toHaveKey('places')
        ->and($result['places'])->toHaveCount(25);
});

test('getCityPlaces fetches from Google when cache is empty', function () {
    $city = 'Bandung';

    Http::fake([
        '*/places:searchText*' => Http::sequence()
            ->push(['places' => [
                [
                    'id' => 'place_1',
                    'displayName' => ['text' => 'Gedung Sate'],
                    'formattedAddress' => 'Jl. Diponegoro, Bandung',
                    'addressComponents' => [
                        ['types' => ['locality'], 'longText' => 'Bandung'],
                        ['types' => ['country'], 'shortText' => 'ID'],
                    ],
                    'location' => ['latitude' => -6.9024, 'longitude' => 107.6186],
                    'rating' => 4.6,
                    'userRatingCount' => 5000,
                    'types' => ['tourist_attraction'],
                    'photos' => [['name' => 'photo1']],
                ],
            ]], 200)
            ->push(['places' => []], 200)
            ->push(['places' => []], 200)
            ->push(['places' => []], 200),
        '*/models/*:generateContent*' => Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => 'place_1|N|0.95']]], 'finishReason' => 'STOP'],
            ],
        ], 200),
    ]);

    $service = app(PlaceCacheService::class);
    $result = $service->getCityPlaces($city);

    expect($result)
        ->toHaveKey('from_cache', false)
        ->toHaveKey('places')
        ->and($result['places']->count())->toBeGreaterThan(0);

    $this->assertDatabaseHas('areas', [
        'grid_lat' => (int) floor(-6.9024 / 0.02),
        'grid_lng' => (int) floor(107.6186 / 0.02),
    ]);
});

test('getCityPlaces returns standardized error message when Google throws an exception', function () {
    Http::fake([
        '*/places:searchText*' => function () {
            throw new RuntimeException('Connection failed');
        },
    ]);

    $service = app(PlaceCacheService::class);
    $result = $service->getCityPlaces('Semarang');

    expect($result)
        ->toBe([
            'places' => [],
            'error' => 'Gagal mengambil data, coba lagi nanti.',
        ]);
});

test('getCityPlaces returns stale data when lock times out', function () {
    config(['services.places.lock_block_seconds' => 1]);
    $city = 'Surabaya';

    Destination::factory()->count(5)->create(['city' => $city]);

    $lock = Cache::lock('city_fetch_surabaya', 60);
    $lock->get();

    $service = app(PlaceCacheService::class);
    $result = $service->getCityPlaces($city);

    expect($result)
        ->toHaveKey('from_cache', true)
        ->toHaveKey('stale', true)
        ->toHaveKey('places');

    $lock->release();
});

test('getPlaceDetail returns cached detail and nearby places when area is fresh', function () {
    $area = Area::factory()->create([
        'cached_at' => now(),
    ]);

    $destination = Destination::factory()->create([
        'place_id' => 'cached_place',
        'area_id' => $area->id,
        'lat' => -6.9024,
        'lng' => 107.6186,
        'detail_fetched_at' => now(),
    ]);

    Destination::factory()->create([
        'place_id' => 'nearby_cached_place',
        'area_id' => $area->id,
        'lat' => -6.9026,
        'lng' => 107.6188,
    ]);

    $service = app(PlaceCacheService::class);
    $result = $service->getPlaceDetail('cached_place');

    expect($result)
        ->toHaveKey('from_cache', true)
        ->toHaveKey('destination')
        ->and($result['destination']->place_id)->toBe('cached_place')
        ->and($result['nearby'])->toHaveCount(1)
        ->and($result['nearby']->first()->place_id)->toBe('nearby_cached_place');
});

test('getPlaceDetail fetches nearby places, updates area cache timestamp, and persists nearby results when stale', function () {
    $destination = Destination::factory()->create([
        'place_id' => 'stale_place',
        'lat' => -6.9024,
        'lng' => 107.6186,
        'city' => 'Bandung',
        'area_id' => null,
        'detail_fetched_at' => now()->subDays(40),
    ]);

    Http::fake([
        '*/places:searchNearby*' => Http::response([
            'places' => [
                [
                    'id' => 'nearby_1',
                    'displayName' => ['text' => 'Nearby Warung'],
                    'formattedAddress' => 'Jl. Test',
                    'addressComponents' => [
                        ['types' => ['locality'], 'longText' => 'Bandung'],
                        ['types' => ['country'], 'shortText' => 'ID'],
                    ],
                    'location' => ['latitude' => -6.9025, 'longitude' => 107.6187],
                    'rating' => 4.5,
                    'userRatingCount' => 100,
                    'types' => ['restaurant'],
                ],
            ],
        ], 200),
        '*/models/*:generateContent*' => Http::sequence()
            ->push([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'nearby_1|Y|0.90']]], 'finishReason' => 'STOP'],
                ],
            ], 200)
            ->push([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'nearby_1|budget|0.88']]], 'finishReason' => 'STOP'],
                ],
            ], 200)
            ->push([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Test microstory.']]], 'finishReason' => 'STOP'],
                ],
            ], 200),
    ]);

    $service = app(PlaceCacheService::class);
    $result = $service->getPlaceDetail('stale_place');

    expect($result)
        ->toHaveKey('from_cache', false)
        ->toHaveKey('destination')
        ->and($result['destination']->detail_fetched_at)->not->toBeNull()
        ->and($result['destination']->ai_microstory)->toBe('Test microstory.')
        ->and($result['destination']->area?->isFresh())->toBeTrue()
        ->and($result['nearby'])->toHaveCount(1)
        ->and($result['nearby']->first()->place_id)->toBe('nearby_1')
        ->and($result['nearby']->first()->category)->toBe('umkm');
});

test('getPlaceDetail creates new destination from Google when not in DB', function () {
    Http::fake([
        '*/places/new_place*' => Http::response([
            'id' => 'new_place',
            'displayName' => ['text' => 'New Place'],
            'formattedAddress' => 'Jl. New, Jakarta',
            'addressComponents' => [
                ['types' => ['locality'], 'longText' => 'Jakarta'],
                ['types' => ['country'], 'shortText' => 'ID'],
            ],
            'location' => ['latitude' => -6.2088, 'longitude' => 106.8456],
            'types' => ['restaurant'],
        ], 200),
        '*/places:searchNearby*' => Http::response(['places' => []], 200),
        '*/models/*:generateContent*' => Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => 'Cerita singkat destinasi baru.']]], 'finishReason' => 'STOP'],
            ],
        ], 200),
    ]);

    $service = app(PlaceCacheService::class);
    $result = $service->getPlaceDetail('new_place');

    expect($result)
        ->toHaveKey('destination')
        ->and($result['destination']->place_id)->toBe('new_place')
        ->and($result['destination']->name)->toBe('New Place')
        ->and($result['destination']->ai_microstory)->toBe('Cerita singkat destinasi baru.');
});

test('getPlaceDetail returns error when place not found', function () {
    Http::fake([
        '*/places/nonexistent*' => Http::response([], 404),
    ]);

    $service = app(PlaceCacheService::class);
    $result = $service->getPlaceDetail('nonexistent');

    expect($result)
        ->toHaveKey('error', 'Tempat tidak ditemukan.')
        ->toHaveKey('code', 404);
});

test('priceTierRange returns correct ranges for all tiers', function () {
    $resolver = app(PriceTierResolver::class);

    expect($resolver->priceTierRange('free'))
        ->toEqual(['min' => 0, 'max' => 0, 'label' => 'Gratis'])
        ->and($resolver->priceTierRange('budget'))
        ->toEqual(['min' => 10000, 'max' => 50000, 'label' => 'Rp10.000 - Rp50.000'])
        ->and($resolver->priceTierRange('mid'))
        ->toEqual(['min' => 50000, 'max' => 150000, 'label' => 'Rp50.000 - Rp150.000'])
        ->and($resolver->priceTierRange('premium'))
        ->toEqual(['min' => 150000, 'max' => 500000, 'label' => 'Rp150.000 - Rp500.000'])
        ->and($resolver->priceTierRange('luxury'))
        ->toEqual(['min' => 500000, 'max' => null, 'label' => 'Rp500.000+'])
        ->and($resolver->priceTierRange('unknown'))
        ->toEqual(['min' => null, 'max' => null, 'label' => 'Tidak diketahui']);
});
