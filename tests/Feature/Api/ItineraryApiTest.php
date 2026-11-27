<?php

declare(strict_types=1);

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\ItineraryLeg;
use App\Models\ItineraryRequest;
use App\Models\TransportMode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('POST /api/itineraries/generate returns 200 with valid itinerary structure and fetches legs', function () {
    $destinations = Destination::factory()->count(30)->create([
        'city' => 'Bandung',
        'rating' => 4.5,
    ]);

    Cache::put('city_data_synced_bandung', true, now()->addDays(30));

    $geminiResponse = [
        'hemat' => [
            'title' => 'Hemat Budget',
            'tags' => ['budget friendly'],
            'score' => 85,
            'total_cost' => 500000,
            'days' => [
                [
                    'day_number' => 1,
                    'items' => [
                        ['place_id' => $destinations[0]->place_id, 'order_index' => 1, 'visit_time' => '08:00'],
                        ['place_id' => $destinations[1]->place_id, 'order_index' => 2, 'visit_time' => '12:00'],
                    ],
                ],
            ],
        ],
        'seimbang' => [
            'title' => 'Seimbang',
            'tags' => ['balanced'],
            'score' => 90,
            'total_cost' => 800000,
            'days' => [
                [
                    'day_number' => 1,
                    'items' => [
                        ['place_id' => $destinations[2]->place_id, 'order_index' => 1, 'visit_time' => '08:00'],
                    ],
                ],
            ],
        ],
        'experience' => [
            'title' => 'Premium',
            'tags' => ['luxury'],
            'score' => 80,
            'total_cost' => 1200000,
            'days' => [
                [
                    'day_number' => 1,
                    'items' => [
                        ['place_id' => $destinations[3]->place_id, 'order_index' => 1, 'visit_time' => '08:00'],
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
        '*maps.googleapis.com/maps/api/distancematrix/json*' => Http::response([
            'status' => 'OK',
            'rows' => [[
                'elements' => [[
                    'status' => 'OK',
                    'distance' => ['value' => 2400],
                    'duration' => ['value' => 480],
                ]],
            ]],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->postJson('/api/itineraries/generate', [
        'origin' => 'Jakarta',
        'destination_label' => 'Bandung',
        'duration_days' => 1,
        'num_people' => 2,
        'budget' => 1500000,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'request' => ['id', 'origin', 'destination_label', 'duration_days', 'num_people', 'budget', 'status'],
                'itineraries',
            ],
        ])
        ->assertJson([
            'status' => 'success',
            'message' => 'Itinerary berhasil dibuat.',
        ]);

    expect($response->json('data.itineraries'))->toHaveCount(3);
    expect(ItineraryLeg::count())->toBe(1);
});

test('POST /api/itineraries/generate returns 422 on invalid payload', function () {
    $response = $this->actingAs($this->user)->postJson('/api/itineraries/generate', [
        'origin' => 'J',
        'duration_days' => 0,
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'status' => 'error',
            'message' => 'Validation failed.',
        ]);
});

test('POST /api/itineraries/generate returns 404 when city has no destinations', function () {
    Http::fake([
        '*/places:searchText*' => Http::response(['places' => []], 200),
    ]);

    $response = $this->actingAs($this->user)->postJson('/api/itineraries/generate', [
        'origin' => 'Jakarta',
        'destination_label' => 'UnknownCity',
        'duration_days' => 1,
        'num_people' => 2,
        'budget' => 1500000,
    ]);

    $response->assertStatus(404)
        ->assertJson([
            'status' => 'error',
        ]);
});

test('GET /api/itineraries/requests/{requestId} returns 200 for owner and 404 for other user', function () {
    $request = ItineraryRequest::factory()->completed()->create(['user_id' => $this->user->id]);
    Itinerary::factory()->create(['request_id' => $request->id]);

    $response = $this->actingAs($this->user)->getJson("/api/itineraries/requests/{$request->id}");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'request',
                'itineraries',
            ],
        ])
        ->assertJson([
            'status' => 'success',
            'message' => 'Riwayat itinerary.',
        ]);

    $otherUser = User::factory()->create();
    $this->actingAs($otherUser)
        ->getJson("/api/itineraries/requests/{$request->id}")
        ->assertStatus(404)
        ->assertJson(['message' => 'Itinerary tidak ditemukan.']);
});

test('GET /api/itineraries/{itineraryId} returns 200 with request and itinerary detail', function () {
    $request = ItineraryRequest::factory()->create([
        'user_id' => $this->user->id,
        'gemini_raw_response' => [
            'parsed' => [
                'variants' => [
                    ['variant' => 'hemat', 'is_recommended' => true],
                ],
            ],
        ],
    ]);

    $itinerary = Itinerary::factory()->create([
        'request_id' => $request->id,
        'variant' => 'hemat',
    ]);

    $day = ItineraryDay::factory()->create([
        'itinerary_id' => $itinerary->id,
        'day_number' => 1,
    ]);

    $destination = Destination::factory()->create();

    ItineraryItem::factory()->create([
        'itinerary_day_id' => $day->id,
        'destination_id' => $destination->id,
    ]);

    $response = $this->actingAs($this->user)->getJson("/api/itineraries/{$itinerary->id}");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'request',
                'itinerary' => [
                    'id',
                    'variant',
                    'days',
                ],
            ],
        ])
        ->assertJson([
            'status' => 'success',
        ]);
});

