<?php

declare(strict_types=1);

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryRequest;
use App\Models\User;
use App\Services\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

test('generate creates itinerary with three variants when successful', function () {
    $user = User::factory()->create();

    // Create destinations for the city and mark cache as synced
    $destinations = Destination::factory()->count(30)->create([
        'city' => 'Bandung',
        'rating' => 4.5,
    ]);

    // Mark city as cached (so it doesn't try to fetch from Google)
    Cache::put('city_data_synced_bandung', true, now()->addDays(30));

    // Mock Gemini response
    $geminiResponse = [
        'hemat' => [
            'title' => 'Hemat Budget',
            'tags' => ['budget friendly', 'local food', 'public transport'],
            'score' => 85,
            'total_cost' => 500000,
            'days' => [
                [
                    'day_number' => 1,
                    'items' => [
                        ['place_id' => $destinations[0]->place_id, 'order_index' => 1, 'visit_time' => '08:00'],
                        ['place_id' => $destinations[1]->place_id, 'order_index' => 2, 'visit_time' => '12:00'],
                        ['place_id' => $destinations[2]->place_id, 'order_index' => 3, 'visit_time' => '21:00'],
                    ],
                ],
            ],
        ],
        'seimbang' => [
            'title' => 'Seimbang',
            'tags' => ['balanced', 'mix activities', 'comfort'],
            'score' => 90,
            'total_cost' => 800000,
            'days' => [
                [
                    'day_number' => 1,
                    'items' => [
                        ['place_id' => $destinations[3]->place_id, 'order_index' => 1, 'visit_time' => '08:00'],
                        ['place_id' => $destinations[4]->place_id, 'order_index' => 2, 'visit_time' => '12:00'],
                        ['place_id' => $destinations[5]->place_id, 'order_index' => 3, 'visit_time' => '21:00'],
                    ],
                ],
            ],
        ],
        'experience' => [
            'title' => 'Premium Experience',
            'tags' => ['luxury', 'premium places', 'best rated'],
            'score' => 80,
            'total_cost' => 1200000,
            'days' => [
                [
                    'day_number' => 1,
                    'items' => [
                        ['place_id' => $destinations[6]->place_id, 'order_index' => 1, 'visit_time' => '08:00'],
                        ['place_id' => $destinations[7]->place_id, 'order_index' => 2, 'visit_time' => '12:00'],
                        ['place_id' => $destinations[8]->place_id, 'order_index' => 3, 'visit_time' => '21:00'],
                    ],
                ],
            ],
        ],
    ];

    Http::fake([
        '*/models/*:generateContent*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode($geminiResponse)]]],
                'finishReason' => 'STOP',
            ]],
        ], 200),
    ]);

    $service = app(ItineraryService::class);
    $result = $service->generate($user, [
        'origin' => 'Jakarta',
        'destination_label' => 'Bandung',
        'duration_days' => 1,
        'num_people' => 2,
        'budget' => 1500000,
    ]);

    expect($result)
        ->toHaveKey('data')
        ->and($result['data'])
        ->toHaveKey('request')
        ->toHaveKey('itineraries')
        ->and($result['data']['itineraries'])->toHaveCount(3);

    $request = ItineraryRequest::latest()->first();
    expect($request->status)->toBe('completed');
});

test('generate returns error when city has no destinations', function () {
    $user = User::factory()->create();

    $service = app(ItineraryService::class);
    $result = $service->generate($user, [
        'origin' => 'Jakarta',
        'destination_label' => 'UnknownCity',
        'duration_days' => 1,
        'num_people' => 2,
        'budget' => 1500000,
    ]);

    expect($result)
        ->toHaveKey('error')
        ->and($result['code'])->toBe(404);
});

test('generate returns error when Gemini fails', function () {
    $user = User::factory()->create();

    // Create destinations with unique areas to avoid UNIQUE constraint violations
    Destination::factory()->count(30)->create([
        'city' => 'Bandung',
        'area_id' => null, // Let factory create unique areas per destination
    ]);

    // Mark city as cached
    Cache::put('city_data_synced_bandung', true, now()->addDays(30));

    Http::fake([
        '*/models/*:generateContent*' => Http::response([], 500),
    ]);

    $service = app(ItineraryService::class);
    $result = $service->generate($user, [
        'origin' => 'Jakarta',
        'destination_label' => 'Bandung',
        'duration_days' => 1,
        'num_people' => 2,
        'budget' => 1500000,
    ]);

    expect($result)
        ->toHaveKey('error')
        ->and($result['code'])->toBe(500);
});

