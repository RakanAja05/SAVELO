<?php

namespace Database\Factories;

use App\Models\Destination;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItineraryItem>
 */
class ItineraryItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'itinerary_day_id' => ItineraryDay::factory(),
            'destination_id' => Destination::factory(),
            'order_index' => fake()->numberBetween(1, 10),
            'visit_time' => fake()->time('H:i'),
            'duration_minutes' => fake()->randomElement([60, 90, 120, 180]),
            'cost_estimate' => fake()->randomFloat(2, 10000, 200000),
            'notes' => null,
            'status' => 'pending',
            'completed_at' => null,
        ];
    }
}
