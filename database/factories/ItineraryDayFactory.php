<?php

namespace Database\Factories;

use App\Models\Itinerary;
use App\Models\ItineraryDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItineraryDay>
 */
class ItineraryDayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'itinerary_id' => Itinerary::factory(),
            'day_number' => 1,
            'estimated_cost' => fake()->randomFloat(2, 50000, 500000),
        ];
    }
}