test('GET /api/itineraries/{itineraryId} returns 404 when itinerary not found', function () {
    $response = $this->actingAs($this->user)->getJson('/api/itineraries/99999');

    $response->assertStatus(404)
        ->assertJson([
            'status' => 'error',
        ]);
});

test('GET /api/itineraries/{itineraryId}/days/{dayNumber} returns 200 and 404 for missing day', function () {
    $request = ItineraryRequest::factory()->create(['user_id' => $this->user->id]);
    $itinerary = Itinerary::factory()->create(['request_id' => $request->id]);
    $day = ItineraryDay::factory()->create(['itinerary_id' => $itinerary->id, 'day_number' => 1]);
    $destination = Destination::factory()->create();
    ItineraryItem::factory()->create(['itinerary_day_id' => $day->id, 'destination_id' => $destination->id]);

    $response = $this->actingAs($this->user)->getJson("/api/itineraries/{$itinerary->id}/days/1");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'request',
                'itinerary',
                'day' => ['day_number', 'estimated_cost', 'items'],
            ],
        ]);

    $this->actingAs($this->user)
        ->getJson("/api/itineraries/{$itinerary->id}/days/99")
        ->assertStatus(404)
        ->assertJson(['message' => 'Rencana hari tidak ditemukan.']);
});

test('PATCH /api/itineraries/{itineraryId}/{itemId} supports swapping place_id and updating status only', function () {
    Http::fake([
        '*maps.googleapis.com/maps/api/distancematrix/json*' => Http::response(['status' => 'ZERO_RESULTS'], 200),
    ]);

    $request = ItineraryRequest::factory()->create(['user_id' => $this->user->id, 'num_people' => 2]);
    $itinerary = Itinerary::factory()->create(['request_id' => $request->id, 'total_cost' => 20000]);
    $day = ItineraryDay::factory()->create(['itinerary_id' => $itinerary->id, 'day_number' => 1, 'estimated_cost' => 20000]);

    $destination1 = Destination::factory()->create(['price_tier' => 'budget']);
    $destination2 = Destination::factory()->create(['price_tier' => 'mid']);

    $item = ItineraryItem::factory()->create([
        'itinerary_day_id' => $day->id,
        'destination_id' => $destination1->id,
        'order_index' => 1,
        'cost_estimate' => 20000,
        'status' => 'pending',
    ]);

    $swapResponse = $this->actingAs($this->user)->patchJson("/api/itineraries/{$itinerary->id}/{$item->id}", [
        'place_id' => $destination2->place_id,
    ]);

    $swapResponse->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => ['request', 'itinerary'],
        ]);

    expect($item->fresh()->destination_id)->toBe($destination2->id);
    expect((float) $itinerary->fresh()->total_cost)->toBe(100000.0);

    $statusResponse = $this->actingAs($this->user)->patchJson("/api/itineraries/{$itinerary->id}/{$item->id}", [
        'status' => 'completed',
    ]);

    $statusResponse->assertStatus(200);
    expect($item->fresh()->status)->toBe('completed');
    expect((float) $itinerary->fresh()->total_cost)->toBe(0.0);

    $this->actingAs($this->user)
        ->patchJson("/api/itineraries/{$itinerary->id}/{$item->id}", [])
        ->assertStatus(422);
});

