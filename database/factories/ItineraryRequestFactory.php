<?php

namespace Database\Factories;

use App\Models\ItineraryRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItineraryRequestFactory extends Factory
{
    protected $model = ItineraryRequest::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'origin' => $this->faker->city(),
            'destination_label' => $this->faker->city(),
            'duration_days' => $this->faker->numberBetween(1, 7),
            'num_people' => $this->faker->numberBetween(1, 5),
            'budget' => $this->faker->numberBetween(500000, 5000000),
            'status' => 'pending',
            'gemini_raw_response' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }
}
