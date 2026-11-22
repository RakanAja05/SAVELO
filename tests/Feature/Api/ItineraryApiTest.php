<?php

declare(strict_types=1);

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\ItineraryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
});

test('POST /api/itineraries/generate returns 200 with valid itinerary structure on success', function () {
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
                'request',
                'itineraries',
            ],
        ])
        ->assertJson([
            'status' => 'success',
            'message' => 'Itinerary berhasil dibuat.',
        ]);

    expect($response->json('data.itineraries'))->toHaveCount(3);
});

test('POST /api/itineraries/generate returns 404 when city has no destinations', function () {
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

test('GET /api/itineraries/{itineraryId} returns 200 with itinerary detail', function () {
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
                'id',
                'variant',
                'days',
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