test('POST /api/itineraries/{itineraryId}/items/{itemId}/check-location enforces 100m radius', function () {
    $request = ItineraryRequest::factory()->create(['user_id' => $this->user->id]);
    $itinerary = Itinerary::factory()->create(['request_id' => $request->id]);
    $day = ItineraryDay::factory()->create(['itinerary_id' => $itinerary->id, 'day_number' => 1]);
    $destination = Destination::factory()->create(['lat' => -6.9024, 'lng' => 107.6186]);
    $item = ItineraryItem::factory()->create(['itinerary_day_id' => $day->id, 'destination_id' => $destination->id]);

    $nearResponse = $this->actingAs($this->user)->postJson(
        "/api/itineraries/{$itinerary->id}/items/{$item->id}/check-location",
        ['user_lat' => -6.9024, 'user_lng' => 107.6186]
    );

    $nearResponse->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'is_within_radius' => true,
            ],
        ]);

    $farResponse = $this->actingAs($this->user)->postJson(
        "/api/itineraries/{$itinerary->id}/items/{$item->id}/check-location",
        ['user_lat' => -6.9047, 'user_lng' => 107.6186]
    );

    $farResponse->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'is_within_radius' => false,
            ],
        ]);

    $this->actingAs($this->user)
        ->postJson("/api/itineraries/{$itinerary->id}/items/{$item->id}/check-location", [])
        ->assertStatus(422);
});

test('GET /api/itineraries/{itineraryId}/items/{itemId}/checkin-preview returns sorted transport modes and leg distance', function () {
    $request = ItineraryRequest::factory()->create(['user_id' => $this->user->id]);
    $itinerary = Itinerary::factory()->create(['request_id' => $request->id]);
    $day = ItineraryDay::factory()->create(['itinerary_id' => $itinerary->id, 'day_number' => 1]);
    $destination1 = Destination::factory()->create();
    $destination2 = Destination::factory()->create(['culture_points' => 30]);
    $item1 = ItineraryItem::factory()->create(['itinerary_day_id' => $day->id, 'destination_id' => $destination1->id, 'order_index' => 1]);
    $item2 = ItineraryItem::factory()->create(['itinerary_day_id' => $day->id, 'destination_id' => $destination2->id, 'order_index' => 2]);

    ItineraryLeg::create([
        'from_item_id' => $item1->id,
        'to_item_id' => $item2->id,
        'distance_km' => 2.5,
        'duration_min' => 10,
    ]);

    TransportMode::factory()->create(['mode' => 'car', 'label' => 'Mobil', 'eco_points_rate' => 1, 'co2_per_km' => 0.15]);
    TransportMode::factory()->create(['mode' => 'walk', 'label' => 'Jalan Kaki', 'eco_points_rate' => 10, 'co2_per_km' => 0]);

    $response = $this->actingAs($this->user)->getJson(
        "/api/itineraries/{$itinerary->id}/items/{$item2->id}/checkin-preview"
    );

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'destination' => ['name', 'address', 'culture_points'],
                'leg_distance_km',
                'transport_modes' => [
                    '*' => ['id', 'mode', 'label', 'eco_points_rate', 'co2_per_km'],
                ],
            ],
        ]);

    expect((float) $response->json('data.leg_distance_km'))->toBe(2.5);
    expect($response->json('data.transport_modes.0.mode'))->toBe('walk');
});

