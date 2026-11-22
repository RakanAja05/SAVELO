<?php

declare(strict_types=1);

use App\Models\Destination;
use App\Models\User;
use App\Services\PlaceCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('GET /api/destinations returns 200 with destinations list when city has cached data', function () {
    $city = 'Yogyakarta';

    Destination::factory()->count(25)->create([
        'city' => $city,
        'rating' => 4.5,
        'category' => 'wisata',
    ]);

    Cache::put('city_data_synced_yogyakarta', true, now()->addDays(30));

    $response = $this->actingAs($this->user)->getJson("/api/destinations?city={$city}");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'from_cache',
                'stale',
                'area',
                'total',
                'destinations' => [
                    '*' => [
                        'id',
                        'place_id',
                        'name',
                        'category',
                        'city',
                        'rating',
                        'price_tier',
                        'price_range' => [
                            'min',
                            'max',
                            'label',
                        ],
                    ],
                ],
            ],
        ])
        ->assertJson([
            'status' => 'success',
        ]);
});

test('GET /api/destinations validates required city parameter', function () {
    $response = $this->actingAs($this->user)->getJson('/api/destinations');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['city']);
});

test('GET /api/destinations/{placeId} returns 200 when destination exists in cache', function () {
    // Mock PlaceCacheService to avoid SQLite acos() issue
    $destination = Destination::factory()->create([
        'place_id' => 'test_place_123',
        'detail_fetched_at' => now(),
    ]);

    $mockService = Mockery::mock(PlaceCacheService::class);
    $mockService->shouldReceive('getPlaceDetail')
        ->with('test_place_123')
        ->once()
        ->andReturn([
            'from_cache' => true,
            'destination' => $destination,
            'nearby' => collect([]),
        ]);

    $this->app->instance(PlaceCacheService::class, $mockService);

    $response = $this->actingAs($this->user)->getJson('/api/destinations/test_place_123');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'from_cache',
                'destination' => [
                    'id',
                    'place_id',
                    'name',
                ],
            ],
        ])
        ->assertJson([
            'status' => 'success',
        ]);
});

test('GET /api/destinations/map returns 200 with map pins', function () {
    $city = 'Yogyakarta';

    // Need at least 20 destinations for getCityPlaces to return from cache
    Destination::factory()->count(15)->create([
        'city' => $city,
        'map_category' => 'umkm',
    ]);

    Destination::factory()->count(5)->create([
        'city' => $city,
        'map_category' => 'iconic',
    ]);

    // Mark cache to avoid fetch attempt
    Cache::put('city_data_synced_yogyakarta', true, now()->addDays(30));

    $response = $this->actingAs($this->user)->getJson("/api/destinations/map?city={$city}");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'from_cache',
                'total',
                'destinations',
            ],
        ])
        ->assertJson([
            'status' => 'success',
        ]);

    expect($response->json('data.total'))->toBe(20);
});
