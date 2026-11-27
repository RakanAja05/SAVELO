<?php

declare(strict_types=1);

use App\Models\Destination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('GET /api/favorites returns 200 with favorites list', function () {
    $destinations = Destination::factory()->count(3)->create();

    $this->user->favorites()->attach($destinations->pluck('id'));

    $response = $this->actingAs($this->user)->getJson('/api/favorites');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'total',
                'destinations' => [
                    '*' => [
                        'id',
                        'place_id',
                        'name',
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
            'message' => 'Destinasi favorit.',
            'data' => [
                'total' => 3,
            ],
        ]);
});

test('POST /api/favorites returns 200 when adding destination to favorites', function () {
    $destination = Destination::factory()->create();

    $response = $this->actingAs($this->user)->postJson('/api/favorites', [
        'destination_id' => $destination->id,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'destination',
            ],
        ])
        ->assertJson([
            'status' => 'success',
        ]);

    $this->assertDatabaseHas('user_favorite_destinations', [
        'user_id' => $this->user->id,
        'destination_id' => $destination->id,
    ]);
});

test('POST /api/favorites returns 422 when destination_id is missing or invalid', function () {
    $response = $this->actingAs($this->user)->postJson('/api/favorites', [
        'destination_id' => 999999,
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'status' => 'error',
            'message' => 'Validation failed.',
        ]);
});

test('DELETE /api/favorites/{destinationId} returns 200 when removing favorite', function () {
    $destination = Destination::factory()->create();
    $this->user->favorites()->attach($destination->id);

    $response = $this->actingAs($this->user)->deleteJson("/api/favorites/{$destination->id}");

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
        ]);

    $this->assertDatabaseMissing('user_favorite_destinations', [
        'user_id' => $this->user->id,
        'destination_id' => $destination->id,
    ]);
});

test('favorites endpoints require authentication', function () {
    $this->getJson('/api/favorites')->assertStatus(401);
    $this->postJson('/api/favorites', ['destination_id' => 1])->assertStatus(401);
    $this->deleteJson('/api/favorites/1')->assertStatus(401);
});
