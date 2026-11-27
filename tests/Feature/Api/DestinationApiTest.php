<?php

declare(strict_types=1);

use App\Models\Area;
use App\Models\Destination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('GET /api/destinations returns 200 with destinations list when city has cached data', function () {
    $city = 'Yogyakarta';

    Destination::factory()->count(20)->create([
        'city' => $city,
        'rating' => 4.5,
        'category' => 'wisata',
    ]);

    Destination::factory()->count(5)->create([
        'city' => $city,
        'rating' => 4.2,
        'category' => 'restoran',
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
            'data' => [
                'from_cache' => true,
                'total' => 25,
            ],
        ]);
});

test('GET /api/destinations filters results by category query parameter', function () {
    $city = 'Yogyakarta';

    Destination::factory()->count(15)->create([
        'city' => $city,
        'category' => 'wisata',
    ]);

    Destination::factory()->count(5)->create([
        'city' => $city,
        'category' => 'restoran',
    ]);

    Cache::put('city_data_synced_yogyakarta', true, now()->addDays(30));

    $response = $this->actingAs($this->user)->getJson("/api/destinations?city={$city}&category=restoran");

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'total' => 5,
            ],
        ]);
});

test('GET /api/destinations validates required city parameter', function () {
    $response = $this->actingAs($this->user)->getJson('/api/destinations');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['city']);
});

test('GET /api/destinations returns 404 when Google returns no places', function () {
    Http::fake([
        '*/places:searchText*' => Http::response(['places' => []], 200),
    ]);

    $response = $this->actingAs($this->user)->getJson('/api/destinations?city=Atlantis');

    $response->assertStatus(404)
        ->assertJson([
            'status' => 'error',
        ]);
});

test('GET /api/destinations requires authentication', function () {
    $response = $this->getJson('/api/destinations?city=Yogyakarta');

    $response->assertStatus(401);
});

test('GET /api/destinations/{placeId} returns 200 when destination and area are fresh in cache', function () {
    $area = Area::factory()->create([
        'cached_at' => now(),
    ]);

    $destination = Destination::factory()->create([
        'place_id' => 'test_place_123',
        'area_id' => $area->id,
        'lat' => -7.7956,
        'lng' => 110.3695,
        'detail_fetched_at' => now(),
    ]);

    $response = $this->actingAs($this->user)->getJson('/api/destinations/test_place_123');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'from_cache',
                'stale',
                'destination' => [
                    'id',
                    'place_id',
                    'name',
                    'ai_microstory',
                    'cached_at',
                    'detail_fetched_at',
                ],
            ],
        ])
        ->assertJson([
            'status' => 'success',
            'data' => [
                'from_cache' => true,
                'destination' => [
                    'place_id' => 'test_place_123',
                ],
            ],
        ]);
});

test('GET /api/destinations/{placeId} returns 404 when place does not exist in DB or Google', function () {
    Http::fake([
        '*/places/unknown_place*' => Http::response([], 404),
    ]);

    $response = $this->actingAs($this->user)->getJson('/api/destinations/unknown_place');

    $response->assertStatus(404)
        ->assertJson([
            'status' => 'error',
            'message' => 'Tempat tidak ditemukan.',
        ]);
});

test('GET /api/destinations/{placeId} requires authentication', function () {
    $response = $this->getJson('/api/destinations/test_place_123');

    $response->assertStatus(401);
});

test('GET /api/destinations/map returns 200 with map pins and supports category filter', function () {
    $city = 'Yogyakarta';

    Destination::factory()->count(15)->create([
        'city' => $city,
        'map_category' => 'umkm',
    ]);

    Destination::factory()->count(5)->create([
        'city' => $city,
        'map_category' => 'iconic',
    ]);

    Cache::put('city_data_synced_yogyakarta', true, now()->addDays(30));

    $response = $this->actingAs($this->user)->getJson("/api/destinations/map?city={$city}&category=umkm");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'from_cache',
                'total',
                'destinations' => [
                    '*' => [
                        'place_id',
                        'map_category',
                        'pin_color',
                        'lat',
                        'lng',
                    ],
                ],
            ],
        ])
        ->assertJson([
            'status' => 'success',
            'data' => [
                'total' => 15,
            ],
        ]);

    expect($response->json('data.destinations.0.pin_color'))->toBe('#4CAF50');
});

test('GET /api/destinations/map validates required city and allowed category', function () {
    $response = $this->actingAs($this->user)->getJson('/api/destinations/map?category=invalid');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['city', 'category']);
});

test('GET /api/destinations/map requires authentication', function () {
    $response = $this->getJson('/api/destinations/map?city=Yogyakarta');

    $response->assertStatus(401);
});