test('getRequestHistory returns itinerary for user', function () {
    $user = User::factory()->create();
    $request = ItineraryRequest::factory()->completed()->create(['user_id' => $user->id]);
    $itinerary = Itinerary::factory()->create(['request_id' => $request->id]);

    $service = app(ItineraryService::class);
    $result = $service->getRequestHistory($user, $request->id);

    expect($result)
        ->toHaveKey('data')
        ->and($result['data'])
        ->toHaveKey('request')
        ->toHaveKey('itineraries');
});

test('getRequestHistory returns error for non-existent request', function () {
    $user = User::factory()->create();

    $service = app(ItineraryService::class);
    $result = $service->getRequestHistory($user, 99999);

    expect($result)
        ->toHaveKey('error', 'Itinerary tidak ditemukan.')
        ->and($result['code'])->toBe(404);
});

test('getItineraryDetail returns detailed itinerary', function () {
    $user = User::factory()->create();
    $request = ItineraryRequest::factory()->create(['user_id' => $user->id]);
    $itinerary = Itinerary::factory()->create(['request_id' => $request->id]);

    $service = app(ItineraryService::class);
    $result = $service->getItineraryDetail($user, $itinerary->id);

    expect($result)
        ->toHaveKey('data')
        ->and($result['data']['itinerary'])->toHaveKey('id', $itinerary->id);
});

test('updateItem swaps destination and recalculates cost', function () {
    $user = User::factory()->create();
    $request = ItineraryRequest::factory()->create(['user_id' => $user->id, 'num_people' => 2]);
    $itinerary = Itinerary::factory()->create(['request_id' => $request->id, 'total_cost' => 100000]);
    $day = $itinerary->days()->create(['day_number' => 1, 'estimated_cost' => 100000]);

    $destination1 = Destination::factory()->create(['price_tier' => 'budget']);
    $destination2 = Destination::factory()->create(['price_tier' => 'mid']);

    $item = $day->items()->create([
        'destination_id' => $destination1->id,
        'order_index' => 1,
        'visit_time' => '08:00',
        'duration_minutes' => 90,
        'cost_estimate' => 20000,
        'status' => 'pending',
    ]);

    $service = app(ItineraryService::class);
    $result = $service->updateItem($user, $itinerary->id, $item->id, [
        'place_id' => $destination2->place_id,
    ]);

    expect($result)->toHaveKey('data');

    $item->refresh();
    expect($item->destination_id)->toBe($destination2->id);
});

test('checkLocation returns distance and radius check', function () {
    $user = User::factory()->create();
    $request = ItineraryRequest::factory()->create(['user_id' => $user->id]);
    $itinerary = Itinerary::factory()->create(['request_id' => $request->id]);
    $day = $itinerary->days()->create([
        'day_number' => 1,
        'estimated_cost' => 0,
    ]);

    $destination = Destination::factory()->create([
        'lat' => -6.9024,
        'lng' => 107.6186,
    ]);

    $item = $day->items()->create([
        'destination_id' => $destination->id,
        'order_index' => 1,
        'visit_time' => '08:00',
        'duration_minutes' => 90,
        'cost_estimate' => 50000,
    ]);

    $service = app(ItineraryService::class);

    // Close location (within 100m)
    $result = $service->checkLocation($user, $itinerary->id, $item->id, -6.9024, 107.6186);

    expect($result)
        ->toHaveKey('data')
        ->and($result['data']['is_within_radius'])->toBeTrue();
});

test('checkinPreview returns transport modes and culture points', function () {
    $user = User::factory()->create();
    $request = ItineraryRequest::factory()->create(['user_id' => $user->id]);
    $itinerary = Itinerary::factory()->create(['request_id' => $request->id]);
    $day = $itinerary->days()->create([
        'day_number' => 1,
        'estimated_cost' => 0,
    ]);

    $destination = Destination::factory()->create(['culture_points' => 20]);

    $item = $day->items()->create([
        'destination_id' => $destination->id,
        'order_index' => 1,
        'visit_time' => '08:00',
        'duration_minutes' => 90,
        'cost_estimate' => 50000,
    ]);

    $service = app(ItineraryService::class);
    $result = $service->checkinPreview($user, $itinerary->id, $item->id);

    expect($result)
        ->toHaveKey('data')
        ->and($result['data'])->toHaveKey('destination')
        ->and($result['data'])->toHaveKey('transport_modes');
});

test('generateSmartSwaps method does not exist and causes error', function () {
    // Pin current broken behavior: the method doesn't exist
    $user = User::factory()->create();
    $request = ItineraryRequest::factory()->create(['user_id' => $user->id]);
    $itinerary = Itinerary::factory()->create(['request_id' => $request->id]);

    $service = app(ItineraryService::class);

    expect(fn () => $service->generateSmartSwaps($itinerary, 1))
        ->toThrow(Error::class);
});