test('PATCH /api/itineraries/{itineraryId}/items/{itemId}/checkin awards points and rejects duplicate checkin', function () {
    $request = ItineraryRequest::factory()->create(['user_id' => $this->user->id, 'budget' => 500000]);
    $itinerary = Itinerary::factory()->create(['request_id' => $request->id, 'total_budget' => 500000, 'total_cost' => 100000]);
    $day = ItineraryDay::factory()->create(['itinerary_id' => $itinerary->id, 'day_number' => 1]);
    $destination = Destination::factory()->create(['culture_points' => 30]);
    $item = ItineraryItem::factory()->create([
        'itinerary_day_id' => $day->id,
        'destination_id' => $destination->id,
        'cost_estimate' => 50000,
        'status' => 'pending',
    ]);
    $mode = TransportMode::factory()->create(['mode' => 'walk', 'label' => 'Jalan Kaki', 'eco_points_rate' => 10, 'co2_per_km' => 0]);

    $response = $this->actingAs($this->user)->patchJson(
        "/api/itineraries/{$itinerary->id}/items/{$item->id}/checkin",
        ['transport_mode_id' => $mode->id]
    );

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'item',
                'points_earned' => ['eco_points', 'culture_points', 'path_points'],
                'user_total_path_points',
                'budget_snapshot' => ['total_budget', 'total_cost', 'remaining_budget'],
            ],
        ])
        ->assertJson([
            'status' => 'success',
            'data' => [
                'points_earned' => [
                    'culture_points' => 30,
                    'path_points' => 30,
                ],
                'user_total_path_points' => 30,
            ],
        ]);

    $this->actingAs($this->user)
        ->patchJson("/api/itineraries/{$itinerary->id}/items/{$item->id}/checkin", ['transport_mode_id' => $mode->id])
        ->assertStatus(422);

    $this->actingAs($this->user)
        ->patchJson("/api/itineraries/{$itinerary->id}/items/{$item->id}/checkin", ['transport_mode_id' => 999999])
        ->assertStatus(422);
});

test('POST /api/itineraries/{itineraryId}/smart-swaps returns 501 Not Implemented cleanly', function () {
    $request = ItineraryRequest::factory()->create(['user_id' => $this->user->id]);
    $itinerary = Itinerary::factory()->create(['request_id' => $request->id]);

    $response = $this->actingAs($this->user)->postJson("/api/itineraries/{$itinerary->id}/smart-swaps", [
        'current_day_number' => 1,
    ]);

    $response->assertStatus(501)
        ->assertJson([
            'status' => 'error',
            'message' => 'Smart swaps belum diimplementasikan pada prototipe ini.',
        ]);

    $this->actingAs($this->user)
        ->postJson("/api/itineraries/{$itinerary->id}/smart-swaps", [])
        ->assertStatus(422);

    $this->actingAs($this->user)
        ->postJson('/api/itineraries/99999/smart-swaps', ['current_day_number' => 1])
        ->assertStatus(404);
});

test('itinerary endpoints require authentication', function () {
    $this->postJson('/api/itineraries/generate', [])->assertStatus(401);
    $this->getJson('/api/itineraries/requests/1')->assertStatus(401);
    $this->getJson('/api/itineraries/1')->assertStatus(401);
    $this->getJson('/api/itineraries/1/days/1')->assertStatus(401);
    $this->patchJson('/api/itineraries/1/1', [])->assertStatus(401);
    $this->postJson('/api/itineraries/1/items/1/check-location', [])->assertStatus(401);
    $this->getJson('/api/itineraries/1/items/1/checkin-preview')->assertStatus(401);
    $this->patchJson('/api/itineraries/1/items/1/checkin', [])->assertStatus(401);
    $this->postJson('/api/itineraries/1/smart-swaps', [])->assertStatus(401);
});
